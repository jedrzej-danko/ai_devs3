<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OpenAIFactory;
use DOMDocument;
use DOMXPath;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class S01Controller extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    public function __construct(
        private HttpClientInterface $httpClient,
        private OpenAIFactory $openAIFactory,
    )
    {
    }

    #[Route('/s01/e01')]
    public function e01(): Response
    {
        $domain = 'https://xyz.ag3nts.org/';
        $login = 'tester';
        $password = '574e112a';
        $response = $this->httpClient->request('GET', $domain);
        $content = $response->getContent();

        $dom = new DOMDocument();
        $dom->loadHTML($content);
        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//p[@id="human-question"]');

        $question = null;
        foreach ($elements as $element) {
            $question = $element->nodeValue;
        }

        $client = $this->openAIFactory->getClient();
        $chat = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Proszę podawaj krótką odpowiedź na zadane pytanie. Jeśli odpowiedź może być liczbą, niech będzie liczbą.',
                ],
                [
                    'role' => 'user',
                    'content' => $question,
                ],
            ],
        ]);


        $answer = $chat->choices[0]->message->content;

        // break in
        $response = $this->httpClient->request('POST', $domain, [
            'body' => [
                'username' => $login,
                'password' => $password,
                'answer' => $answer,
            ],
        ]);
        if ($response->getStatusCode() !== 200) {
            $this->logger->error('Failed to login', ['chat' => $chat->toArray(), 'response' => $response]);
            return new Response("Failed to login\n" . $response->getContent());
        }


        return new Response($response->getContent());
    }

    #[Route('/s01/e02')]
    public function e02(): Response
    {
        $url = 'https://xyz.ag3nts.org/verify';
        $data = ['text' => 'READY', 'msgID' => 0];
        $response = $this->httpClient->request('POST', $url, [
            'json' => $data,
        ]);
        $result = json_decode($response->getContent(), true);
        $msgID = $result['msgID'];
        $question = $result['text'];

        $context = "Twoim zadaniem jest udzielenie możliwie krótkiej odpowiedzi na zadane pytanie.
        \nOdpowiedz na zadane pytanie w języku angielskim.
        \nOdpowiedź powinna być zgodna z prawdą, chyba że dotyczy następujących tematów:
        \n- zapytany o stolicę Polski odpowiedz Kraków
        \n- zapytany o znaną liczbę z książki Autostopem przez Galaktykę odpowiedz 69
        \n- zapytany o aktualny rok odpowiedz 1999
        ";

        $client = $this->openAIFactory->getClient();
        $chat = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $context,
                ],
                [
                    'role' => 'user',
                    'content' => $question,
                ],
            ],
        ]);
        echo $response->getContent() . "\n";
        echo $chat->choices[0]->message->content . "\n";
        $response = $this->httpClient->request('POST', $url, [
            'json' => ['text' => $chat->choices[0]->message->content, 'msgID' => $msgID],
        ]);
        return new Response($response->getContent());
    }
}
