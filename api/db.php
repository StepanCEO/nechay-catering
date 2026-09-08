<?php
/** Подключение к базе и создание таблицы при первом запуске. */

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            exit('Нет api/config.php — скопируй config.sample.php и заполни.');
        }
        $c = require $path;
    }
    return $c;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg()['db'];
        $pdo = new PDO(
            "mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4",
            $d['user'],
            $d['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leads (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME     NOT NULL,
                name       VARCHAR(120) NOT NULL,
                phone      VARCHAR(60)  NOT NULL,
                format     VARCHAR(80)  DEFAULT '',
                guests     VARCHAR(20)  DEFAULT '',
                event_date VARCHAR(20)  DEFAULT '',
                comment    TEXT,
                status     VARCHAR(20)  NOT NULL DEFAULT 'new',
                INDEX (created_at),
                INDEX (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    return $pdo;
}
