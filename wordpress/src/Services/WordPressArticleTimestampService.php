<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Support\Carbon;
use Throwable;

final class WordPressArticleTimestampService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function sync(SeoArticle $article, array $payload): void
    {
        $timestamps = $this->resolve($payload);
        if ($timestamps === []) {
            return;
        }

        $article->forceFill($timestamps)->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{created_at?: Carbon, updated_at?: Carbon}
     */
    public function resolve(array $payload): array
    {
        $createdAt = $this->parse($payload['post_date'] ?? null);
        $updatedAt = $this->parse($payload['post_modified'] ?? null);

        $timestamps = [];
        if ($createdAt !== null) {
            $timestamps['created_at'] = $createdAt;
        }
        if ($updatedAt !== null) {
            $timestamps['updated_at'] = $updatedAt;
        }

        return $timestamps;
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse($value, date_default_timezone_get());
        } catch (Throwable) {
            return null;
        }
    }

    public function remoteIsNewerThanLocal(?Carbon $localUpdated, mixed $remoteModified): bool
    {
        $remote = $this->parse($remoteModified);
        if ($remote === null) {
            return false;
        }

        if ($localUpdated === null) {
            return true;
        }

        return $remote->getTimestamp() > $localUpdated->getTimestamp();
    }
}
