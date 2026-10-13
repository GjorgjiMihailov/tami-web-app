<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class CheckMt940CommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mt940-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $options */
    private function writeStatement(string $name, array $options): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.$name, Mt940Fixture::statement($options));
    }

    public function test_a_clean_folder_succeeds_and_prints_a_summary(): void
    {
        $this->writeStatement('a.txt', ['number' => 1, 'lines' => [['dir' => 'in', 'amount' => '100.00']]]);
        $this->writeStatement('b.TXT', ['number' => 2, 'date' => '2026-02-11', 'opening' => '100.00', 'lines' => [['dir' => 'in', 'amount' => '5.00']]]);

        $this->artisan('bank:check-mt940', ['folder' => $this->dir])
            ->expectsOutputToContain('изводи: 2, ставки: 2')
            ->assertExitCode(0);
    }

    public function test_a_gap_makes_the_command_fail(): void
    {
        $this->writeStatement('a.txt', ['number' => 1]);
        $this->writeStatement('c.txt', ['number' => 3, 'date' => '2026-02-12']);

        $this->artisan('bank:check-mt940', ['folder' => $this->dir])
            ->expectsOutputToContain('фали 2')
            ->assertExitCode(1);
    }

    public function test_it_never_writes_to_the_database(): void
    {
        $this->writeStatement('a.txt', ['number' => 1]);

        $this->artisan('bank:check-mt940', ['folder' => $this->dir])->run();

        $this->assertSame(0, BankStatement::count());
    }

    public function test_a_missing_folder_fails(): void
    {
        $this->artisan('bank:check-mt940', ['folder' => $this->dir.'-nema'])->assertExitCode(1);
    }
}
