<?php

declare(strict_types=1);

final class RepoBrowser
{
    private const ANSI_FG_COLORS = [
        30 => '#111827',
        31 => '#ef4444',
        32 => '#22c55e',
        33 => '#eab308',
        34 => '#3b82f6',
        35 => '#d946ef',
        36 => '#06b6d4',
        37 => '#e5e7eb',
        90 => '#6b7280',
        91 => '#f87171',
        92 => '#4ade80',
        93 => '#facc15',
        94 => '#60a5fa',
        95 => '#e879f9',
        96 => '#22d3ee',
        97 => '#ffffff',
    ];

    public function __construct(private array $configuredRoots)
    {
    }

    private function ansiToHtml(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $parts = preg_split('/(\e\[[0-9;]*m)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }

        $activeColor = null;
        $result = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^\e\[([0-9;]*)m$/', $part, $matches) === 1) {
                $codes = $matches[1] === '' ? [0] : array_map('intval', explode(';', $matches[1]));
                foreach ($codes as $code) {
                    if ($code === 0 || $code === 39) {
                        $activeColor = null;
                        continue;
                    }

                    if (isset(self::ANSI_FG_COLORS[$code])) {
                        $activeColor = self::ANSI_FG_COLORS[$code];
                    }
                }
                continue;
            }

            $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            if ($activeColor !== null) {
                $result .= '<span style="color:' . $activeColor . ';">' . $escaped . '</span>';
            } else {
                $result .= $escaped;
            }
        }

        return $result;
    }

    public function display(): void
    {
        $scanner = new GitRepoScanner(array_map(static fn (RepoRoot $root): string => $root->getPath(), $this->configuredRoots));
        $repos = $scanner->findRepos();

        $selectedRepo = $_GET['repo'] ?? null;
        $selectedCommand = $_GET['command'] ?? null;

        if (is_string($selectedRepo) && $selectedRepo !== '' && is_string($selectedCommand)) {
            $resolvedRepo = $this->resolveRepoFromSelector($selectedRepo, $repos);
            if ($resolvedRepo !== null) {
                $this->renderRepoDetail($resolvedRepo, $selectedCommand, $repos);
                return;
            }
        }

        $this->renderRepoList($repos);
    }

    private function resolveRepoFromSelector(string $selector, array $repos): ?GitRepo
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
        if ($rootIndex < 1 || $rootIndex > count($this->configuredRoots)) {
            return null;
        }

        array_shift($parts);
        $relative = implode(DIRECTORY_SEPARATOR, $parts);
        $root = $this->configuredRoots[$rootIndex - 1];
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

    private function getRepoSelector(GitRepo $repo): string
    {
        $repoPath = GitRepo::normalizePath($repo->getDisplayPath());

        foreach ($this->configuredRoots as $index => $root) {
            if (!$root instanceof RepoRoot) {
                continue;
            }

            $rootPath = $root->getPath();
            if ($rootPath !== '' && str_starts_with($repoPath, $rootPath . DIRECTORY_SEPARATOR)) {
                $relative = substr($repoPath, strlen($rootPath) + 1);
                return ($index + 1) . '/' . str_replace('\\', '/', $relative);
            }
        }

        return '1/' . str_replace('\\', '/', basename($repoPath));
    }

    private function getRelativeDisplayPath(GitRepo $repo): string
    {
        $repoPath = GitRepo::normalizePath($repo->getDisplayPath());

        foreach ($this->configuredRoots as $index => $root) {
            if (!$root instanceof RepoRoot) {
                continue;
            }

            $rootPath = $root->getPath();
            if ($rootPath !== '' && str_starts_with($repoPath, $rootPath . DIRECTORY_SEPARATOR)) {
                $relative = substr($repoPath, strlen($rootPath) + 1);
                return str_replace('\\', '/', $relative);
            }
        }

        return str_replace('\\', '/', basename($repoPath));
    }

    private function getRepoRootLabel(int $rootIndex): string
    {
        $root = $this->configuredRoots[$rootIndex] ?? null;
        if ($root instanceof RepoRoot) {
            return $root->getLabel();
        }

        return 'root ' . ($rootIndex + 1);
    }

