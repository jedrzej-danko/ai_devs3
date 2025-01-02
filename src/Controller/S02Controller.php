<?php

namespace App\Controller;

use App\Service\OpenAIFactory;
use App\Service\Poligon;
use App\Service\PoligonException;
use DOMDocument;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class S02Controller  extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    public function __construct(
        private HttpClientInterface $httpClient,
        private OpenAIFactory $openAIFactory,
    )
    {
    }

    #[Route('/s02/e01')]
    public function e01(string $poligonApiKey, Poligon $poligon): Response
    {
        try {
            $response = $poligon->send('https://centrala.ag3nts.org/report', 'mp3',
                'ul. Łojasiewicza');

            return new Response($response);
        } catch (PoligonException $e) {
            return new Response($e->getResponse()->getContent(false));
        }
    }

    #[Route('/s02/e03')]
    public function e03(string $poligonApiKey, Poligon $poligon): Response
    {
        try {
            $response = $poligon->send('https://centrala.ag3nts.org/report', 'robotid',
                'https://i.postimg.cc/Xq3JBdHw/e837a494-7045-468c-a905-5f59e8cf5e50.png');

            return new Response($response);
        } catch (PoligonException $e) {
            return new Response($e->getResponse()->getContent(false));
        }
    }

    #[Route('/s02/e04')]
    public function e04(string $projectDir, string $poligonApiKey, Poligon $poligon): Response
    {
        $dir = $projectDir . '/data/pliki_z_fabryki/';

        $categories = [
            'people' => [],
            'hardware' => [],
            'other' => [],
        ];

        $files = scandir($dir);

        $context = 'Przesyłam treść raportu z fabryki. Twoim zadaniem jest określenie, czy raport dotyczy
         - ludzi lub wykrycia śladów ich obecności (nazwa kategorii: people), 
         - usuniętych usterek sprzętu (nazwa kategorii: hardware) 
         - jakiejś innej sprawy np. raportu podczas którego nie wykryto niczyjej aktywności, lub alarm nie dotyczył 
          aktywności człowieka, lub naprawa dotyczyła aktualizacji lub konfiguracji oprogramowania (nazwa kategorii: other).
        Odpowiedzią powinno być jedno słowo, które jest nazwą kategorii.';

        foreach ($files as $file) {
            $fileName = $dir . $file;
            if (is_dir($fileName)) {
                continue;
            }
            $pathInfo = pathinfo($fileName);
            if (!in_array($pathInfo['extension'], ['txt', 'png', 'mp3'])) {
                continue;
            }

            $client = $this->openAIFactory->getClient();
            $content = match($pathInfo['extension']) {
                'txt' => [
                    'type' => 'text',
                    'text' => file_get_contents($fileName)
                ],
                'png' => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:image/png;base64,' . base64_encode(file_get_contents($fileName)) ]
                ],
                'mp3' => [
                    'type' => 'text',
                    'text' => file_get_contents($fileName . '.transcript')
                ],
                default => '',
            };
            $chat = $client->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $context,
                    ],
                    [
                        'role' => 'user',
                        'content' => [$content]
                    ],
                ],
            ]);

            echo "Plik: $file\n<br />";
            echo "Treść: \n<br />" . json_encode($content) . "\n<br />";
            $category = $chat->choices[0]->message->content;
            echo "Kategoria: {$category} \n<br /><br />";

            $categories[$category][] = $file;
        }

        try {
            $response = $poligon->send('https://centrala.ag3nts.org/report', 'kategorie',
                [
                    'people' => $categories['people'],
                    'hardware' => $categories['hardware'],
                ]
            );

            return $this->json(array_merge($categories, ['response' => $response]));
        } catch (PoligonException $e) {
            return new Response($e->getResponse()->getContent(false));
        }
    }

    #[Route('/s02/e05')]
    public function e05(string $projectDir, string $poligonApiKey, Poligon $poligon): Response
    {
        $dom = new DOMDocument();
        $dom->loadHTML(file_get_contents('https://centrala.ag3nts.org/dane/arxiv-draft.html'), LIBXML_NOERROR);

        $xpath = new \DOMXPath($dom);
        $contentNode = $xpath->query('//div[@class="container"]');

        $contentNode = $contentNode->item(0);



        $context = 'Na podane przez użytkownika pytania udziel krótkich, jednozdaniowych odpowiedzi, korzystając z danych z dokumentu poniżej. 
        Każdą odpowiedź poprzedź numerem pytania w następujący sposób:
        Jeśli wiadomość od użytkownika miała postać:
01=treść pytania pierwszego
02=treść pytania drugiego
Odpowiedź powinna mieć postać:
01=treść odpowiedzi na pytanie pierwsze
02=treść odpowiedzi na pytanie drugie
        ';

        $context .= 'Dokument:
\n\n';
        foreach ($contentNode->childNodes as $node) {
            if (!$node) {
                continue;
            }
            $context .= match ($node->nodeName) {
                'h1' => '# ' . $node->textContent . "\n\n",
                'h2' => '## ' . $node->textContent . "\n\n",
                'p' => $node->textContent . "\n\n",
                'figure' => $this->describeImage($node, 'https://centrala.ag3nts.org/dane'), // 'Obrazek!' . "\n\n",
                'audio' => $this->transcriptAudio($projectDir, $node),
                default => '',
            };
        }

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
                    'content' => '01=jakiego owocu użyto podczas pierwszej próby transmisji materii w czasie?
02=Na rynku którego miasta wykonano testową fotografię użytą podczas testu przesyłania multimediów?
03=Co Bomba chciał znaleźć w Grudziądzu?
04=Resztki jakiego dania zostały pozostawione przez Rafała?
05=Od czego pochodzą litery BNW w nazwie nowego modelu językowego?',
                ],
            ],
        ]);


        return new Response($chat->choices[0]->message->content);

