<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Изгледот на рамката: подлога со тежина, темна странична лента и групи што се
 * отвораат во прелистувачот. Се проверува преку класите во HTML — тоа е она што
 * стварно стигнува до прелистувачот.
 */
class SidebarLookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
    }

    private function dashboard(): string
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $this->actingAs($admin)
            ->get(route('companies.dashboard', $company))
            ->getContent();
    }

    public function test_no_screen_uses_the_faint_gray_borders_any_more(): void
    {
        $html = $this->dashboard();

        $this->assertStringNotContainsString('border-gray-100', $html, 'Рамките одат на sand.');
        $this->assertStringNotContainsString('border-gray-200', $html, 'Рамките одат на sand.');
    }

    public function test_the_sidebar_is_dark(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('bg-rail', $html, 'Страничната лента е темна.');
        $this->assertStringContainsString('text-rail-text', $html);
    }

    public function test_a_group_opens_without_going_to_the_server(): void
    {
        $html = $this->dashboard();

        $this->assertStringNotContainsString('wire:click="toggleGroup', $html, 'Отворањето е во прелистувачот.');
        $this->assertStringContainsString('x-collapse', $html, 'Групата се отвора со лизгање.');
    }
}
