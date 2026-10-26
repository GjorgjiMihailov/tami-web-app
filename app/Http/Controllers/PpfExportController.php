<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PpfCalculation;
use App\Support\Ppf\PpfDocumentBuilder;
use App\Support\Ppf\PpfValidator;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/** ППФ XML за една пресметка. Само канцеларијата (рутата е зад EnsureAccountingAccess). */
class PpfExportController extends Controller
{
    public function __invoke(Company $company, PpfCalculation $calculation): Response
    {
        Gate::authorize('view', $company);
        abort_unless($calculation->company_id === $company->id, 404);

        // Грешките се прикажуваат на самиот екран; симнувањето не може да рендерира порака.
        if (! PpfValidator::check($company, $calculation)->passes()) {
            return back();
        }

        // Прво датотеката, па печатот: пад при градењето не смее да остави запишан извоз што не се случил.
        $xml = PpfDocumentBuilder::build(
            $company->loadMissing('bankAccounts'),
            $calculation->payments()->with('payee')->get(),
            $calculation->period_date,
        );

        $calculation->forceFill(['exported_at' => now(), 'exported_by' => auth()->id()])->save();

        // Името е „Akontativna presmetka br.N.xml“: само латиница, цифри, празно место и точка,
        // па ништо што Windows го одбива.
        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                PpfDocumentBuilder::fileName($calculation),
                'ppf-'.$calculation->year.'-'.$calculation->number.'.xml',
            ),
        ]);
    }
}
