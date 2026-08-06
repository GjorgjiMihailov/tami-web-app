<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EfakturaPdfController extends Controller
{
    public function signingInput(Request $request, Company $company, SalesInvoice $salesInvoice, EfakturaJwsService $jwsService)
    {
        $this->authorizePdf($company, $salesInvoice);

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euid' => $salesInvoice->efaktura_doc_id,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-pdf:{$token}", [
            'company_id' => $company->id,
            'sales_invoice_id' => $salesInvoice->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function store(Request $request, Company $company, SalesInvoice $salesInvoice, EfakturaJwsService $jwsService)
    {
        $this->authorizePdf($company, $salesInvoice);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-pdf:{$validated['token']}");

        if (! $cached || $cached['company_id'] !== $company->id || $cached['sales_invoice_id'] !== $salesInvoice->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPdfFetch($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json([
                'error' => 'ujp_unreachable',
                'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.',
            ], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Response key name ("pdfBase64") is a best guess — not yet confirmed live. If Task 8
        // finds a different key, this is the only place that needs to change.
        $pdfBase64 = $response->json('pdfBase64');
        if (! $pdfBase64) {
            return response()->json(['error' => 'ujp_response_missing_pdf', 'body' => $response->body()], 422);
        }

        $path = "efaktura-pdfs/{$company->id}/{$salesInvoice->id}.pdf";
        Storage::disk('local')->put($path, base64_decode($pdfBase64));
        $salesInvoice->update(['efaktura_pdf_path' => $path]);

        return response()->json(['status' => 'saved']);
    }

    public function download(Company $company, SalesInvoice $salesInvoice)
    {
        Gate::authorize('view', $salesInvoice);
        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_unless($salesInvoice->efaktura_pdf_path && Storage::disk('local')->exists($salesInvoice->efaktura_pdf_path), 404);

        $filename = "faktura-{$salesInvoice->fiscal_year}-{$salesInvoice->invoice_number}.pdf";

        return Storage::disk('local')->download($salesInvoice->efaktura_pdf_path, $filename);
    }

    private function authorizePdf(Company $company, SalesInvoice $salesInvoice): void
    {
        Gate::authorize('view', $salesInvoice);
        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Преземање на официјален ПДФ преку фирмениот сертификат сè уште не е поддржано.'
        );
        abort_if($salesInvoice->efaktura_pdf_path && Storage::disk('local')->exists($salesInvoice->efaktura_pdf_path), 422, 'ПДФ-от е веќе преземен.');
        abort_unless($salesInvoice->isEfakturaAccepted(), 422, 'Фактурата сè уште не е прифатена кај УЈП.');
    }
}
