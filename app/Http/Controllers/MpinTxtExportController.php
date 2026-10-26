<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Support\Payroll\Mpin\MpinTxtBuilder;
use App\Support\Payroll\Mpin\MpinTxtValidator;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * МПИН во стариот .txt формат (Windows-1251) — се шифрира во стариот софтвер
 * и се поднесува како порано. Паралелен на XML извозот, исти правила.
 */
class MpinTxtExportController extends Controller
{
    public function __invoke(Company $company, PayrollRun $run): Response
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        if (! MpinTxtValidator::check($run)->passes()) {
            // Грешките ги прикажува самиот екран на пресметката (исто како кај XML).
            return back();
        }

        // Прво датотеката, па печатот — ист редослед како кај XML извозот.
        $bytes = MpinTxtBuilder::build($run);

        $run->forceFill([
            'mpin_exported_at' => now(),
            'mpin_exported_by' => auth()->id(),
        ])->save();

        // Името е составено од ЕДБ, година и месец — само цифри, па нема
        // знаци што Windows или HTTP заглавието ги одбиваат.
        $filename = MpinTxtBuilder::fileName($run);

        return response($bytes, 200, [
            'Content-Type' => 'text/plain; charset=windows-1251',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                $filename,
            ),
        ]);
    }
}
