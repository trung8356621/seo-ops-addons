<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;

final class WordPressFieldConflictService
{
    public const META_LAST_SYNCED_FIELD_SNAPSHOT = 'wp_last_synced_field_snapshot';

    public const META_LATEST_FIELD_SNAPSHOT = 'wp_latest_field_snapshot';

    /**
     * @param  array<string, mixed>  $local
     * @param  array<string, mixed>  $latestWordPress
     * @return array<string, array{baseline: string, local: string, wordpress: string}>
     */
    public function detectConflicts(SeoArticle $article, array $local, array $latestWordPress = []): array
    {
        $baseline = $this->lastSyncedSnapshot($article);
        if ($baseline === []) {
            return [];
        }

        $latestWordPress = $latestWordPress !== [] ? $latestWordPress : $this->latestWordPressSnapshot($article);
        if ($latestWordPress === []) {
            return [];
        }

        $conflicts = [];
        foreach ($local as $field => $value) {
            $field = trim((string) $field);
            if ($field === '' || ! array_key_exists($field, $baseline) || ! array_key_exists($field, $latestWordPress)) {
                continue;
            }

            $base = $this->normalizeFieldValue($field, $baseline[$field]);
            $localValue = $this->normalizeFieldValue($field, $value);
            $wpValue = $this->normalizeFieldValue($field, $latestWordPress[$field]);

            if ($localValue !== $base && $wpValue !== $base && $localValue !== $wpValue) {
                $conflicts[$field] = [
                    'baseline' => $base,
                    'local' => $localValue,
                    'wordpress' => $wpValue,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @return array<string, string>
     */
    public function localSnapshotFromPayload(array $payload): array
    {
        $snapshot = [];
        foreach (['title', 'slug', 'post_content'] as $field) {
            if (array_key_exists($field, $payload)) {
                $snapshot[$field] = $this->normalizeFieldValue($field, $payload[$field]);
            }
        }

        if (isset($payload['seo']) && is_array($payload['seo'])) {
            foreach (['seo_title', 'meta_description', 'focus_keyword'] as $field) {
                if (array_key_exists($field, $payload['seo'])) {
                    $snapshot[$field] = $this->normalizeFieldValue($field, $payload['seo'][$field]);
                }
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $payload
     */
    public function rememberSuccessfulSync(SeoArticle $article, array $decoded, array $payload, string $postContent): void
    {
        $snapshot = $this->localSnapshotFromPayload([
            ...$payload,
            'post_content' => $postContent,
        ]);

        $remoteSlug = trim((string) ($decoded['slug'] ?? ''));
        if ($remoteSlug !== '') {
            $snapshot['slug'] = $this->normalizeFieldValue('slug', $remoteSlug);
        }

        $this->writeSnapshot($article, self::META_LAST_SYNCED_FIELD_SNAPSHOT, $snapshot);
        $this->writeSnapshot($article, self::META_LATEST_FIELD_SNAPSHOT, $snapshot);
    }

    /**
     * @return array<string, string>
     */
    public function lastSyncedSnapshot(SeoArticle $article): array
    {
        return $this->readSnapshot($article, self::META_LAST_SYNCED_FIELD_SNAPSHOT);
    }

    /**
     * @return array<string, string>
     */
    public function latestWordPressSnapshot(SeoArticle $article): array
    {
        return $this->readSnapshot($article, self::META_LATEST_FIELD_SNAPSHOT);
    }

    /**
     * @return array<string, string>
     */
    private function readSnapshot(SeoArticle $article, string $key): array
    {
        if (! $article->relationLoaded('articleMetas')) {
            $article->loadMissing('articleMetas');
        }
        $raw = trim((string) ($article->articleMetas->firstWhere('meta_key', $key)?->meta_value ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $snapshot = [];
        foreach ($decoded as $field => $value) {
            $field = trim((string) $field);
            if ($field !== '') {
                $snapshot[$field] = $this->normalizeFieldValue($field, $value);
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, string>  $snapshot
     */
    private function writeSnapshot(SeoArticle $article, string $key, array $snapshot): void
    {
        if ($snapshot === []) {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => $key],
            ['meta_value' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'],
        );
    }

    private function normalizeFieldValue(string $field, mixed $value): string
    {
        $value = trim((string) $value);
        if ($field === 'slug') {
            return \Illuminate\Support\Str::slug($value);
        }

        if ($field === 'post_content') {
            return preg_replace('/\s+/u', ' ', $value) ?? $value;
        }

        return $value;
    }
}
