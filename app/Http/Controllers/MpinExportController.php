<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Support\Payroll\Mpin\MpinDocumentBuilder;
use App\Support\Payroll\Mpin\MpinValidator;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class MpinExportController extends Controller
{
    public function __invoke(Company $company, PayrollRun $run): Response
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $result = MpinValidator::check($run);

        if (! $result->passes()) {
            // Грешките се прикажуваат на самиот екран на пресметката, не тука:
            // ова е симнување, а симнувањето не може да рендерира порака.
            return back()->with('mpin_errors', $result->errors);
        }

        $run->forceFill([
            'mpin_exported_at' => now(),
            'mpin_exported_by' => auth()->id(),
        ])->save();

        $xml = MpinDocumentBuilder::build($run);

        // Името на фирмата е кориснички внесен текст (само required|string|max:255,
        // без ограничување на азбука или знаци) и не смее да оди сурово во
        // Content-Disposition: наводник во името би го скршил заглавието, а
        // кирилицата во гол filename= не е дозволена со RFC 6266. Затоа
        // HeaderUtils::makeDisposition — таа го дава точното име преку
        // filename*=UTF-8''... и ASCII резерва преку filename=. Резервата не
        // смее да доаѓа од името на фирмата (транслитерацијата би била
        // погрешна); наместо тоа е составена само од сигурни податоци.
        $filename = MpinDocumentBuilder::fileName($run);
        $fallback = sprintf('mpin-%d-%02d-101.xml', $run->year, $run->month);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                $fallback,
            ),
        ]);
    }
}
