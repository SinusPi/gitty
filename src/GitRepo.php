<?php

declare(strict_types=1);

final class GitRepo
{
    public function __construct(private string $path)
    {
    }

    public static function normalizePath(string $path): string
    {
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        return rtrim($normalized, DIRECTORY_SEPARATOR);
    }

    public function getPath(): string
    {
        return self::normalizePath($this->path);
    }

    public function getDisplayPath(): string
    {
        if (basename($this->path) === '.git') {
            return dirname($this->path);
        }

        return $this->path;
    }

    public function getName(): string
    {
        $displayPath = $this->getDisplayPath();
        $name = basename($displayPath);

        if ($name === '.git') {
            return basename(dirname($displayPath));
        }

        return $name;
    }

    public function getDisplayName(): string
    {
        return $this->getName();
    }

    public function getBranchOutput(): string
    {
        return GitCommandRunner::run($this->path, ['branch']);
    }

    public function getLogOutput(): string
    {
        return GitCommandRunner::run($this->path, ['log', '--oneline', '-n', '20']);
    }

    public function getTreeOutput(int $limit = 120): string
    {
        return GitCommandRunner::run($this->path, ['log', '--graph', '--decorate', '--oneline', '--color=always', '-n', (string) $limit, '--all']);
    }

    public function getBranches(): array
    {
        [$output, $status] = GitCommandRunner::runWithStatus($this->path, ['branch', '--format=%(refname:short)']);

        if ($status !== 0 || trim($output) === '') {
            return [];
        }

        $branches = preg_split('/\r\n|\r|\n/', $output) ?: [];
        return array_values(array_values(array_filter(array_map('trim', $branches), static fn (string $branch): bool => $branch !== '')));
    }

    public function getHeadBranch(): string
    {
        [$output, $status] = GitCommandRunner::runWithStatus($this->path, ['rev-parse', '--abbrev-ref', 'HEAD']);

        if ($status !== 0 || trim($output) === '') {
            return 'HEAD';
        }

        return trim($output);
    }

    public function getBranchCommits(string $branch, int $limit = 30, array $knownBranches = [], ?string $knownHeadBranch = null): array
    {
        $ref = $branch !== '' ? $branch : 'HEAD';
        [$headOutput, $headStatus] = GitCommandRunner::runWithStatus($this->path, ['rev-parse', '--verify', 'HEAD']);
        if ($headStatus !== 0 || trim($headOutput) === '') {
            return [];
        }

        $logArgs = ['log', '--pretty=format:%H%x1f%an%x1f%ad%x1f%s', '--date=iso-strict', '-n', (string) $limit, $ref];

        $headRef = $knownHeadBranch ?? $this->getHeadBranch();
        if ($headRef !== '' && $ref !== $headRef) {
            $otherBranches = array_values(array_filter(
                $knownBranches !== [] ? $knownBranches : $this->getBranches(),
                static fn (string $candidate): bool => $candidate !== '' && $candidate !== $ref
            ));

            if ($otherBranches !== []) {
                $logArgs = ['log', '--first-parent', '--pretty=format:%H%x1f%an%x1f%ad%x1f%s', '--date=iso-strict', '-n', (string) $limit, $ref, '--not'];
                foreach ($otherBranches as $otherBranch) {
                    $logArgs[] = $otherBranch;
                }
            }
        }

        [$output, $status] = GitCommandRunner::runWithStatus($this->path, $logArgs);

        if ($status !== 0 || trim($output) === '') {
            return [];
        }

        $commits = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $parts = explode("\x1f", $line);
            if (count($parts) < 4) {
                continue;
            }

            $commits[] = [
                'hash' => trim((string) $parts[0]),
                'author' => trim((string) $parts[1]),
                'date' => trim((string) $parts[2]),
                'message' => trim((string) $parts[3]),
            ];
        }

        return $commits;
    }

    public function getBranchGraph(string $branch, int $limit = 120, array $knownBranches = [], ?string $knownHeadBranch = null): string
    {
        $ref = $branch !== '' ? $branch : 'HEAD';
        [$headOutput, $headStatus] = GitCommandRunner::runWithStatus($this->path, ['rev-parse', '--verify', 'HEAD']);
        if ($headStatus !== 0 || trim($headOutput) === '') {
            return '';
        }

        $logArgs = ['log', '--graph', '--decorate', '--oneline', '--color=always', '-n', (string) $limit, $ref];

        $headRef = $knownHeadBranch ?? $this->getHeadBranch();
        if ($headRef !== '' && $ref !== $headRef) {
            $otherBranches = array_values(array_filter(
                $knownBranches !== [] ? $knownBranches : $this->getBranches(),
                static fn (string $candidate): bool => $candidate !== '' && $candidate !== $ref
            ));

            if ($otherBranches !== []) {
                $logArgs = ['log', '--graph', '--decorate', '--oneline', '--color=always', '--first-parent', '-n', (string) $limit, $ref, '--not'];
                foreach ($otherBranches as $otherBranch) {
                    $logArgs[] = $otherBranch;
                }
            }
        }

        [$output, $status] = GitCommandRunner::runWithStatus($this->path, $logArgs);
        if ($status !== 0) {
            return '';
        }

        return $output;
    }

    public function getDetailData(bool $includeAllBranchCommits = true): array
    {
        $branches = $this->getBranches();
        $headBranch = $this->getHeadBranch();

        if ($branches === []) {
            $branches = [$headBranch !== '' ? $headBranch : 'HEAD'];
        }

        if (!in_array($headBranch, $branches, true)) {
            $headBranch = $branches[0] ?? 'HEAD';
        }

        $commitsByBranch = [];
        if ($includeAllBranchCommits) {
            foreach ($branches as $branch) {
                $commitsByBranch[$branch] = $this->getBranchCommits($branch, 30, $branches, $headBranch);
            }
        }

        return [
            'repo_path' => $this->path,
            'display_name' => $this->getDisplayName(),
            'display_path' => $this->getDisplayPath(),
            'head_branch' => $headBranch,
            'branches' => $branches,
            'commits_by_branch' => $commitsByBranch,
        ];
    }

    public function getCommitCount(): int
    {
        [$output, $status] = GitCommandRunner::runWithStatus($this->path, ['rev-list', '--count', 'HEAD']);

        if ($status !== 0) {
            return 0;
        }

        $trimmed = trim($output);
        return is_numeric($trimmed) ? (int) $trimmed : 0;
    }

    public function getLastCommit(): array
    {
        [$output, $status] = GitCommandRunner::runWithStatus($this->path, ['log', '-1', '--pretty=format:%an%n%ad%n%s%n%b']);

        if ($status !== 0 || trim($output) === '') {
            return [
                'exists' => false,
                'author' => '',
                'date' => '',
                'subject' => 'No commits yet',
                'full_message' => 'No commits yet',
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', $output);
        $author = trim((string) ($lines[0] ?? ''));
        $date = trim((string) ($lines[1] ?? ''));
        $subject = trim((string) ($lines[2] ?? ''));
        $body = trim(implode("\n", array_slice($lines, 3)));

        $fullMessage = trim($subject . ($body !== '' ? "\n" . $body : ''));
        if ($fullMessage === '') {
            $fullMessage = 'No commit message';
        }

        return [
            'exists' => true,
            'author' => $author !== '' ? $author : 'unknown',
            'date' => $date !== '' ? $date : 'unknown',
            'subject' => $subject !== '' ? $subject : 'No commit message',
            'full_message' => $fullMessage,
        ];
    }
}
