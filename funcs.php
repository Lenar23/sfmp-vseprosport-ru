<?php
declare(strict_types = 1);

use GuzzleHttp\Client;
use DiDom\Document;
use GuzzleHttp\Exception\GuzzleException;

/**
 * @throws GuzzleException
 */
function get_html(string $url, Client $client): ?string
{
    try {
        $client->request('GET', $url);
    } catch (GuzzleException $e) {
        error_log(
            "[" . date("Y-m-d H:i:s") . "] Error:  . {$e->getMessage()}" . PHP_EOL . "File: {$e->getFile()}" . PHP_EOL . "Line: {$e->getLine()}" . PHP_EOL . '=============' . PHP_EOL,
            3,
            ERROR_LOGS
        );
        abort("Page $url not found. Code 404.\n");
        return null;
    }

    return $client->get($url)->getBody()->getContents();
}

function get_pages_count(Document $document): string|int
{
    $pagination = $document->find('.pagination a.page-link');

    if (count($pagination) > 1) {
        return $pagination[count($pagination) - 2]->text();
    } else {
        return 1;
    }
}

function get_forecasts(Document $document, Client $client): array
{
    static $forecastsCnt = 1;
    $forecastsData = [];
    $forecasts = $document->find('div.forecast-list a');

    foreach ($forecasts as $forecast) {
        if (!$forecast->has('a div')) {
            continue;
        }

        sleep(rand(1, 3));
        $uri = $forecast->first('a')->attr('href');
        if (!$forecast = get_forecast($document, $client, URL . "/{$uri}"))
            continue;
        echo "forecast {$forecastsCnt}...\n";
        $forecastsData[$forecastsCnt] = $forecast;
        $forecastsCnt++;
    }

    return $forecastsData;
}

function get_forecast(Document $document, Client $client, string $url): null|array
{
    $forecast = [];
    $html = get_html($url, $client);
    $document->loadHtml($html);

    if ($document->has('h1.h1')) {
        $forecast['title'] = $document->first('h1.h1')->text();
        $forecast['author']['name'] = $document->first('div.author-info div.d-flex span')->text();
        $forecast['author']['photo_url'] = URL . $document->first('div.author-info source[type="image/jpg"]')->attr('srcset');
        $forecast['bets'] = get_bets($document);
        $forecast['bookmaker'] = get_bookmakers($document);
        $forecast['description'] = $document->first('div p')->text();
        $forecast['content'] = get_content_forecasts($document);
        $forecast['posted_at'] = get_posted_time($document) ?? (new DateTime())->getTimestamp();
        $forecast['teams'] = get_teams($document);
        $forecast['tournament'] = $document->first('div.top-prediction div.tournamentplace span')->text();
        $forecast['sport'] = $document->first('div.author-info span.sport')->text();
        $forecast['event_time'] = $document->first('div.col-4 time')->attr('datetime');
    } else {
        return null;
    }

    return $forecast;
}

function get_posted_time(Document $document): ?int
{
    if (!$document->find('span.published')) {
        return (new DateTime('now'))->getTimestamp();
    }

     preg_match('/\d+/', $document->first('span.published')->text(), $matches);

     return (new DateTime("now -$matches[0] hours"))->getTimestamp();
}
function get_bookmakers($document): array
{
    $bookmakers = $document->find('div.bonus-item-bet picture');
    $names = [];
    foreach ($bookmakers as $book) {
        $names[] = $book->first('img')->attr('title');
    }

    return $names;
}
function get_bets(Document $document): array
{
    $rates = [];
    $bets = $document->find('div.bonus-item-bet');
    $i = 0;
    foreach ($bets as $bet) {
        $rates[get_bookmakers($document)[$i]] = (double)$bet->first('span')->text();
        $i++;
    }

    return $rates;
}
function get_content_forecasts(Document $document): array
{
    $forecasts = $document->find('section.prediction-section div.default-content div.bonus-item');
    $content = [];
    $i = 0;
    foreach ($forecasts as $forecast) {
        $text = '';
        $forecastPars = $forecast->find('p');
        foreach ($forecastPars as $forecastPar) {
            $text .= $forecastPar->text();
            if ($forecastPar->find('strong')) {
                $text .= '. ';
            }
        }
        $content[get_bookmakers($document)[$i]] = rtrim($text);
        $i++;
    }

    return $content;
}

