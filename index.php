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

    public function getBranchCommits(string $branch, int $limit = 30): array
    {
        $ref = $branch !== '' ? $branch : 'HEAD';
        [$output, $status] = GitCommandRunner::runWithStatus($this->path, ['log', '--pretty=format:%H%x1f%an%x1f%ad%x1f%s', '--date=iso-strict', '-n', (string) $limit, $ref]);

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

    public function getDetailData(): array
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
        foreach ($branches as $branch) {
            $commitsByBranch[$branch] = $this->getBranchCommits($branch, 30);
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
        echo '.repo-item { background: #fff; border: 1px solid #d6d9df; border-radius: 8px; padding: 1rem; margin-bottom: 0.5rem; cursor: pointer; transition: border-color 0.15s ease, box-shadow 0.15s ease; }';
        echo '.repo-item:hover { border-color: #8bb3ff; box-shadow: 0 0 0 2px rgba(13,110,253,0.08); }';
        echo '.repo-item:focus { outline: 2px solid #0d6efd; outline-offset: 2px; }';
        echo '.repo-name { color: #0a58ca; text-decoration: none; font-weight: 600; margin-bottom: 0.25rem; }';
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

    private function renderRepoMeta(GitRepo $repo): string
    {
        $commitCount = $repo->getCommitCount();
        $lastCommit = $repo->getLastCommit();

        if ($lastCommit['exists'] === false) {
            $lastCommitText = 'No commits yet';
            $hoverText = 'This repository has no commits yet.';
        } else {
            $formatted = trim($lastCommit['author'] !== '' ? $lastCommit['author'] : 'unknown');
            $lastCommitText = $formatted . ' • ' . $lastCommit['date'] . ' • ' . $lastCommit['subject'];
            $hoverText = $lastCommit['full_message'];
        }

        return '<div class="repo-meta"><strong>Commits:</strong> ' . (int) $commitCount . ' &nbsp;|&nbsp; <strong>Last:</strong> <span title="' . htmlspecialchars($hoverText, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lastCommitText, ENT_QUOTES, 'UTF-8') . '</span></div>';
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
                $detailHref = '?repo=' . rawurlencode($repo->getPath()) . '&amp;command=branch';
                echo '<li class="repo-item" tabindex="0" role="button" onclick="window.location.href=\'' . addslashes($detailHref) . '\';" onkeydown="if (event.key === \"Enter\" || event.key === \" \") { event.preventDefault(); window.location.href=\'' . addslashes($detailHref) . '\'; }">';
                echo '<div class="repo-name">' . htmlspecialchars($repo->getDisplayName(), ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<div class="repo-meta">' . htmlspecialchars($repo->getDisplayPath(), ENT_QUOTES, 'UTF-8') . '</div>';
                echo $this->renderRepoMeta($repo);
                echo '</li>';
            }

            echo '</ul>';
            echo '</li>';
            return;
        }

        foreach ($node['repos'] as $repo) {
            $detailHref = '?repo=' . rawurlencode($repo->getPath()) . '&amp;command=branch';
            echo '<li class="repo-item" tabindex="0" role="button" onclick="window.location.href=\'' . addslashes($detailHref) . '\';" onkeydown="if (event.key === \"Enter\" || event.key === \" \") { event.preventDefault(); window.location.href=\'' . addslashes($detailHref) . '\'; }">';
            echo '<div class="repo-name">' . htmlspecialchars($repo->getDisplayName(), ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div class="repo-meta">' . htmlspecialchars($repo->getDisplayPath(), ENT_QUOTES, 'UTF-8') . '</div>';
            echo $this->renderRepoMeta($repo);
            echo '</li>';
        }
    }

    private function renderRepoDetail(string $repoPath, string $command, array $repos): void
    {
        $repo = null;
        $normalizedRepoPath = GitRepo::normalizePath($repoPath);
        foreach ($repos as $candidate) {
            if (GitRepo::normalizePath($candidate->getPath()) === $normalizedRepoPath) {
                $repo = $candidate;
                break;
            }
        }

        if ($repo === null) {
            http_response_code(404);
            echo 'Repository not found.';
            return;
        }

        $repoData = $repo->getDetailData();
        $selectedBranch = $repoData['head_branch'];
        $selectedMode = 'branch';

        if (isset($_GET['branch']) && is_string($_GET['branch']) && $_GET['branch'] !== '') {
            $selectedBranch = $_GET['branch'];
        }

        if (isset($_GET['mode']) && is_string($_GET['mode']) && $_GET['mode'] !== '') {
            $selectedMode = $_GET['mode'];
        }

        $detailUrlBase = '?repo=' . rawurlencode($repo->getPath()) . '&amp;command=branch';
        $branchCommits = $repoData['commits_by_branch'][$selectedBranch] ?? $repo->getBranchCommits($selectedBranch, 30);

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>Git Repo Browser</title>';
        echo '<style>';
        echo 'body { font-family: Arial, sans-serif; margin: 2rem; background: #f4f5f7; color: #222; }';
        echo 'a { color: #0a58ca; text-decoration: none; }';
        echo '.layout { max-width: 1120px; }';
        echo '.page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }';
        echo '.box { background: #fff; border: 1px solid #d6d9df; border-radius: 8px; padding: 1rem; }';
        echo '.toolbar { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }';
        echo '.branch-list { display: flex; flex-wrap: wrap; gap: 0.5rem; }';
        echo '.branch-button { display: inline-block; border: 1px solid #d6d9df; background: #fff; padding: 0.45rem 0.8rem; border-radius: 999px; text-decoration: none; font: inherit; color: #111827; }';
        echo '.branch-button.is-selected { background: #dbeafe; border-color: #60a5fa; font-weight: 700; }';
        echo '.button { display: inline-block; border: 1px solid #d7ddf6; background: #eef2ff; color: #1f2a44; border-radius: 6px; padding: 0.7rem 1rem; text-decoration: none; font: inherit; }';
        echo '.button.is-active { background: #0d6efd; color: #fff; border-color: #0d6efd; }';
        echo '.mode-label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: #475569; margin: 1rem 0 0.5rem; font-weight: 700; }';
        echo '.commit-box { max-height: 420px; overflow-y: auto; border: 1px solid #d6d9df; border-radius: 8px; background: #fff; }';
        echo '.commit-row { display: grid; grid-template-columns: 100px 200px 220px 1fr; gap: 0.85rem; padding: 0.8rem 1rem; border-bottom: 1px solid #eef2f7; }';
        echo '.commit-row:last-child { border-bottom: 0; }';
        echo '.commit-hash { font-family: Consolas, monospace; font-size: 0.82rem; color: #1f2937; }';
        echo '.commit-meta { color: #4b5563; font-size: 0.82rem; }';
        echo '.commit-message { font-weight: 600; color: #111827; word-break: break-word; }';
        echo '.placeholder { padding: 1rem; color: #475569; background: #f8fafc; border: 1px solid #dbe2ed; border-radius: 8px; }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="layout">';
        echo '<div class="page-header">';
        echo '<p><a href="./">← Back to repo list</a></p>';
        echo '<h1>' . htmlspecialchars($repoData['display_name'], ENT_QUOTES, 'UTF-8') . '</h1>';
        echo '</div>';

        echo '<div class="toolbar box">';
        echo '<div class="branch-list" id="branch-list" aria-label="Branches">';
        foreach ($repoData['branches'] as $branch) {
            $isSelected = $branch === $selectedBranch;
            $branchUrl = $detailUrlBase . '&amp;branch=' . rawurlencode($branch) . '&amp;mode=branch';
            echo '<a class="branch-button' . ($isSelected ? ' is-selected' : '') . '" href="' . $branchUrl . '" aria-pressed="' . ($isSelected ? 'true' : 'false') . '">' . htmlspecialchars($branch, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</div>';
        $treeUrl = $detailUrlBase . '&amp;branch=' . rawurlencode($selectedBranch) . '&amp;mode=tree';
        echo '<a class="button' . ($selectedMode === 'tree' ? ' is-active' : '') . '" href="' . $treeUrl . '">Tree</a>';
        echo '</div>';

        echo '<div class="mode-label">Primary mode</div>';
        echo '<div class="box">';
        echo '<p><strong>Repository:</strong> ' . htmlspecialchars($repoData['display_path'], ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><strong>Selected branch:</strong> ' . htmlspecialchars($selectedBranch, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '</div>';

        echo '<div class="box" style="margin-top: 1rem;">';
        if ($selectedMode === 'tree') {
            echo '<div class="placeholder">Tree mode is not implemented yet. Git commit graph output will appear here in a future update.</div>';
        } else {
            echo '<div class="commit-box" id="commit-list" aria-live="polite">';
            if ($branchCommits === []) {
                echo '<div class="placeholder">No commits available for this branch yet.</div>';
            } else {
                foreach ($branchCommits as $commit) {
                    $hash = (string) ($commit['hash'] ?? '');
                    $author = (string) ($commit['author'] ?? 'unknown');
                    $date = (string) ($commit['date'] ?? 'unknown');
                    $message = (string) ($commit['message'] ?? '(no message)');
                    echo '<div class="commit-row">';
                    echo '<div class="commit-hash">' . htmlspecialchars(substr($hash, 0, 8), ENT_QUOTES, 'UTF-8') . '</div>';
                    echo '<div class="commit-meta">' . htmlspecialchars($author, ENT_QUOTES, 'UTF-8') . '</div>';
                    echo '<div class="commit-meta">' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</div>';
                    echo '<div class="commit-message">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
                    echo '</div>';
                }
            }
            echo '</div>';
        }
        echo '</div>';

        echo '<script type="application/json" id="repo-data">' . json_encode($repoData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        echo '<script>';
        echo 'const selectedBranch = ' . json_encode($selectedBranch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';';
        echo '</script>';
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
        $commitCount = $repo->getCommitCount();
        $lastCommit = $repo->getLastCommit();

        $lastCommitText = $lastCommit['exists']
            ? $lastCommit['author'] . ' • ' . $lastCommit['date'] . ' • ' . $lastCommit['subject']
            : 'No commits yet';

        echo $indent . '- ' . $displayName . ' [' . $displayPath . '] (commits: ' . $commitCount . ', last: ' . $lastCommitText . ')' . PHP_EOL;
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

        $resolved = $trimmed;
        if (!preg_match('/^[A-Za-z]:[\\\\\/]/', $trimmed) && !str_starts_with($trimmed, '\\\\') && !str_starts_with($trimmed, '/')) {
            $relative = ltrim($trimmed, '.\\/');
            $resolved = __DIR__ . DIRECTORY_SEPARATOR . $relative;
        }

        $normalized[] = GitRepo::normalizePath($resolved);
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
