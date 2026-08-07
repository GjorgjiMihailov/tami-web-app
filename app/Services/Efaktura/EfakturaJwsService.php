<?php

namespace App\Services\Efaktura;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Support\Base64Url;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class EfakturaJwsService
{
    public function __construct(private ?EfakturaDocumentBuilder $documentBuilder = null)
    {
        $this->documentBuilder ??= new EfakturaDocumentBuilder;
    }

    /**
     * @return array{signingInput: string, payloadJson: string}
     */
    public function buildSigningInput(SalesInvoice $invoice, string $certificateBase64Der): array
    {
        return $this->buildSigningInputForPayload($this->documentBuilder->build($invoice), $certificateBase64Der);
    }

    /**
     * Same base64url(header) + "." + base64url(payload) assembly as buildSigningInput(),
     * but for the small, non-invoice JSON bodies the status-refresh and PDF-fetch endpoints
     * sign (e.g. {requestTimestamp, dateFrom, dateTo} or {requestTimestamp, euid}).
     *
     * @return array{signingInput: string, payloadJson: string}
     */
    public function buildSigningInputForPayload(array $payload, string $certificateBase64Der): array
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $header = ['alg' => 'RS256', 'x5c' => [$certificateBase64Der]];
        $headerJson = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signingInput = Base64Url::encode($headerJson).'.'.Base64Url::encode($payloadJson);

        return ['signingInput' => $signingInput, 'payloadJson' => $payloadJson];
    }

    // Confirmed live via Task 8 (2026-08-06): unlike /sales-invoices/send, these two document-
    // management endpoints live under /einvoice_api/, not /JSONReceiver/ — confirmed by curl'ing
    // both bases directly against efakturatest.ujp.gov.mk (via the SSH tunnel): /JSONReceiver/...
    // returned a bare 404 (route doesn't exist there), while /einvoice_api/... returned a real
    // "JWS must not be null or empty string" validation error, proving the route exists there and
    // is being processed. The original guess (per the design doc's prose) had both under
    // /JSONReceiver/ purely by analogy with /sales-invoices/send — wrong for this pair.
    private const STATUS_REFRESH_PATH = '/einvoice_api/api/v1/documents/sales-invoice/invoices-status';

    private const PDF_FETCH_PATH = '/einvoice_api/api/v1/documents/sales-invoice/pdf';

    // /einvoice_api/ base confirmed for the whole "Управување со Документи" family. Paths and
    // request/response shapes below were re-confirmed directly against efakturawiki.ujp.gov.mk's
    // full "Управување со Документи" page content (pasted by the user 2026-08-07, Task 12 live
    // testing) — this superseded an earlier guess. Live-testing correction found: "current-status"
    // takes a single euid ({requestTimestamp, euid}) and returns one docStatus object — it is NOT
    // the date-ranged batch endpoint. The batch-by-date-range endpoint (what this app's discovery
    // flow actually needs, mirroring the already-shipped sales-invoice status refresh) is
    // "invoices-status" — same request shape ({requestTimestamp, dateFrom, dateTo}) and same
    // response shape ({invoices: [{euid, statusCode, statusName, ...}]}) this app already parses
    // correctly for sales invoices.
    private const PURCHASE_IDS_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/ids';

    private const PURCHASE_PAYLOAD_LIST_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/payload/list';

    private const PURCHASE_STATUS_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/invoices-status';

    private const PURCHASE_ACCEPT_REJECT_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/accept-reject';

    private const PURCHASE_PDF_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/pdf';

    public function send(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest(
            $company,
            '/JSONReceiver/api/v1/sales-invoices/send',
            $signingInput,
            $signatureBase64Url,
            ['X-DOC-TYPE-CODE' => '100'],
        );
    }

    public function sendStatusRefresh(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::STATUS_REFRESH_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPdfFetch(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PDF_FETCH_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPurchaseInvoiceIds(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_IDS_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPurchaseInvoicePayloadList(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_PAYLOAD_LIST_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPurchaseInvoiceStatus(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_STATUS_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPurchaseInvoiceAcceptReject(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_ACCEPT_REJECT_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPurchaseInvoicePdfFetch(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_PDF_PATH, $signingInput, $signatureBase64Url);
    }

    private function postSignedRequest(Company $company, string $path, string $signingInput, string $signatureBase64Url, array $extraHeaders = []): Response
    {
        $compact = $signingInput.'.'.$signatureBase64Url;
        $baseUrl = config('services.efaktura.base_url');
        $url = rtrim($baseUrl, '/').$path;

        $request = Http::withHeaders(array_merge([
            'X-EUJP-ID' => $company->efaktura_eujp_id,
            'X-EDB' => $company->tax_id,
            'X-SERIAL-NUMBER' => $company->efaktura_token_serial_number,
        ], $extraHeaders))->timeout(20);

        if ($connectTo = config('services.efaktura.connect_to')) {
            $request = $request->withOptions(['curl' => [CURLOPT_CONNECT_TO => [$connectTo]]]);
        }

        return $request->post($url, [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'jws' => $compact,
        ]);
    }
}
