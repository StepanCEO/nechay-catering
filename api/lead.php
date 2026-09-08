<?php
/** Приём заявки с формы сайта. */

declare(strict_types=1);
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'method']));
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;                       // на случай обычной отправки формы
}

function field(array $d, string $k, int $max = 500): string
{
    $v = isset($d[$k]) ? trim((string)$d[$k]) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    return mb_substr($v, 0, $max);
}

$name  = field($data, 'name', 120);
$phone = field($data, 'phone', 60);

if (mb_strlen($name) < 2 || mb_strlen($phone) < 5) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => 'validation']));
}

// простая защита от повторной отправки одного и того же за минуту
$lead = [
    'name'       => $name,
    'phone'      => $phone,
    'format'     => field($data, 'format', 80),
    'guests'     => field($data, 'guests', 20),
    'event_date' => field($data, 'date', 20),
    'comment'    => field($data, 'comment', 2000),
];

try {
    $pdo = db();

    $dup = $pdo->prepare(
        'SELECT id FROM leads WHERE phone = ? AND created_at > (NOW() - INTERVAL 1 MINUTE) LIMIT 1'
    );
    $dup->execute([$lead['phone']]);
    if ($dup->fetch()) {
        exit(json_encode(['ok' => true, 'duplicate' => true]));
    }

    $st = $pdo->prepare(
        'INSERT INTO leads (created_at, name, phone, format, guests, event_date, comment)
         VALUES (NOW(), :name, :phone, :format, :guests, :event_date, :comment)'
    );
    $st->execute($lead);
} catch (Throwable $e) {
    error_log('lead.php: ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'server']));
}

// уведомление в Telegram — не критично, поэтому ошибки только в лог
$tg = cfg()['telegram'] ?? [];
if (!empty($tg['token']) && !empty($tg['chat_id'])) {
    $lines = [
        '🔔 Заявка с сайта',
        'Имя: ' . $lead['name'],
        'Телефон: ' . $lead['phone'],
    ];
    if ($lead['format'])     $lines[] = 'Формат: ' . $lead['format'];
    if ($lead['guests'])     $lines[] = 'Гостей: ' . $lead['guests'];
    if ($lead['event_date']) $lines[] = 'Дата: ' . $lead['event_date'];
    if ($lead['comment'])    $lines[] = 'Комментарий: ' . $lead['comment'];

    $ch = curl_init("https://api.telegram.org/bot{$tg['token']}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id' => $tg['chat_id'],
            'text'    => implode("\n", $lines),
        ]),
    ]);
    if (curl_exec($ch) === false) {
        error_log('telegram: ' . curl_error($ch));
    }
    curl_close($ch);
}

echo json_encode(['ok' => true]);
