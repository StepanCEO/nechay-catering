<?php
/**
 * Проверка подключения Метрики. Открывается только из-под входа в админку.
 * Показывает, какой именно config.php прочитал PHP и что отвечает API —
 * токен целиком не печатает.
 */

declare(strict_types=1);

require __DIR__ . '/../api/db.php';
require __DIR__ . '/../api/metrika.php';

session_start();
if (empty($_SESSION['ok'])) {
    http_response_code(403);
    exit('Сначала войдите в админку: /admin/');
}

$path = realpath(__DIR__ . '/../api/config.php') ?: (__DIR__ . '/../api/config.php');
$cfg  = cfg();
$m    = $cfg['metrika'] ?? [];
$tok  = (string)($m['token'] ?? '');
$cnt  = (string)($m['counter'] ?? '');

/* живой запрос мимо кэша — интересен сам код ответа */
$http = null; $body = '';
if ($tok !== '' && $cnt !== '' && function_exists('curl_init')) {
    $ch = curl_init('https://api-metrika.yandex.net/stat/v1/data?' . http_build_query([
        'ids' => $cnt, 'metrics' => 'ym:s:visits', 'date1' => '7daysAgo', 'date2' => 'today',
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: OAuth ' . $tok],
    ]);
    $body = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
}

function row(string $label, string $value, ?bool $ok = null): void
{
    $mark = $ok === null ? '' : ($ok ? '✓ ' : '✗ ');
    $col  = $ok === null ? 'var(--ink)' : ($ok ? 'var(--ok)' : 'var(--bad)');
    echo '<dt>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt>'
       . '<dd style="color:' . $col . '">' . $mark
       . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</dd>';
}
?><!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Проверка Метрики — Нечай</title>
<link rel="stylesheet" href="style.css?v=2">
<style>
  dl.check{ display:grid; grid-template-columns:auto 1fr; gap:10px 20px; margin:0; }
  dl.check dt{ color:var(--soft); }
  dl.check dd{ margin:0; word-break:break-all; }
  pre.raw{
    margin:16px 0 0; padding:12px 14px; background:var(--cream-2);
    border-radius:10px; font-size:12.5px; white-space:pre-wrap; word-break:break-all;
  }
</style>
</head><body>
<header class="top">
  <div class="top__brand"><b>Нечай</b>
    <nav class="top__nav"><a href="index.php">← к заявкам</a></nav>
  </div>
</header>
<main>
  <section class="card block">
    <h2>Что видит PHP</h2>
    <dl class="check">
      <?php
        row('Файл настроек', $path, is_file($path));
        row('Изменён', is_file($path) ? date('d.m.Y H:i', (int)filemtime($path)) : 'файла нет');
        row('Номер счётчика', $cnt !== '' ? $cnt : 'пусто', $cnt !== '');
        row(
            'Токен',
            $tok !== ''
                ? 'задан, ' . strlen($tok) . ' символов, начинается на «' . substr($tok, 0, 4) . '…»'
                : 'пусто',
            $tok !== ''
        );
        row('Библиотека cURL', function_exists('curl_init') ? 'есть' : 'нет', function_exists('curl_init'));
        row('Версия PHP', PHP_VERSION);
        if ($http !== null) {
            row('Ответ API Метрики', 'код ' . $http, $http === 200);
        }
      ?>
    </dl>

    <?php if ($http !== null && $http !== 200): ?>
      <pre class="raw"><?= htmlspecialchars(mb_substr($body, 0, 500), ENT_QUOTES, 'UTF-8') ?></pre>
    <?php endif; ?>

    <?php if ($tok === ''): ?>
      <p class="hint">
        Токена в этом файле нет. Значит правился другой файл — сверь путь выше
        с тем, что открыт в файловом менеджере, и посмотри на дату изменения:
        если она старая, правка не сохранилась.
      </p>
    <?php elseif ($http === 200): ?>
      <p class="hint">Всё работает — открывай «Аналитику».
        Данные обновляются раз в 10 минут.</p>
    <?php elseif ($http === 403 && strpos($body, 'invalid_token') !== false): ?>
      <p class="hint">
        Яндекс не принял токен. Чаще всего он скопирован не целиком —
        проверь, что строка совпадает с той, что показал Яндекс,
        от первого символа до последнего, без пробелов по краям.
      </p>
    <?php elseif ($http === 403): ?>
      <p class="hint">
        Токен рабочий, но доступа к счётчику <?= htmlspecialchars($cnt, ENT_QUOTES, 'UTF-8') ?>
        у него нет. Обычно это значит, что токен получен под другим аккаунтом
        Яндекса — не тем, на котором заведён счётчик.
      </p>
    <?php elseif ($http === 401): ?>
      <p class="hint">Токен просрочен или скопирован не целиком. Получи новый.</p>
    <?php endif; ?>
  </section>

  <p class="hint">Страница нужна только для настройки — потом её можно удалить
    с сервера, файл <code>admin/check.php</code>.</p>
</main>
</body></html>
