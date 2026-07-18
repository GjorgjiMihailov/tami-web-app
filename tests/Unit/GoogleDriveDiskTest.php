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
                'client_id' => '123456789fake',
                'client_email' => 'fake@example.iam.gserviceaccount.com',
                'private_key' => $this->fakePrivateKey(),
                'folder_id' => 'fake-folder-id',
            ],
        ]);

        $disk = Storage::disk('google');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
    }

    private function fakePrivateKey(): string
    {
        // Google\Client::setAuthConfig() only stores this string into
        // $config['signing_key'] — it never parses/validates it unless a real
        // API call is made (which this test does not do) — so a real RSA key
        // is not required here, only a non-empty string.
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            // openssl_pkey_new() can fail in environments with a broken/missing
            // openssl.cnf (observed on this project's Windows dev machine). This
            // is not a real key — it's a placeholder string that is honest about
            // not being one, kept short so nobody mistakes it for real PEM data.
            return 'placeholder-not-a-real-key (openssl_pkey_new unavailable in this environment)';
        }

        openssl_pkey_export($resource, $pem);

        return $pem;
    }
}
