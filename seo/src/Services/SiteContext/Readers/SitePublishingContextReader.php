<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteContext\Readers;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Publishing state for Site Intelligence Context.
 */
final class SitePublishingContextReader
{
    /**
     * @return array{published: int, draft: int, scheduled: int, private: int, other: int}
     */
    public function status(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return ['published' => 0, 'draft' => 0, 'scheduled' => 0, 'private' => 0, 'other' => 0];
        }
        $base = SeoArticle::query()->where('site_id', $siteId)->where('status', '!=', 'trash');
        $published = (int) (clone $base)->where('status', 'published')->count();
        $draft = (int) (clone $base)->where('status', 'draft')->count();
        $scheduled = (int) (clone $base)->where('status', 'scheduled')->count();
        $private = (int) (clone $base)->where('status', 'private')->count();
        $total = (int) (clone $base)->count();

        return [
            'published' => $published,
            'draft' => $draft,
            'scheduled' => $scheduled,
            'private' => $private,
            'other' => max(0, $total - $published - $draft - $scheduled - $private),
        ];
    }
}
