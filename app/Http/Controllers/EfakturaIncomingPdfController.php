<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EfakturaIncomingPdfController extends Controller
{
    public function signingInput(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizePdf($company, $incomingEfakturaDocument);

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euid' => $incomingEfakturaDocument->euid,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-pdf:{$token}", [
            'company_id' => $company->id,
            'document_id' => $incomingEfakturaDocument->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function store(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizePdf($company, $incomingEfakturaDocument);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-pdf:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id || $cached['document_id'] !== $incomingEfakturaDocument->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoicePdfFetch($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        $pdfBase64 = $response->json('pdfBase64');
        if (! $pdfBase64) {
            return response()->json(['error' => 'ujp_response_missing_pdf', 'body' => $response->body()], 422);
        }

        $path = "efaktura-pdfs/incoming/{$company->id}/{$incomingEfakturaDocument->id}.pdf";
        Storage::disk('local')->put($path, base64_decode($pdfBase64));
        $incomingEfakturaDocument->update(['efaktura_pdf_path' => $path]);

        return response()->json(['status' => 'saved']);
    }

    public function download(Company $company, IncomingEfakturaDocument $incomingEfakturaDocument)
    {
        Gate::authorize('view', $company);
        abort_if($incomingEfakturaDocument->company_id !== $company->id, 404);
        abort_unless($incomingEfakturaDocument->efaktura_pdf_path && Storage::disk('local')->exists($incomingEfakturaDocument->efaktura_pdf_path), 404);

        $filename = "vlezna-faktura-{$incomingEfakturaDocument->doc_number}.pdf";

        return Storage::disk('local')->download($incomingEfakturaDocument->efaktura_pdf_path, $filename);
    }

    private function authorizePdf(Company $company, IncomingEfakturaDocument $incomingEfakturaDocument): void
    {
        Gate::authorize('view', $company);
        abort_if($incomingEfakturaDocument->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Преземање официјален ПДФ преку фирмениот сертификат сè уште не е поддржано.'
        );
        abort_if($incomingEfakturaDocument->efaktura_pdf_path && Storage::disk('local')->exists($incomingEfakturaDocument->efaktura_pdf_path), 422, 'ПДФ-от е веќе преземен.');
        abort_unless($incomingEfakturaDocument->decision === IncomingEfakturaDocument::DECISION_ACCEPTED, 422, 'Фактурата сè уште не е прифатена.');
    }
}
