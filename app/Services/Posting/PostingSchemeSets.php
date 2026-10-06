<?php

namespace App\Services\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\User;
use App\Models\UserPostingScheme;
use App\Support\Posting\PostingDocType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Личен предложен комплет шеми по сметководител. Се полни од шема на вистинска
 * фирма (веќе проверена), а се применува само при создавање нова фирма.
 */
class PostingSchemeSets
{
    public static function remember(User $user, PostingScheme $scheme): UserPostingScheme
    {
        $draft = app(PostingSchemeEditor::class)->draftOf($scheme);

        $definition = [
            'name' => $scheme->name,
            'rows' => array_map(function (array $row) {
                $mode = $row['account_mode'];

                return array_filter([
                    'mode' => $mode,
                    'account' => $mode === 'fixed' ? $row['account_code'] : null,
                    'matrix' => $mode === 'matrix' ? $row['matrix_key'] : null,
                    'side' => $row['side'],
                    'formula' => $row['formula'],
                    'partner' => (bool) $row['with_partner'],
                    'description' => $row['description'],
                    'condition' => $row['condition'],
                ], fn ($value) => $value !== null);
            }, $draft['rows']),
            'matrix' => array_map(fn (array $m) => [
                'key' => $m['matrix_key'],
                'kind' => $m['item_kind'],
                'group' => $m['vat_group'],
                'account' => $m['account_code'],
            ], $draft['matrix']),
        ];

        return UserPostingScheme::updateOrCreate(
            ['user_id' => $user->id, 'doc_type' => $scheme->doc_type->value],
            ['name' => $scheme->name, 'definition' => $definition]
        );
    }

    public static function has(User $user, PostingDocType $type): bool
    {
        return UserPostingScheme::where('user_id', $user->id)->where('doc_type', $type->value)->exists();
    }

    public static function forget(User $user, PostingDocType $type): void
    {
        UserPostingScheme::where('user_id', $user->id)->where('doc_type', $type->value)->delete();
    }

    /** @return array{name: string, rows: list<array<string, mixed>>, matrix: list<array<string, mixed>>} */
    public static function definitionFor(?User $user, PostingDocType $type): array
    {
        $mine = $user === null ? null : UserPostingScheme::where('user_id', $user->id)->where('doc_type', $type->value)->first();

        return $mine?->definition ?? DefaultPostingSchemes::definition($type);
    }

    /**
     * Нова фирма го добива предлогот на корисникот што ја создава — само за
     * видовите за кои има предлог. Ако некое конто го нема во планот на
     * фирмата, тој вид се прескокнува (останува стандардната, лено при прво
     * книжење): создавањето фирма никогаш не се блокира.
     */
    public static function seedCompany(Company $company, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        foreach (UserPostingScheme::where('user_id', $user->id)->get() as $mine) {
            try {
                DB::transaction(function () use ($company, $mine) {
                    $scheme = PostingScheme::create(['company_id' => $company->id, 'doc_type' => $mine->doc_type, 'name' => $mine->definition['name']]);
                    DefaultPostingSchemes::populate($scheme, $company, $mine->definition);
                });
            } catch (ModelNotFoundException) {
                // Конто од предлогот го нема (аналитичко) во планот на оваа фирма.
            }
        }
    }
}
