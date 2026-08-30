<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
| Loads .env into the environment for the integration suite.
|
| Hand-rolled rather than pulling in vlucas/phpdotenv: this reads a handful of
| KEY=value lines for tests only, and an SDK with no runtime dependencies should
| not gain one for that. Values already present in the real environment win, so
| CI secrets are never overwritten by a stray local file.
*/
$envFile = __DIR__.'/../.env';

if (! is_file($envFile)) {
    return;
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);

    if (strlen($value) > 1 && $value[0] === $value[-1] && in_array($value[0], ['"', "'"], true)) {
        $value = substr($value, 1, -1);
    }

    if ($key === '' || getenv($key) !== false) {
        continue;
    }

    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
