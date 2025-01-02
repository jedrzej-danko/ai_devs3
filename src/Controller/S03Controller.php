<?php

namespace App\Controller;

use App\Service\OpenAIFactory;
use App\Service\Poligon;
use App\Service\PoligonException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class S03Controller extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private HttpClientInterface $httpClient,
        private OpenAIFactory $openAIFactory,
    )
    {
    }

    #[Route('/s03/e01')]
    public function e01(string $projectDir, Poligon $poligon)
    {
        $context = 'Z przedstawionego dokumentu wyciągnij wszystkie słowa kluczowe, które mogą się do niego odnosić.
        Jeśli to możliwe, uwzględnij datę i czas, wszystkie informacje dotyczące lokalizacji, nazwy osób i inne nazwy własne,
        a także wszelkie inne informacje, które mogą być istotne.
        Z nazwy pliku pozyskaj datę i nazwę sektora w postaci litery i cyfry (np. sektor C4). 
        Rzeczowniki zwracaj w postaci mianownika liczby pojedynczej. 
        Nie pomijaj dodatkowych określeń rzeczownika (np. jednostka organiczna).
        Uwzględnij istotne czynności (np. wykrycie /  patrol kontynuowany / patrol zakończony itp). 
        Listę słów kluczowych przedstaw w postaci listy, oddzielonej przecinkami.
        Nie dodawaj etykiet ("data", "czas", "imię" itp)
        W słowach kluczowych nie używaj znaków interpunkcyjnych (w tym dwukropka).      
        
        Dla każdego słowa kluczowego będącego imieniem i nazwiskiem wykorzystaj bazę faktów do utworzenia dodatkowych słów kluczowych,
        opisujących osobę (np. Jan Kowalski -> Jan Kowalski, pracownik fabryki, pracownik działu kontroli).
        
        Fakty: 
        
        ';

        $knowledgeDir = $projectDir . '/data/pliki_z_fabryki/facts';
        foreach (new \DirectoryIterator($knowledgeDir) as $fileInfo) {
            // only txt files
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'txt') {
                continue;
            }
            $content = file_get_contents($fileInfo->getPathname());
            $context .= "$content\n";
        }

//        $knowledgeDir = $projectDir . '/data/pliki_z_fabryki';
//        foreach (new \DirectoryIterator($knowledgeDir) as $fileInfo) {
//            // only txt files
//            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'txt') {
//                continue;
//            }
//            $content = file_get_contents($fileInfo->getPathname());
//            $context .= "<document>
//$content
//</document>";
//        }
//        return new Response($context);

        $dataDir = $projectDir . '/data/pliki_z_fabryki';
        $client = $this->openAIFactory->getClient();

        $dir = new \DirectoryIterator($dataDir);
        $result = [];
        foreach ($dir as $fileInfo) {
            // only txt files
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'txt') {
                continue;
            }
            $content = file_get_contents($fileInfo->getPathname());
            $chat = $client->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $context,
                    ],
                    [
                        'role' => 'user',
                        'content' => "nazwa pliku: {$fileInfo->getFilename()}
                        {$content}",
                    ],
                ],
            ]);

            $result[$fileInfo->getFilename()] = $chat->choices[0]->message->content;
        }

        try {
            $response = $poligon->send('https://centrala.ag3nts.org/report', 'dokumenty',
                $result
            );

            return $this->json(array_merge($result, ['response' => $response]));
        } catch (PoligonException $e) {
            $result['response'] = $e->getResponse()->getContent(false);
            return $this->json($result);
        }


//        $chat = $client->chat()->create([
//            'model' => 'gpt-4o-mini',
//            'messages' => [
//                [
//                    'role' => 'system',
//                    'content' => $context,
//                ],
//                [
//                    'role' => 'user',
//                    'content' => 'nazwa pliku: 2024-11-12_report-00-sektor_C4.txt
//                    Godzina 22:43. Wykryto jednostkę organiczną w pobliżu północnego skrzydła fabryki. Osobnik przedstawił się jako Aleksander Ragowski. Przeprowadzono skan biometryczny, zgodność z bazą danych potwierdzona. Jednostka przekazana do działu kontroli. Patrol kontynuowany.',
//                ],
//            ],
//        ]);
//        return new Response($chat->choices[0]->message->content);
    }
}