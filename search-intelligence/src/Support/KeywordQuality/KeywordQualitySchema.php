<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality;

use Illuminate\Support\Facades\Schema;

final class KeywordQualitySchema
{
    public static function tablesReady(): bool
    {
        $schema = Schema::connection('omi_seo_ai');

        return $schema->hasTable('seo_keyword_quality_tags')
            && $schema->hasTable('seo_keyword_quality_policies')
            && $schema->hasTable('seo_keyword_quality_suppressions')
            && $schema->hasTable('seo_keyword_quality_seen')
            && $schema->hasTable('seo_keyword_quality_scans');
    }
}
