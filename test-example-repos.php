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

$emptyRepoPath = sys_get_temp_dir() . '/gitty-empty-repo-' . bin2hex(random_bytes(6));
$initCommand = 'git init --bare ' . escapeshellarg($emptyRepoPath);
exec($initCommand . ' 2>&1', $emptyInitOutput, $emptyInitStatus);
if ($emptyInitStatus !== 0) {
    fwrite(STDERR, "Failed to initialize empty bare repo.\nOutput:\n" . implode(PHP_EOL, $emptyInitOutput) . "\n");
    exit($emptyInitStatus);
}

$combinedRoots = implode(PATH_SEPARATOR, [$repoRoots, $emptyRepoPath]);
putenv('GITTY_REPO_ROOTS=' . $combinedRoots);

$emptyCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/index.php') . ' --list';
$emptyOutput = [];
exec($emptyCommand, $emptyOutput, $emptyStatus);
$emptyOutputText = implode(PHP_EOL, $emptyOutput);

if ($emptyStatus !== 0) {
    fwrite(STDERR, "Empty repo CLI check failed with exit code {$emptyStatus}.\nOutput:\n{$emptyOutputText}\n");
    exit($emptyStatus);
}

if (str_contains($emptyOutputText, 'No commits yet') === false) {
    fwrite(STDERR, "Empty repo did not report 'No commits yet'.\nOutput:\n{$emptyOutputText}\n");
    exit(1);
}

if (str_contains($emptyOutputText, '(commits: 0') === false) {
    fwrite(STDERR, "Empty repo did not report zero commit count.\nOutput:\n{$emptyOutputText}\n");
    exit(1);
}

echo "Example repo regression test passed.\n";
