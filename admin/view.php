<?php
/** Разметка админки: вход, шапка, заявки, аналитика, графики. */

declare(strict_types=1);

const STATUS_TITLES = [
    'new'    => 'Новая',
    'work'   => 'В работе',
    'done'   => 'Успех',
    'reject' => 'Отказ',
];

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 12 345 — с неразрывным пробелом, чтобы число не переносилось. */
function n(float $v, int $dec = 0): string
{
    return number_format($v, $dec, ',', "\u{00A0}");
}

/** 134 секунды → «2 мин 14 с». */
function dur(int $sec): string
{
    if ($sec < 60) {
        return $sec . "\u{00A0}с";
    }
    $m = intdiv($sec, 60);
    $s = $sec % 60;
    return $m . "\u{00A0}мин" . ($s ? ' ' . $s . "\u{00A0}с" : '');
}

/** «5 заявок» — правильное окончание. */
function plural(int $n, string $one, string $few, string $many): string
{
    $n10 = $n % 10;
    $n100 = $n % 100;
    if ($n10 === 1 && $n100 !== 11)                     return $one;
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20)) return $few;
    return $many;
}

/* ============================ страницы ============================ */

function render_login(string $error): void
{
    ?><!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Вход — Нечай</title>
<link rel="stylesheet" href="style.css?v=2">
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

function page_start(string $title, string $page): void
{
    ?><!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — Нечай</title>
<link rel="stylesheet" href="style.css?v=2">
</head><body>
<header class="top">
  <div class="top__brand">
    <b>Нечай</b>
    <nav class="top__nav">
      <a href="?p=leads" class="<?= $page === 'leads' ? 'on' : '' ?>">Заявки</a>
      <a href="?p=stats" class="<?= $page === 'stats' ? 'on' : '' ?>">Аналитика</a>
    </nav>
  </div>
  <div class="top__side">
    <a href="../" target="_blank" rel="noopener">Сайт ↗</a>
    <a class="logout" href="?logout=1">Выйти</a>
  </div>
</header>
<main><?php
}

function page_end(): void
{
    ?></main></body></html><?php
}

/** Плитка с числом. */
function stat_card(string $value, string $label, string $note = ''): void
{
    ?><div class="stat">
      <b><?= h($value) ?></b>
      <span><?= h($label) ?></span>
      <?php if ($note !== ''): ?><i><?= h($note) ?></i><?php endif; ?>
    </div><?php
}

/* ============================ заявки ============================ */

function render_leads(
    array $leads, array $counts, int $total, int $week, int $month,
    int $trash, string $filter, string $q, string $csrf
): void {
    page_start('Заявки', 'leads');
    $tabs = ['all' => 'Все'] + STATUS_TITLES;
    ?>
<section class="stats">
  <?php
    stat_card(n($total), 'всего заявок');
    stat_card(n($week), 'за 7 дней');
    stat_card(n($month), 'за 30 дней');
    stat_card(n($counts['new'] ?? 0), 'не обработано');
  ?>
</section>

<div class="toolbar">
  <nav class="tabs">
    <?php foreach ($tabs as $k => $label):
        $num = $k === 'all' ? $total : ($counts[$k] ?? 0); ?>
      <a href="?p=leads&amp;f=<?= h($k) ?><?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>"
         class="<?= $filter === $k ? 'on' : '' ?>"><?= h($label) ?> <i><?= $num ?></i></a>
    <?php endforeach; ?>
    <a href="?p=leads&amp;f=trash" class="tabs__trash <?= $filter === 'trash' ? 'on' : '' ?>">
      Корзина <i><?= $trash ?></i>
    </a>
  </nav>

  <form class="search" method="get">
    <input type="hidden" name="p" value="leads">
    <input type="hidden" name="f" value="<?= h($filter) ?>">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Имя, телефон, комментарий">
    <button type="submit">Найти</button>
    <?php if ($q !== ''): ?>
      <a class="search__clear" href="?p=leads&amp;f=<?= h($filter) ?>">Сбросить</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($leads): ?>
  <p class="hint">
    Показано <?= count($leads) ?> <?= plural(count($leads), 'заявка', 'заявки', 'заявок') ?>.
    <a href="?export=csv&amp;f=<?= h($filter) ?><?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>">
      Скачать таблицей (CSV)
    </a>
  </p>
<?php endif; ?>

<?php if (!$leads): ?>
  <p class="empty"><?= $filter === 'trash'
      ? 'Корзина пуста.'
      : ($q !== '' ? 'По запросу ничего не нашлось.' : 'Заявок пока нет.') ?></p>
<?php else: ?>
  <div class="list">
  <?php foreach ($leads as $l): ?>
    <article class="card lead st-<?= h($l['status']) ?><?= $filter === 'trash' ? ' lead--trashed' : '' ?>">
      <div class="lead__head">
        <div>
          <b class="lead__name"><?= h($l['name']) ?></b>
          <a class="lead__phone" href="tel:<?= h(preg_replace('/[^\d+]/', '', $l['phone'])) ?>">
            <?= h($l['phone']) ?>
          </a>
        </div>
        <time><?= h(date('d.m.Y H:i', strtotime((string)$l['created_at']))) ?></time>
      </div>

      <dl class="lead__meta">
        <?php foreach ([
            'Формат' => $l['format'],
            'Гостей' => $l['guests'],
            'Дата'   => $l['event_date'],
        ] as $k => $v): if ($v === '' || $v === null) continue; ?>
          <dt><?= h($k) ?></dt><dd><?= h((string)$v) ?></dd>
        <?php endforeach; ?>
      </dl>

      <?php if (!empty($l['comment'])): ?>
        <p class="lead__comment"><?= nl2br(h((string)$l['comment'])) ?></p>
      <?php endif; ?>

      <?php $back = '?p=leads&f=' . $filter . ($q !== '' ? '&q=' . urlencode($q) : ''); ?>

      <?php if ($filter === 'trash'): ?>
        <div class="lead__actions">
          <span class="lead__deleted">
            В корзине с <?= h(date('d.m.Y', strtotime((string)$l['deleted_at']))) ?>
          </span>
          <form method="post" action="<?= h($back) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button type="submit" name="action" value="restore" class="btn-ghost">Восстановить</button>
          </form>
          <form method="post" action="<?= h($back) ?>"
                onsubmit="return confirm('Удалить заявку «<?= h(addslashes($l['name'])) ?>» насовсем? Отменить будет нельзя.')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button type="submit" name="action" value="purge" class="btn-danger">Удалить навсегда</button>
          </form>
        </div>
      <?php else: ?>
        <div class="lead__actions">
          <form class="lead__status" method="post" action="<?= h($back) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <?php foreach (STATUS_TITLES as $k => $label): ?>
              <button type="submit" name="status" value="<?= h($k) ?>"
                      class="<?= $l['status'] === $k ? 'on' : '' ?>"><?= h($label) ?></button>
            <?php endforeach; ?>
          </form>
          <form method="post" action="<?= h($back) ?>" class="lead__del">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button type="submit" name="action" value="delete" class="btn-trash"
                    title="Переместить в корзину" aria-label="Удалить заявку">
              <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true">
                <path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                      d="M4 7h16M10 4h4M9.5 7.5l.6 11M14.5 7.5l-.6 11M6 7l1 12.2A1.8 1.8 0 0 0 8.8 21h6.4a1.8 1.8 0 0 0 1.8-1.8L18 7"/>
              </svg>
            </button>
          </form>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($filter === 'trash' && $leads): ?>
  <p class="hint">Из корзины заявки удаляются насовсем — восстановить их уже не выйдет.</p>
<?php endif;
    page_end();
}

/* ============================ аналитика ============================ */

function render_stats(array $d): void
{
    page_start('Аналитика', 'stats');
    $days    = $d['days'];
    $m       = $d['metrika'];
    $periods = [7 => '7 дней', 30 => '30 дней', 90 => '90 дней'];
    ?>
<nav class="tabs tabs--period">
  <?php foreach ($periods as $k => $label): ?>
    <a href="?p=stats&amp;d=<?= $k ?>" class="<?= $days === $k ? 'on' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
  <span class="tabs__note"><?= h($d['from_h']) ?> — <?= h($d['to_h']) ?></span>
</nav>

<section class="stats">
  <?php
    stat_card(n($d['leads_period']), 'заявок за период');
    if ($m) {
        stat_card(n($m['totals']['visits']), 'визитов');
        stat_card(n($m['totals']['users']), 'посетителей');
        stat_card(
            $m['totals']['visits'] > 0
                ? n($d['leads_period'] / $m['totals']['visits'] * 100, 1) . '%'
                : '—',
            'конверсия в заявку',
            'заявки ÷ визиты'
        );
        stat_card(n($m['totals']['views']), 'просмотров страниц');
        stat_card(n($m['totals']['bounce'], 1) . '%', 'отказов', 'ушли за 15 секунд');
        stat_card(n($m['totals']['depth'], 1), 'страниц за визит');
        stat_card(dur($m['totals']['duration']), 'в среднем на сайте');
    } else {
        stat_card(n($d['leads_total']), 'заявок всего');
        stat_card(n($d['leads_week']), 'за 7 дней');
    }
  ?>
</section>

<?php if ($m): ?>
  <section class="card block">
    <h2>Посещаемость по дням</h2>
    <?= chart_line($m['days_labels'], [
        ['label' => 'Визиты',      'color' => '#C4661C', 'values' => $m['days_visits']],
        ['label' => 'Посетители',  'color' => '#7C6659', 'values' => $m['days_users']],
    ]) ?>
  </section>
<?php endif; ?>

<section class="card block">
  <h2>Заявки по дням</h2>
  <?php if (array_sum($d['leads_values']) > 0): ?>
    <?= chart_bars($d['leads_labels'], $d['leads_values']) ?>
  <?php else: ?>
    <p class="empty">За этот период заявок не было.</p>
  <?php endif; ?>
</section>

<?php if ($m && $m['goals']): ?>
  <section class="card block">
    <h2>Цели</h2>
    <div class="goals">
      <?php foreach ($m['goals'] as $g): ?>
        <div class="goal">
          <b><?= n($g['reaches']) ?></b>
          <span><?= h($g['name']) ?></span>
          <i><?= n($g['rate'], 2) ?>% визитов</i>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($m || $d['formats']): ?>
  <div class="cols">
    <?php
      $visit = ['визит', 'визита', 'визитов'];
      if ($m) {
          bar_block('Откуда приходят',     $m['sources'], $visit);
          bar_block('С чего смотрят',      $m['devices'], $visit);
          bar_block('Города',              $m['cities'],  $visit);
          bar_block('Популярные страницы', $m['pages'],   ['просмотр', 'просмотра', 'просмотров']);
      }
      if ($d['formats']) {
          bar_block('Какие форматы заказывают', $d['formats'], ['заявка', 'заявки', 'заявок']);
      }
    ?>
  </div>
<?php endif; ?>

<?php if (!$m): ?>
  <section class="card block setup">
    <h2>Подключить статистику Метрики</h2>
    <?php if ($d['metrika_error']): ?>
      <p class="err"><?= h($d['metrika_error']) ?></p>
    <?php endif; ?>
    <p>Заявки считаются сами. Чтобы здесь же появились визиты, источники
       и графики посещаемости, нужен токен доступа к Метрике:</p>
    <ol>
      <li>Открыть <a href="https://oauth.yandex.ru/client/new" target="_blank" rel="noopener">oauth.yandex.ru</a>
          и создать приложение с правом «Яндекс.Метрика — получение статистики».</li>
      <li>Получить для него OAuth-токен.</li>
      <li>Вписать токен в <code>api/config.php</code>, в <code>metrika.token</code>,
          а номер счётчика — в <code>metrika.counter</code>.</li>
    </ol>
    <p class="hint">Подробнее — в README, раздел «Админка».</p>
  </section>
<?php endif;
    page_end();
}

/**
 * Список «название — полоска — число».
 * $unit — три формы слова: «визит, визита, визитов».
 */
function bar_block(string $title, array $rows, array $unit): void
{
    ?><section class="card block">
      <h2><?= h($title) ?></h2>
      <?php if (!$rows): ?>
        <p class="empty">Нет данных.</p>
      <?php else: ?>
        <ul class="bars">
          <?php foreach ($rows as $r): ?>
            <li>
              <span class="bars__name" title="<?= h($r['name']) ?>"><?= h($r['name']) ?></span>
              <span class="bars__val"><?= n($r['value']) ?>
                <em><?= h(plural((int)$r['value'], $unit[0], $unit[1], $unit[2])) ?></em></span>
              <span class="bars__track"><i style="width:<?= max(2, round($r['share'])) ?>%"></i></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section><?php
}

/* ============================ графики ============================ */

/**
 * Верх шкалы: круглое число, которое делится на четыре линии сетки без
 * остатка. Иначе подписи выходят вроде «37,5» и график читается плохо.
 */
function nice_max(float $v, int $lines = 4): float
{
    if ($v <= $lines) {
        return $lines;
    }
    $step = $v / $lines;
    $pow  = 10 ** floor(log10($step));
    foreach ([1, 2, 3, 4, 5, 6, 8, 10] as $s) {
        if ($step <= $s * $pow) {
            return $s * $pow * $lines;
        }
    }
    return 10 * $pow * $lines;
}

/** Линейный график с заливкой. $series: [['label','color','values'], …]. */
function chart_line(array $labels, array $series): string
{
    $count = count($labels);
    if ($count === 0) {
        return '<p class="empty">Нет данных.</p>';
    }

    $W = 760; $H = 240;
    $padL = 60; $padR = 14; $padT = 16; $padB = 30;   // слева место под подписи шкалы
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $max = 0.0;
    foreach ($series as $s) {
        $max = max($max, (float)max($s['values'] ?: [0]));
    }
    $max = nice_max($max);

    $x = static function (int $i) use ($padL, $plotW, $count): float {
        return $count > 1 ? $padL + $i * $plotW / ($count - 1) : $padL + $plotW / 2;
    };
    $y = static function (float $v) use ($padT, $plotH, $max): float {
        return $padT + $plotH - ($max > 0 ? $v / $max * $plotH : 0);
    };

    $svg  = '<div class="chart"><svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" '
          . 'aria-label="График посещаемости">';

    // сетка и подписи слева
    for ($g = 0; $g <= 4; $g++) {
        $val = $max / 4 * $g;
        $gy  = round($y($val), 1);
        $svg .= '<line class="chart__grid" x1="' . $padL . '" y1="' . $gy . '" x2="' . ($W - $padR) . '" y2="' . $gy . '"/>';
        $svg .= '<text class="chart__tick" x="' . ($padL - 8) . '" y="' . ($gy + 4) . '" text-anchor="end">'
              . h(n($val)) . '</text>';
    }

    foreach ($series as $k => $s) {
        $line = '';
        $area = '';
        foreach ($s['values'] as $i => $v) {
            $px = round($x($i), 1);
            $py = round($y((float)$v), 1);
            $line .= ($i === 0 ? 'M' : 'L') . $px . ' ' . $py;
            $area .= ($i === 0 ? 'M' : 'L') . $px . ' ' . $py;
        }
        $area .= 'L' . round($x($count - 1), 1) . ' ' . ($padT + $plotH)
               . 'L' . round($x(0), 1) . ' ' . ($padT + $plotH) . 'Z';

        if ($k === 0) {
            $svg .= '<path d="' . $area . '" fill="' . h($s['color']) . '" opacity=".12"/>';
        }
        $svg .= '<path d="' . $line . '" fill="none" stroke="' . h($s['color'])
              . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

        // точки рисуем, только когда их немного — иначе получается каша
        if ($count <= 32) {
            foreach ($s['values'] as $i => $v) {
                $svg .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y((float)$v), 1)
                      . '" r="2.6" fill="' . h($s['color']) . '"><title>'
                      . h($labels[$i] . ': ' . n((float)$v) . ' ' . mb_strtolower($s['label']))
                      . '</title></circle>';
            }
        }
    }

    // подписи снизу — примерно шесть штук, чтобы не наезжали
    $step = max(1, (int)ceil($count / 6));
    for ($i = 0; $i < $count; $i += $step) {
        $svg .= '<text class="chart__tick" x="' . round($x($i), 1) . '" y="' . ($H - 10)
              . '" text-anchor="middle">' . h($labels[$i]) . '</text>';
    }
    $svg .= '</svg></div>';

    $svg .= '<ul class="legend">';
    foreach ($series as $s) {
        $svg .= '<li><i style="background:' . h($s['color']) . '"></i>' . h($s['label']) . '</li>';
    }
    $svg .= '</ul>';

    return $svg;
}

