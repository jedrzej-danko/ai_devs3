<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\ResponseInterface;

class PoligonException extends \Exception
{
    private ResponseInterface $response;

    public static function create(ResponseInterface $response): static
    {
        $e = new static('Poligon failure', $response->getStatusCode());
        $e->response = $response;
        return $e;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}