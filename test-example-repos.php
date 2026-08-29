<?php

declare(strict_types=1);

$repoRoots = implode(PATH_SEPARATOR, [
    __DIR__ . '/example-repos/fruits',
    __DIR__ . '/example-repos/veggies',
]);

putenv('GITTY_REPO_ROOTS=' . $repoRoots);

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/index.php') . ' --list';
$output = [];
exec($command, $output, $status);
$outputText = implode(PHP_EOL, $output);

if ($status !== 0) {
    fwrite(STDERR, "CLI command failed with exit code {$status}.\nOutput:\n{$outputText}\n");
    exit($status);
}

echo "CLI output:\n" . $outputText . "\n";

$expected = [
    'apples',
    'golden-delicious',
    'granny-smith',
    'banana',
    'cabbage',
    'carrot',
    'cider apples',
    'sour two',
    'tart one',
];

foreach ($expected as $needle) {
    if (str_contains($outputText, $needle) === false) {
        fwrite(STDERR, "Missing expected repo name in CLI output: {$needle}\nOutput:\n{$outputText}\n");
        exit(1);
    }
}

echo "Example repo regression test passed.\n";
