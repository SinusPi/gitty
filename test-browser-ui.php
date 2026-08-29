<?php

declare(strict_types=1);

function fail(string $message, string $html = ''): void
{
    if ($html !== '') {
        fwrite(STDERR, $message . "\nHTML preview:\n" . substr($html, 0, 2000) . "\n");
    } else {
        fwrite(STDERR, $message . "\n");
    }

    exit(1);
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle) === false) {
        fail($message, $haystack);
    }
}

$repoRoots = implode(PATH_SEPARATOR, [
    __DIR__ . '/example-repos/fruits',
    __DIR__ . '/example-repos/veggies',
]);

putenv('GITTY_REPO_ROOTS=' . $repoRoots);

$host = '127.0.0.1';
$port = 18180;
$baseUrl = 'http://' . $host . ':' . $port;

$server = proc_open(
    ['php', '-S', $host . ':' . $port, '-t', __DIR__],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    __DIR__,
    null,
    ['bypass_shell' => true]
);

if (!is_resource($server)) {
    fail('Failed to start PHP built-in server for browser test.');
}

$startedAt = microtime(true);
$html = '';

while (microtime(true) - $startedAt < 10.0) {
    usleep(200000);

    $context = stream_context_create([
        'http' => [
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($baseUrl, false, $context);
    if ($response !== false && $response !== '') {
        $html = $response;
        break;
    }
}

if ($html === '') {
    $stderr = stream_get_contents($pipes[2]);
    proc_terminate($server);
    proc_close($server);
    fail('Browser test never reached the app over HTTP.', $stderr);
}

proc_terminate($server);
proc_close($server);

assertContains('<title>Git Repo Browser</title>', $html, 'Browser page title missing.');
assertContains('role="button"', $html, 'Repo tiles are not marked as interactive buttons.');
assertContains('onclick="window.location.href', $html, 'Repo tiles are not wired to open details on click.');
assertContains('command=branch', $html, 'Tile click target does not include the default repo detail command.');
assertContains('golden-delicious', $html, 'Expected repo name not found in HTML output.');
assertContains('cider apples', $html, 'Expected nested repo name not found in HTML output.');

fwrite(STDOUT, "HTML UI regression test passed.\n");
