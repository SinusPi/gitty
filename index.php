<?php

declare(strict_types=1);

final class GitRepo
{
    public function __construct(private string $path)
    {
    }

    public function getPath(): string
    {
        return $this->path;
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
}

final class GitCommandRunner
{
    public static function run(string $repoPath, array $arguments): string
    {
        $command = 'git --git-dir=' . escapeshellarg($repoPath);

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg((string) $argument);
        }

        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        $text = implode(PHP_EOL, $output);

        if ($status !== 0 && trim($text) === '') {
            return sprintf('git exited with status %d.', $status);
        }

        return trim($text) !== '' ? $text : '(no output)';
    }
}

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

            if ($this->isBareGitRepo($repoPath) && !isset($repos[$repoPath])) {
                $repos[$repoPath] = new GitRepo($repoPath);
            }
        }
    }

    private function isBareGitRepo(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $requiredPaths = [
            $directory . DIRECTORY_SEPARATOR . 'HEAD',
            $directory . DIRECTORY_SEPARATOR . 'config',
            $directory . DIRECTORY_SEPARATOR . 'objects',
            $directory . DIRECTORY_SEPARATOR . 'refs',
        ];

        foreach ($requiredPaths as $path) {
            if (!file_exists($path)) {
                return false;
            }
        }

        return is_file($directory . DIRECTORY_SEPARATOR . 'HEAD')
            && is_file($directory . DIRECTORY_SEPARATOR . 'config')
            && is_dir($directory . DIRECTORY_SEPARATOR . 'objects')
            && is_dir($directory . DIRECTORY_SEPARATOR . 'refs');
    }
}

final class RepoBrowser
{
    public function __construct(private array $configuredRoots)
    {
    }

    public function display(): void
    {
        $scanner = new GitRepoScanner($this->configuredRoots);
        $repos = $scanner->findRepos();

        $selectedRepo = $_GET['repo'] ?? null;
        $selectedCommand = $_GET['command'] ?? null;

        if (is_string($selectedRepo) && $selectedRepo !== '' && is_string($selectedCommand)) {
            $this->renderRepoDetail($selectedRepo, $selectedCommand, $repos);
            return;
        }

        $this->renderRepoList($repos);
    }

    private function renderRepoList(array $repos): void
    {
        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>Git Repo Browser</title>';
        echo '<style>';
        echo 'body { font-family: Arial, sans-serif; margin: 2rem; background: #f4f5f7; color: #222; }';
        echo 'h1 { margin-bottom: 1rem; }';
        echo '.repo-root-header { margin: 1.5rem 0 0.75rem; font-size: 1.1rem; font-weight: 700; }';
        echo '.repo-tree { list-style: none; padding-left: 0; max-width: 900px; }';
        echo '.tree-node { margin-bottom: 0.75rem; }';
        echo '.tree-folder { background: #fff; border: 1px solid #d6d9df; border-radius: 8px; padding: 0.9rem 1rem; margin-bottom: 0.5rem; cursor: pointer; user-select: none; }';
        echo '.tree-folder::before { content: "▸ "; }';
        echo '.tree-folder.open::before { content: "▾ "; }';
        echo '.tree-children { list-style: none; padding-left: 1.25rem; margin: 0; display: none; }';
        echo '.tree-folder.open + .tree-children { display: block; }';
        echo '.repo-item { background: #fff; border: 1px solid #d6d9df; border-radius: 8px; padding: 1rem; margin-bottom: 0.5rem; }';
        echo '.repo-item a { color: #0a58ca; text-decoration: none; font-weight: 600; }';
        echo '.repo-meta { color: #555; margin-top: 0.35rem; font-size: 0.9rem; }';
        echo '.empty { color: #666; font-style: italic; }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<h1>Git Repo Browser</h1>';

        if ($this->configuredRoots === []) {
            echo '<p class="empty">No repo folders configured. Set the GITTY_REPO_ROOTS environment variable or update the repo_roots list in config.php.</p>';
            return;
        }

        if ($repos === []) {
            echo '<p class="empty">No bare Git repositories were found in the configured roots.</p>';
            return;
        }

        foreach ($this->configuredRoots as $root) {
            $rootRepos = array_values(array_filter($repos, function (GitRepo $repo) use ($root): bool {
                $displayPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $repo->getDisplayPath());
                $rootPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
                return $rootPath !== '' && str_starts_with($displayPath, $rootPath . DIRECTORY_SEPARATOR);
            }));

            if ($rootRepos === []) {
                continue;
            }

            echo '<div class="repo-root-header">Repo root: ' . htmlspecialchars($root, ENT_QUOTES, 'UTF-8') . '</div>';
            $tree = $this->buildTree($rootRepos);
            echo '<ul class="repo-tree">';
            foreach ($tree as $node) {
                $this->renderTreeNode($node);
            }
            echo '</ul>';
        }

