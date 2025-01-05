<?php

namespace App\Service;

use Qdrant\Config;
use Qdrant\Http\Builder;
use Qdrant\Qdrant;

class QdrantFactory
{
    private ?Qdrant $client = null;

    public function getClient(): Qdrant
    {
        if (!$this->client) {
            $this->createClient();
        }

        return $this->client;
    }

    private function createClient(): void
    {
        $config = new Config('http://localhost:6334/');

        $transport = (new Builder())->build($config);
        $this->client = new Qdrant($transport);
    }
}