<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OpenAIFactory;
use App\Service\Poligon;
use App\Service\PoligonException;
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

    #[Route('/s01/e03')]
    public function e03(string $projectDir, Poligon $poligon): Response
    {
        $rawData = file_get_contents($projectDir . '/zadania/s01e03.json');
        $data = json_decode($rawData, true);

        $questions = [];
        foreach ($data['test-data'] as $k => $item) {
            // verify question's math answer
            if (!empty($item['question'])) {
                $question = $item['question'];
                list($a, $op, $b) = sscanf($question, "%d %s %d");
                match($op) {
                    '+' => $answer = $a + $b,
                    '-' => $answer = $a - $b,
                    '*' => $answer = $a * $b,
                    '/' => $answer = $b !== 0 ? $a / $b : null,
                    default => $answer = null,
                };
                if ($answer !== $item['answer']) {
//                    echo "Corrected answer for question $k (was: {$item['answer']}, should be: {$answer})<br />\n";
                    $data['test-data'][$k]['answer'] = $answer;
                }
            }
            // check if it contains text question
            if (!empty($item['test'])) {
                $questions[] = [
                    'key' => $k,
                    'question' => $item['test']['q'],
                    'answer' => ''
                ];
            }
            // extract question from text
        }
        // get answers from OpenAI
        $context = "Twoim zadaniem jest odpowiedzieć na pytania zadane w pliku JSON.:
        - odpowiedzi na pytanie zadane w polu 'question' powinny być zapisane w polu 'answer'
        - odpowiedzi powinny maksymalnie krótkie i zwięzłe (liczba, pojedyncze słowo, krótka fraza)
        - odpowiedź zwróć jako dane JSON, z zachowaniem oryginalnej struktury. W szczególności nie zmieniaj wartości pola 'key'
        - nie używaj formatu markdown!";
        $messages[] = [
            'role' => 'system',
            'content' => $context,
        ];
        $messages[] = [
            'role' => 'user',
            'content' => json_encode($questions),
        ];

        $client = $this->openAIFactory->getClient();
        $chat = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
        ]);
        $response = $chat->choices[0]->message->content;
        $result = json_decode($response, true);
        if (!$result) {
            return new Response("Failed to get answers from OpenAI\n" . $response . "\n" . json_last_error_msg());
        }
        // update answers
        foreach ($result as $item) {
            $data['test-data'][$item['key']]['test']['a'] = $item['answer'];
        }

        $data['apikey'] = $poligon->poligonApiKey;
        $response = $poligon->send('https://centrala.ag3nts.org/report', 'JSON', $data);

        return new Response($response);
    }

    #[Route('/s01/e05')]
    public function e05(string $poligonApiKey, Poligon $poligon): Response
    {
        $input = file_get_contents('https://centrala.ag3nts.org/data/' . $poligonApiKey . '/cenzura.txt');
        $context = "Twoim zadaniem jest zabezpieczenie danych osobowych w treści przesłanej przez użytkownika. 
        Zabezpieczenie odbywa się poprzez wstawienia słowa CENZURA w miejscu następujących informacji:
        - imienia i nazwiska,
        - nazwy ulicy i numeru domu,
        - nazwie miasta,
        - wieku osoby.
        Cała pozostała treść powinna pozostać niezmieniona. Zwróć wyłącznie zabezpieczoną treść.
        
        Przykład:
        Dane wejściowe: 'Dane osoby podejrzanej: Paweł Zieliński. Zamieszkały w Warszawie na ulicy Pięknej 5. Ma 28 lat.'
        Rezultat: 'Dane osoby podejrzanej: CENZURA. Zamieszkały w CENZURA na ulicy CENZURA. Ma CENZURA lat.'
        Dane wejściowe: 'Informacje o podejrzanym: Adam Nowak. Mieszka w Katowicach przy ulicy Tuwima 10. Wiek: 32 lata.',
        Rezultat: 'Informacje o podejrzanym: CENZURA. Mieszka w CENZURA przy ulicy CENZURA. Wiek: CENZURA lata.'";

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
                    'content' => $input,
                ],
            ],
        ]);
        echo $chat->choices[0]->message->content . "\n";

        try {
            $response = $poligon->send('https://centrala.ag3nts.org/report', 'CENZURA',
                $chat->choices[0]->message->content);

            return new Response($response);
        } catch (PoligonException $e) {
            return new Response($e->getResponse()->getContent(false));
        }
    }
}
