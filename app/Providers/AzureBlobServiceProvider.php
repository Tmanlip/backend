<?php

namespace App\Providers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureBlobServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('azure', function ($app, $config) {

            $connectionString =
                "DefaultEndpointsProtocol=https;" .
                "AccountName={$config['account_name']};" .
                "AccountKey={$config['account_key']};" .
                "EndpointSuffix=core.windows.net";

            $client = BlobRestProxy::createBlobService($connectionString);

            $adapter = new AzureBlobStorageAdapter(
                $client,
                $config['container']
            );

            $filesystem = new Filesystem($adapter);

            // ✅ THIS IS THE CRITICAL FIX
            return new FilesystemAdapter(
                $filesystem,
                $adapter,
                $config
            );
        });
    }
}