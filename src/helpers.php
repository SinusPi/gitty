<?php

declare(strict_types=1);

function normalizeConfiguredRoots(string|array|null $roots): array
{
    if (is_string($roots)) {
        $roots = preg_split('/[\r\n,;]+/', $roots) ?: [];
    }

    if (!is_array($roots)) {
        $roots = [];
    }

    $normalized = [];
    $seenPaths = [];
    foreach ($roots as $root) {
        $repoRoot = RepoRoot::fromConfigValue($root);
        if ($repoRoot === null) {
            continue;
        }

        $normalizedPath = $repoRoot->getPath();
        if ($normalizedPath === '' || isset($seenPaths[$normalizedPath])) {
            continue;
        }

        $seenPaths[$normalizedPath] = true;
        $normalized[] = $repoRoot;
    }

    return array_values($normalized);
}

function getConfiguredRepoRoots(): array
{
    $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
    $config = [];

    if (is_file($configPath)) {
        $loadedConfig = require $configPath;
        if (is_array($loadedConfig)) {
            $config = $loadedConfig;
        }
    }

    $defaultRoots = [];
    if (isset($config['repo_roots']) && is_array($config['repo_roots'])) {
        $defaultRoots = $config['repo_roots'];
    }

    $environmentRoots = getenv('GITTY_REPO_ROOTS');
    if (is_string($environmentRoots) && trim($environmentRoots) !== '') {
        return normalizeConfiguredRoots($environmentRoots);
    }

    return normalizeConfiguredRoots($defaultRoots);
}

function getRepoRootPaths(array $repoRoots): array
{
    return array_values(array_map(static fn (RepoRoot $root): string => $root->getPath(), array_filter($repoRoots, static fn ($root): bool => $root instanceof RepoRoot)));
}

function formatRepoRootLabel(array $repoRoots, int $index): string
{
    $root = $repoRoots[$index] ?? null;
    return $root instanceof RepoRoot ? $root->getLabel() : 'root ' . ($index + 1);
}

function formatRepoRootDescription(array $repoRoots, int $index): string
{
    $root = $repoRoots[$index] ?? null;
    return $root instanceof RepoRoot ? $root->getDescription() : '';
}

function resolveRepoSelector(string $selector, array $repos, array $repoRoots): ?GitRepo
{
    $trimmed = trim($selector);
    if ($trimmed === '' || str_contains($trimmed, ':') || str_starts_with($trimmed, '/') || str_starts_with($trimmed, '\\')) {
        return null;
    }

    $parts = preg_split('#[\\/]+#', $trimmed) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    if ($parts === []) {
        return null;
    }

    $rootIndex = (int) ($parts[0] ?? 0);
    if ($rootIndex < 1 || $rootIndex > count($repoRoots)) {
        return null;
    }

    array_shift($parts);
    $relative = implode(DIRECTORY_SEPARATOR, $parts);
    $root = $repoRoots[$rootIndex - 1];
    if (!$root instanceof RepoRoot) {
        return null;
    }

    $rootPath = $root->getPath();
    $candidatePath = $relative === '' ? $rootPath : GitRepo::normalizePath($rootPath . DIRECTORY_SEPARATOR . $relative);

    foreach ($repos as $repo) {
        $repoPath = GitRepo::normalizePath($repo->getPath());
        $displayPath = GitRepo::normalizePath($repo->getDisplayPath());
        if ($repoPath === $candidatePath || $displayPath === $candidatePath) {
            return $repo;
        }
    }

    return null;
}

function buildCliTree(array $repos, array $repoRoots): array
{
    $root = [];

    foreach ($repos as $repo) {
        $path = $repo->getDisplayPath();
        $relative = stripConfiguredRootForCli($path, $repoRoots);
        $segments = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $relative), static fn (string $segment) => $segment !== ''));

        if ($segments === []) {
            $root[] = [
                'name' => $repo->getDisplayName(),
                'children' => [],
                'repos' => [$repo],
            ];
            continue;
        }

        $cursor = &$root;
        foreach ($segments as $index => $segment) {
            $isLeaf = $index === count($segments) - 1;
            $foundIndex = null;

            foreach ($cursor as $key => $node) {
                if (($node['name'] ?? null) === $segment) {
                    $foundIndex = $key;
                    break;
                }
            }

            if ($foundIndex === null) {
                $cursor[] = [
                    'name' => $segment,
                    'children' => [],
                    'repos' => [],
                ];
                $foundIndex = count($cursor) - 1;
            }

            if ($isLeaf) {
                $cursor[$foundIndex]['repos'][] = $repo;
            } else {
                $cursor = &$cursor[$foundIndex]['children'];
            }
        }
    }

    return $root;
}

function buildRootGroups(array $repos, array $repoRoots): array
{
    $groups = [];

    foreach ($repoRoots as $root) {
        if (!$root instanceof RepoRoot) {
            continue;
        }

        $rootPath = $root->getPath();
        $groups[$rootPath] = array_values(array_filter($repos, function (GitRepo $repo) use ($rootPath): bool {
            $displayPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $repo->getDisplayPath());
            $normalizedRootPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rootPath), DIRECTORY_SEPARATOR);
            return $normalizedRootPath !== '' && str_starts_with($displayPath, $normalizedRootPath . DIRECTORY_SEPARATOR);
        }));
    }

    return $groups;
}

