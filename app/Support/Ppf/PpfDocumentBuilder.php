<?php

namespace App\Support\Ppf;

use App\Models\Company;
use App\Models\PpfCalculation;
use App\Models\PpfPayment;
use App\Support\Bank\AccountNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Гради ujp_ppf_acc_calc XML за една пресметка, во обликот на вистинските
 * поднесени фајлови од MyGPM: декларација без encoding, табови, CRLF, празни
 * ознаки `contact` (и `pca_bank_account` кога нема сметка).
 *
 * Редоследот на заглавието е „sequence“ во XSD; редовите се „all“, па
 * редоследот таму е како во вистинските фајлови.
 */
final class PpfDocumentBuilder
{
    private const EOL = "\r\n";

    /**
     * @param  Collection<int, PpfPayment>  $payments  исплати на пресметката, со вчитан payee
     * @param  CarbonInterface  $period  датум на пресметката (periodfrom = periodto)
     */
    public static function build(Company $company, Collection $payments, CarbonInterface $period): string
    {
        $payments = $payments->sortBy('id')->values();
        $date = self::date($period);

        $out = ['<?xml version="1.0" standalone="yes"?>', '<ujp_ppf_acc_calc>'];
        $out[] = "\t<ppfTaxpayer>";
        $out[] = "\t\t".self::tag('edb', (string) $company->tax_id);
        $out[] = "\t</ppfTaxpayer>";
        $out[] = "\t".self::tag('name', $company->name);
        $out[] = "\t".self::tag('pca_bank_account', self::companyAccount($company));
        $out[] = "\t".self::tag('periodfrom', $date);
        $out[] = "\t".self::tag('periodto', $date);
        $out[] = "\t".self::tag('contact', '');
        $out[] = "\t".self::tag('mail', (string) $company->email);

        foreach ($payments as $position => $payment) {
            array_push($out, ...self::detail($position + 1, $payment));
        }

        $out[] = '</ujp_ppf_acc_calc>';

        return implode(self::EOL, $out).self::EOL;
    }

    /** Исто име како во MyGPM: „Akontativna presmetka br.N.xml“. */
    public static function fileName(PpfCalculation $calculation): string
    {
        return 'Akontativna presmetka br.'.$calculation->number.'.xml';
    }

    /** @return list<string> */
    private static function detail(int $rowId, PpfPayment $payment): array
    {
        $payee = $payment->payee;

        $rows = [
            "\t<calculation_detail>",
            "\t\t".self::tag('row_id', (string) $rowId),
            "\t\t".self::tag('embg', $payee->embg),
            "\t\t".self::tag('name', $payee->name),
            "\t\t".self::tag('surname', $payee->surname),
            "\t\t<parMunicipality>",
            "\t\t\t".self::tag('municipality_code', $payee->municipality_code),
            "\t\t</parMunicipality>",
            "\t\t<type_income>",
            "\t\t\t".self::tag('code', $payment->type_income_code),
            "\t\t</type_income>",
            "\t\t<subtype_income>",
            "\t\t\t".self::tag('code', (string) $payment->subtype_income_code),
            "\t\t</subtype_income>",
            "\t\t".self::tag('grossIncome', (string) $payment->gross_income),
        ];

        foreach (['avans' => $payment->advance, 'ddv_iznos' => $payment->vat_amount] as $name => $value) {
            if ($value !== null) {
                $rows[] = "\t\t".self::tag($name, (string) $value);
            }
        }

        array_push($rows,
            "\t\t<type_payment>",
            "\t\t\t".self::tag('ptpCode', $payment->payment_type_code),
            "\t\t</type_payment>",
        );

        $account = AccountNumber::key($payee->bank_account);

        if ($account !== '') {
            $rows[] = "\t\t".self::tag('bankAccount', $account);
        }

        $rows[] = "\t\t".self::tag('paymentdate', self::date($payment->payment_date));

        if (filled($payment->decision_number)) {
            $rows[] = "\t\t".self::tag('brojResenieDo', $payment->decision_number);
        }

        if ($payment->decision_date !== null) {
            $rows[] = "\t\t".self::tag('datumResenieDo', self::date($payment->decision_date));
        }

        if (filled($payment->comment)) {
            $rows[] = "\t\t".self::tag('commentDoc', mb_substr($payment->comment, 0, 255));
        }

        if ($payment->gross_free !== null) {
            $rows[] = "\t\t".self::tag('grossFree', (string) $payment->gross_free);
        }

        $rows[] = "\t</calculation_detail>";

        return $rows;
    }

    private static function companyAccount(Company $company): string
    {
        foreach ($company->bankAccounts as $account) {
            $key = AccountNumber::key($account->account_number);

            if ($key !== '') {
                return $key;
            }
        }

        return '';
    }

    private static function date(CarbonInterface $date): string
    {
        return $date->format('d.m.Y');
    }

    private static function tag(string $name, string $value): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $value));

        return "<$name>".htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</$name>";
    }
}
