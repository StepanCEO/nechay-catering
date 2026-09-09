<?php
/** Админка: вход, список заявок, статусы, сводка. */

declare(strict_types=1);

/* Если что-то падает, хостинг по умолчанию отдаёт пустую страницу —
   браузер пишет «сайт ничего не отправил в ответ». Ловим и показываем
   понятный текст: без него причину не найти. */
set_error_handler(function ($no, $str, $file, $line) {
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
       . '<title>Ошибка</title><link rel="stylesheet" href="style.css"></head>'
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

/* ---------- смена статуса ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['status'], $_POST['id'])) {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Просроченная форма, обнови страницу.');
    }
    $allowed = ['new', 'work', 'done', 'reject'];
    if (in_array($_POST['status'], $allowed, true)) {
        $st = db()->prepare('UPDATE leads SET status = ? WHERE id = ?');
        $st->execute([$_POST['status'], (int)$_POST['id']]);
    }
    header('Location: index.php' . (isset($_GET['f']) ? '?f=' . urlencode((string)$_GET['f']) : ''));
    exit;
}

/* ---------- данные ---------- */
$filter = $_GET['f'] ?? 'all';
$sql    = 'SELECT * FROM leads';
$args   = [];
if (in_array($filter, ['new', 'work', 'done', 'reject'], true)) {
    $sql .= ' WHERE status = ?';
    $args[] = $filter;
}
$sql .= ' ORDER BY created_at DESC LIMIT 300';
$st = db()->prepare($sql);
$st->execute($args);
$leads = $st->fetchAll();

$counts = [];
foreach (db()->query('SELECT status, COUNT(*) c FROM leads GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['c'];
}
$total = array_sum($counts);
$week  = (int)db()->query(
    'SELECT COUNT(*) FROM leads WHERE created_at > (NOW() - INTERVAL 7 DAY)'
)->fetchColumn();

$metrika = metrika_summary($cfg['metrika'] ?? []);

render_panel($leads, $counts, $total, $week, $filter, $metrika, $_SESSION['csrf']);


/* ============================================================ */

/** Сводка визитов из Метрики. Пусто, если токен не задан. */
function metrika_summary(array $m): ?array
{
    if (empty($m['counter']) || empty($m['token'])) {
        return null;
    }
    $url = 'https://api-metrika.yandex.net/stat/v1/data?' . http_build_query([
        'ids'     => $m['counter'],
        'metrics' => 'ym:s:visits,ym:s:users',
        'date1'   => '7daysAgo',
        'date2'   => 'today',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => ['Authorization: OAuth ' . $m['token']],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if (!$res) {
        return null;
    }
    $d = json_decode($res, true);
    $totals = $d['totals'][0] ?? null;
    if (!is_array($totals)) {
        return null;
    }
    return ['visits' => (int)$totals[0], 'users' => (int)($totals[1] ?? 0)];
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function render_login(string $error): void
{
    ?><!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Вход — Нечай</title>
<link rel="stylesheet" href="style.css">
</head><body class="login-page">
  <form class="card login" method="post">
    <h1>Заявки «Нечай»</h1>
    <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
    <label>Пароль
      <input type="password" name="password" autocomplete="current-password" autofocus required>
    </label>
    <button type="submit">Войти</button>
  </form>
</body></html><?php
}

function render_panel(
    array $leads, array $counts, int $total, int $week,
    string $filter, ?array $metrika, string $csrf
): void {
    $titles = ['new' => 'Новая', 'work' => 'В работе', 'done' => 'Успех', 'reject' => 'Отказ'];
    $tabs   = ['all' => 'Все'] + $titles;
    ?><!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Заявки — Нечай</title>
<link rel="stylesheet" href="style.css">
</head><body>
<header class="top">
  <h1>Заявки «Нечай»</h1>
  <a class="logout" href="?logout=1">Выйти</a>
</header>

<section class="stats">
  <div class="stat"><b><?= $total ?></b><span>всего заявок</span></div>
  <div class="stat"><b><?= $week ?></b><span>за неделю</span></div>
  <div class="stat"><b><?= $counts['new'] ?? 0 ?></b><span>не обработано</span></div>
  <?php if ($metrika): ?>
    <div class="stat"><b><?= $metrika['visits'] ?></b><span>визитов за 7 дней</span></div>
    <div class="stat"><b><?= $metrika['users'] ?></b><span>посетителей</span></div>
  <?php endif; ?>
</section>

<nav class="tabs">
  <?php foreach ($tabs as $k => $label):
      $n = $k === 'all' ? $total : ($counts[$k] ?? 0); ?>
    <a href="?f=<?= h($k) ?>" class="<?= $filter === $k ? 'on' : '' ?>">
      <?= h($label) ?> <i><?= $n ?></i>
    </a>
  <?php endforeach; ?>
</nav>

<?php if (!$leads): ?>
  <p class="empty">Заявок пока нет.</p>
<?php else: ?>
  <div class="list">
  <?php foreach ($leads as $l): ?>
    <article class="card lead st-<?= h($l['status']) ?>">
      <div class="lead__head">
        <div>
          <b class="lead__name"><?= h($l['name']) ?></b>
          <a class="lead__phone" href="tel:<?= h(preg_replace('/[^\d+]/', '', $l['phone'])) ?>">
            <?= h($l['phone']) ?>
          </a>
        </div>
        <time><?= h(date('d.m.Y H:i', strtotime($l['created_at']))) ?></time>
      </div>

      <dl class="lead__meta">
        <?php foreach ([
            'Формат' => $l['format'],
            'Гостей' => $l['guests'],
            'Дата'   => $l['event_date'],
        ] as $k => $v): if ($v === '' || $v === null) continue; ?>
          <dt><?= h($k) ?></dt><dd><?= h($v) ?></dd>
        <?php endforeach; ?>
      </dl>

      <?php if (!empty($l['comment'])): ?>
        <p class="lead__comment"><?= nl2br(h($l['comment'])) ?></p>
      <?php endif; ?>

      <form class="lead__status" method="post" action="?f=<?= h($filter) ?>">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <?php foreach ($titles as $k => $label): ?>
          <button type="submit" name="status" value="<?= h($k) ?>"
                  class="<?= $l['status'] === $k ? 'on' : '' ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
      </form>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</body></html><?php
}
