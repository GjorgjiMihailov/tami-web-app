<?php

namespace App\Providers;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class GoogleDriveServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('google', function ($app, array $config) {
            $client = new Client();
            $client->setAuthConfig([
                'type' => 'service_account',
                'client_id' => $config['client_id'],
                'client_email' => $config['client_email'],
                'private_key' => str_replace('\\n', "\n", (string) $config['private_key']),
            ]);
            $client->addScope(Drive::DRIVE);

            $adapter = new GoogleDriveAdapter(new Drive($client), null, [
                'sharedFolderId' => $config['folder_id'],
            ]);

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
