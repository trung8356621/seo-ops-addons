<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

use Illuminate\Support\Str;
use Omnichannel\Addons\Content\Models\SeoArticle;
use RuntimeException;

final class DatasetPackageWriter
{
    public function __construct(
        private readonly int $ttlSeconds = 3600,
        private readonly int $maxRows = 250,
        private readonly int $maxBytes = 5_242_880,
    ) {}

    /** @param array<string, scalar|null> $parameters @return array<string, mixed> */
    public function writeArticleInventory(int $siteId, array $parameters): array
    {
        if ($siteId <= 0) throw new RuntimeException('Dataset site scope is required.');
        $this->cleanupExpired();

        $requested = max(1, (int) ($parameters['limit_max'] ?? $this->maxRows));
        $limit = min($requested, $this->maxRows);
        $status = trim((string) ($parameters['status'] ?? ''));
        $directory = $this->directory();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Dataset package directory cannot be created.');
        }

        $id = (string) Str::ulid();
        $temporary = $directory.DIRECTORY_SEPARATOR.$id.'.tmp';
        $target = $directory.DIRECTORY_SEPARATOR.$id.'.jsonl';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('Dataset package cannot be opened.');

        $query = SeoArticle::query()->where('site_id', $siteId)->where('status', '!=', 'trash');
        if (in_array($status, SeoArticle::PUBLISHED_STATUSES, true)) {
            $query->whereIn('status', SeoArticle::PUBLISHED_STATUSES);
        } elseif ($status !== '') {
            $query->where('status', $status);
        }
        $matched = (clone $query)->count();
        $written = 0;
        $bytes = 0;
        $preview = [];
        try {
            foreach ($query->orderBy('id')->limit($limit)->select(['id', 'title', 'slug', 'status'])->cursor() as $article) {
                $row = [
                    'article_ref' => 'article:'.(int) $article->id,
                    'title' => (string) $article->title,
                    'slug' => $article->slug !== null ? (string) $article->slug : null,
                    'status' => (string) $article->status,
                ];
                $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                if ($bytes + strlen($line) > $this->maxBytes) break;
                if (fwrite($handle, $line) !== strlen($line)) throw new RuntimeException('Dataset package write failed.');
                if ($written < 25) {
                    $preview[] = $row;
                }
                $bytes += strlen($line);
                $written++;
            }
        } finally {
            fclose($handle);
        }
        if (! rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Dataset package finalization failed.');
        }

        $createdAt = now();
        $manifest = [
            'id' => $id,
            'status' => 'ready',
            'format' => 'jsonl',
            'schema' => 'seo.agent.dataset.articles.v1',
            'site_ref' => 'site:'.$siteId,
            'filters' => ['status' => $status !== '' ? $status : 'not_trash'],
            'projection' => ['article_ref', 'title', 'slug', 'status'],
            'requested' => $requested,
            'row_limit' => $limit,
            'ordering' => ['article_id' => 'asc'],
            'preview_count' => count($preview),
            'matched_total' => $matched,
            'exported' => $written,
            'omitted' => max(0, $matched - $written),
            'truncated' => $written < $matched,
            'size_bytes' => $bytes,
            'sha256' => hash_file('sha256', $target),
            'created_at' => $createdAt->toIso8601String(),
            'expires_at' => $createdAt->copy()->addSeconds($this->ttlSeconds)->toIso8601String(),
            'download_url' => url('/api/agent-qa/v1/datasets/'.$id),
        ];
        file_put_contents($directory.DIRECTORY_SEPARATOR.$id.'.manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $manifest + ['preview' => $preview];
    }

    /** @return array<string, mixed>|null */
    public function manifest(string $id): ?array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) return null;
        $path = $this->directory().DIRECTORY_SEPARATOR.$id.'.manifest.json';
        if (! is_file($path)) return null;
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || strtotime((string) ($decoded['expires_at'] ?? '')) < time()) return null;
        return $decoded;
    }

    public function path(string $id): ?string
    {
        if ($this->manifest($id) === null) return null;
        $path = $this->directory().DIRECTORY_SEPARATOR.$id.'.jsonl';
        return is_file($path) ? $path : null;
    }

    private function directory(): string
    {
        return storage_path('app/private/agent-qa-datasets');
    }

    private function cleanupExpired(): void
    {
        $directory = $this->directory();
        if (! is_dir($directory)) return;
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.manifest.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && strtotime((string) ($manifest['expires_at'] ?? '')) >= time()) continue;
            $id = basename($manifestPath, '.manifest.json');
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) continue;
            @unlink($directory.DIRECTORY_SEPARATOR.$id.'.jsonl');
            @unlink($manifestPath);
        }
    }
}