function stripConfiguredRootForCli(string $path, array $repoRoots): string
{
    $normalized = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

    foreach ($repoRoots as $root) {
        if (!$root instanceof RepoRoot) {
            continue;
        }

        $rootPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $root->getPath()), DIRECTORY_SEPARATOR);
        if ($rootPath === '' || $normalized === $rootPath) {
            continue;
        }

        if (str_starts_with($normalized, $rootPath . DIRECTORY_SEPARATOR)) {
            return substr($normalized, strlen($rootPath) + 1);
        }
    }

    return $normalized;
}

function printCliTreeNode(array $node, int $depth, array $repoRoots): void
{
    $indent = str_repeat('  ', $depth);

    if (!empty($node['children'])) {
        echo $indent . $node['name'] . PHP_EOL;
        foreach ($node['children'] as $child) {
            printCliTreeNode($child, $depth + 1, $repoRoots);
        }
    }

    foreach ($node['repos'] as $repo) {
        $displayName = $repo->getDisplayName();
        $displayPath = $repo->getDisplayPath();
        $relativePath = stripConfiguredRootForCli($displayPath, $repoRoots);
        $commitCount = $repo->getCommitCount();
        $lastCommit = $repo->getLastCommit();

        $lastCommitText = $lastCommit['exists']
            ? $lastCommit['author'] . ' • ' . $lastCommit['date'] . ' • ' . $lastCommit['subject']
            : 'No commits yet';

        echo $indent . '- ' . $displayName . ' [' . $relativePath . '] (commits: ' . $commitCount . ', last: ' . $lastCommitText . ')' . PHP_EOL;
    }
}

function runCliMode(array $argv): void
{
    $arguments = $argv;
    array_shift($arguments);

    $repoRootDefinitions = getConfiguredRepoRoots();
    $repoRoots = getRepoRootPaths($repoRootDefinitions);
    $scanner = new GitRepoScanner($repoRoots);
    $repos = $scanner->findRepos();

    if ($arguments === [] || in_array('--list', $arguments, true)) {
        echo 'Configured repo roots:' . PHP_EOL;
        foreach ($repoRootDefinitions as $index => $root) {
            if (!$root instanceof RepoRoot) {
                continue;
            }

            $line = ' - ' . ($index + 1) . ' (' . $root->getLabel() . ')';
            $description = $root->getDescription();
            if ($description !== '') {
                $line .= ' - ' . $description;
            }
            echo $line . PHP_EOL;
        }

        echo PHP_EOL . 'Found repositories (' . count($repos) . '):' . PHP_EOL;
        $groups = buildRootGroups($repos, $repoRootDefinitions);

        foreach ($repoRootDefinitions as $index => $root) {
            if (!$root instanceof RepoRoot) {
                continue;
            }

            $rootRepos = $groups[$root->getPath()] ?? [];
            if ($rootRepos === []) {
                continue;
            }

            echo 'Repo root: ' . ($index + 1) . ' (' . $root->getLabel() . ')' . PHP_EOL;
            $description = $root->getDescription();
            if ($description !== '') {
                echo '  ' . $description . PHP_EOL;
            }
            $tree = buildCliTree($rootRepos, $repoRootDefinitions);
            foreach ($tree as $node) {
                printCliTreeNode($node, 1, $repoRootDefinitions);
            }
            echo PHP_EOL;
        }
        return;
    }

    $repoPath = null;
    $command = null;

    for ($i = 0; $i < count($arguments); $i++) {
        $value = $arguments[$i];

        if ($value === '--repo' && isset($arguments[$i + 1])) {
            $repoPath = $arguments[$i + 1];
            $i++;
            continue;
        }

        if ($value === '--command' && isset($arguments[$i + 1])) {
            $command = $arguments[$i + 1];
            $i++;
            continue;
        }

        if ($value === '--help' || $value === '-h') {
            echo "Usage:\n";
            echo "  php index.php --list\n";
            echo "  php index.php --repo \"1/apples/cider apples/sour two\" --command branch\n";
            echo "  php index.php --repo \"1/apples/cider apples/sour two\" --command log\n";
            echo "  php index.php --repo \"1/apples/cider apples/sour two\" --command tree\n";
            return;
        }
    }

    if ($repoPath === null || $command === null) {
        echo "A repo path and command are required. Use --help for usage.\n";
        return;
    }

    $matchedRepo = resolveRepoSelector($repoPath, $repos, $repoRootDefinitions);
    if ($matchedRepo === null && $repoPath !== '') {
        $matchedRepo = null;
        foreach ($repos as $repo) {
            if ($repo->getPath() === GitRepo::normalizePath($repoPath)) {
                $matchedRepo = $repo;
                break;
            }
        }
    }

    if ($matchedRepo === null) {
        echo "Repository not found: {$repoPath}\n";
        return;
    }

    $output = match ($command) {
        'branch' => $matchedRepo->getBranchOutput(),
        'log' => $matchedRepo->getLogOutput(),
        'tree' => $matchedRepo->getTreeOutput(),
        default => 'Unsupported command. Use branch, log, or tree.',
    };

    echo $output . PHP_EOL;
}
