<?php

declare(strict_types=1);

final class RepoRoot
{
    public function __construct(
        private string $path,
        private string $name = '',
        private string $description = '',
    ) {
    }

    public function getPath(): string
    {
        return GitRepo::normalizePath($this->path);
    }

    public function getName(): string
    {
        return trim($this->name);
    }

    public function getDescription(): string
    {
        return trim($this->description);
    }

    public function getLabel(): string
    {
        $name = $this->getName();
        if ($name !== '') {
            return $name;
        }

        $normalized = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $this->getPath()), DIRECTORY_SEPARATOR);
        $fallback = basename($normalized);

        if ($fallback === '' || $fallback === '.' || $fallback === DIRECTORY_SEPARATOR) {
            return 'root';
        }

        return $fallback;
    }

    public function withNormalizedPath(): self
    {
        return new self($this->getPath(), $this->getName(), $this->getDescription());
    }

    public static function fromConfigValue(mixed $root): ?self
    {
        if (is_array($root)) {
            $path = trim((string) ($root['path'] ?? ''));
            if ($path === '') {
                return null;
            }

            return (new self(
                $path,
                trim((string) ($root['name'] ?? '')),
                trim((string) ($root['description'] ?? '')),
            ))->withNormalizedPath();
        }

        $path = trim((string) $root);
        if ($path === '') {
            return null;
        }

        return (new self($path))->withNormalizedPath();
    }
}
