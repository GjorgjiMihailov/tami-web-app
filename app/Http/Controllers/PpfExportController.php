<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PpfPayment;
use App\Support\Ppf\PpfDocumentBuilder;
use App\Support\Ppf\PpfValidator;
use App\Support\Ppf\StrictDate;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/** ППФ XML за еден датум на исплата. Само канцеларијата (рутата е зад EnsureAccountingAccess). */
class PpfExportController extends Controller
{
    /** Знаци што Windows не ги дозволува во име на датотека (истото како кај МПИН извозот). */
    private const WINDOWS_ILLEGAL_CHARACTERS = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];

    public function __invoke(Company $company, string $date): Response
    {
        Gate::authorize('view', $company);

        $day = StrictDate::parse($date);
        abort_if($day === null, 404);

        $payments = PpfPayment::where('company_id', $company->id)
            ->whereDate('payment_date', $date)
            ->with('payee')
            ->orderBy('id')
            ->get();

        abort_if($payments->isEmpty(), 404);

        // Датумот на пресметката (периодот во фајлот): основно денот на исплатата, но
        // понекогаш се поднесува денови подоцна — го дава екранот како ?period=гггг-мм-дд.
        $period = null;

        if (request()->query('period') !== null) {
            $period = StrictDate::parse((string) request()->query('period'));
            abort_if($period === null, 404);
        }

        // Грешките се прикажуваат на самиот екран; симнувањето не може да рендерира порака.
        if (! PpfValidator::check($company, $payments, $period)->passes()) {
            return back();
        }

        // Прво датотеката, па печатот: пад при градењето не смее да остави запишан извоз што не се случил.
        $xml = PpfDocumentBuilder::build($company->loadMissing('bankAccounts'), $payments, $period);

        PpfPayment::whereKey($payments->modelKeys())->update([
            'exported_at' => now(),
            'exported_by' => auth()->id(),
        ]);

        $filename = str_replace(self::WINDOWS_ILLEGAL_CHARACTERS, '-', PpfDocumentBuilder::fileName($day));

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                'ppf-'.$date.'.xml',
            ),
        ]);
    }
}
