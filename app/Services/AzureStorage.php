<?php

namespace App\Services;

use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\DeleteBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\GetBlobOptions;

class AzureStorage
{
    /** @var array<string, bool> */
    protected static array $containerChecked = [];

    protected static function client()
    {
        $connectionString = env('AZURE_STORAGE_CONNECTION_STRING');

        if (empty($connectionString)) {
            $connectionString = sprintf(
                'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
                env('AZURE_STORAGE_NAME'),
                env('AZURE_STORAGE_KEY')
            );
        }

        return BlobRestProxy::createBlobService($connectionString, [
            'timeout' => (int) env('AZURE_STORAGE_TIMEOUT', 10),
            'connect_timeout' => (int) env('AZURE_STORAGE_CONNECT_TIMEOUT', 5),
        ]);
    }

    protected static function container(): string
    {
        $container = trim((string) env('AZURE_STORAGE_CONTAINER', ''));

        if ($container === '') {
            throw new \RuntimeException('AZURE_STORAGE_CONTAINER is not configured.');
        }

        return $container;
    }

    protected static function ensureContainerExists(BlobRestProxy $client, string $container): void
    {
        if (!empty(self::$containerChecked[$container])) {
            return;
        }

        try {
            $client->getContainerProperties($container);
            self::$containerChecked[$container] = true;
            return;
        } catch (ServiceException $e) {
            $notFound = (int) $e->getCode() === 404 || str_contains((string) $e->getMessage(), 'ContainerNotFound');
            if (!$notFound) {
                throw $e;
            }
        }

        $client->createContainer($container);
        self::$containerChecked[$container] = true;
    }

    public static function put(string $blobName, string $content): void
    {
        $client = self::client();
        $container = self::container();
        self::ensureContainerExists($client, $container);

        $options = new CreateBlockBlobOptions();
        $attempts = max(1, (int) env('AZURE_STORAGE_UPLOAD_RETRIES', 2));
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client->createBlockBlob($container, $blobName, $content, $options);
                return;
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt < $attempts) {
                    usleep(200000 * $attempt);
                }
            }
        }

        if ($lastException instanceof \Throwable) {
            throw $lastException;
        }
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

    public static function exists(string $blobName): bool
    {
        $client = self::client();

        try {
            $client->getBlobProperties(self::container(), $blobName);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function delete(string $blobName): void
    {
        $client = self::client();
        $options = new DeleteBlobOptions();
        $client->deleteBlob(self::container(), $blobName, $options);
    }

    public static function url(string $blobName): string
    {
        $accountName = env('AZURE_STORAGE_NAME');
        $container = self::container();
        $normalizedBlob = ltrim($blobName, '/');

        return sprintf(
            'https://%s.blob.core.windows.net/%s/%s',
            $accountName,
            $container,
            str_replace('%2F', '/', rawurlencode($normalizedBlob))
        );
    }
}
