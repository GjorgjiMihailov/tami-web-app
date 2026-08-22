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
    /**
     * Знаци што Windows не ги дозволува во име на датотека — корисникот ги
     * зачувува овие извози во C:\MyGPM\MPIN_XML\..., па име со кое било од
     * нив би паднало таму тивко (без грешка тука, но и без успешно зачувано
     * име). Symfony само фрла исклучок за / и \; остатокот го проверуваме
     * самите за истата причина.
     */
    private const WINDOWS_ILLEGAL_CHARACTERS = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];

    public function __invoke(Company $company, PayrollRun $run): Response
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $result = MpinValidator::check($run);

        if (! $result->passes()) {
            // Грешките се прикажуваат на самиот екран на пресметката, не тука:
            // ова е симнување, а симнувањето не може да рендерира порака.
            // Ништо не се пренесува во flash — PayrollRunShow сам го врти
            // MpinValidator при секое рендерирање и веќе ги прикажува истите
            // грешки. Пренесена копија би била втор извор на вистина што ниту
            // еден поглед не го чита и што застарува штом нешто се измени.
            return back();
        }

        // Прво датотеката, па печатот. Обратниот редослед запишуваше извоз што
        // не се случил ако градењето падне — а токму падот е моментот кога
        // сметководителот најмногу треба да види дека извоз НЕМА.
        $xml = MpinDocumentBuilder::build($run);

        $run->forceFill([
            'mpin_exported_at' => now(),
            'mpin_exported_by' => auth()->id(),
        ])->save();

        // Името на фирмата е кориснички внесен текст (само required|string|max:255,
        // без ограничување на азбука или знаци) и не смее да оди сурово во
        // Content-Disposition: наводник во името би го скршил заглавието, а
        // кирилицата во гол filename= не е дозволена со RFC 6266. Затоа
        // HeaderUtils::makeDisposition — таа го дава точното име преку
        // filename*=UTF-8''... и ASCII резерва преку filename=. Резервата не
        // смее да доаѓа од името на фирмата (транслитерацијата би била
        // погрешна); наместо тоа е составена само од сигурни податоци.
        //
        // MpinDocumentBuilder::fileName() си останува непроменето — тоа е
        // вистинското име и Task 11 (или некој друг повикувач) можеби сака
        // токму него. Чистењето се случува само тука, на самата граница
        // каде името оди во HTTP заглавие: / и \ HeaderUtils директно ги
        // одбива (фрла исклучок — 500 при секој извоз за фирма со „/“ во
        // името, на пр. „Импорт/Експорт“, а тоа не е измислен случај за
        // македонски фирми со комбинирана дејност); : * ? " < > | Symfony ги
        // прифаќа без исклучок, но Windows ги одбива при зачувување на
        // датотеката во C:\MyGPM\MPIN_XML\..., што би било тивок неуспех.
        // Затоа целото множество се третира исто: заменето со цртичка, знак
        // дозволен насекаде и што не се чита како печатна грешка.
        $filename = str_replace(
            self::WINDOWS_ILLEGAL_CHARACTERS,
            '-',
            MpinDocumentBuilder::fileName($run),
        );
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
