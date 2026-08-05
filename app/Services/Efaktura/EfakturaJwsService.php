<?php

namespace App\Services\Efaktura;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Support\Base64Url;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $payload = $this->documentBuilder->build($invoice);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $header = ['alg' => 'RS256', 'x5c' => [$certificateBase64Der]];
        $headerJson = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signingInput = Base64Url::encode($headerJson).'.'.Base64Url::encode($payloadJson);

        return ['signingInput' => $signingInput, 'payloadJson' => $payloadJson];
    }

    public function send(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        $compact = $signingInput.'.'.$signatureBase64Url;
        $baseUrl = config('services.efaktura.base_url');
        $url = rtrim($baseUrl, '/').'/JSONReceiver/api/v1/sales-invoices/send';

        $request = Http::withHeaders([
            'X-EUJP-ID' => $company->efaktura_eujp_id,
            'X-EDB' => $company->tax_id,
            'X-SERIAL-NUMBER' => $company->efaktura_token_serial_number,
            'X-DOC-TYPE-CODE' => '100',
        ])->timeout(20);

        if ($connectTo = config('services.efaktura.connect_to')) {
            $request = $request->withOptions(['curl' => [CURLOPT_CONNECT_TO => [$connectTo]]]);
        }

        $requestTimestamp = now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s');
        [$headerPart, $payloadPart] = explode('.', $signingInput);

        Log::info('EFAKTURA_DEBUG_TASK18 request', [
            'url' => $url,
            'headers' => [
                'X-EUJP-ID' => $company->efaktura_eujp_id,
                'X-EDB' => $company->tax_id,
                'X-SERIAL-NUMBER' => $company->efaktura_token_serial_number,
                'X-DOC-TYPE-CODE' => '100',
            ],
            'requestTimestamp' => $requestTimestamp,
            'jwsHeader' => Base64Url::decode($headerPart),
            'jwsPayload' => Base64Url::decode($payloadPart),
            'signatureLength' => strlen($signatureBase64Url),
        ]);

        $response = $request->post($url, [
            'requestTimestamp' => $requestTimestamp,
            'jws' => $compact,
        ]);

        Log::info('EFAKTURA_DEBUG_TASK18 response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return $response;
    }
}
