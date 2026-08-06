<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EfakturaStatusController extends Controller
{
    public function signingInput(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeRefresh($company);

        $earliestPending = $this->earliestPendingInvoice($company);

        if (! $earliestPending) {
            return response()->json(['error' => 'nothing_pending', 'message' => 'Нема чекачки фактури за освежување статус.'], 422);
        }

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'dateFrom' => $earliestPending->efaktura_sent_at->timezone('Europe/Skopje')->toDateString(),
            'dateTo' => now()->timezone('Europe/Skopje')->toDateString(),
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-status-refresh:{$token}", [
            'company_id' => $company->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function refresh(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeRefresh($company);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-status-refresh:{$validated['token']}");

        if (! $cached || $cached['company_id'] !== $company->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendStatusRefresh($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json([
                'error' => 'ujp_unreachable',
                'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.',
            ], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        \Illuminate\Support\Facades\Log::info('EFAKTURA_DEBUG_STATUS_REFRESH', [
            'company_id' => $company->id,
            'raw_body' => $response->body(),
            'known_efaktura_doc_ids' => SalesInvoice::where('company_id', $company->id)->whereNotNull('efaktura_doc_id')->pluck('efaktura_doc_id', 'id'),
        ]);

        // Response shape ("invoices" array of {euid, statusCode, statusName}) is a best guess —
        // not yet confirmed live (see Global Constraints). If Task 8 finds a different shape,
        // this is the only place that needs to change.
        $items = $response->json('invoices', []);
        $updated = 0;

        foreach ($items as $item) {
            $euid = $item['euid'] ?? null;
            if (! $euid) {
                continue;
            }

            $invoice = SalesInvoice::where('company_id', $company->id)->where('efaktura_doc_id', $euid)->first();
            if (! $invoice) {
                continue;
            }

            $invoice->update([
                'efaktura_ujp_status_code' => $item['statusCode'] ?? null,
                'efaktura_ujp_status_name' => $item['statusName'] ?? null,
            ]);
            $updated++;
        }

        return response()->json(['status' => 'refreshed', 'updated' => $updated]);
    }

    private function earliestPendingInvoice(Company $company): ?SalesInvoice
    {
        return SalesInvoice::where('company_id', $company->id)
            ->where('efaktura_status', 'sent')
            // efaktura_sent_at is nullable; NULL sorts first in the orderBy() below in both
            // SQLite and MySQL, which would permanently pin a never-sent row as "earliest
            // pending" and fatal on ->timezone() in signingInput(). Exclude NULLs explicitly.
            ->whereNotNull('efaktura_sent_at')
            // whereNotIn() treats a NULL column as excluded (SQL: "NULL NOT IN (...)" is NULL,
            // not true) — an invoice never checked before (status_code still null) must count
            // as pending too, so it needs an explicit whereNull() branch alongside whereNotIn().
            ->where(function ($query) {
                $query->whereNull('efaktura_ujp_status_code')
                    ->orWhereNotIn('efaktura_ujp_status_code', SalesInvoice::EFAKTURA_ACCEPTED_STATUS_CODES);
            })
            ->orderBy('efaktura_sent_at')
            ->first();
    }

    private function authorizeRefresh(Company $company): void
    {
        Gate::authorize('view', $company);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Освежување статус преку фирмениот сертификат сè уште не е поддржано.'
        );
    }
}