        echo '</body>';
        echo '</html>';
    }

    private function buildTree(array $repos): array
    {
        $root = [];

        foreach ($repos as $repo) {
            $relativePath = $this->stripConfiguredRoot($repo->getDisplayPath());
            $segments = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $relativePath), static fn (string $segment) => $segment !== ''));

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
                $found = null;

                foreach ($cursor as $key => $node) {
                    if (($node['name'] ?? null) === $segment && (isset($node['children']) || isset($node['repos']))) {
                        $found = $key;
                        break;
                    }
                }

                if ($found === null) {
                    $cursor[] = [
                        'name' => $segment,
                        'children' => [],
                        'repos' => [],
                    ];
                    $found = count($cursor) - 1;
                }

                if ($isLeaf) {
                    $cursor[$found]['repos'][] = $repo;
                } else {
                    $cursor = &$cursor[$found]['children'];
                }
            }
        }

        return $root;
    }

    private function stripConfiguredRoot(string $path): string
    {
        $normalized = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        foreach ($this->configuredRoots as $root) {
            $rootPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
            if ($rootPath === '' || $normalized === $rootPath) {
                continue;
            }

            if (str_starts_with($normalized, $rootPath . DIRECTORY_SEPARATOR)) {
                return substr($normalized, strlen($rootPath) + 1);
            }
        }

        return basename($normalized) === '.git' ? dirname($normalized) : $normalized;
    }

    private function renderTreeNode(array $node): void
    {
        $hasChildren = !empty($node['children']);
        $hasRepos = !empty($node['repos']);

        if ($hasChildren) {
            echo '<li class="tree-node">';
            echo '<div class="tree-folder open" onclick="this.classList.toggle(\'open\');">' . htmlspecialchars((string) $node['name'], ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<ul class="tree-children">';

            foreach ($node['children'] as $child) {
                $this->renderTreeNode($child);
            }

            foreach ($node['repos'] as $repo) {
                $href = '?repo=' . rawurlencode($repo->getPath());
                echo '<li class="repo-item">';
                echo '<div><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($repo->getDisplayName(), ENT_QUOTES, 'UTF-8') . '</a></div>';
                echo '<div class="repo-meta">' . htmlspecialchars($repo->getDisplayPath(), ENT_QUOTES, 'UTF-8') . '</div>';
                echo '</li>';
            }

            echo '</ul>';
            echo '</li>';
            return;
        }

        foreach ($node['repos'] as $repo) {
            $href = '?repo=' . rawurlencode($repo->getPath());
            echo '<li class="repo-item">';
            echo '<div><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($repo->getDisplayName(), ENT_QUOTES, 'UTF-8') . '</a></div>';
            echo '<div class="repo-meta">' . htmlspecialchars($repo->getDisplayPath(), ENT_QUOTES, 'UTF-8') . '</div>';
            echo '</li>';
        }
    }

    private function renderRepoDetail(string $repoPath, string $command, array $repos): void
    {
        $repo = null;
        foreach ($repos as $candidate) {
            if ($candidate->getPath() === $repoPath) {
                $repo = $candidate;
                break;
            }
        }

        if ($repo === null) {
            http_response_code(404);
            echo 'Repository not found.';
            return;
        }

        $output = match ($command) {
            'branch' => $repo->getBranchOutput(),
            'log' => $repo->getLogOutput(),
            default => 'Unsupported command. Allowed values: branch, log.',
        };

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>' . htmlspecialchars($repo->getName(), ENT_QUOTES, 'UTF-8') . ' - Git output</title>';
        echo '<style>';
        echo 'body { font-family: Arial, sans-serif; margin: 2rem; background: #f4f5f7; color: #222; }';
        echo 'a { color: #0a58ca; text-decoration: none; }';
        echo '.box { background: #fff; border: 1px solid #d6d9df; border-radius: 8px; padding: 1rem; }';
        echo '.actions { display: flex; gap: 0.5rem; margin-bottom: 1rem; }';
        echo '.button { display: inline-block; padding: 0.55rem 0.9rem; border-radius: 6px; background: #0d6efd; color: #fff; }';
        echo 'pre { white-space: pre-wrap; word-break: break-word; background: #111827; color: #f9fafb; border-radius: 8px; padding: 1rem; overflow: auto; }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<p><a href="./">← Back to repo list</a></p>';
        echo '<h1>' . htmlspecialchars($repo->getName(), ENT_QUOTES, 'UTF-8') . '</h1>';
        echo '<div class="actions">';
        echo '<a class="button" href="?repo=' . rawurlencode($repo->getPath()) . '&amp;command=branch">Branch</a>';
        echo '<a class="button" href="?repo=' . rawurlencode($repo->getPath()) . '&amp;command=log">Log</a>';
        echo '</div>';
        echo '<div class="box">';
        echo '<p><strong>Repository:</strong> ' . htmlspecialchars($repo->getPath(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><strong>Command:</strong> ' . htmlspecialchars($command, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<pre>' . htmlspecialchars($output, ENT_QUOTES, 'UTF-8') . '</pre>';
        echo '</div>';
        echo '</body>';
        echo '</html>';
    }
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
        $groups[$root] = array_values(array_filter($repos, function (GitRepo $repo) use ($root): bool {
            $displayPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $repo->getDisplayPath());
            $rootPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
            return $rootPath !== '' && str_starts_with($displayPath, $rootPath . DIRECTORY_SEPARATOR);
        }));
    }

    return $groups;
}

