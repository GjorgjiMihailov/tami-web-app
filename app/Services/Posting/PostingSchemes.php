<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Support\Posting\PostingDocType;

/** Шемата на фирма за вид документ: постојната, или лено создадена од стандардната. */
class PostingSchemes
{
    public static function for(Company $company, PostingDocType $type): PostingScheme
    {
        return PostingScheme::where('company_id', $company->id)->where('doc_type', $type->value)->first()
            ?? DefaultPostingSchemes::create($company, $type);
    }

    /** Залихата за увоз според шемата на влезна фактура (за ставки што се книжат како залиха при увоз). */
    public static function importStockAccount(Company $company): ?Account
    {
        return self::for($company, PostingDocType::PURCHASE_INVOICE)->rows()
            ->where('account_mode', 'fixed')
            ->where('condition', 'import')
            ->where('formula', 'ЗАЛИХА')
            ->with('account')
            ->first()?->account;
    }
}
