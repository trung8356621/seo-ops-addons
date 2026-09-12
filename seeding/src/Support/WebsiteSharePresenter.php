<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use Omnichannel\Addons\Seeding\Models\WebsiteShareJob;
use Omnichannel\Addons\Seeding\Models\WebsiteShareTarget;

final class WebsiteSharePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function feedCard(WebsiteShareJob $job): array
    {
        $targets = [];
        foreach ($job->targets as $target) {
            $targets[] = self::target($target);
        }

        $eligibleAt = $job->eligible_at;
        $secondsLeft = null;
        if ($job->status?->value === 'scheduled' && $eligibleAt !== null) {
            $secondsLeft = max(0, $eligibleAt->getTimestamp() - now()->getTimestamp());
        }

        return [
            'id' => (int) $job->id,
            'article_id' => (int) $job->article_id,
            'site_id' => (int) $job->site_id,
            'source_type' => (string) $job->source_type,
            'status' => $job->status?->value,
            'status_label' => $job->status?->label(),
            'title' => $job->title,
            'article_url' => $job->article_url,
            'domain' => $job->domain,
            'thumbnail_url' => $job->thumbnail_url,
            'indexed_at' => $job->indexed_at?->toIso8601String(),
            'indexed_at_label' => $job->indexed_at?->format('d/m/Y H:i'),
            'eligible_at' => $eligibleAt?->toIso8601String(),
            'seconds_until_eligible' => $secondsLeft,
            'share_content' => $job->share_content,
            'content_generated_at' => $job->content_generated_at?->toIso8601String(),
            'targets' => $targets,
            'socials' => array_values(array_map(
                static fn (array $t): string => (string) ($t['social_label'] ?? $t['social']),
                $targets
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function target(WebsiteShareTarget $target): array
    {
        return [
            'id' => (int) $target->id,
            'social' => $target->social?->value,
            'social_label' => $target->social?->label(),
            'target_count' => (int) $target->target_count,
            'completed_count' => (int) $target->completed_count,
            'is_complete' => $target->isComplete(),
        ];
    }
}
