<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    public function test_backup_run_creates_a_database_dump_on_the_configured_disk(): void
    {
        Storage::fake('backup-test');
        config(['backup.backup.destination.disks' => ['backup-test']]);

        Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);

        $zipFiles = array_filter(
            Storage::disk('backup-test')->allFiles(),
            fn (string $file) => str_ends_with($file, '.zip'),
        );

        $this->assertNotEmpty($zipFiles);
    }

    public function test_backup_clean_command_runs_without_error(): void
    {
        Storage::fake('backup-test');
        config(['backup.backup.destination.disks' => ['backup-test']]);

        $exitCode = Artisan::call('backup:clean', ['--disable-notifications' => true]);

        $this->assertSame(0, $exitCode);
    }
}
