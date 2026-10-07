<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support;

use Illuminate\Support\Facades\Schema;

final class KeywordGroupSchema
{
    public static function tablesReady(): bool
    {
        $schema = Schema::connection('omi_seo_ai');

        return $schema->hasTable('seo_keyword_groups')
            && $schema->hasTable('seo_keyword_group_keywords');
    }

    public static function topicLinkReady(): bool
    {
        $schema = Schema::connection('omi_seo_ai');

        return $schema->hasTable('seo_topics')
            && $schema->hasColumn('seo_topics', 'keyword_group_id');
    }

    public static function topicCandidateReady(): bool
    {
        $schema = Schema::connection('omi_seo_ai');

        return $schema->hasTable('seo_keyword_group_keywords')
            && $schema->hasColumn('seo_keyword_group_keywords', 'is_topic_candidate');
    }
}
