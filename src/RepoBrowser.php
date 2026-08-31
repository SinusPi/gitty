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
                echo $this->renderRepoDetail($resolvedRepo, $selectedCommand, $repos);
                return;
            }
        }

        echo $this->renderRepoList($repos);
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

    private function renderSharedStyles(): string
    {
        ob_start();
        ?>
        <style>
            body { font-family: Arial, sans-serif; margin: 2rem; background: #f4f5f7; color: #222; }
            a { color: #0a58ca; text-decoration: none; }
            .layout { max-width: 1120px; }
            h1 { margin: 0 0 1rem; }
            .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
            .repo-root-header { margin: 1.5rem 0 0.75rem; font-size: 1.1rem; font-weight: 700; }
            .repo-root-description { margin: -0.35rem 0 0.75rem; color: #64748b; font-size: 0.9rem; }
            .repo-tree { list-style: none; padding-left: 0; max-width: 900px; }
            .tree-node { margin-bottom: 0.75rem; }
            .tree-folder { background: #fff; border: 1px solid #d6d9df; border-radius: 8px; padding: 0.9rem 1rem; margin-bottom: 0.5rem; cursor: pointer; user-select: none; }
            .tree-folder::before { content: "▸ "; }
            .tree-folder.open::before { content: "▾ "; }
            .tree-children { list-style: none; padding-left: 1.25rem; margin: 0; display: none; }
            .tree-folder.open + .tree-children { display: block; }
            .repo-item { background: #fff; border: 1px solid #d6d9df; border-radius: 8px; padding: 1rem; margin-bottom: 0.5rem; cursor: pointer; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
            .repo-item:hover { border-color: #8bb3ff; box-shadow: 0 0 0 2px rgba(13,110,253,0.08); }
            .repo-item:focus { outline: 2px solid #0d6efd; outline-offset: 2px; }
            .repo-name { color: #0a58ca; text-decoration: none; font-weight: 600; margin-bottom: 0.25rem; }
            .repo-meta { color: #555; margin-top: 0.35rem; font-size: 0.9rem; }
            .empty { color: #666; font-style: italic; }
            .box { background: #fff; border: 1px solid #d6d9df; border-radius: 8px; padding: 1rem; }
            .toolbar { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
            .branch-list { display: flex; flex-wrap: wrap; gap: 0.5rem; }
            .branch-button { display: inline-block; border: 1px solid #d6d9df; background: #fff; padding: 0.45rem 0.8rem; border-radius: 999px; text-decoration: none; font: inherit; color: #111827; }
            .branch-button.is-selected { background: #dbeafe; border-color: #60a5fa; font-weight: 700; }
            .button { display: inline-block; border: 1px solid #d7ddf6; background: #eef2ff; color: #1f2a44; border-radius: 6px; padding: 0.7rem 1rem; text-decoration: none; font: inherit; }
            .button.is-active { background: #0d6efd; color: #fff; border-color: #0d6efd; }
            .commit-box { max-height: 420px; overflow-y: auto; border: 1px solid #d6d9df; border-radius: 8px; background: #fff; }
            .commit-row { display: grid; grid-template-columns: 100px 200px 220px 1fr; gap: 0.85rem; padding: 0.8rem 1rem; border-bottom: 1px solid #eef2f7; }
            .commit-row:last-child { border-bottom: 0; }
            .commit-hash { font-family: Consolas, monospace; font-size: 0.82rem; color: #1f2937; }
            .commit-meta { color: #4b5563; font-size: 0.82rem; }
            .commit-message { font-weight: 600; color: #111827; word-break: break-word; }
            .tag-badge { display: inline-block; padding: 0.05rem 0.45rem; margin-right: 0.25rem; border: 1px solid #c7d2fe; background: #eef2ff; color: #3730a3; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
            .graph-box { max-height: 420px; overflow: auto; border: 1px solid #d6d9df; border-radius: 8px; background: #0f172a; color: #e2e8f0; }
            .graph-output { margin: 0; padding: 1rem; font-family: Consolas, Monaco, monospace; font-size: 0.82rem; line-height: 1.45; white-space: pre; }
            .page-footer { margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #d6d9df; color: #475569; font-size: 0.85rem; }
            .placeholder { padding: 1rem; color: #475569; background: #f8fafc; border: 1px solid #dbe2ed; border-radius: 8px; }
        </style>
        <?php

        return (string) ob_get_clean();
    }

    private function renderHtmlWrapper(string $headerHtml, string $contentHtml, string $footerHtml = '', string $scriptHtml = ''): string
    {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Git Repo Browser</title>
            <?= $this->renderSharedStyles() ?>
        </head>
        <body>
            <div class="layout">
                <?= $headerHtml ?>
                <?= $contentHtml ?>
                <?php if ($footerHtml !== ''): ?>
                    <footer class="page-footer"><?= $footerHtml ?></footer>
                <?php endif; ?>
                <?php if ($scriptHtml !== ''): ?>
                    <?= $scriptHtml ?>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php

        return (string) ob_get_clean();
    }

    private function outputHtmlWrapper(string $headerHtml, string $contentHtml, string $footerHtml = '', string $scriptHtml = ''): void
    {
        echo $this->renderHtmlWrapper($headerHtml, $contentHtml, $footerHtml, $scriptHtml);
    }

    private function renderRepoList(array $repos): string
    {
        ob_start();

        if ($this->configuredRoots === []) {
            ?>
            <p class="empty">No repo folders configured. Set the GITTY_REPO_ROOTS environment variable or update the repo_roots list in config.php.</p>
            <?php
            return $this->renderHtmlWrapper('<h1>Git Repo Browser</h1>', (string) ob_get_clean());
        }

        if ($repos === []) {
            ?>
            <p class="empty">No bare Git repositories were found in the configured roots.</p>
            <?php
            return $this->renderHtmlWrapper('<h1>Git Repo Browser</h1>', (string) ob_get_clean());
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

            $rootDescription = $this->getRepoRootDescription($rootIndex);
            $tree = $this->buildTree($rootRepos);
            ?>
            <div class="repo-root-header">Repo root <?= $rootIndex + 1 ?>: <?= htmlspecialchars($this->getRepoRootLabel($rootIndex), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($rootDescription !== ''): ?>
                <div class="repo-root-description"><?= htmlspecialchars($rootDescription, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <ul class="repo-tree">
                <?php foreach ($tree as $node) {
                    echo $this->renderTreeNode($node);
                } ?>
            </ul>
            <?php
        }

        return $this->renderHtmlWrapper('<h1>Git Repo Browser</h1>', (string) ob_get_clean());
    }

    private function renderRepoMeta(GitRepo $repo): string
    {
        $commitCount = $repo->getCommitCount();
        $lastCommit = $repo->getLastCommit();

        if ($lastCommit['exists'] === false) {
            $lastCommitText = 'No commits yet';
            $hoverText = 'This repository has no commits yet.';
            $subjectHtml = htmlspecialchars($lastCommitText, ENT_QUOTES, 'UTF-8');
        } else {
            $formatted = trim($lastCommit['author'] !== '' ? $lastCommit['author'] : 'unknown');
            $subjectHtml = htmlspecialchars($formatted . ' • ' . $lastCommit['date'] . ' • ', ENT_QUOTES, 'UTF-8')
                . $this->renderTagBadgesInMessage((string) $lastCommit['subject']);
            $hoverText = $lastCommit['full_message'];
        }

        return '<div class="repo-meta"><strong>Commits:</strong> ' . (int) $commitCount . ' &nbsp;|&nbsp; <strong>Last:</strong> <span title="' . htmlspecialchars($hoverText, ENT_QUOTES, 'UTF-8') . '">' . $subjectHtml . '</span></div>';
    }

    private function renderTagBadgesInMessage(string $message): string
    {
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $message, $matches) !== 1) {
            return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $matches[1])), static fn (string $tag): bool => $tag !== ''));
        if ($tags === []) {
            return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        }

        $badgeParts = [];
        foreach ($tags as $tag) {
            $badgeParts[] = '<span class="tag-badge">' . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        $subject = htmlspecialchars(trim((string) ($matches[2] ?? '')), ENT_QUOTES, 'UTF-8');
        return implode(' ', $badgeParts) . ($subject !== '' ? ' ' . $subject : '');
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

    private function renderTreeNode(array $node): string
    {
        $hasChildren = !empty($node['children']);

        ob_start();

        if ($hasChildren) {
            ?>
            <li class="tree-node">
                <div class="tree-folder open" onclick="this.classList.toggle('open');"><?= htmlspecialchars((string) $node['name'], ENT_QUOTES, 'UTF-8') ?></div>
                <ul class="tree-children">
                    <?php foreach ($node['children'] as $child): ?>
                        <?= $this->renderTreeNode($child) ?>
                    <?php endforeach; ?>

                    <?php foreach ($node['repos'] as $repo): ?>
                        <?php $detailHref = '?repo=' . rawurlencode($this->getRepoSelector($repo)) . '&amp;command=branch'; ?>
                        <li class="repo-item" tabindex="0" role="button" onclick="window.location.href='<?= addslashes($detailHref) ?>';" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location.href='<?= addslashes($detailHref) ?>'; }">
                            <div class="repo-name"><?= htmlspecialchars($repo->getDisplayName(), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="repo-meta"><?= htmlspecialchars($this->getRelativeDisplayPath($repo), ENT_QUOTES, 'UTF-8') ?></div>
                            <?= $this->renderRepoMeta($repo) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <?php
            return (string) ob_get_clean();
        }

        foreach ($node['repos'] as $repo):
            $detailHref = '?repo=' . rawurlencode($this->getRepoSelector($repo)) . '&amp;command=branch';
            ?>
            <li class="repo-item" tabindex="0" role="button" onclick="window.location.href='<?= addslashes($detailHref) ?>';" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location.href='<?= addslashes($detailHref) ?>'; }">
                <div class="repo-name"><?= htmlspecialchars($repo->getDisplayName(), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="repo-meta"><?= htmlspecialchars($this->getRelativeDisplayPath($repo), ENT_QUOTES, 'UTF-8') ?></div>
                <?= $this->renderRepoMeta($repo) ?>
            </li>
            <?php
        endforeach;

        return (string) ob_get_clean();
    }

    private function renderRepoDetail(GitRepo $repo, string $command, array $repos): string
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

        ob_start();
        ?>
        <div class="page-header">
            <p><a href="./">← Back to repo list</a></p>
            <h1><?= htmlspecialchars($repoData['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <?php
        $headerHtml = (string) ob_get_clean();

        ob_start();
        $treeUrl = $detailUrlBase . '&amp;branch=' . rawurlencode($selectedBranch) . '&amp;mode=tree';
        ?>
        <div class="toolbar box">
            <div class="branch-list" id="branch-list" aria-label="Branches">
                <?php foreach ($repoData['branches'] as $branch): ?>
                    <?php
                    $isSelected = $branch === $selectedBranch;
                    $branchUrl = $detailUrlBase . '&amp;branch=' . rawurlencode($branch) . '&amp;mode=branch';
                    ?>
                    <a class="branch-button<?= $isSelected ? ' is-selected' : '' ?>" href="<?= $branchUrl ?>" aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"><?= htmlspecialchars($branch, ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
            </div>
            <a class="button<?= $selectedMode === 'tree' ? ' is-active' : '' ?>" href="<?= $treeUrl ?>">Tree</a>
        </div>

        <div class="box" style="margin-top: 1rem;">
            <p><strong>Repository:</strong> <?= htmlspecialchars((string) $repoData['display_path'], ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong>Selected branch:</strong> <?= htmlspecialchars($selectedBranch, ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="box" style="margin-top: 1rem;">
            <?php if ($selectedMode === 'tree'): ?>
                <?php if (trim($branchGraph) === ''): ?>
                    <div class="placeholder">No graph output available for this branch yet.</div>
                <?php else: ?>
                    <div class="graph-box" id="graph-view" aria-live="polite">
                        <pre class="graph-output"><?= $this->ansiToHtml($branchGraph) ?></pre>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="commit-box" id="commit-list" aria-live="polite">
                    <?php if ($branchCommits === []): ?>
                        <div class="placeholder">No commits available for this branch yet.</div>
                    <?php else: ?>
                        <?php foreach ($branchCommits as $commit): ?>
                            <?php
                            $hash = (string) ($commit['hash'] ?? '');
                            $author = (string) ($commit['author'] ?? 'unknown');
                            $date = (string) ($commit['date'] ?? 'unknown');
                            $message = (string) ($commit['message'] ?? '(no message)');
                            ?>
                            <div class="commit-row">
                                <div class="commit-hash"><?= htmlspecialchars(substr($hash, 0, 8), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="commit-meta"><?= htmlspecialchars($author, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="commit-meta"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="commit-message"><?= $this->renderTagBadgesInMessage($message) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        $contentHtml = (string) ob_get_clean();
        $footerHtml = '<strong>Timing:</strong> data '
            . htmlspecialchars((string) $repoData['timing']['detail_data_ms'], ENT_QUOTES, 'UTF-8')
            . ' ms, branch '
            . htmlspecialchars((string) $repoData['timing']['selected_branch_ms'], ENT_QUOTES, 'UTF-8')
            . ' ms, total '
            . htmlspecialchars((string) $repoData['timing']['total_ms'], ENT_QUOTES, 'UTF-8')
            . ' ms';
        $scriptHtml = '<script type="application/json" id="repo-data">'
            . json_encode($repoData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>'
            . '<script>const selectedBranch = '
            . json_encode($selectedBranch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . ';</script>';

        return $this->renderHtmlWrapper($headerHtml, $contentHtml, $footerHtml, $scriptHtml);
    }
}
