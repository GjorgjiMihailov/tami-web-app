<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * Throwaway spike for Phase 8b — not part of the shipped feature.
 * Confirms whether UJP's efakturatest environment accepts our JWS construction
 * (RS256 + x5c) before writing-plans commits to a concrete implementation.
 * Remove once the real send flow (SalesInvoiceShow button) is built and verified.
 */
class EfakturaSpikeSend extends Command
{
    protected $signature = 'efaktura:spike-send {company : ID на компанијата чиј сертификат се користи}';

    protected $description = 'Спајк: потпишува и испраќа еден статички тест-JSON кон efakturatest.ujp.gov.mk';

    public function handle(): int
    {
        $company = Company::find($this->argument('company'));

        if (! $company) {
            $this->error('Компанијата не постои.');

            return self::FAILURE;
        }

        if ($company->efaktura_credential_mode !== Company::EFAKTURA_MODE_OWN || ! $company->hasEfakturaAccess()) {
            $this->error('Компанијата нема поставено сопствен е-Фактура сертификат (Company Profile → Уреди).');

            return self::FAILURE;
        }

        $certPath = Storage::disk('local')->path($company->efaktura_certificate_path);
        $password = (string) $company->efaktura_certificate_password;

        $pkcs12Contents = file_get_contents($certPath);
        if (! openssl_pkcs12_read($pkcs12Contents, $certs, $password)) {
            $this->error('Сертификатот не може да се отвори со зачуваната лозинка.');

            return self::FAILURE;
        }

        $certInfo = openssl_x509_parse($certs['cert']);
        $serialNumberHex = $certInfo['serialNumberHex'] ?? null;
        $serialNumberDec = $certInfo['serialNumber'] ?? null;

        $this->info('Сериски број на сертификат (hex): '.$serialNumberHex);
        $this->info('Сериски број на сертификат (dec): '.$serialNumberDec);

        // x5c per RFC 7515 §4.1.6 is standard base64 (not base64url) of the DER cert bytes.
        $pemBody = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certs['cert']);
        $derBase64 = base64_encode(base64_decode($pemBody));

        $jwk = JWKFactory::createFromPKCS12CertificateFile($certPath, $password, [
            'use' => 'sig',
            'alg' => 'RS256',
        ]);

        $payload = $this->buildTestPayload($company);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $algorithmManager = new AlgorithmManager([new RS256]);
        $jwsBuilder = new JWSBuilder($algorithmManager);

        $jws = $jwsBuilder
            ->create()
            ->withPayload($jsonPayload)
            ->addSignature($jwk, [
                'alg' => 'RS256',
                'x5c' => [$derBase64],
            ])
            ->build();

        $compact = (new CompactSerializer)->serialize($jws, 0);

        $this->info('--- JWS (compact, прв 80 карактери) ---');
        $this->line(substr($compact, 0, 80).'...');

        $baseUrl = config('services.efaktura.base_url');
        $url = rtrim($baseUrl, '/').'/JSONReceiver/api/v1/sales-invoices/send';

        // Try hex serial first — if UJP rejects with an auth/cert error, the
        // next iteration should try $serialNumberDec or a different header format.
        $headers = [
            'X-EUJP-ID' => $company->efaktura_eujp_id,
            'X-EDB' => $company->tax_id,
            'X-SERIAL-NUMBER' => $serialNumberHex,
        ];

        $this->info('--- Барање ---');
        $this->line('POST '.$url);
        $this->line('Headers: '.json_encode($headers, JSON_UNESCAPED_UNICODE));
        $this->line('Payload (пред потпишување): '.$jsonPayload);

        $response = Http::withHeaders($headers)
            ->withBody($compact, 'application/jose')
            ->post($url);

        $this->info('--- Одговор од УЈП ---');
        $this->line('Status: '.$response->status());
        $this->line('Body: '.$response->body());

