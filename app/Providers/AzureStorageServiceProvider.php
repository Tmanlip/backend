<?php

namespace App\Providers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem as Flysystem; 
use Illuminate\Filesystem\FilesystemAdapter; // THIS IS THE BRIDGE
use Illuminate\Support\Facades\Http;

class AzureStorageServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // ... existing imports ...
        Storage::extend('azure_managed', function ($app, $config) {
            
            // Check if we are running in Azure App Service by looking for a specific Azure env variable
            $isAzure = env('WEBSITE_INSTANCE_ID') || env('WEBSITE_SITE_NAME');

            if ($isAzure) {
                // PRODUCTION: Fetch Token from Azure Metadata Service
                try {
                    $response = Http::withHeaders(['Metadata' => 'true'])
                        ->timeout(2) // Don't hang if it's not there
                        ->get('http://169.254.169.254/metadata/identity/oauth2/token', [
                            'api-version' => '2018-02-01',
                            'resource' => 'https://storage.azure.com/',
                        ]);

                    $token = $response->json()['access_token'];
                    $connectionString = "DefaultEndpointsProtocol=https;AccountName={$config['account_name']};BearerToken={$token}";
                } catch (\Exception $e) {
                    // Fallback if token fetch fails even in Azure
                    throw new \Exception("Managed Identity token fetch failed: " . $e->getMessage());
                }
            } else {
                // LOCAL: Use the standard Account Key from your .env
                $connectionString = "DefaultEndpointsProtocol=https;AccountName={$config['account_name']};AccountKey={$config['account_key']};EndpointSuffix=core.windows.net";
            }

            $client = BlobRestProxy::createBlobService($connectionString);
            $adapter = new AzureBlobStorageAdapter($client, $config['container']);
            $driver = new \League\Flysystem\Filesystem($adapter);

            return new \Illuminate\Filesystem\FilesystemAdapter($driver, $adapter, $config);
        });
    }
}