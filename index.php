<?php

set_time_limit(0);
ini_set('memory_limit', '-1');

use DiDom\Document;
use GuzzleHttp\Client;

if (PHP_MAJOR_VERSION < 8) {
    die('Require PHP version >= 8.0');
}

require_once  __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/funcs.php';

const URL = 'https://www.vseprosport.ru';

if (!empty($argv[1])) {
    $client = new Client();
    $document = new Document();
    $url = $argv[1];

    echo "Start processing...\n";
    $html = get_html($url, $client);
    if (!$html) {
        die;
    }
    $document->loadHtml($html);

    $pagesCount = get_pages_count($document);
    $forecastsData = [];
    for ($i = 1; $i <= $pagesCount; $i++) {
        echo "PARSING PAGE {$i} of {$pagesCount}...\n";
        sleep(rand(1, 3));

        if ($i > 1) {
            $html = get_html($url . "/{$i}", $client);
            if (!$html) {
                continue;
            }

            $document->loadHtml($html);
        }

        $forecastsData =  array_merge($forecastsData, get_forecasts($document, $client));
    }

    echo "Finish processing...\n";
}

$prCnt = count($forecastsData);
echo "\n=========================================\n";
echo "Completed! Items received: $prCnt\n";

echo "It is saving in database...\n";
    foreach ($forecastsData as $forecastsDatum) {
        try {
            insert_to_sources('vseprosport.ru');
            insert_to_authors([$forecastsDatum['author']['name'], $forecastsDatum['author']['photo_url']]);
            insert_to_sports([$forecastsDatum['sport']]);
            insert_to_tournaments([$forecastsDatum['tournament']], $forecastsDatum['sport']);
            insert_to_teams([$forecastsDatum['teams']]);
            insert_to_bets($forecastsDatum['bets']);
            insert_to_bookmakers($forecastsDatum['bookmaker']);
            insert_to_forecast_type('ординар');
            insert_to_events([$forecastsDatum['sport'], $forecastsDatum['tournament'], $forecastsDatum['teams'][0], $forecastsDatum['teams'][1]]);
            insert_to_forecasts([
                'title' => $forecastsDatum['title'],
                'desc' => $forecastsDatum['description'],
                'content' => $forecastsDatum['content'],
                'posted_at' => $forecastsDatum['posted_at']
            ],
            [
                'author' => $forecastsDatum['author']['name'],
                'event' => [
                    'sport' => $forecastsDatum['sport'],
                    'tournament' => $forecastsDatum['tournament'],
                    'team_1' => $forecastsDatum['teams'][0],
                    'team_2' => $forecastsDatum['teams'][1]
                ],
                'bet' => [
                    'rate' => $forecastsDatum['bets'],
                    'market_id' => 1,
                    'outcome_id' => 1
                ],
                'bookmaker' => $forecastsDatum['bookmaker']
            ]);
        } catch (\PDOException $e) {
            error_log("[" . date("Y-m-d H:i:s") . "] Error:  . {$e->getMessage()}" . PHP_EOL . "File: {$e->getFile()}" . PHP_EOL . "Line: {$e->getLine()}" . PHP_EOL . '=============' . PHP_EOL, 3,ERROR_LOGS);
        }
    }
echo "Saving completed!\n";