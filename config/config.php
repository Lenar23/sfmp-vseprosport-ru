<?php
define('ROOT', dirname(__DIR__));
const ERROR_LOGS = ROOT . '/tmp/errors.log';
const DB_SETTINGS = [
    'driver' => 'pgsql',
    'host' => 'localhost',
    'port' => '5432',
    'username' => 'postgres',
    'password' => 'postgresql',
    'database' => 'sfmp',
    'charset' => 'utf8',
    'prefix' => '',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
];
