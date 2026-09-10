<?php
/**
 * Клиент Яндекс.Метрики для админки.
 *
 * Все отчёты берутся одним и тем же способом — Reporting API,
 * https://api-metrika.yandex.net/stat/v1/data. Ответы кэшируются на
 * несколько минут: без этого каждый заход в админку дёргал бы API
 * по семь раз и упирался в лимиты.
 */

declare(strict_types=1);

const METRIKA_CACHE_TTL = 600;          // 10 минут

/** Настроена ли Метрика — есть ли номер счётчика и токен. */
function metrika_ready(array $m): bool
{
    return !empty($m['counter']) && !empty($m['token']);
}

/**
 * Один запрос к API. Возвращает разобранный ответ либо null,
 * а текст ошибки кладёт в $err — админка его показывает.
 */
function metrika_query(array $m, array $params, ?string &$err = null): ?array
{
    $params += ['ids' => $m['counter'], 'accuracy' => 'full'];
    ksort($params);

    $cacheFile = sys_get_temp_dir() . '/nechay-metrika-' . md5(json_encode($params)) . '.json';
    if (is_file($cacheFile) && time() - filemtime($cacheFile) < METRIKA_CACHE_TTL) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $ch = curl_init('https://api-metrika.yandex.net/stat/v1/data?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: OAuth ' . $m['token']],
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        $err = 'Не получилось связаться с API Метрики: ' . $cerr;
        return null;
    }

    $data = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($data)) {
        $err = $data['message'] ?? ('API Метрики ответило кодом ' . $code);
        if ($code === 403) {
            $err .= '. Похоже, токен не даёт доступа к счётчику ' . $m['counter'] . '.';
        } elseif ($code === 401) {
            $err .= '. Токен просрочен или неверный — получите новый.';
        }
        return null;
    }

    @file_put_contents($cacheFile, json_encode($data));
    return $data;
}

/**
 * Итоги из ответа. В запросе без разбивки Метрика отдаёт totals плоским
 * списком чисел — по одному на метрику: "totals": [1842, 1310, …].
 * Вложенный вариант тоже встречается, поэтому разбираем оба.
 */
function metrika_totals_row(?array $d): ?array
{
    $t = $d['totals'] ?? null;
    if (is_array($t) && isset($t[0]) && is_array($t[0])) {
        $t = $t[0];
    }
    return is_array($t) ? $t : null;
}

/** Итоги за период: визиты, посетители, просмотры, отказы, глубина, время. */
function metrika_totals(array $m, string $from, string $to, ?string &$err = null): ?array
{
    $d = metrika_query($m, [
        'metrics' => 'ym:s:visits,ym:s:users,ym:s:pageviews,ym:s:bounceRate,'
                   . 'ym:s:pageDepth,ym:s:avgVisitDurationSeconds',
        'date1'   => $from,
        'date2'   => $to,
    ], $err);

    $t = metrika_totals_row($d);
    if ($t === null) {
        // молчаливый отказ хуже ошибки: без этого админка просто рисует
        // инструкцию по настройке, хотя настроено всё верно
        if ($err === null && $d !== null) {
            $err = 'Метрика ответила, но в ответе нет итогов. Ключи: '
                 . implode(', ', array_slice(array_keys($d), 0, 8)) . '.';
        }
        return null;
    }
    return [
        'visits'   => (int)round((float)$t[0]),
        'users'    => (int)round((float)($t[1] ?? 0)),
        'views'    => (int)round((float)($t[2] ?? 0)),
        'bounce'   => (float)($t[3] ?? 0),
        'depth'    => (float)($t[4] ?? 0),
        'duration' => (int)round((float)($t[5] ?? 0)),
    ];
}

/** Визиты и посетители по дням — для графика. */
function metrika_by_day(array $m, string $from, string $to, ?string &$err = null): array
{
    $d = metrika_query($m, [
        'dimensions' => 'ym:s:date',
        'metrics'    => 'ym:s:visits,ym:s:users',
        'sort'       => 'ym:s:date',
        'date1'      => $from,
        'date2'      => $to,
        'limit'      => 400,
    ], $err);

    $rows = [];
    foreach ($d['data'] ?? [] as $r) {
        $rows[(string)($r['dimensions'][0]['name'] ?? '')] = [
            'visits' => (int)round((float)$r['metrics'][0]),
            'users'  => (int)round((float)($r['metrics'][1] ?? 0)),
        ];
    }
    return $rows;
}

/**
 * Разрез визитов по любому измерению: источники, устройства, города.
 * Возвращает список [название, визиты, доля в процентах].
 */
function metrika_breakdown(
    array $m, string $dimension, string $from, string $to,
    int $limit = 8, string $metric = 'ym:s:visits', ?string &$err = null
): array {
    $d = metrika_query($m, [
        'dimensions' => $dimension,
        'metrics'    => $metric,
        'sort'       => '-' . $metric,
        'date1'      => $from,
        'date2'      => $to,
        'limit'      => $limit,
    ], $err);

    $sum   = metrika_totals_row($d);
    $total = (float)($sum[0] ?? 0);
    $rows  = [];
    foreach ($d['data'] ?? [] as $r) {
        $value = (float)$r['metrics'][0];
        $name  = (string)($r['dimensions'][0]['name'] ?? '');
        if ($name === '') {
            $name = 'Не определено';
        }
        $rows[] = [
            'name'  => $name,
            'value' => (int)round($value),
            'share' => $total > 0 ? $value / $total * 100 : 0,
        ];
    }
    return $rows;
}

/**
 * Достижения целей. Цели задаются в config.php: 'goals' => [12345 => 'Заявка'].
 * Без них блок просто не показывается — номера целей берутся в кабинете Метрики.
 */
function metrika_goals(array $m, string $from, string $to, ?string &$err = null): array
{
    $goals = $m['goals'] ?? [];
    if (!$goals) {
        return [];
    }
    $metrics = [];
    foreach (array_keys($goals) as $id) {
        $metrics[] = 'ym:s:goal' . (int)$id . 'reaches';
        $metrics[] = 'ym:s:goal' . (int)$id . 'conversionRate';
    }
    $d = metrika_query($m, [
        'metrics' => implode(',', array_slice($metrics, 0, 20)),
        'date1'   => $from,
        'date2'   => $to,
    ], $err);

    $t = metrika_totals_row($d);
    if ($t === null) {
        return [];
    }
    $out = [];
    $i   = 0;
    foreach ($goals as $id => $label) {
        $out[] = [
            'name'    => (string)$label,
            'reaches' => (int)round((float)($t[$i] ?? 0)),
            'rate'    => (float)($t[$i + 1] ?? 0),
        ];
        $i += 2;
    }
    return $out;
}