function get_teams(Document $document): array
{
    $teams = [];
    $teamBlocks = $document->find('div.top-prediction div.row div.col-sm div.text-center');
    foreach ($teamBlocks as $team) {
        $teams[] = trim($team->first('div.team-img span')->text());
    }

    return $teams;
}

function get_connection_db(): \PDO
{
    $dsn = "pgsql:host=" . DB_SETTINGS['host'] . ";port=" . DB_SETTINGS['port'] . ";dbname=" . DB_SETTINGS['database'];

    try {
        $connection = new \PDO($dsn, DB_SETTINGS['username'], DB_SETTINGS['password'], DB_SETTINGS['options']);
    } catch (\PDOException $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] DB Error: {$e->getMessage()}" . PHP_EOL, 3, ERROR_LOGS);
        abort('Database error connection');
    }

    return $connection;
}

function abort(string $error_message): void
{
    echo $error_message;
}

function query(string $query, $params = []): PDOStatement
{
    $stmt = get_connection_db()->prepare($query);
    $stmt->execute($params);

    return $stmt;
}

function find_one(string $tbl, string $value,  $key = 'id', string $fields = '*'): mixed
{
    return query("SELECT $fields FROM {$tbl} WHERE {$key} = ? LIMIT 1",  [$value])->fetch();
}

function find_by_multiple_conditions(string $tbl, array $values = [],  array $keys = [], string $fields = '*'): mixed
{
    $conditions = "WHERE $keys[0] = ?";
    for ($i = 1; $i < count($keys); $i++) {
        $conditions .= " AND $keys[$i] = ?";
    }

    return query("SELECT $fields FROM {$tbl} {$conditions} LIMIT 1", $values)->fetch();
}

function find_by_join_request(string $major_tbl, string $single_field, array $tbls, array $values = [], array $keys = [], string $fields = '*'): mixed
{
    $join = '';
    for ($i = 0; $i <= count($tbls); $i++) {
        if ($i == count($tbls) && !empty($keys[$i])) {
            $key = $keys[$i];
            $i--;
            $join .= " JOIN $tbls[$i] AS {$tbls[$i][0]} ON {$major_tbl}.$key = {$tbls[$i][0]}.id";
            break;
        }

        $join .= " JOIN $tbls[$i] ON {$major_tbl}.$keys[$i] = {$tbls[$i]}.id";
    }

    $conditions = "WHERE {$tbls[0]}.$single_field = ?";
    for ($i = 1; $i <= count($keys); $i++) {
        if ($i == count($tbls) && !empty($keys[$i])) {
            $i--;
            $conditions .= " AND {$tbls[$i][0]}.$single_field = ?";
            break;
        }

        $conditions .= " AND {$tbls[$i]}.$single_field = ?";
    }

    return query("SELECT {$major_tbl}.$fields FROM $major_tbl{$join} {$conditions} LIMIT 1", $values)->fetch();
}
function insert_to_sources(string $name, string $tbl = 'sources'): void
{
    $query = "INSERT INTO $tbl (name, url) VALUES(?, ?)";
    $column = 'name';
    if(find_one($tbl, $name, $column, 'id'))
        return;

    query($query, [$name, URL]);
}
function insert_to_authors(array $authors, $tbl = 'authors'): void
{
    $query = "INSERT INTO $tbl (source_id, name, photo_url) VALUES(?, ?, ?)";
    $column = 'name';
    if(find_one($tbl, $authors[0], $column, 'id'))
        return;

    $tbl_name = 'sources';
    $column = 'url';
    $source_id = find_one($tbl_name, URL, $column, 'id')['id'];

    query($query, array_merge([$source_id], $authors));
}

