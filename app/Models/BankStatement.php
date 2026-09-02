<?php

namespace App\Models;

use App\Support\BankStatementKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Еден извод од банка, денарски или девизен.
 *
 * Состојбите и прометот намерно ги нема: тие се во самиот фајл, а препишувањето
 * би било работа без добивка. Внесени се само толку податоци колку што треба за
 * изводот да се најде и за да се види дека еден фали.
 */
class BankStatement extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'bank', 'account', 'kind', 'number', 'statement_date', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => BankStatementKind::class,
            'number' => 'integer',
            'statement_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Самиот извод, преку постоечката полиморфна табела за документи. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
