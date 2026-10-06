<?php

namespace App\Services\Posting;

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
}
