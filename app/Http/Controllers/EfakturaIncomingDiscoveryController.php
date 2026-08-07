<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EfakturaIncomingDiscoveryController extends Controller
{
    public function idsSigningInput(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        // УЈП live-confirmed constraint (Task 12, 2026-08-07): "Дозволениот временски опсег е 1
        // месец" (E4014) — purchase-invoice/ids rejects any dateFrom more than 1 month before
        // dateTo. A stale/never-checked watermark is clamped to the most recent month; the
        // watermark still advances to dateTo after a successful discovery, so a backlog older
        // than 1 month is caught up incrementally over multiple "Провери за нови фактури" clicks
        // rather than in one call.
        $dateTo = now()->timezone('Europe/Skopje')->toDateString();
        $earliestAllowedFrom = now()->timezone('Europe/Skopje')->subMonth()->toDateString();
        $dateFrom = $company->efaktura_purchase_last_checked_at
            ? $company->efaktura_purchase_last_checked_at->timezone('Europe/Skopje')->toDateString()
            : now()->startOfYear()->toDateString();
        if ($dateFrom < $earliestAllowedFrom) {
            $dateFrom = $earliestAllowedFrom;
        }

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-ids:{$token}", [
            'company_id' => $company->id,
            'signing_input' => $result['signingInput'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function ids(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-ids:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoiceIds($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Response shape ("euids" array of strings) is a best guess — not yet confirmed live.
        // If Task 12 finds a different shape, this is the only place that needs to change.
        $allEuids = $response->json('euids', []);
        $knownEuids = IncomingEfakturaDocument::where('company_id', $company->id)->whereIn('euid', $allEuids)->pluck('euid')->all();
        $newEuids = array_values(array_diff($allEuids, $knownEuids));

        return response()->json(['newEuids' => $newEuids, 'dateFrom' => $cached['date_from'], 'dateTo' => $cached['date_to']]);
    }

    public function payloadSigningInput(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate([
            'certificateBase64' => 'required|string',
            'euids' => 'required|array|min:1',
            'euids.*' => 'string',
        ]);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euids' => $validated['euids'],
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-payload:{$token}", [
            'company_id' => $company->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function payload(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-payload:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoicePayloadList($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Response shape ("documents" array of {euid, document}) is a best guess — not yet
        // confirmed live. If Task 12 finds a different shape, this is the only place that needs
        // to change.
        $items = $response->json('documents', []);
        $created = 0;

        foreach ($items as $item) {
            $euid = $item['euid'] ?? null;
            $document = $item['document'] ?? null;
            if (! $euid || ! $document || IncomingEfakturaDocument::where('company_id', $company->id)->where('euid', $euid)->exists()) {
                continue;
            }

            $header = $document['header'] ?? [];
            $seller = $document['seller'] ?? [];
            $totals = $document['docTotals'] ?? [];

            IncomingEfakturaDocument::create([
                'company_id' => $company->id,
                'euid' => $euid,
                'doc_number' => $header['docNumber'] ?? null,
                'doc_date' => $header['docDate'] ?? null,
                'seller_name' => $seller['sellerName'] ?? null,
                'seller_tax_id' => $seller['sellerTin'] ?? null,
                'total_amount' => $totals['docGrossAmount'] ?? null,
                'payload_json' => ['document' => $document],
                'discovered_at' => now(),
            ]);
            $created++;
        }

        return response()->json(['status' => 'discovered', 'created' => $created]);
    }

    public function statusSigningInput(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate([
            'certificateBase64' => 'required|string',
            'dateFrom' => 'required|date',
            'dateTo' => 'required|date',
        ]);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'dateFrom' => $validated['dateFrom'],
            'dateTo' => $validated['dateTo'],
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-status:{$token}", [
            'company_id' => $company->id,
            'signing_input' => $result['signingInput'],
            'date_to' => $validated['dateTo'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function status(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-status:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoiceStatus($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Response shape ("invoices" array of {euid, statusCode, statusName}), mirroring the
        // already-verified sales-invoice status-refresh shape, is a best guess for this
        // purchase-invoice endpoint — not yet confirmed live.
        $items = $response->json('invoices', []);
        $updated = 0;

        foreach ($items as $item) {
            $euid = $item['euid'] ?? null;
            if (! $euid) {
                continue;
            }

            $document = IncomingEfakturaDocument::where('company_id', $company->id)->where('euid', $euid)->first();
            if (! $document) {
                continue;
            }

            $document->update([
                'status_code' => $item['statusCode'] ?? null,
                'status_name' => $item['statusName'] ?? null,
            ]);
            $updated++;
        }

        $company->update(['efaktura_purchase_last_checked_at' => $cached['date_to']]);

        return response()->json(['status' => 'refreshed', 'updated' => $updated]);
    }

    private function authorizeDiscovery(Company $company): void
    {
        Gate::authorize('view', $company);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Откривање влезни е-фактури преку фирмениот сертификат сè уште не е поддржано.'
        );
    }
}
