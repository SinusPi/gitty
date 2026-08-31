<?php

declare(strict_types=1);

final class GitRepoScanner
{
    public function __construct(private array $repoRoots)
    {
    }

    public function findRepos(): array
    {
        $repos = [];

        foreach ($this->repoRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $this->scanDirectory($root, $repos);
        }

        ksort($repos);

        return array_values($repos);
    }

    private function scanDirectory(string $directory, array &$repos): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isDir()) {
                continue;
            }

            $repoPath = $item->getPathname();

            $normalizedRepoPath = GitRepo::normalizePath($repoPath);
            if ($this->isBareGitRepo($repoPath) && !isset($repos[$normalizedRepoPath])) {
                $repos[$normalizedRepoPath] = new GitRepo($repoPath);
            }
        }
    }

    private function isBareGitRepo(string $directory): bool
    {
        if (!is_dir($directory)) return false;

        $required = [
            $directory . DIRECTORY_SEPARATOR . 'HEAD' => 'file',
            $directory . DIRECTORY_SEPARATOR . 'config' => 'file',
            $directory . DIRECTORY_SEPARATOR . 'objects' => 'dir',
            $directory . DIRECTORY_SEPARATOR . 'refs' => 'dir',
        ];

        foreach ($required as $path => $type) {
            if ($type === 'file' && !is_file($path)) return false;
            if ($type === 'dir' && !is_dir($path)) return false;
        }

        return true;
    }
}
