<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\CtaKeywordBlacklistDebugService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\AiPrompt\Services\OutlineSkipListMatcher;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CtaKeywordBlacklistDebugServiceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_scan_matches_only_keywords_table(): void
    {
        $this->requireSeoDatabaseConnection();
        if (! Schema::connection('omi_seo_ai')->hasTable('keywords')) {
            $this->markTestSkipped('keywords table missing on omi_seo_ai (migrated SEO DB required).');
        }

        $suffix = uniqid('cta_debug_', true);
        $service = app(KeywordPersistenceService::class);

        $blocked = $service->upsert('Báo giá ngay '.$suffix, Keyword::TYPE_NORMAL, 2, '/blocked');
        $service->upsert('balo quảng cáo '.$suffix, Keyword::TYPE_NORMAL, 2, '/ok');

        $this->assertNotNull($blocked);

        $report = (new CtaKeywordBlacklistDebugService(new OutlineSkipListMatcher))->scan(2, ['báo giá ngay']);

        $matchedForTest = collect($report['matched_keywords'])
            ->filter(static fn (array $row): bool => str_contains($row['phrase'], $suffix))
            ->values()
            ->all();

        $this->assertCount(1, $matchedForTest);
        $this->assertSame('Báo giá ngay '.$suffix, $matchedForTest[0]['phrase']);
        $this->assertSame((int) $blocked->id, $matchedForTest[0]['id']);
        $this->assertSame(['báo giá ngay'], $matchedForTest[0]['matched_rules']);
    }
}
