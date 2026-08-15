<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Observers;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordIntelligenceScheduler;

final class KeywordIntelligenceDirtyObserver
{
    public function created(Keyword $keyword): void
    {
        $this->touch($keyword, true, false);
    }

    public function updated(Keyword $keyword): void
    {
        $this->touch($keyword, false, $keyword->wasChanged('phrase'));
    }

    private function touch(Keyword $keyword, bool $created, bool $phraseChanged): void
    {
        if (! $created && ! $phraseChanged) {
            return;
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')
            && Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'is_dirty')) {
            SeoKeywordClassification::query()->where('keyword_id', (int) $keyword->id)->update(['is_dirty' => true]);
        }

        $siteId = (int) ($keyword->site_id ?? 0);
        if ($siteId <= 0) {
            return;
        }

        app(KeywordIntelligenceScheduler::class)->onKeywordChanged($siteId, $created, $phraseChanged);
    }
}
