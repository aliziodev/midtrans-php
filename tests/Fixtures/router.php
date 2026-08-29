<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server, used by CurlTransportServerTest to drive
 * CurlTransport against a real socket.
 *
 * Scenarios that need to behave differently across attempts key their counter
 * off a caller-supplied ?key=, so tests never share state.
 */
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

$stateDir = sys_get_temp_dir().'/midtrans-curl-transport-test';
if (! is_dir($stateDir)) {
    @mkdir($stateDir, 0o777, true);
}

/** Returns how many times this key has been hit before this request. */
$priorHits = static function (string $key) use ($stateDir): int {
    $file = $stateDir.'/'.preg_replace('/[^A-Za-z0-9_-]/', '', $key);
    $handle = fopen($file, 'c+');

    if ($handle === false) {
        return 0;
    }

    flock($handle, LOCK_EX);
    $seen = (int) stream_get_contents($handle);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) ($seen + 1));
    flock($handle, LOCK_UN);
    fclose($handle);

    return $seen;
};

$json = static function (int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
};

switch ($path) {
    case '/ok':
        header('X-Trace-Id: trace-abc-123');
        header('X-Multi-Word-Header: yes');
        $json(200, ['ok' => true]);
        break;

    case '/always':
        $json((int) ($query['status'] ?? 500), ['ok' => false]);
        break;

    // Fails `fail` times with `status`, then succeeds and reports the attempt
    // number so a test can assert how many requests were actually made.
    case '/fail-then-ok':
        $seen = $priorHits((string) ($query['key'] ?? 'default'));
        $fail = (int) ($query['fail'] ?? 1);

        if ($seen < $fail) {
            $json((int) ($query['status'] ?? 500), ['attempt' => $seen + 1]);
        } else {
            $json(200, ['attempt' => $seen + 1]);
        }
        break;

    // 429 carrying Retry-After on the first hit, then 200.
    case '/retry-after':
        $seen = $priorHits((string) ($query['key'] ?? 'default'));

        if ($seen === 0) {
            header('Retry-After: '.(string) ($query['seconds'] ?? 1));
            $json(429, ['attempt' => 1]);
        } else {
            $json(200, ['attempt' => $seen + 1]);
        }
        break;

    case '/echo':
        $json(200, [
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'body' => file_get_contents('php://input'),
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
        ]);
        break;

    case '/redirect':
        header('Location: /ok');
        $json(302, ['redirected' => true]);
        break;

    case '/not-json':
        http_response_code(200);
        header('Content-Type: text/html');
        echo '<html><body>gateway timeout</body></html>';
        break;

    default:
        $json(404, ['error' => 'unknown route '.$path]);
}
