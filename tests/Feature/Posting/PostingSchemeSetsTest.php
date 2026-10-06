<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\User;
use App\Services\Posting\DefaultPostingSchemes;
use App\Services\Posting\PostingSchemeEditor;
use App\Services\Posting\PostingSchemes;
use App\Services\Posting\PostingSchemeSets;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostingSchemeSetsTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('accountant');
    }

    /** Шема на фирма со сменет опис на првиот ред — за да се познае дека е „мојата“. */
    private function changedScheme(Company $company, PostingDocType $type = PostingDocType::SALES_INVOICE, string $description = 'Мој опис {фактура}'): PostingScheme
    {
        $scheme = PostingSchemes::for($company, $type);
        $draft = app(PostingSchemeEditor::class)->draftOf($scheme);
        $draft['rows'][0]['description'] = $description;
        $this->assertSame([], app(PostingSchemeEditor::class)->save($scheme, $draft['rows'], $draft['matrix']));

        return $scheme->fresh();
    }

    public function test_without_a_personal_set_the_standard_definition_is_used(): void
    {
        $this->assertFalse(PostingSchemeSets::has($this->accountant, PostingDocType::SALES_INVOICE));
        $this->assertSame(
            DefaultPostingSchemes::definition(PostingDocType::SALES_INVOICE),
            PostingSchemeSets::definitionFor($this->accountant, PostingDocType::SALES_INVOICE)
        );
        $this->assertSame(
            DefaultPostingSchemes::definition(PostingDocType::SALES_INVOICE),
            PostingSchemeSets::definitionFor(null, PostingDocType::SALES_INVOICE)
        );
    }

    public function test_a_scheme_can_be_remembered_and_read_back_in_the_standard_format(): void
    {
        $company = Company::factory()->create();
        $scheme = $this->changedScheme($company);

        PostingSchemeSets::remember($this->accountant, $scheme);

        $this->assertTrue(PostingSchemeSets::has($this->accountant, PostingDocType::SALES_INVOICE));
        $definition = PostingSchemeSets::definitionFor($this->accountant, PostingDocType::SALES_INVOICE);
        $this->assertSame('Мој опис {фактура}', $definition['rows'][0]['description']);
        $this->assertSame('1200', $definition['rows'][0]['account']);
        $this->assertSame('ВКУПНО', $definition['rows'][0]['formula']);
        $this->assertTrue($definition['rows'][0]['partner']);
        $this->assertCount(5, $definition['rows']);
        $this->assertCount(12, $definition['matrix']);
        $this->assertSame('74000', collect($definition['matrix'])->firstWhere(fn ($m) => $m['key'] === 'revenue' && $m['kind'] === 'service' && $m['group'] === 'general')['account']);
    }

    public function test_remembering_again_replaces_and_forget_removes(): void
    {
        $company = Company::factory()->create();
        PostingSchemeSets::remember($this->accountant, $this->changedScheme($company, description: 'Прв'));
        PostingSchemeSets::remember($this->accountant, $this->changedScheme($company, description: 'Втор'));

        $this->assertSame('Втор', PostingSchemeSets::definitionFor($this->accountant, PostingDocType::SALES_INVOICE)['rows'][0]['description']);
        $this->assertSame(1, $this->accountant->hasMany(\App\Models\UserPostingScheme::class)->count());

        PostingSchemeSets::forget($this->accountant, PostingDocType::SALES_INVOICE);

        $this->assertFalse(PostingSchemeSets::has($this->accountant, PostingDocType::SALES_INVOICE));
    }

    public function test_one_accountants_set_does_not_leak_to_another(): void
    {
        $other = User::factory()->create();
        $other->assignRole('accountant');
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));

        $this->assertFalse(PostingSchemeSets::has($other, PostingDocType::SALES_INVOICE));
    }

    public function test_seeding_a_company_creates_only_the_types_the_user_has_a_set_for(): void
    {
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));
        $fresh = Company::factory()->create();

        PostingSchemeSets::seedCompany($fresh, $this->accountant);

        $this->assertSame(1, PostingScheme::where('company_id', $fresh->id)->count());
        $scheme = PostingScheme::where('company_id', $fresh->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('Мој опис {фактура}', $scheme->rows->first()->description);
        $this->assertSame(12, $scheme->matrixAccounts()->count());
    }

    public function test_seeding_without_a_user_or_a_set_creates_nothing(): void
    {
        $company = Company::factory()->create();

        PostingSchemeSets::seedCompany($company, null);
        PostingSchemeSets::seedCompany($company, $this->accountant);

        $this->assertSame(0, PostingScheme::where('company_id', $company->id)->count());
    }

    public function test_a_missing_account_leaves_that_type_to_the_standard_lazy_scheme(): void
    {
        $company = Company::factory()->create();
        PostingSchemeSets::remember($this->accountant, $this->changedScheme($company));
        $fresh = Company::factory()->create();
        \App\Models\Account::where('company_id', $fresh->id)->where('code', '74000')->delete();

        PostingSchemeSets::seedCompany($fresh, $this->accountant);

        $this->assertSame(0, PostingScheme::where('company_id', $fresh->id)->count());
    }

    public function test_a_company_created_by_an_accountant_gets_the_accountants_set(): void
    {
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));

        $this->actingAs($this->accountant);
        $created = Company::factory()->create();

        $scheme = PostingScheme::where('company_id', $created->id)->where('doc_type', 'sales_invoice')->first();
        $this->assertNotNull($scheme);
        $this->assertSame('Мој опис {фактура}', $scheme->rows->first()->description);
    }

    public function test_a_company_created_by_someone_without_a_set_gets_nothing_up_front(): void
    {
        $other = User::factory()->create();
        $other->assignRole('accountant');
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));

        $this->actingAs($other);
        $created = Company::factory()->create();

        $this->assertSame(0, PostingScheme::where('company_id', $created->id)->count());
    }

    public function test_reset_to_default_uses_the_users_set_and_falls_back_to_the_standard(): void
    {
        $source = Company::factory()->create();
        PostingSchemeSets::remember($this->accountant, $this->changedScheme($source));
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $editor = app(PostingSchemeEditor::class);

        $editor->resetToDefault($scheme, $this->accountant);
        $this->assertSame('Мој опис {фактура}', $scheme->fresh()->rows->first()->description);

        $editor->resetToDefault($scheme);
        $this->assertSame('{фактура}', $scheme->fresh()->rows->first()->description);
    }

    public function test_reset_with_a_set_whose_account_is_missing_changes_nothing(): void
    {
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        \App\Models\Account::where('company_id', $company->id)->where('code', '74001')->update(['is_analytical' => false]);
        $before = $scheme->rows()->count();

        try {
            app(PostingSchemeEditor::class)->resetToDefault($scheme, $this->accountant);
            $this->fail('Очекуван ModelNotFoundException.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame($before, $scheme->rows()->count());
        }
    }
}
