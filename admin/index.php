<?php
/**
 * Админка: вход, заявки со статусами и корзиной, аналитика.
 * Разметка вынесена в view.php, запросы к Метрике — в api/metrika.php.
 */

declare(strict_types=1);

/* Если что-то падает, хостинг по умолчанию отдаёт пустую страницу —
   браузер пишет «сайт ничего не отправил в ответ». Ловим и показываем
   понятный текст: без него причину не найти. */
set_error_handler(function ($no, $str, $file, $line) {
    // мелочь вроде deprecated только пишем в лог: из-за смены версии PHP
    // на хостинге админка не должна падать целиком
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE)) {
        error_log('admin: ' . $str . ' @ ' . $file . ':' . $line);
        return true;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});
set_exception_handler(function (Throwable $e) {
    error_log('admin: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $msg = $e->getMessage();
    $hint = '';
    if (stripos($msg, 'unknown database') !== false) {
        $hint = 'Не найдена база с таким именем — проверь <code>db.name</code> в api/config.php. '
              . 'У Timeweb имя обычно с префиксом логина, вроде <code>cm483206_что-то</code>.';
    } elseif (stripos($msg, 'access denied') !== false) {
        $hint = 'База отказала в доступе — проверь <code>db.user</code> и <code>db.pass</code> в api/config.php.';
    } elseif (stripos($msg, 'connection refused') !== false || stripos($msg, "can't connect") !== false) {
        $hint = 'Сервер базы не отвечает — проверь <code>db.host</code>, обычно это <code>localhost</code>.';
    }
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Ошибка</title><link rel="stylesheet" href="style.css?v=2"></head>'
       . '<body class="login-page"><div class="card login">'
       . '<h1>Админка не открылась</h1>'
       . ($hint ? '<p>' . $hint . '</p>' : '')
       . '<p class="err">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p style="font-size:12.5px;color:var(--soft)">'
       . htmlspecialchars(basename($e->getFile()), ENT_QUOTES, 'UTF-8') . ', строка ' . (int)$e->getLine()
       . '</p></div></body></html>';
    exit;
});

require __DIR__ . '/../api/db.php';
require __DIR__ . '/../api/metrika.php';
require __DIR__ . '/view.php';

session_start();

$cfg    = cfg();
$hash   = $cfg['admin_password_hash'] ?? '';
$authed = !empty($_SESSION['ok']);

/* ---------- вход и выход ---------- */
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$error = '';
if (!$authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    if ($hash === '') {
        $error = 'В config.php не задан admin_password_hash.';
    } elseif (password_verify((string)$_POST['password'], $hash)) {
        session_regenerate_id(true);
        $_SESSION['ok']   = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: index.php');
        exit;
    } else {
        sleep(1);                       // притормаживаем подбор
        $error = 'Неверный пароль.';
    }
}

if (!$authed) {
    render_login($error);
    exit;
}

$csrf   = $_SESSION['csrf'];
$page   = ($_GET['p'] ?? 'leads') === 'stats' ? 'stats' : 'leads';
$filter = (string)($_GET['f'] ?? 'all');
$q      = trim((string)($_GET['q'] ?? ''));
$back   = 'index.php?p=leads&f=' . urlencode($filter) . ($q !== '' ? '&q=' . urlencode($q) : '');

/* ---------- действия над заявкой ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Просроченная форма, обнови страницу.');
    }
    $id = (int)($_POST['id'] ?? 0);

    if (isset($_POST['status']) && in_array($_POST['status'], array_keys(STATUS_TITLES), true)) {
        $st = db()->prepare('UPDATE leads SET status = ? WHERE id = ?');
        $st->execute([$_POST['status'], $id]);
    } elseif (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('UPDATE leads SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
    } elseif (($_POST['action'] ?? '') === 'restore') {
        db()->prepare('UPDATE leads SET deleted_at = NULL WHERE id = ?')->execute([$id]);
    } elseif (($_POST['action'] ?? '') === 'purge') {
        // насовсем — и только из корзины, случайно сюда не попасть
        db()->prepare('DELETE FROM leads WHERE id = ? AND deleted_at IS NOT NULL')->execute([$id]);
    }

    header('Location: ' . $back);
    exit;
}

/* ---------- выгрузка в CSV ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    export_csv(fetch_leads($filter, $q));
    exit;
}

/* ---------- страницы ---------- */
if ($page === 'stats') {
    render_stats(collect_stats($cfg));
} else {
    $counts = [];
    foreach (db()->query(
        'SELECT status, COUNT(*) c FROM leads WHERE deleted_at IS NULL GROUP BY status'
    ) as $r) {
        $counts[$r['status']] = (int)$r['c'];
    }
    $total = array_sum($counts);
    $week  = (int)db()->query(
        'SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND created_at > (NOW() - INTERVAL 7 DAY)'
    )->fetchColumn();
    $month = (int)db()->query(
        'SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND created_at > (NOW() - INTERVAL 30 DAY)'
    )->fetchColumn();
    $trash = (int)db()->query('SELECT COUNT(*) FROM leads WHERE deleted_at IS NOT NULL')->fetchColumn();

    render_leads(fetch_leads($filter, $q), $counts, $total, $week, $month, $trash, $filter, $q, $csrf);
}


/* ============================================================ */

/** Заявки по текущему фильтру и поисковому запросу. */
function fetch_leads(string $filter, string $q): array
{
    $where = $filter === 'trash' ? ['deleted_at IS NOT NULL'] : ['deleted_at IS NULL'];
    $args  = [];

    if (isset(STATUS_TITLES[$filter])) {
        $where[] = 'status = ?';
        $args[]  = $filter;
    }
    if ($q !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR comment LIKE ? OR format LIKE ?)';
        $like    = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        array_push($args, $like, $like, $like, $like);
    }

    $st = db()->prepare(
        'SELECT * FROM leads WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at DESC LIMIT 500'
    );
    $st->execute($args);
    return $st->fetchAll();
}

