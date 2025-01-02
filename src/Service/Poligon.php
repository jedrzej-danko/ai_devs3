<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class Poligon
{
    public function __construct(
        private HttpClientInterface $httpClient,
        public readonly string $poligonApiKey
    ) {
    }

    public function send($endpoint, $task, $data)
    {
        $data = [
            'task' => $task,
            'answer' => $data,
            'apikey' => $this->poligonApiKey
        ];

        $response = $this->httpClient->request('POST', $endpoint, [
            'json' => $data
        ]);
        if ($response->getStatusCode() !== 200) {
            throw PoligonException::create($response);
        }
        return $response->getContent();
    }


}