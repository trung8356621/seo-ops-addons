<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ConsumeVocabularySuggestCandidateService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Tests\TestCase;

/**
 * Multi-tenant Vocabulary Suggest consume must not wipe shared classification.
 * Requires SEO_TEST_USE_MYSQL=true + omi_seo_ai.
 */
final class ConsumeVocabularySuggestMultiSiteIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        foreach (['keywords', 'keyword_meta', 'seo_keyword_classifications'] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->fail('Missing required table on omi_seo_ai: '.$table);
            }
        }
    }

    public function test_consume_site_a_preserves_classification_for_site_b(): void
    {
        $siteA = 910001 + (int) (microtime(true) * 10) % 1000;
        $siteB = $siteA + 1;
        $phrase = 'shared vocab suggest '.uniqid('', true);
        $clusterKey = 'ck_shared_'.substr(md5($phrase), 0, 10);

        $persistence = app(KeywordPersistenceService::class);
        $keyword = $persistence->upsert(
            $phrase,
            Keyword::TYPE_SUGGEST,
            $siteA,
            targetUrl: 'https://site-a.example/vocab',
            searchVolume: 10,
        );
        self::assertNotNull($keyword);
        $keyword->forceFill([
            'source' => KeywordSourceNormalizer::AI_GENERATED,
            'type' => Keyword::TYPE_SUGGEST,
        ])->save();
        $keywordId = (int) $keyword->id;

        // Attach same keyword to site B (shared ownership).
        $persistence->upsert(
            $phrase,
            Keyword::TYPE_SUGGEST,
            $siteB,
            targetUrl: 'https://site-b.example/vocab',
            searchVolume: 11,
        );

        DB::connection('omi_seo_ai')->table('seo_keyword_classifications')->updateOrInsert(
            ['keyword_id' => $keywordId],
            [
                'phrase_kind' => 'keyword_phrase',
                'is_seo_keyword' => true,
                'keyword_score' => 0.8,
                'source_kind' => KeywordSourceNormalizer::AI_GENERATED,
                'normalized_text' => mb_strtolower($phrase),
                'folded_text' => mb_strtolower($phrase),
                'raw_text' => $phrase,
                'cluster_key' => $clusterKey,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        self::assertTrue(
            DB::connection('omi_seo_ai')->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', 'like', "site.{$siteA}.%")
                ->exists(),
        );
        self::assertTrue(
            DB::connection('omi_seo_ai')->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', 'like', "site.{$siteB}.%")
                ->exists(),
        );

        $svc = app(ConsumeVocabularySuggestCandidateService::class);
        $resultA = $svc->consume($keywordId, $siteA);

        self::assertTrue($resultA['detached'] || $resultA['already_absent']);
        self::assertTrue($resultA['shared_with_other_sites']);
        self::assertFalse($resultA['classification_cleared']);
        self::assertFalse($resultA['deleted']);

        // Site A detached; site B meta remains.
        self::assertFalse(
            DB::connection('omi_seo_ai')->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', 'like', "site.{$siteA}.%")
                ->exists(),
        );
        self::assertTrue(
            DB::connection('omi_seo_ai')->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', 'like', "site.{$siteB}.%")
                ->exists(),
        );

        $classRow = DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
            ->where('keyword_id', $keywordId)
            ->first();
        self::assertNotNull($classRow);
        self::assertSame($clusterKey, (string) ($classRow->cluster_key ?? ''));
        self::assertTrue(Keyword::query()->whereKey($keywordId)->exists());

        // Idempotent re-consume at A.
        $again = $svc->consume($keywordId, $siteA);
        self::assertTrue($again['already_absent'] || $again['shared_with_other_sites']);
        $classAgain = DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
            ->where('keyword_id', $keywordId)
            ->value('cluster_key');
        self::assertSame($clusterKey, (string) $classAgain);

        // Consume at B — now orphan cleanup may clear classification / delete keyword.
        $resultB = $svc->consume($keywordId, $siteB);
        self::assertFalse(
            DB::connection('omi_seo_ai')->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', 'like', "site.{$siteB}.%")
                ->exists(),
        );

        if (! $resultB['shared_with_other_sites']) {
            $clearedKey = DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
                ->where('keyword_id', $keywordId)
                ->value('cluster_key');
            // Classification cleared OR keyword deleted.
            self::assertTrue(
                $resultB['classification_cleared']
                || $resultB['deleted']
                || $clearedKey === null
                || (string) $clearedKey === '',
            );
        }

        // Still idempotent.
        $svc->consume($keywordId, $siteB);
        $svc->consume($keywordId, $siteA);
    }
}
