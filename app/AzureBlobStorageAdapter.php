<?php

namespace App;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureBlobStorageAdapter extends AbstractAdapter
{
    protected $client;
    protected $container;

    public function __construct(BlobRestProxy $client, $container)
    {
        $this->client = $client;
        $this->container = $container;
    }

    public function write($path, $contents, Config $config)
    {
        $this->client->createBlockBlob($this->container, $path, $contents);
        return ['path' => $path, 'contents' => $contents];
    }

    public function writeStream($path, $resource, Config $config)
    {
        $this->client->createBlockBlob($this->container, $path, $resource);
        return ['path' => $path];
    }

    public function has($path)
    {
        try {
            $this->client->getBlob($this->container, $path);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function read($path)
    {
        $blob = $this->client->getBlob($this->container, $path);
        return ['contents' => stream_get_contents($blob->getContentStream())];
    }

    public function delete($path)
    {
        $this->client->deleteBlob($this->container, $path);
        return true;
    }
}