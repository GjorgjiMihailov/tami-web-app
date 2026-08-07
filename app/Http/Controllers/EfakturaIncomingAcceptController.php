<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Services\Efaktura\EfakturaJwsService;
use App\Services\Efaktura\IncomingPurchaseInvoiceBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EfakturaIncomingAcceptController extends Controller
{
    public function signingInput(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizeDecision($company, $incomingEfakturaDocument);

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euid' => $incomingEfakturaDocument->euid,
            'isAccepted' => true,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-accept:{$token}", [
            'company_id' => $company->id,
            'document_id' => $incomingEfakturaDocument->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function store(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService, IncomingPurchaseInvoiceBuilder $builder)
    {
        $this->authorizeDecision($company, $incomingEfakturaDocument);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-accept:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id || $cached['document_id'] !== $incomingEfakturaDocument->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoiceAcceptReject($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        $invoice = $builder->build($company, $incomingEfakturaDocument->payload_json, $request->user());

        $incomingEfakturaDocument->update([
            'decision' => IncomingEfakturaDocument::DECISION_ACCEPTED,
            'decided_at' => now(),
            'decided_by' => $request->user()->id,
            'purchase_invoice_id' => $invoice->id,
        ]);

        return response()->json(['status' => 'accepted', 'purchaseInvoiceId' => $invoice->id]);
    }

    private function authorizeDecision(Company $company, IncomingEfakturaDocument $incomingEfakturaDocument): void
    {
        Gate::authorize('view', $company);
        abort_if($incomingEfakturaDocument->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Прифаќање влезна е-фактура преку фирмениот сертификат сè уште не е поддржано.'
        );
        abort_if($incomingEfakturaDocument->decision !== null, 422, 'Веќе е одлучено за оваа фактура.');
    }
}