//        try {
//            $response = $poligon->send('https://centrala.ag3nts.org/report', 'mp3',
//                'ul. Łojasiewicza');
//
//            return new Response($response);
//        } catch (PoligonException $e) {
//            return new Response($e->getResponse()->getContent(false));
//        }
    }

    #[Route('/s02/e05-final')]
    public function e05Final(string $projectDir, string $poligonApiKey, Poligon $poligon): Response
    {
        $aiResponse = '01=Użyto owocu w postaci truskawki podczas pierwszej próby transmisji materii w czasie. 
        02=Testową fotografię wykonano na rynku w Grudziądzu. 
        03=Bomba chciał znaleźć hotel w Grudziądzu. 
        04=Resztki ciasta zostały pozostawione przez Rafała. 
        05=Litery BNW w nazwie nowego modelu językowego pochodzą od "Brave New World" – Nowy Wspaniały Świat.';

        try {
            $response = $poligon->send(
                'https://centrala.ag3nts.org/report',
                'arxiv',
                [
                    '01' => 'Użyto owocu w postaci truskawki podczas pierwszej próby transmisji materii w czasie.',
                    '02' => 'Testową fotografię wykonano na rynku w Krakowie.',
                    '03' => 'Bomba chciał znaleźć hotel w Grudziądzu.',
                    '04' => 'Resztki ciasta pizzy zostały pozostawione przez Rafała.',
                    '05' => 'Litery BNW w nazwie nowego modelu językowego pochodzą od "Brave New World" – Nowy Wspaniały Świat.'
                ]
            );

            return new Response($response);
        } catch (PoligonException $e) {
            return new Response($e->getResponse()->getContent(false));
        }
    }

    private function transcriptAudio(string $projectDir, \DOMNode $audioNode): string
    {
        // get source node
        $sourceNode = $audioNode->getElementsByTagName('source')->item(0);
        $audioUrl = $sourceNode->getAttribute('src');
        $audioUrl = basename($audioUrl);
        $transcriptFileName = $projectDir . '/data/s02e05/' . $audioUrl . '.transcript';
        if (!file_exists($transcriptFileName)) {
            return "Oczekiwano transkrypcji dla pliku $transcriptFileName";
        }
        return
            "Transkrypcja dla pliku $audioUrl:\n" .
            file_get_contents($transcriptFileName) .
            "\n\n";
    }

    private function describeImage(\DOMElement $figureNode, string $baseUrl): string
    {
        // $imageNode is a figure tag
        // get an img node from the figure, get a full url
        // get a text  from the figure caption
        // send both text and image to the AI, ask from the description
        // return the description
        $imgNodes = $figureNode->getElementsByTagName('img');
        foreach($imgNodes as $imgNode) {
            $imgSrc = $imgNode->getAttribute('src');
            if ($imgSrc) {
                break;
            }
        }
        $imgSrc = $baseUrl . '/' . $imgSrc; // $imgNode->getAttribute('src');
        $caption = $figureNode->getElementsByTagName('figcaption')->item(0)->textContent;
        $client = $this->openAIFactory->getClient();
        $chat = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Potrzebuję opisu obrazka przekazanego przez użytkownika. Użyj podpisu dla kontekstu.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image_url',
                            'image_url' => ['url' => $imgSrc]
                        ],
                        [
                            'type' => 'text',
                            'text' => $caption
                        ]
                    ]
                ],
            ],
        ]);
        return
        "tu znajduje się zdjęcie przedstawiające następującą treść: \n\n" .
//        "$imgSrc \n\n" .
            $chat->choices[0]->message->content . "\n\n" .
            "Podpisane w dokumencie jako $caption\n\n";

    }
}