function stripConfiguredRootForCli(string $path, array $repoRoots): string
{
    $normalized = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

    foreach ($repoRoots as $root) {
        $rootPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        if ($rootPath === '' || $normalized === $rootPath) {
            continue;
        }

        if (str_starts_with($normalized, $rootPath . DIRECTORY_SEPARATOR)) {
            return substr($normalized, strlen($rootPath) + 1);
        }
    }

    return $normalized;
}

function printCliTreeNode(array $node, int $depth): void
{
    $indent = str_repeat('  ', $depth);

    if (!empty($node['children'])) {
        echo $indent . $node['name'] . PHP_EOL;
        foreach ($node['children'] as $child) {
            printCliTreeNode($child, $depth + 1);
        }
    }

    foreach ($node['repos'] as $repo) {
        $displayName = $repo->getDisplayName();
        $displayPath = $repo->getDisplayPath();
        echo $indent . '- ' . $displayName . ' [' . $displayPath . ']' . PHP_EOL;
    }
}

function normalizeConfiguredRoots(string|array|null $roots): array
{
    if (is_string($roots)) {
        $roots = preg_split('/[\r\n,;]+/', $roots) ?: [];
    }

    if (!is_array($roots)) {
        $roots = [];
    }

    $normalized = [];
    foreach ($roots as $root) {
        $trimmed = trim((string) $root);
        if ($trimmed === '') {
            continue;
        }

        $normalized[] = $trimmed;
    }

    return array_values(array_unique($normalized));
}

function getConfiguredRepoRoots(): array
{
    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
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

function runCliMode(array $argv): void
{
    $arguments = $argv;
    array_shift($arguments);

    $repoRoots = getConfiguredRepoRoots();
    $scanner = new GitRepoScanner($repoRoots);
    $repos = $scanner->findRepos();

    if ($arguments === [] || in_array('--list', $arguments, true)) {
        echo 'Configured repo roots:' . PHP_EOL;
        foreach ($repoRoots as $root) {
            echo ' - ' . $root . PHP_EOL;
        }

        echo PHP_EOL . 'Found repositories (' . count($repos) . '):' . PHP_EOL;
        $groups = buildRootGroups($repos, $repoRoots);

        foreach ($repoRoots as $root) {
            $rootRepos = $groups[$root] ?? [];
            if ($rootRepos === []) {
                continue;
            }

            echo 'Repo root: ' . $root . PHP_EOL;
            $tree = buildCliTree($rootRepos, [$root]);
            foreach ($tree as $node) {
                printCliTreeNode($node, 1);
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
            echo "  php index.php --repo \"/path/to/repo.git\" --command branch\n";
            echo "  php index.php --repo \"/path/to/repo.git\" --command log\n";
            return;
        }
    }

    if ($repoPath === null || $command === null) {
        echo "A repo path and command are required. Use --help for usage.\n";
        return;
    }

    $matchedRepo = null;
    foreach ($repos as $repo) {
        if ($repo->getPath() === $repoPath) {
            $matchedRepo = $repo;
            break;
        }
    }

    if ($matchedRepo === null) {
        echo "Repository not found: {$repoPath}\n";
        return;
    }

    $output = match ($command) {
        'branch' => $matchedRepo->getBranchOutput(),
        'log' => $matchedRepo->getLogOutput(),
        default => 'Unsupported command. Use branch or log.',
    };

    echo $output . PHP_EOL;
}

if (PHP_SAPI === 'cli') {
    runCliMode($argv);
    exit(0);
}

$browser = new RepoBrowser(getConfiguredRepoRoots());
$browser->display();
