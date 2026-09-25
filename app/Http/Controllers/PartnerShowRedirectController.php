<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Partner;

/**
 * Деталите за кооперант живеат во листата (лево список, десно детали).
 * Стар линк или обележувач до /partners/{id} го носи таму.
 */
class PartnerShowRedirectController extends Controller
{
    public function __invoke(Company $company, Partner $partner)
    {
        return redirect()->route('partners.index', [$company, 'partner' => $partner->id]);
    }
}
