<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\Filesystem;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureBlobServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $manager = $this->app->make(FilesystemManager::class);

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
    }
}
