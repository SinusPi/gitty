<?php

declare(strict_types=1);

final class GitCommandRunner
{
    public static function run(string $repoPath, array $arguments): string
    {
        [$output, $status] = self::runWithStatus($repoPath, $arguments);

        if ($status !== 0 && trim($output) === '') {
            return sprintf('git exited with status %d.', $status);
        }

        return trim($output) !== '' ? $output : '(no output)';
    }

    public static function runWithStatus(string $repoPath, array $arguments): array
    {
        $command = ['git', '--git-dir=' . $repoPath];
        foreach ($arguments as $argument) {
            $command[] = (string) $argument;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['', 1];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        $output = trim((string) $stdout);
        if ($output === '' && trim((string) $stderr) !== '') {
            $output = trim((string) $stderr);
        }

        return [$output, $status];
    }
}
