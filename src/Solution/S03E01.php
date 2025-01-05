<?php

namespace App\Solution;

use App\Service\OpenAIFactory;
use SplFileInfo;
use Symfony\Contracts\Cache\CacheInterface;

class S03E01
{
    public const string FACTS_PROMPT = <<<EOT
Przygotuj słowa kluczowe w języku polskim w formie mianownika dla dostarczonego dokumentu.
Oto zasady, którymi musisz się kierować:

- pierwszym słowem powinna być nazwa obiektu, którego dotyczy dokument (np. Barbara Zawadzka, Sektor C)
- Słowa kluczowe muszą być w formie mianownika.
- Powinny odzwierciedlać najważniejsze cechy obiektu, o którym mówi dokument .
- Unikaj powtarzania tych samych słów, chyba że są kluczowe dla kontekstu.
- Przedstaw raport w formie listy słów kluczowych, oddzielonych przecinkami.
EOT;

    public const string REPORT_PROMPT = <<<EOT
Przygotuj słowa kluczowe w języku polskim w formie mianownika dla dostarczonego raportu. 
        Raporty dotyczą wydarzeń związanych z bezpieczeństwem wokół fabryki, opisywanych w dostarczonych plikach tekstowych. 
        Oto zasady, którymi musisz się kierować:
	1.	Kontekst raportu: Przeczytaj uważnie całą treść raportu i zidentyfikuj kluczowe informacje, takie jak:
	- Data i czas zdarzenia.
	- Miejsce (np. sektor, obiekt).
	- Osoby wymienione w raporcie.
	- Typ incydentu (np. wykrycie urządzenia, analiza odcisków palców).
	- Działania podjęte przez patrol lub inne jednostki.
	- nazwę sektora przedstaw w formie litery i cyfry oraz samej litery: "sektor C4" oraz "sektor C"
	2.	Generowanie słów kluczowych:
	- Słowa kluczowe muszą być w formie mianownika.
	- Powinny odzwierciedlać najważniejsze elementy raportu (np. “nadajnik”, “ultradźwięk”, “krzak”, 
	“Barbara Zawadzka”, “analiza odcisków palców”).
	- Unikaj powtarzania tych samych słów, chyba że są kluczowe dla kontekstu.
	3. Za każdym razem dokonaj przeglądu danych uzupełniających. 
	- użyj znajdujących się w nich informacji do uzupełnienia słów kluczowych.
    4. Przedstaw raport w formie listy słów kluczowych, oddzielonych przecinkami.
    
## Przykład wejścia (treść raportu):
Nazwa raportu: 2024-11-12_report-00-sektor_C4.txt
Godzina 22:43. Wykryto jednostkę organiczną w pobliżu północnego skrzydła fabryki. Osobnik przedstawił się jako Aleksander Ragowski. 
Przeprowadzono skan biometryczny, zgodność z bazą danych potwierdzona. Jednostka przekazana do działu kontroli. Patrol kontynuowany.

## Przykład wyjścia (słowa kluczowe):
2024-11-12, 22:43, sektor C, sektor C4, północne skrzydło fabryki, Aleksander Ragowski, skan biometryczny, zgodność z bazą danych, dział kontroli, patrol
EOT;


    private array $facts = [];
    
    public function __construct(
        protected OpenAIFactory $openAIFactory,
        protected CacheInterface $cache
    ) {
    }


    public function solve(\DirectoryIterator $facts, SplFileInfo $report): array
    {
        /** @var SplFileInfo $fact */
        foreach ($facts as $fact) {
            if (!$fact->isFile() || $fact->getExtension() !== 'txt') {
                continue;
            }
            if (!empty($this->facts[$fact->getFilename()])) {
                continue;
            }
            $keywords = $this->cache->get(
                $fact->getFilename(),
                function () use ($fact) {
                    return $this->parseFactFiles($fact);
                }
            );
            $keywords = array_map('trim', $keywords);
            $this->facts[$fact->getFilename()] = $keywords;
        }

        $reportKeywords = $this->getReportKeywords($report);

        $result = $reportKeywords;

        foreach ($reportKeywords as $keyword) {
            foreach ($this->facts as $factKeywords) {
                if (strtolower(trim($factKeywords[0])) === strtolower(trim($keyword))) {
                    $result = array_merge($result, $factKeywords);
                }
            }
        }

        return array_unique($result);
    }

    protected function parseFactFiles(SplFileInfo $fileInfo): array
    {
        $content = file_get_contents($fileInfo->getPathname());
        $response = $this->openAIFactory->getClient()->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => self::FACTS_PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ]);

        return array_map('trim', explode(',', $response['choices'][0]['message']['content']));
    }

    protected function getReportKeywords(SplFileInfo $report): array
    {
        $content = "Raport: {$report->getFilename()}\n";
        $content .= "Treść raportu: " . file_get_contents($report->getPathname());
        $response = $this->openAIFactory->getClient()->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => self::REPORT_PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ]);

        return array_map('trim', explode(',', $response['choices'][0]['message']['content']));
    }
}