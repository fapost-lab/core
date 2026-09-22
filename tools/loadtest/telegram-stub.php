<?php

declare(strict_types=1);

/**
 * Telegram Bot API stub for the load-test harness.
 *
 * Run with: php -S 127.0.0.1:PORT tools/loadtest/telegram-stub.php
 * (add PHP_CLI_SERVER_WORKERS=N to the environment for real concurrency —
 * the built-in server is otherwise single-threaded).
 *
 * Answers every `/bot{token}/{method}` call the same way the real Bot API
 * would for the handful of methods the platform actually calls during a
 * load test (setWebhook, deleteWebhook, getMe, sendChatAction, sendMessage,
 * editMessageReplyMarkup), and appends every outbound sendMessage to a
 * JSON-lines log — "what the end user would have seen" — so the load test's
 * `verify` step can check delivery without a real Telegram account.
 *
 * This script has no Laravel dependency on purpose: it must survive
 * independently of the app's queue workers and run as a plain PHP process.
 */

header('Content-Type: application/json');

$logPath = getenv('LOADTEST_STUB_LOG');
if (false === $logPath || '' === $logPath) {
    $logPath = getcwd() . '/storage/logs/loadtest-stub.jsonl';
}

$counterPath = dirname($logPath) . '/.loadtest-stub-message-id';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (1 !== preg_match('#^/bot(?<token>[^/]+)/(?<method>[A-Za-z]+)$#', (string)$path, $matches)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error_code' => 404, 'description' => 'Not Found']);

    return;
}

$token  = $matches['token'];
$method = $matches['method'];

$raw  = file_get_contents('php://input');
$body = is_string($raw) && '' !== $raw ? json_decode($raw, true) : [];
$body = is_array($body) ? $body : [];

respond(handle($method, $token, $body, $logPath, $counterPath));

/**
 * @param  array<string, mixed>  $body
 *
 * @return array<string, mixed>
 */
function handle(string $method, string $token, array $body, string $logPath, string $counterPath): array
{
    return match ($method) {
        'getMe' => [
            'ok'     => true,
            'result' => [
                'id'         => crc32($token) % 1_000_000_000,
                'is_bot'     => true,
                'first_name' => 'LoadTest Bot',
                'username'   => 'loadtest_bot_' . mb_substr(md5($token), 0, 8),
            ],
        ],
        'setWebhook', 'deleteWebhook', 'sendChatAction' => ['ok' => true, 'result' => true],
        'editMessageReplyMarkup'                        => ['ok' => true, 'result' => true],
        'sendMessage'                                   => sendMessage($body, $logPath, $counterPath),
        default                                         => ['ok' => true, 'result' => true],
    };
}

/**
 * @param  array<string, mixed>  $body
 *
 * @return array<string, mixed>
 */
function sendMessage(array $body, string $logPath, string $counterPath): array
{
    $chatId = $body['chat_id'] ?? null;
    $text   = is_string($body['text'] ?? null) ? $body['text'] : '';
    $now    = microtime(true);

    $messageId = nextMessageId($counterPath);

    appendLog($logPath, [
        'method'     => 'sendMessage',
        'chat_id'    => is_numeric($chatId) ? (int)$chatId : $chatId,
        'text'       => $text,
        'message_id' => $messageId,
        'ts'         => $now,
    ]);

    return [
        'ok'     => true,
        'result' => [
            'message_id' => $messageId,
            'date'       => (int)$now,
            'chat'       => ['id' => is_numeric($chatId) ? (int)$chatId : $chatId],
            'text'       => $text,
        ],
    ];
}

/**
 * Atomically incremented, shared across `php -S` worker processes via flock.
 */
function nextMessageId(string $counterPath): int
{
    ensureDirectoryExists(dirname($counterPath));

    $handle = fopen($counterPath, 'c+');
    if (false === $handle) {
        // Falls back to a coarse-grained but still-unique id when the counter
        // file cannot be opened (e.g. read-only filesystem in a sandbox run).
        return (int)round(microtime(true) * 1000);
    }

    flock($handle, LOCK_EX);

    $current = (int)mb_trim((string)stream_get_contents($handle));
    $next    = $current + 1;

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, (string)$next);
    fflush($handle);

    flock($handle, LOCK_UN);
    fclose($handle);

    return $next;
}

/**
 * @param  array<string, mixed>  $entry
 */
function appendLog(string $logPath, array $entry): void
{
    ensureDirectoryExists(dirname($logPath));

    $handle = fopen($logPath, 'a');
    if (false === $handle) {
        return;
    }

    flock($handle, LOCK_EX);
    fwrite($handle, json_encode($entry, JSON_THROW_ON_ERROR) . "\n");
    flock($handle, LOCK_UN);
    fclose($handle);
}

function ensureDirectoryExists(string $directory): void
{
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

/**
 * @param  array<string, mixed>  $payload
 */
function respond(array $payload): void
{
    echo json_encode($payload, JSON_THROW_ON_ERROR);
}
