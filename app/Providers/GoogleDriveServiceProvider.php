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
            $client->setClientId($config['client_id']);
            $client->setClientSecret($config['client_secret']);
            // Stub access_token + a real refresh_token: satisfies
            // setAccessToken()'s validation without making a network call.
            // The client library refreshes it lazily, automatically, the
            // first time a real Drive API request is made.
            $client->setAccessToken([
                'access_token' => '',
                'refresh_token' => $config['refresh_token'],
            ]);
            $client->addScope(Drive::DRIVE);

            $adapter = new GoogleDriveAdapter(new Drive($client), null, [
                // "sharedFolderId" is just the adapter's option name for "root
                // folder ID" — it works identically for a folder this OAuth
                // user owns outright, not only ones shared with them.
                'sharedFolderId' => $config['folder_id'],
            ]);

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
