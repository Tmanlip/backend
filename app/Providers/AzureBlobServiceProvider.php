<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureBlobServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Storage::extend('azure', function ($app, $config) {
            $client = BlobRestProxy::createBlobService(
                "DefaultEndpointsProtocol=https;AccountName={$config['account_name']};AccountKey={$config['account_key']};EndpointSuffix=core.windows.net"
            );

            $adapter = new AzureBlobStorageAdapter(
                $client,
                $config['container']
            );

            return new Filesystem($adapter);
        });
    }
}
