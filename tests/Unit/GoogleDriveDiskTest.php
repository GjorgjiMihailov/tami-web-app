<?php

namespace Tests\Unit;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDriveDiskTest extends TestCase
{
    public function test_google_disk_resolves_to_a_filesystem_adapter(): void
    {
        config([
            'filesystems.disks.google' => [
                'driver' => 'google',
                'client_id' => 'fake-client-id.apps.googleusercontent.com',
                'client_secret' => 'fake-client-secret',
                'refresh_token' => 'fake-refresh-token',
                'folder_id' => 'fake-folder-id',
            ],
        ]);

        $disk = Storage::disk('google');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
    }
}