    private function getRepoRootDescription(int $rootIndex): string
    {
        $root = $this->configuredRoots[$rootIndex] ?? null;
        return $root instanceof RepoRoot ? $root->getDescription() : '';
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
        echo '.repo-root-description { margin: -0.35rem 0 0.75rem; color: #64748b; font-size: 0.9rem; }';
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

        foreach ($this->configuredRoots as $rootIndex => $root) {
            if (!$root instanceof RepoRoot) {
                continue;
            }

            $rootPathForFilter = $root->getPath();
            $rootRepos = array_values(array_filter($repos, function (GitRepo $repo) use ($rootPathForFilter): bool {
                $displayPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $repo->getDisplayPath());
                $rootPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rootPathForFilter), DIRECTORY_SEPARATOR);
                return $rootPath !== '' && str_starts_with($displayPath, $rootPath . DIRECTORY_SEPARATOR);
            }));

            if ($rootRepos === []) {
                continue;
            }

            echo '<div class="repo-root-header">Repo root ' . ($rootIndex + 1) . ': ' . htmlspecialchars($this->getRepoRootLabel($rootIndex), ENT_QUOTES, 'UTF-8') . '</div>';
            $rootDescription = $this->getRepoRootDescription($rootIndex);
            if ($rootDescription !== '') {
                echo '<div class="repo-root-description">' . htmlspecialchars($rootDescription, ENT_QUOTES, 'UTF-8') . '</div>';
            }
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

        foreach ($this->configuredRoots as $index => $root) {
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
                $detailHref = '?repo=' . rawurlencode($this->getRepoSelector($repo)) . '&amp;command=branch';
                echo '<li class="repo-item" tabindex="0" role="button" onclick="window.location.href=\'' . addslashes($detailHref) . '\';" onkeydown="if (event.key === \"Enter\" || event.key === \" \") { event.preventDefault(); window.location.href=\'' . addslashes($detailHref) . '\'; }">';
                echo '<div class="repo-name">' . htmlspecialchars($repo->getDisplayName(), ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<div class="repo-meta">' . htmlspecialchars($this->getRelativeDisplayPath($repo), ENT_QUOTES, 'UTF-8') . '</div>';
                echo $this->renderRepoMeta($repo);
                echo '</li>';
            }

            echo '</ul>';
            echo '</li>';
            return;
        }

        foreach ($node['repos'] as $repo) {
            $detailHref = '?repo=' . rawurlencode($this->getRepoSelector($repo)) . '&amp;command=branch';
            echo '<li class="repo-item" tabindex="0" role="button" onclick="window.location.href=\'' . addslashes($detailHref) . '\';" onkeydown="if (event.key === \"Enter\" || event.key === \" \") { event.preventDefault(); window.location.href=\'' . addslashes($detailHref) . '\'; }">';
            echo '<div class="repo-name">' . htmlspecialchars($repo->getDisplayName(), ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div class="repo-meta">' . htmlspecialchars($this->getRelativeDisplayPath($repo), ENT_QUOTES, 'UTF-8') . '</div>';
            echo $this->renderRepoMeta($repo);
            echo '</li>';
        }
    }

    private function renderRepoDetail(GitRepo $repo, string $command, array $repos): void
    {
        $requestStartedAt = microtime(true);
        $detailDataStartedAt = microtime(true);
        $repoData = $repo->getDetailData(false);
        $detailDataDurationMs = (microtime(true) - $detailDataStartedAt) * 1000;
        $repoData['repo_path'] = $this->getRepoSelector($repo);
        $repoData['display_path'] = $this->getRelativeDisplayPath($repo);

        $selectedBranch = $repoData['head_branch'];
        $selectedMode = 'branch';

        if (isset($_GET['branch']) && is_string($_GET['branch']) && $_GET['branch'] !== '') {
            $selectedBranch = $_GET['branch'];
        }

        if (isset($_GET['mode']) && is_string($_GET['mode']) && $_GET['mode'] !== '') {
            $selectedMode = $_GET['mode'];
        }

        $detailUrlBase = '?repo=' . rawurlencode($this->getRepoSelector($repo)) . '&amp;command=branch';
        $selectedBranchLoadStartedAt = microtime(true);
        $branchCommits = [];
        $branchGraph = '';
        if ($selectedMode === 'tree') {
            $branchGraph = $repo->getBranchGraph($selectedBranch, 120, $repoData['branches'], $repoData['head_branch']);
        } else {
            $branchCommits = $repo->getBranchCommits($selectedBranch, 30, $repoData['branches'], $repoData['head_branch']);
        }
        $selectedBranchLoadDurationMs = (microtime(true) - $selectedBranchLoadStartedAt) * 1000;

        $repoData['timing'] = [
            'detail_data_ms' => round($detailDataDurationMs, 1),
            'selected_branch_ms' => round($selectedBranchLoadDurationMs, 1),
            'total_ms' => round((microtime(true) - $requestStartedAt) * 1000, 1),
        ];

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
        echo '.graph-box { max-height: 420px; overflow: auto; border: 1px solid #d6d9df; border-radius: 8px; background: #0f172a; color: #e2e8f0; }';
        echo '.graph-output { margin: 0; padding: 1rem; font-family: Consolas, Monaco, monospace; font-size: 0.82rem; line-height: 1.45; white-space: pre; }';
        echo '.timing-meta { color: #475569; font-size: 0.85rem; margin-top: 0.45rem; }';
        echo '.page-footer { margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #d6d9df; color: #475569; font-size: 0.85rem; }';
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

        echo '<div class="box" style="margin-top: 1rem;">';
        echo '<p><strong>Repository:</strong> ' . htmlspecialchars((string) $repoData['display_path'], ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><strong>Selected branch:</strong> ' . htmlspecialchars($selectedBranch, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '</div>';

        echo '<div class="box" style="margin-top: 1rem;">';
        if ($selectedMode === 'tree') {
            if (trim($branchGraph) === '') {
                echo '<div class="placeholder">No graph output available for this branch yet.</div>';
            } else {
                echo '<div class="graph-box" id="graph-view" aria-live="polite">';
                echo '<pre class="graph-output">' . $this->ansiToHtml($branchGraph) . '</pre>';
                echo '</div>';
            }
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

        echo '<footer class="page-footer">';
        echo '<strong>Timing:</strong> data ' . htmlspecialchars((string) $repoData['timing']['detail_data_ms'], ENT_QUOTES, 'UTF-8') . ' ms, branch ' . htmlspecialchars((string) $repoData['timing']['selected_branch_ms'], ENT_QUOTES, 'UTF-8') . ' ms, total ' . htmlspecialchars((string) $repoData['timing']['total_ms'], ENT_QUOTES, 'UTF-8') . ' ms';
        echo '</footer>';

        echo '<script type="application/json" id="repo-data">' . json_encode($repoData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        echo '<script>';
        echo 'const selectedBranch = ' . json_encode($selectedBranch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';';
        echo '</script>';
        echo '</body>';
        echo '</html>';
    }
}
