<?php

namespace App\Support\Ppf;

use App\Models\Company;
use App\Models\PayrollCode;
use App\Models\PpfPayment;
use App\Support\Bank\AccountNumber;
use App\Support\Embg;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DOMDocument;
use Illuminate\Support\Collection;

/**
 * Проверки пред симнување: правилата од шифрарниците и XSD на УЈП
 * (resources/ujp/ppf_acc_calc.xsd). Грешка блокира, предупредување само се покажува.
 */
final class PpfValidator
{
    /** XSD: датумите на периодот мора да се >= 01.01.2018. */
    private const EARLIEST = '2018-01-01';

    /**
     * @param  Collection<int, PpfPayment>  $payments  исплати од ист датум, со вчитан payee
     */
    public static function check(Company $company, Collection $payments, ?CarbonInterface $period = null): PpfValidationResult
    {
        $company->loadMissing('bankAccounts');
        $errors = [];
        $warnings = [];

        if (! $company->tax_id) {
            $errors[] = 'Фирмата нема внесен ЕДБ.';
        }

        if (blank($company->email)) {
            $errors[] = 'Фирмата нема е-пошта — УЈП ја бара во заглавието на фајлот.';
        }

        if ($payments->isEmpty()) {
            $errors[] = 'Нема ниту една исплата за овој датум.';

            return new PpfValidationResult($errors, $warnings);
        }

        if ($payments->pluck('payment_date')->map(fn ($d) => $d->toDateString())->unique()->count() > 1) {
            $errors[] = 'Исплатите во еден фајл мора да бидат од ист датум.';
        }

        if ($period !== null) {
            if ($period->lt(Carbon::parse(self::EARLIEST))) {
                $errors[] = 'УЈП не прима датум на пресметка пред 01.01.2018.';
            } elseif ($period->lt($payments->min('payment_date'))) {
                $warnings[] = 'Датумот на пресметката е пред датумот на исплата — проверете дали е намерно.';
            }
        }

        $hasCompanyAccount = $company->bankAccounts->contains(fn ($a) => AccountNumber::key($a->account_number) !== '');

        if (! $hasCompanyAccount) {
            $warnings[] = 'Фирмата нема трансакциска сметка во профилот — полето за сметка на исплатувачот ќе остане празно.';
        }

        $municipalities = PayrollCode::where('type', 'opstina')->where('code', '!=', '999')->pluck('code')->all();

        foreach ($payments as $payment) {
            array_push($errors, ...self::checkPayment($company, $payment, $municipalities));
        }

        if ($errors === []) {
            array_push($errors, ...self::schemaErrors(PpfDocumentBuilder::build($company, $payments, $period)));
        }

        return new PpfValidationResult($errors, $warnings);
    }

    /**
     * @param  list<string>  $municipalities
     * @return list<string>
     */
    private static function checkPayment(Company $company, PpfPayment $payment, array $municipalities): array
    {
        $payee = $payment->payee;
        $who = 'Исплата на '.($payee?->fullName() ?: '(без примател)').': ';
        $errors = [];

        if ($payee === null || $payment->company_id !== $company->id || $payee->company_id !== $company->id) {
            return [$who.'исплатата и примателот не припаѓаат на оваа фирма.'];
        }

        if (blank($payee->name) || blank($payee->surname)) {
            $errors[] = $who.'примателот мора да има име и презиме.';
        }

        if (! Embg::isValid((string) $payee->embg)) {
            $errors[] = $who.'ЕМБГ не е валиден.';
        }

        if (! in_array($payee->municipality_code, $municipalities, true)) {
            $errors[] = $who.'општината не е од шифрарникот на УЈП.';
        }

        $date = Carbon::parse($payment->payment_date);

        if ($date->lt(Carbon::parse(self::EARLIEST))) {
            $errors[] = $who.'УЈП не прима датум пред 01.01.2018.';
        }

        $type = $payment->type_income_code;
        $subtype = (string) $payment->subtype_income_code;

        if (! PpfCodes::typeExists($type)) {
            $errors[] = $who."видот на приход „{$type}“ не постои во шифрарникот.";
        } elseif (! PpfCodes::typeValidOn($type, $date)) {
            $errors[] = $who."видот „{$type}“ не важи за исплата на {$date->format('d.m.Y')}.";
        } elseif (! PpfCodes::typeHasSubtypes($type)) {
            $errors[] = $who."видот „{$type}“ нема подвид во шифрарникот на УЈП, а XSD бара подвид — фајлот не може да се направи.";
        } elseif ($subtype === '') {
            $errors[] = $who.'изберете подвид на приход.';
        } elseif (! PpfCodes::subtypeBelongsTo($type, $subtype)) {
            $errors[] = $who."подвидот „{$subtype}“ не припаѓа на видот „{$type}“.";
        }

        if ((int) $payment->gross_income <= 0) {
            $errors[] = $who.'бруто износот мора да биде поголем од нула.';
        }

        if (! PpfCodes::paymentTypeExists($payment->payment_type_code)) {
            $errors[] = $who.'начинот на плаќање не е од шифрарникот.';
        } elseif ($payment->payment_type_code === '00001' && AccountNumber::key($payee->bank_account) === '') {
            $errors[] = $who.'за плаќање на трансакциска сметка е потребна сметката на примателот.';
        }

        return $errors;
    }

    /** @return list<string> */
    private static function schemaErrors(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument;
        $loaded = $document->loadXML($xml);
        $valid = $loaded && $document->schemaValidate(resource_path('ujp/ppf_acc_calc.xsd'));
        $messages = array_map(fn ($e) => trim($e->message), libxml_get_errors());

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $valid ? [] : array_map(fn (string $m) => 'Датотеката не ја поминува XSD проверката на УЈП: '.$m, array_slice($messages ?: ['непозната грешка'], 0, 3));
    }
}
