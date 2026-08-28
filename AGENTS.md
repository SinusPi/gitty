# Gitty project notes

## Goal
Build a small PHP 8.1 tool for browsing bare Git repositories stored under configured root folders, including repos nested in subdirectories.

## Current implementation
- Single-file PHP app in index.php
- Uses classes for:
  - GitRepo
  - GitCommandRunner
  - GitRepoScanner
  - RepoBrowser
- Detects bare repos by checking for expected bare repo filesystem layout:
  - HEAD
  - config
  - objects
  - refs
- Recursively scans configured repo roots
- Supports basic Git commands:
  - branch
  - log --oneline -n 20
- CLI mode supports:
  - --list
  - --repo <path> --command branch
  - --repo <path> --command log
- Browser mode renders a repo list and command output pages.

## Configuration
- Default configuration lives in config.php
- Structure:
  ```php
  return [
      'repo_roots' => [
          '/home/user/repos',
          '/home/user/more-repos',
      ],
  ];
  ```
- Environment override supported via GITTY_REPO_ROOTS (takes priority over config.php)

## Important behavior
- Repo roots may be nested arbitrarily under configured folders.
- Only bare repos are treated as valid repos.
- Git commands are executed via `git --git-dir=<repoPath>` and output is displayed raw.
- Default branch in bare repos should be set correctly in HEAD for branch/log to be meaningful.
- A `.git` directory is displayed as its parent folder name.
- Repos that share the same parent folder are collapsed to that folder name in the list view, while the actual path remains visible in metadata.

## Files
- index.php — main app
- config.php — default repo roots configuration
- AGENTS.md — workspace-local agent memory
