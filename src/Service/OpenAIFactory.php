<?php

namespace App\Service;

use OpenAI;

class OpenAIFactory
{
    public function __construct(
        private string $openAiApiKey,
    )
    {
    }

    public function getClient()
    {
        return OpenAI::client($this->openAiApiKey);
    }
}