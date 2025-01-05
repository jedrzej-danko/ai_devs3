<?php

namespace App\Controller;

use App\Service\OpenAIFactory;
use App\Service\Poligon;
use App\Service\PoligonException;
use App\Solution\S03E01;
use App\Solution\S03E02;
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
    public function e01(string $projectDir, Poligon $poligon, S03E01 $solution)
    {
        $knowledgeDir = $projectDir . '/data/pliki_z_fabryki/facts';
        $facts = new \DirectoryIterator($knowledgeDir);

        $dataDir = $projectDir . '/data/pliki_z_fabryki';

        $dir = new \DirectoryIterator($dataDir);
        $result = [];
        foreach ($dir as $report) {
            // only txt files
            if (!$report->isFile() || $report->getExtension() !== 'txt') {
                continue;
            }

            $keywords = $solution->solve($facts, $report);

            $result[$report->getFilename()] = join(', ', $keywords);
        }

        try {
            $response = $poligon->send(
                'https://centrala.ag3nts.org/report',
                'dokumenty',
                $result
            );

            return $this->json(array_merge($result, ['response' => $response]));
        } catch (PoligonException $e) {
            $result['response'] = $e->getResponse()->getContent(false);
            return $this->json($result);
        }
    }

    #[Route('/s03/e02')]
    public function e02(Poligon $poligon, string $projectDir, S03E02 $solution): Response
    {
        $result = $solution->solve(
            $projectDir . '/data/pliki_z_fabryki/',
            'W raporcie, z którego dnia znajduje się wzmianka o kradzieży prototypu broni?'
        );
        $date = null;
        foreach ($result['result'] as $item) {
            $date = $item['payload']['date'];
        }
        try {
            $response = $poligon->send(
                'https://centrala.ag3nts.org/report',
                'wektory',
                $date
            );

            return $this->json(
                array_merge(
                    $result['result'],
                    ['date' => $date],
                    ['response' => $response]
                ));
        } catch (PoligonException $e) {
            $result = $result['result'];
            $result['response'] = $e->getResponse()->getContent(false);
            $result['date'] = $date;
            return $this->json($result);
        }
    }
}