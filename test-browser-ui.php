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

$repoDetailUrl = $baseUrl . '/?repo=' . rawurlencode('1/apples/cider apples/sour two') . '&command=branch';
$harvestBranchUrl = $baseUrl . '/?repo=' . rawurlencode('1/apples/cider apples/sour two') . '&command=branch&branch=' . rawurlencode('feature/harvest-tracker') . '&mode=branch';

$startedAt = microtime(true);
$html = '';
$branchHtml = '';

while (microtime(true) - $startedAt < 10.0) {
    usleep(200000);

    $context = stream_context_create([
        'http' => [
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($repoDetailUrl, false, $context);
    if ($response !== false && $response !== '') {
        $html = $response;
        break;
    }
    echo "$repoDetailUrl returned no response";
}

if ($html === '') {
    $stderr = stream_get_contents($pipes[2]);
    proc_terminate($server);
    proc_close($server);
    fail('Browser detail page never reached the app over HTTP.', $stderr);
}

$branchResponse = @file_get_contents($harvestBranchUrl, false, stream_context_create([
    'http' => [
        'timeout' => 2,
        'ignore_errors' => true,
    ],
]));

if ($branchResponse !== false && $branchResponse !== '') {
    $branchHtml = $branchResponse;
}

proc_terminate($server);
proc_close($server);

assertContains('<title>Git Repo Browser</title>', $html, 'Browser page title missing.');
assertContains('repo=1%2Fapples%2Fcider%20apples%2Fsour%20two', $html, 'Detail page should use a root-relative repo selector instead of an absolute filesystem path.');
assertContains('branch-list', $html, 'Detail page is missing the branch selection list.');
assertContains('commit-list', $html, 'Detail page is missing the commit log panel.');
assertContains('Tree', $html, 'Tree mode button is missing from the repo detail actions.');
assertContains('repo-data', $html, 'Repo detail page is missing server-rendered JSON branch data.');
assertContains('branch-button is-selected', $html, 'HEAD branch should be selected by default in the detail view.');
assertContains('Merge branch', $html, 'The selected branch log should show commit metadata and messages.');
if (str_contains($html, __DIR__ . '/example-repos')) {
    fail('Absolute filesystem paths should not appear in the browser HTML output.', $html);
}

if ($branchHtml === '') {
    fail('Feature branch detail page did not return HTML.');
}

if (str_contains($branchHtml, '381a3a8')) {
    fail('Feature branch log still includes pre-fork commit 381a3a8.', $branchHtml);
}

$rootPage = '';
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
    fail('Failed to start PHP built-in server for root page test.');
}

$startedAt = microtime(true);
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
        $rootPage = $response;
        break;
    }
}

proc_terminate($server);
proc_close($server);

assertContains('<title>Git Repo Browser</title>', $rootPage, 'Root browser page title missing.');
assertContains('role="button"', $rootPage, 'Repo tiles are not marked as interactive buttons in the root view.');

fwrite(STDOUT, "HTML UI regression test passed.\n");