/** Таблица заявок файлом. Разделитель — точка с запятой, так открывает Excel. */
function export_csv(array $leads): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nechay-leads-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");       // BOM, иначе Excel ломает кириллицу
    $head = ['Дата', 'Имя', 'Телефон', 'Формат', 'Гостей', 'Дата события', 'Комментарий', 'Статус'];
    fputcsv($out, $head, ';', '"', '');
    foreach ($leads as $l) {
        fputcsv($out, [
            date('d.m.Y H:i', strtotime((string)$l['created_at'])),
            $l['name'], $l['phone'], $l['format'], $l['guests'], $l['event_date'],
            (string)$l['comment'],
            STATUS_TITLES[$l['status']] ?? $l['status'],
        ], ';', '"', '');
    }
    fclose($out);
}

/** Всё, что нужно странице аналитики: свои заявки плюс данные Метрики. */
function collect_stats(array $cfg): array
{
    $days = (int)($_GET['d'] ?? 30);
    if (!in_array($days, [7, 30, 90], true)) {
        $days = 30;
    }
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $to   = date('Y-m-d');

    /* заявки по дням: сначала нули на весь период, потом заполняем из базы —
       иначе дни без заявок выпадут и график соврёт */
    $byDay = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $byDay[date('Y-m-d', strtotime('-' . $i . ' days'))] = 0;
    }
    $st = db()->prepare(
        'SELECT DATE(created_at) d, COUNT(*) c FROM leads
         WHERE deleted_at IS NULL AND DATE(created_at) >= ? GROUP BY d'
    );
    $st->execute([$from]);
    foreach ($st as $r) {
        if (isset($byDay[$r['d']])) {
            $byDay[$r['d']] = (int)$r['c'];
        }
    }

    $st = db()->prepare(
        "SELECT format, COUNT(*) c FROM leads
         WHERE deleted_at IS NULL AND format <> '' AND DATE(created_at) >= ?
         GROUP BY format ORDER BY c DESC LIMIT 8"
    );
    $st->execute([$from]);
    $rows      = $st->fetchAll();
    $formatSum = array_sum(array_column($rows, 'c'));
    $formats   = [];
    foreach ($rows as $r) {
        $formats[] = [
            'name'  => (string)$r['format'],
            'value' => (int)$r['c'],
            'share' => $formatSum > 0 ? $r['c'] / $formatSum * 100 : 0,
        ];
    }

    $data = [
        'days'          => $days,
        'from_h'        => date('d.m.Y', strtotime($from)),
        'to_h'          => date('d.m.Y', strtotime($to)),
        'leads_labels'  => array_map(
            static fn ($d) => date('d.m', strtotime($d)),
            array_keys($byDay)
        ),
        'leads_values'  => array_values($byDay),
        'leads_period'  => array_sum($byDay),
        'leads_total'   => (int)db()->query('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL')->fetchColumn(),
        'leads_week'    => (int)db()->query(
            'SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND created_at > (NOW() - INTERVAL 7 DAY)'
        )->fetchColumn(),
        'formats'       => $formats,
        'metrika'       => null,
        'metrika_error' => '',
    ];

    $m = $cfg['metrika'] ?? [];
    if (!metrika_ready($m)) {
        return $data;
    }

    $err    = null;
    $totals = metrika_totals($m, $from, $to, $err);
    if (!$totals) {
        $data['metrika_error'] = (string)$err;
        return $data;
    }

    $perDay = metrika_by_day($m, $from, $to);
    $labels = $visits = $users = [];
    foreach ($perDay as $date => $v) {
        $labels[] = date('d.m', strtotime($date));
        $visits[] = $v['visits'];
        $users[]  = $v['users'];
    }

    $data['metrika'] = [
        'totals'      => $totals,
        'days_labels' => $labels,
        'days_visits' => $visits,
        'days_users'  => $users,
        'sources'     => metrika_breakdown($m, 'ym:s:lastTrafficSource', $from, $to),
        'devices'     => metrika_breakdown($m, 'ym:s:deviceCategory', $from, $to, 5),
        'cities'      => metrika_breakdown($m, 'ym:s:regionCity', $from, $to),
        'pages'       => metrika_breakdown($m, 'ym:pv:URLPathFull', $from, $to, 8, 'ym:pv:pageviews'),
        'goals'       => metrika_goals($m, $from, $to),
    ];
    return $data;
}
