<?php

namespace App\Services;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\DeleteBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\GetBlobOptions;

class AzureStorage
{
    protected static function client()
    {
        $connectionString = sprintf(
            'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
            env('AZURE_STORAGE_NAME'),
            env('AZURE_STORAGE_KEY')
        );

        return BlobRestProxy::createBlobService($connectionString);
    }

    protected static function container(): string
    {
        return env('AZURE_STORAGE_CONTAINER');
    }

    public static function put(string $blobName, string $content): void
    {
        $client = self::client();
        $options = new CreateBlockBlobOptions();
        $client->createBlockBlob(self::container(), $blobName, $content, $options);
    }

    public static function get(string $blobName): ?string
    {
        $client = self::client();
        try {
            $blob = $client->getBlob(self::container(), $blobName);
            return stream_get_contents($blob->getContentStream());
        } catch (\Exception $e) {
            return null; // Return null if file does not exist
        }
    }

    public static function delete(string $blobName): void
    {
        $client = self::client();
        $options = new DeleteBlobOptions();
        $client->deleteBlob(self::container(), $blobName, $options);
    }
}