/** Столбики: заявки по дням. */
function chart_bars(array $labels, array $values): string
{
    $count = count($labels);
    if ($count === 0) {
        return '<p class="empty">Нет данных.</p>';
    }

    $W = 760; $H = 200;
    $padL = 60; $padR = 14; $padT = 14; $padB = 28;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;
    $max   = nice_max((float)max($values ?: [0]));
    $slot  = $plotW / $count;
    $bw    = min(26, max(3, $slot * 0.62));

    $svg = '<div class="chart"><svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" '
         . 'aria-label="График заявок по дням">';

    for ($g = 0; $g <= 4; $g++) {
        $val = $max / 4 * $g;
        $gy  = round($padT + $plotH - $val / $max * $plotH, 1);
        $svg .= '<line class="chart__grid" x1="' . $padL . '" y1="' . $gy . '" x2="' . ($W - $padR) . '" y2="' . $gy . '"/>';
        $svg .= '<text class="chart__tick" x="' . ($padL - 8) . '" y="' . ($gy + 4) . '" text-anchor="end">'
              . h(n($val)) . '</text>';
    }

    foreach ($values as $i => $v) {
        $bh = $max > 0 ? $v / $max * $plotH : 0;
        $cx = $padL + $slot * ($i + 0.5);
        $svg .= '<rect x="' . round($cx - $bw / 2, 1) . '" y="' . round($padT + $plotH - $bh, 1)
              . '" width="' . round($bw, 1) . '" height="' . round(max($v > 0 ? 2 : 0, $bh), 1)
              . '" rx="2" fill="#C4661C"><title>' . h($labels[$i] . ': ' . $v . ' '
              . plural((int)$v, 'заявка', 'заявки', 'заявок')) . '</title></rect>';
    }

    $step = max(1, (int)ceil($count / 6));
    for ($i = 0; $i < $count; $i += $step) {
        $svg .= '<text class="chart__tick" x="' . round($padL + $slot * ($i + 0.5), 1)
              . '" y="' . ($H - 9) . '" text-anchor="middle">' . h($labels[$i]) . '</text>';
    }

    return $svg . '</svg></div>';
}
