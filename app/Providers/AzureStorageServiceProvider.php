<?php

namespace App\Providers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem as Flysystem; 
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;

class AzureStorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('azure_managed', function ($app, $config) {
            
            $isAzure = env('WEBSITE_INSTANCE_ID') || env('WEBSITE_SITE_NAME');

            if ($isAzure) {
                try {
                    $response = Http::withHeaders(['Metadata' => 'true'])
                        ->timeout(5)
                        ->get('http://169.254.169.254/metadata/identity/oauth2/token', [
                            'api-version' => '2018-02-01',
                            'resource' => 'https://storage.azure.com/',
                        ]);

                    $token = $response->json()['access_token'];
                    $connectionString = "DefaultEndpointsProtocol=https;AccountName={$config['account_name']};BearerToken={$token}";
                } catch (\Exception $e) {
                    throw new \Exception("Managed Identity token fetch failed: " . $e->getMessage());
                }
            } else {
                $connectionString = "DefaultEndpointsProtocol=https;AccountName={$config['account_name']};AccountKey={$config['account_key']};EndpointSuffix=core.windows.net";
            }

            $client = BlobRestProxy::createBlobService($connectionString);
            $adapter = new AzureBlobStorageAdapter($client, $config['container']);
            
            // Create Flysystem
            $driver = new Flysystem($adapter);

            // Return the Laravel Adapter
            return new FilesystemAdapter($driver, $adapter, $config);
        });
    }
}