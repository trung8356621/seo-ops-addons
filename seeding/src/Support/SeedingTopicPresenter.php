<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use Omnichannel\Addons\Seeding\LinkIntelligence\Models\LinkResource;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;

final class SeedingTopicPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function topic(SeedingTopic $topic): array
    {
        if (! $topic->relationLoaded('linkResources')) {
            $topic->load('linkResources');
        }

        $links = $topic->linkResources
            ->map(static fn (LinkResource $link): array => self::link($link))
            ->values()
            ->all();

        return [
            'id' => (int) $topic->id,
            'site_id' => (int) $topic->site_id,
            'full_text' => (string) $topic->full_text,
            'source_html' => $topic->source_html,
            'social_url' => $topic->social_url,
            'social_platform' => $topic->social_platform?->value,
            'social_platform_label' => $topic->social_platform?->label(),
            'status' => $topic->status->value,
            'status_label' => $topic->status->label(),
            'published_at' => $topic->published_at?->toIso8601String(),
            'archived_at' => $topic->archived_at?->toIso8601String(),
            'is_archived' => $topic->isArchived(),
            'preview' => $topic->preview(60),
            'links' => $links,
            'links_count' => count($links),
            'created_at' => $topic->created_at?->toIso8601String(),
            'updated_at' => $topic->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function link(LinkResource $link): array
    {
        return [
            'id' => (int) $link->id,
            'original_url' => (string) $link->original_url,
            'normalized_url' => (string) $link->normalized_url,
            'domain' => (string) $link->domain,
            'title' => $link->title,
            'description' => $link->description,
        ];
    }

    /**
     * @param  list<SeedingTopic>  $topics
     * @return list<array<string, mixed>>
     */
    public static function collection(array $topics): array
    {
        return array_map(static fn (SeedingTopic $topic): array => self::topic($topic), $topics);
    }
}
