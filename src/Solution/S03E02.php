<?php

namespace App\Solution;

use App\Service\OpenAIFactory;
use App\Service\QdrantFactory;
use Qdrant\Models\Filter\Condition\MatchString;
use Qdrant\Models\Filter\Filter;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\PointStruct;
use Qdrant\Models\Request\CreateCollection;
use Qdrant\Models\Request\SearchRequest;
use Qdrant\Models\Request\VectorParams;
use Qdrant\Models\VectorStruct;
use Qdrant\Qdrant;
use OpenAI\Client as OpenAiClient;
use Qdrant\Response;
use Symfony\Component\Uid\Uuid;

class S03E02
{

    public const string COLLECTION_NAME = 'collection1';
    public const string VECTOR_NAME = 'content';

    private Qdrant $qdrant;
    private OpenAiClient $openAI;

    private $documents = [];

    public function __construct(
        QdrantFactory $qdrantFactory,
        OpenAIFactory $openAIFactory
    ) {
        $this->qdrant = $qdrantFactory->getClient();
        $this->openAI = $openAIFactory->getClient();

    }

    public function solve(string $documentDir, string $query): Response
    {
        $cache = $documentDir . '/../documents.json';
        if (!file_exists($cache)) {
            $this->resetCollection();
            $this->populateCollection($documentDir);
            file_put_contents($cache, json_encode($this->documents));
        } else {
            $this->documents = json_decode(file_get_contents($cache), true);
        }

        $embedding = $this->createEmbedding($query);

        $search = (new SearchRequest(new VectorStruct($embedding, self::VECTOR_NAME)))
            ->setFilter((new Filter())->addMust(new MatchString('type', 'do-not-share')))
            ->setLimit(1)
            ->setWithPayload(true);

        $response = $this->qdrant->collections(self::COLLECTION_NAME)->points()->search($search);
        return $response;
    }

    private function resetCollection(): void
    {
        $this->qdrant->collections(self::COLLECTION_NAME)->delete();

        $createCollection = new CreateCollection();
        $createCollection->addVector(new VectorParams(1536, VectorParams::DISTANCE_COSINE), self::VECTOR_NAME);
        $this->qdrant->collections(self::COLLECTION_NAME)->create($createCollection);
    }

    private function populateCollection(string $documentDir): void
    {
        $this->storeDocuments($this->readDocuments($documentDir), 'report');
        $this->storeDocuments($this->readDocuments($documentDir . '/facts'), 'fact');
        $this->storeDocuments($this->readDocuments($documentDir . '/do-not-share'), 'do-not-share');
    }

    private function readDocuments(string $documentsPath): array
    {
        $documents = [];
        $dir = new \DirectoryIterator($documentsPath);
        foreach ($dir as $document) {
            if (!$document->isFile() || $document->getExtension() !== 'txt') {
                continue;
            }

            $documents[$document->getFilename()] = file_get_contents($document->getPathname());
        }

        return $documents;
    }

    /**
     * @param array<string, string> $documents
     * @return void
     */
    private function storeDocuments(array $documents, string $documentType)
    {
        $embeddings = [];
        foreach ($documents as $documentName => $documentContent) {
            $content = "$documentName\n$documentContent";

            $embeddings[$documentName] = $this->createEmbedding($content);
        }

        $points = new PointsStruct();

        foreach ($embeddings as $documentName => $embedding) {
            $documentId = Uuid::v4()->toString();
            $this->documents[$documentId] = $documentName;
            $points->addPoint(
                new PointStruct(
                    $documentId,
                    new VectorStruct($embedding, self::VECTOR_NAME),
                    [
                        'id' => $documentId,
                        'documentName' => $documentName,
                        'date' => $this->extractDate($documentName),
                        'type' => $documentType
                    ]
                )
            );
        }
        $this->qdrant->collections(self::COLLECTION_NAME)->points()->upsert($points);
    }

    private function createEmbedding($content): array
    {
        $response = $this->openAI->embeddings()->create([
            'model' => 'text-embedding-ada-002',
            'input' => $content
        ]);

        return $response->embeddings[0]->embedding;
    }

    private function extractDate(string $documentName): string
    {
        $delimiters = ['-', '_', '.'];
        foreach ($delimiters as $delimiter) {
            $matches = [];
            preg_match("/(\d{4}$delimiter\d{2}$delimiter\d{2})/", $documentName, $matches);
            if (!empty($matches)) {
                return str_replace($delimiter, '-', $matches[0]);
            }
        }
        return '';
    }
}