        return self::SUCCESS;
    }

    private function buildTestPayload(Company $company): array
    {
        $docNumber = 'SPIKE-'.Str::uuid()->toString();
        $today = now()->toDateString();

        return [
            'requestTimestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'document' => [
                'header' => [
                    'docStorno' => 0,
                    'docType' => '100',
                    'docTypeName' => 'Фактура',
                    'docDate' => $today,
                    'docTurnoverDate' => $today,
                    'docNumber' => $docNumber,
                    'docId' => $docNumber,
                    'docNotes' => null,
                    'docHeader' => null,
                    'docFooter' => null,
                ],
                'seller' => [
                    'sellerCCode' => 'MK',
                    'sellerCName' => 'Северна Македонија',
                    'sellerTin' => $company->tax_id,
                    'sellerForeignTin' => null,
                    'sellerVatNumber' => 'МК'.$company->tax_id,
                    'sellerName' => $company->name,
                    'sellerAddress' => [
                        'streetAddress' => $company->street_address ?? '',
                        'streetNumber' => $company->street_number ?? '',
                        'postalCode' => $company->postal_code ?? '',
                        'city' => $company->city ?? '',
                    ],
                    'sellerContact' => null,
                    'sellerEmail' => null,
                ],
                'buyer' => [
                    'buyerCCode' => 'MK',
                    'buyerCName' => 'Северна Македонија',
                    'buyerTin' => $company->tax_id,
                    'buyerForeignTin' => null,
                    'buyerVatNumber' => 'МК'.$company->tax_id,
                    'buyerName' => $company->name,
                    'buyerAddress' => [
                        'streetAddress' => $company->street_address ?? '',
                        'streetNumber' => $company->street_number ?? '',
                        'postalCode' => $company->postal_code ?? '',
                        'city' => $company->city ?? '',
                    ],
                    'buyerContact' => null,
                    'buyerEmail' => null,
                ],
                'docPayment' => [
                    'docPaymentTypeCode' => 'P12',
                    'docPaymentTypeDesc' => 'Плаќање преку банка',
                    'docPaymentTypeDueDays' => null,
                    'docPaymentTypeDueDate' => null,
                    'docPaymentTerms' => null,
                    'docPaymentNote' => null,
                    'docPaymentInterest' => null,
                    'docPaymentDiscount' => null,
                    'docCurrency' => 'MKD',
                    'docCurrencyCode' => 'MKD',
                    'docCurrencyDate' => $today,
                    'docCurrencyExchRate' => 1,
                ],
                'docItems' => [
                    [
                        'docItemLineNo' => 1,
                        'docItemSku' => 'SPIKE-1',
                        'docItemSenderCode' => 'SPIKE-1',
                        'docItemReceiverCode' => null,
                        'docItemDesc' => 'Спајк тест-ставка',
                        'docItemMUnit' => 'бр.',
                        'docItemQty' => 1,
                        'docItemUnitOriginalPriceWoVat' => 100,
                        'docItemUnitDiscountAmount' => 0,
                        'docItemUnitPriceWoVat' => 100,
                        'docItemUnitVat' => 18,
                        'docItemVat' => 18,
                        'docItemVatGroup' => 'DDV-A',
                        'docItemTotalOriginalPriceWoVat' => 100,
                        'docItemTotalPriceWoVat' => 100,
                        'docItemTotalVat' => 18,
                        'docItemTotalPriceWVat' => 118,
                        'docItemTaxIndicator' => 'DDV-A',
                        'docItemDomesticProduct' => null,
                    ],
                ],
                'docTotals' => [
                    'docNetAmount' => 100,
                    'docDiscountAmount' => 0,
                    'docNetAmountDisc' => 100,
                    'docVatAmount' => 18,
                    'docGrossAmount' => 118,
                    'docGrossAmountR' => 118,
                    'docAvansAmount' => 0,
                    'docFinalAmount' => 118,
                ],
                'vatTotals' => [
                    [
                        'vatTaxIndicator' => 'DDV-A',
                        'vatTaxIndicatorNote' => '',
                        'vatCode' => 'DDV-A',
                        'vatPercent' => 18,
                        'vatTaxableAmount' => 100,
                        'vatAmount' => 18,
                        'vatTotalAmount' => 118,
                    ],
                ],
            ],
        ];
    }
}
