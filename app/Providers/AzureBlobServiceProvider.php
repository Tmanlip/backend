<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\Filesystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;

class AzureBlobServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving(FilesystemManager::class, function ($manager) {

            $manager->extend('azure', function ($app, $config) {

                $connectionString =
                    "DefaultEndpointsProtocol=https;" .
                    "AccountName={$config['account_name']};" .
                    "AccountKey={$config['account_key']};" .
                    "EndpointSuffix=core.windows.net";

                $client = BlobRestProxy::createBlobService($connectionString);

                $adapter = new AzureBlobStorageAdapter(
                    $client,
                    $config['container'],
                    $config['prefix'] ?? ''
                );

                return new Filesystem($adapter);
            });
        });
    }

    public function boot(): void
    {
        //
    }
}