<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EfakturaSendController extends Controller
{
    public function signingInput(Request $request, Company $company, SalesInvoice $salesInvoice, EfakturaJwsService $jwsService)
    {
        $this->authorizeSigning($company, $salesInvoice);

        if (! $this->hasCompleteAddress($company)) {
            return response()->json(['error' => 'incomplete_address', 'message' => "Адресата на фирмата \"{$company->name}\" не е целосна — пополни улица, број, поштенски број и град во Профил на фирма."], 422);
        }
        if (! $this->hasCompleteAddress($salesInvoice->partner)) {
            return response()->json(['error' => 'incomplete_address', 'message' => "Адресата на партнерот \"{$salesInvoice->partner->name}\" не е целосна — пополни улица, број, поштенски број и град во профилот на партнерот."], 422);
        }

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $result = $jwsService->buildSigningInput($salesInvoice, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-signing:{$token}", [
            'company_id' => $company->id,
            'sales_invoice_id' => $salesInvoice->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function send(Request $request, Company $company, SalesInvoice $salesInvoice, EfakturaJwsService $jwsService)
    {
        $this->authorizeSigning($company, $salesInvoice);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-signing:{$validated['token']}");

        if (! $cached || $cached['company_id'] !== $company->id || $cached['sales_invoice_id'] !== $salesInvoice->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->send($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $salesInvoice->update(['efaktura_status' => 'failed', 'efaktura_error' => $e->getMessage()]);

            return response()->json([
                'error' => 'ujp_unreachable',
                'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.',
            ], 503);
        }

        if (! $response->successful()) {
            $salesInvoice->update(['efaktura_status' => 'failed', 'efaktura_error' => $response->body()]);

            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Field name is a best guess pending Task 18's live-UJP confirmation of the real success response shape.
        $salesInvoice->update([
            'efaktura_status' => 'sent',
            'efaktura_sent_at' => now(),
            'efaktura_error' => null,
            'efaktura_doc_id' => $response->json('docId') ?? $response->json('documentId') ?? $response->json('id'),
        ]);

        return response()->json(['status' => 'sent']);
    }

    private function authorizeSigning(Company $company, SalesInvoice $salesInvoice): void
    {
        Gate::authorize('view', $salesInvoice);
        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless($salesInvoice->status === 'confirmed', 422, 'Само потврдени фактури можат да се потпишат и испратат.');
        abort_if($salesInvoice->efaktura_status === 'sent', 422, 'Оваа фактура е веќе испратена до УЈП.');
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Праќање на е-Фактура преку фирмениот сертификат сè уште не е поддржано — регистрирај сопствен потпишувачки уред за оваа компанија.'
        );
    }

    private function hasCompleteAddress(Company|Partner $party): bool
    {
        return filled($party->street_address) && filled($party->street_number)
            && filled($party->postal_code) && filled($party->city);
    }
}
