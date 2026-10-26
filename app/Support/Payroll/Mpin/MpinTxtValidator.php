<?php

namespace App\Support\Payroll\Mpin;

use App\Models\PayrollRun;

/**
 * Проверките за .txt: сите од XML-от (истите блокади, вклучително минат труд и
 * надоместоци) плус податоците за фирмата што XML-от ги нема — .txt во редот
 * со фирмата носи матичен број, адреса, општина и овластено лице.
 */
final class MpinTxtValidator
{
    public static function check(PayrollRun $run): MpinValidationResult
    {
        $base = MpinValidator::check($run);

        if ($base->errors !== []) {
            return $base;
        }

        $company = $run->company;
        $errors = [];

        $required = [
            'registration_number' => 'матичен број',
            'city' => 'град',
            'payroll_municipality_code' => 'шифра на општина (во податоците за плата)',
            'payroll_authorized_person' => 'овластено лице (во податоците за плата)',
        ];

        $missing = [];

        foreach ($required as $field => $label) {
            if (blank($company->$field)) {
                $missing[] = $label;
            }
        }

        if (blank($company->street_address) && blank($company->address)) {
            $missing[] = 'адреса';
        }

        if ($missing !== []) {
            $errors[] = 'Во профилот на фирмата недостасува: '.implode(', ', $missing).'. .txt датотеката ги бара.';
        }

        foreach (MpinTxtBuilder::unsafeText($run) as $problem) {
            $errors[] = 'Текстот не може да се запише во .txt: '.$problem.'.';
        }

        return new MpinValidationResult($errors, $base->warnings);
    }
}
