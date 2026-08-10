<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArticleLastContentChange;
use Illuminate\Support\Carbon;

/**
 * Thời điểm nội dung bài đổi thật: manual save / sync / AI persist.
 * Không dùng updated_at (status/poll/heartbeat).
 */
final class ArticleLastContentChangeResolver
{
    public function resolve(SeoArticle|array|null $article): ArticleLastContentChange
    {
        $aiAt = $this->asCarbon(
            is_array($article)
                ? ($article['last_ai_content_at'] ?? $article['last_ai_persisted_at'] ?? null)
                : ($article?->last_ai_content_at ?? $article?->getAttribute('last_ai_content_at') ?? null)
        );

        $manualAt = $this->asCarbon(
            is_array($article)
                ? ($article['last_manual_saved_at'] ?? null)
                : ($article?->last_manual_saved_at)
        );
        $syncedAt = $this->asCarbon(
            is_array($article)
                ? ($article['last_synced_at'] ?? null)
                : ($article?->wordpressLink?->last_synced_at)
        );

        $candidates = [];
        if ($manualAt instanceof Carbon) {
            $candidates[] = ['at' => $manualAt, 'source' => 'manual', 'label' => 'Lưu thủ công'];
        }
        if ($syncedAt instanceof Carbon) {
            $candidates[] = ['at' => $syncedAt, 'source' => 'sync', 'label' => 'Đồng bộ'];
        }
        if ($aiAt instanceof Carbon) {
            $candidates[] = ['at' => $aiAt, 'source' => 'ai', 'label' => 'AI'];
        }

        if ($candidates === []) {
            return new ArticleLastContentChange(
                occurredAt: null,
                source: null,
            );
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp(),
        );
        $winner = $candidates[0];
        /** @var Carbon $at */
        $at = $winner['at'];
        $tz = $this->appTimezone();
        $absolute = $at->copy()->timezone($tz)->format('d/m/Y H:i:s');

        return new ArticleLastContentChange(
            occurredAt: $at,
            source: (string) $winner['source'],
            sourceLabel: (string) $winner['label'],
            display: $at->copy()->timezone($tz)->format('d/m/Y H:i'),
            relative: $this->relativeLabel($at),
            absolute: $absolute,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveArray(SeoArticle|array|null $article): array
    {
        return $this->resolve($article)->toArray();
    }

    private function appTimezone(): string
    {
        try {
            $value = config('app.timezone', 'UTC');
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        } catch (\Throwable) {
            // Pure unit test / no container.
        }

        return 'UTC';
    }

    private function relativeLabel(Carbon $at): string
    {
        try {
            return $at->diffForHumans();
        } catch (\Throwable) {
            return $at->toDateTimeString();
        }
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