function insert_to_forecasts(array $forecasts, array $fields, string $tbl = 'forecasts'): void
{
    $query = "INSERT INTO $tbl (
                   source_id, 
                   author_id,
                   event_id, 
                   bet_id,
                   title,
                   description, 
                   content,
                   forecast_type_id, 
                   bookmaker_id, 
                   posted_at 
                   ) 
            VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, to_timestamp(?))";

    $column = 'content';

    $fields_events = ['sport_id', 'tournament_id', 'team1_id', 'team2_id'];
    $bind_tbls = ['sports', 'tournaments', 'teams'];
    $fields_bets = ['rate', 'market_id', 'outcome_id'];

    $data['source_id'] = find_one('sources', URL, 'url', 'id')['id'];
    $data['author_id'] = find_one('authors', $fields['author'], 'name', 'id')['id'];
    $data['desc'] = $forecasts['desc'];
    $data['posted_at'] = $forecasts['posted_at'];
    $data['title'] = $forecasts['title'];
    $data['forecast_type_id'] = 1;

    $event_values = [
        $fields['event']['sport'],
        $fields['event']['tournament'],
        $fields['event']['team_1'],
        $fields['event']['team_2']
    ];

    $data['event_id'] = find_by_join_request('events',  'name', $bind_tbls, $event_values, $fields_events,'id')['id'];

    for ($i = 0; $i < count($forecasts['content']); $i++) {
        if (find_one($tbl, $forecasts['content'][$fields['bookmaker'][$i]], $column, 'id')) {
            continue;
        }

        $data['bookmaker_id'] = find_one('bookmakers', $fields['bookmaker'][$i], 'name', 'id')['id'];
        $data['bet_id'] = find_by_multiple_conditions('bets', [$fields['bet']['rate'][$fields['bookmaker'][$i]], $fields['bet']['market_id'], $fields['bet']['outcome_id']], $fields_bets, 'id')['id'];
        $data[] = $forecasts['content'][$fields['bookmaker'][$i]];

        query(
            $query,
            [
                $data['source_id'],
                $data['author_id'],
                $data['event_id'],
                $data['bet_id'],
                $data['title'],
                $data['desc'],
                $data[$i],
                $data['forecast_type_id'],
                $data['bookmaker_id'],
                $data['posted_at']
            ]
        );
    }
}

function insert_to_teams(array $teams, string $tbl = 'teams'): void
{
    $query = "INSERT INTO $tbl (name) VALUES(?)";
    $column = 'name';
    foreach($teams[0] as $team) {
        if (find_one($tbl, $team, $column, 'id'))
            continue;

        query($query, [$team]);
    }
}

function insert_to_sports(array $sports, $tbl = 'sports'): void
{
    $query = "INSERT INTO $tbl (name) VALUES(?)";
    $column = 'name';
    if(find_one($tbl, $sports[0], $column, 'id'))
        return;

    query($query, $sports);
}

function insert_to_tournaments(array $tournaments, string $sport, $tbl = 'tournaments'): void
{
    $query = "INSERT INTO $tbl (name, sport_id) VALUES(?, ?)";
    $column = 'name';
    if(find_one($tbl, $tournaments[0], $column, 'id'))
        return;

    $sport_id = find_one('sports', $sport, 'name', 'id');
    array_push($tournaments, $sport_id['id']);

    query($query, $tournaments);
}

function insert_to_events(array $events, $tbl = 'events'): void
{
    $ids = [];
    $tbls_name = [
        'sports',
        'tournaments',
        'teams',
    ];
    $query = "INSERT INTO $tbl (sport_id, tournament_id, team1_id, team2_id) VALUES(?, ?, ?, ?)";

    for ($i = 0, $j = 0; $j < count($events); $j++) {
        $ids[] = find_one($tbls_name[$i], $events[$j], 'name', 'id')['id'];

        if ($i < count($tbls_name) - 1) {
            $i++;
        }
    }

    $fields = ['sport_id', 'tournament_id', 'team1_id', 'team2_id'];
    if (find_by_multiple_conditions($tbl, $ids, $fields, 'id')) {
        return;
    }

    query($query, $ids);
}

function insert_to_bets(array $bets, $tbl = 'bets'): void
{
    $fields = ['rate', 'market_id', 'outcome_id'];
    $query = "INSERT INTO $tbl (rate, market_id, outcome_id) VALUES(?, ?, ?)";

    foreach ($bets as $bet) {
        if (find_by_multiple_conditions($tbl, [$bet, 1, 1], $fields, 'id')) {
            continue;
        }

        query($query, [$bet, 1, 1]);
    }
}

function insert_to_bookmakers(array $bookmakers, $tbl = 'bookmakers'): void
{
    $query = "INSERT INTO $tbl (name) VALUES(?)";
    $column = 'name';
    foreach($bookmakers as $bookmaker) {
        if (find_one($tbl, $bookmaker, $column, 'id'))
            return;
        query($query, [$bookmaker]);
    }
}

function insert_to_forecast_type(string $type, string $tbl = 'forecast_types'): void
{
    $query = "INSERT INTO $tbl (name) VALUES(?)";
    $column = 'name';
    if (find_one($tbl, $type, $column, 'id'))
            return;

    query($query, [$type]);
}
