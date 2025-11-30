<?php

require 'vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

$connectionString = getenv("AZURE_STORAGE_CONNECTION_STRING");

// Create client
$blobClient = BlobRestProxy::createBlobService($connectionString);

// Example: upload a file
$containerName = "try";
$filePath = "test.txt";
$content = fopen($filePath, "r");

try {
    $blobClient->createBlockBlob($containerName, basename($filePath), $content);
    echo "Uploaded successfully!";
} catch (ServiceException $e) {
    echo "Error: ".$e->getMessage();
}