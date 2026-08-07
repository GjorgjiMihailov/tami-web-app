<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EfakturaIncomingRejectController extends Controller
{
    public function signingInput(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizeDecision($company, $incomingEfakturaDocument);

        $validated = $request->validate([
            'certificateBase64' => 'required|string',
            'reasonCode' => 'required|string',
            'comment' => 'nullable|string|max:255',
        ]);

        // Validate reason code is known
        $reasonCodes = array_keys(IncomingEfakturaDocument::REJECT_REASONS);
        if (!in_array($validated['reasonCode'], $reasonCodes)) {
            return response()->json(['errors' => ['reasonCode' => ['The selected reason code is invalid.']]], 422);
        }

        // Validate comment is required for REJECT_REASON_OTHER
        if ($validated['reasonCode'] === IncomingEfakturaDocument::REJECT_REASON_OTHER && !($validated['comment'] ?? null)) {
            return response()->json(['errors' => ['comment' => ['Comment is required for this reason code.']]], 422);
        }

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euid' => $incomingEfakturaDocument->euid,
            'isAccepted' => false,
            'rejectReasonCode' => $validated['reasonCode'],
            'comment' => $validated['comment'] ?? null,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-reject:{$token}", [
            'company_id' => $company->id,
            'document_id' => $incomingEfakturaDocument->id,
            'signing_input' => $result['signingInput'],
            'reason_code' => $validated['reasonCode'],
            'comment' => $validated['comment'] ?? null,
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function store(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizeDecision($company, $incomingEfakturaDocument);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-reject:{$validated['token']}");
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

        $incomingEfakturaDocument->update([
            'decision' => IncomingEfakturaDocument::DECISION_REJECTED,
            'decided_at' => now(),
            'decided_by' => $request->user()->id,
            'reject_reason_code' => $cached['reason_code'],
            'reject_comment' => $cached['comment'],
        ]);

        return response()->json(['status' => 'rejected']);
    }

    private function authorizeDecision(Company $company, IncomingEfakturaDocument $incomingEfakturaDocument): void
    {
        Gate::authorize('view', $company);
        abort_if($incomingEfakturaDocument->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Одбивање влезна е-фактура преку фирмениот сертификат сè уште не е поддржано.'
        );
        abort_if($incomingEfakturaDocument->decision !== null, 422, 'Веќе е одлучено за оваа фактура.');
    }
}
