<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\DeleteBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\GetBlobOptions;

class AzureStorage
{
    /** @var array<string, bool> */
    protected static array $containerChecked = [];

    /** @var array<string, bool> */
    protected static array $containerCreationAttempted = [];

    protected static bool $diagnosticsLogged = false;

    protected static function debugEnabled(): bool
    {
        return filter_var(env('AZURE_STORAGE_DEBUG', false), FILTER_VALIDATE_BOOL);
    }

    protected static function trace(string $message, array $context = []): void
    {
        if (self::debugEnabled()) {
            Log::debug($message, $context);
        }
    }

    protected static function client()
    {
        $connectionString = trim((string) env('AZURE_STORAGE_CONNECTION_STRING', ''));
        $accountName = trim((string) env('AZURE_STORAGE_NAME', ''));
        $accountKey = trim((string) env('AZURE_STORAGE_KEY', ''));
        $container = trim((string) env('AZURE_STORAGE_CONTAINER', ''));
        $timeout = (int) env('AZURE_STORAGE_TIMEOUT', 10);
        $connectTimeout = (int) env('AZURE_STORAGE_CONNECT_TIMEOUT', 5);

        if (!self::$diagnosticsLogged) {
            Log::info('Azure storage configuration summary.', [
                'using_connection_string' => $connectionString !== '',
                'account_name' => $accountName !== '' ? $accountName : null,
                'key_present' => $accountKey !== '',
                'container' => $container !== '' ? $container : null,
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
            ]);

            self::$diagnosticsLogged = true;
        }

        if (empty($connectionString)) {
            $connectionString = sprintf(
                'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
                $accountName,
                $accountKey
            );
        }

        return BlobRestProxy::createBlobService($connectionString, [
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
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
        self::$containerCreationAttempted[$container] = true;
    }

    protected static function isContainerMissing(
        \Throwable $exception
    ): bool {
        $message = (string) $exception->getMessage();

        return (int) $exception->getCode() === 404
            || str_contains($message, 'ContainerNotFound')
            || str_contains($message, 'The specified container does not exist');
    }

    public static function put(string $blobName, string $content): void
    {
        $client = self::client();
        $container = self::container();
        self::trace('Azure blob upload started.', [
            'blob_name' => $blobName,
            'container' => $container,
            'content_length' => strlen($content),
        ]);

        $options = new CreateBlockBlobOptions();
        $attempts = max(1, (int) env('AZURE_STORAGE_UPLOAD_RETRIES', 2));
        $lastException = null;
        $hasRetriedAfterContainerCheck = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client->createBlockBlob($container, $blobName, $content, $options);
                self::$containerChecked[$container] = true;
                return;
            } catch (\Throwable $e) {
                if (! $hasRetriedAfterContainerCheck && self::isContainerMissing($e)) {
                    self::trace('Azure blob upload succeeded.', [
                        'blob_name' => $blobName,
                        'container' => $container,
                        'attempt' => $attempt,
                    ]);
                    self::ensureContainerExists($client, $container);
                    $hasRetriedAfterContainerCheck = true;
                    $attempt--;
                    continue;
                        self::trace('Azure blob upload reported missing container; retrying after container check.', [
                            'blob_name' => $blobName,
                            'container' => $container,
                            'attempt' => $attempt,
                            'exception_code' => (int) $e->getCode(),
                        ]);
                }

                $lastException = $e;
                if ($attempt < $attempts) {
                    usleep(200000 * $attempt);
                }
                    self::trace('Azure blob upload attempt failed.', [
                        'blob_name' => $blobName,
                        'container' => $container,
                        'attempt' => $attempt,
                        'exception_code' => (int) $e->getCode(),
                        'exception_class' => $e::class,
                        'exception_message' => $e->getMessage(),
                    ]);

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
