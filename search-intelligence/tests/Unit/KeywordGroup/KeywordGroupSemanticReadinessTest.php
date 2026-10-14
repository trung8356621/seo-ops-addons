<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\KeywordGroup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Jobs\RefreshKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupSemanticState;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupManualService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticReadiness;
use RuntimeException;
use Tests\TestCase;

final class KeywordGroupSemanticReadinessTest extends TestCase
{
    private KeywordGroupSemanticReadiness $readiness;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('omi_seo_ai');
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_07_180000_create_seo_keyword_groups.php';
        $migration->up();
        $state = require dirname(__DIR__, 3).'/database/migrations/2026_10_14_120000_create_seo_keyword_group_semantic_states.php';
        $state->up();
        $this->readiness = new KeywordGroupSemanticReadiness();
    }

    public function test_new_site_and_pending_first_run_stay_hidden(): void
    {
        self::assertFalse($this->readiness->isReady(7));
        self::assertSame([], $this->readiness->filterReadySiteIds([7]));

        $this->readiness->markPending(7);

        self::assertFalse($this->readiness->isReady(7));
        self::assertSame(SeoKeywordGroupSemanticState::PHASE_PENDING, $this->phase(7));
    }

    public function test_first_success_including_empty_eligible_set_becomes_ready(): void
    {
        $this->readiness->markPending(7);
        $this->readiness->run(7, static fn (): null => null);

        self::assertTrue($this->readiness->isReady(7));
        self::assertSame([7], $this->readiness->filterReadySiteIds([7, 8]));
        self::assertSame(0, SeoKeywordGroup::query()->where('site_id', 7)->count());
        self::assertSame(SeoKeywordGroupSemanticState::PHASE_SUCCEEDED, $this->phase(7));
    }

    public function test_first_failure_and_missing_group_tables_stay_unready(): void
    {
        try {
            $this->readiness->run(7, static function (): void {
                throw new RuntimeException('semantic_down');
            });
            self::fail('expected failure');
        } catch (RuntimeException $e) {
            self::assertSame('semantic_down', $e->getMessage());
        }

        self::assertFalse($this->readiness->isReady(7));
        self::assertSame(SeoKeywordGroupSemanticState::PHASE_FAILED, $this->phase(7));

        Schema::connection('omi_seo_ai')->drop('seo_keyword_groups');
        Schema::connection('omi_seo_ai')->drop('seo_keyword_group_keywords');
        try {
            (new RefreshKeywordGroupsJob(9))->handle(
                app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticRefreshService::class),
                $this->readiness,
            );
            self::fail('expected missing tables');
        } catch (RuntimeException $e) {
            self::assertSame('keyword_group_tables_missing', $e->getMessage());
        }
        self::assertFalse($this->readiness->isReady(9));
    }

    public function test_refresh_failure_keeps_previous_success_and_success_clears_invalidation(): void
    {
        $this->readiness->markSucceeded(7);
        $this->readiness->markRunning(7);
        self::assertTrue($this->readiness->isReady(7));

        try {
            $this->readiness->run(7, static function (): void {
                throw new RuntimeException('refresh_failed');
            });
            self::fail('expected refresh failure');
        } catch (RuntimeException) {
        }

        self::assertTrue($this->readiness->isReady(7));
        self::assertSame(SeoKeywordGroupSemanticState::PHASE_FAILED, $this->phase(7));

        $this->readiness->invalidate(7);
        self::assertFalse($this->readiness->isReady(7));
        $this->readiness->markSucceeded(7);
        self::assertTrue($this->readiness->isReady(7));
    }

    public function test_deleting_last_semantic_group_revokes_readiness_and_manual_groups_do_not_grant_it(): void
    {
        $manual = new KeywordGroupManualService();
        $manualGroup = $manual->create(7, 'Manual only');
        self::assertFalse($this->readiness->isReady(7));

        $this->readiness->markSucceeded(7);
        $semantic = SeoKeywordGroup::query()->create([
            'site_id' => 7,
            'name' => 'Semantic',
            'source' => KeywordGroupSource::SEMANTIC,
            'is_locked' => false,
        ]);
        $manual->delete(7, (int) $semantic->id);

        self::assertFalse($this->readiness->isReady(7));
        self::assertNotNull(SeoKeywordGroup::query()->find($manualGroup->id));
    }

    public function test_manual_rename_of_last_semantic_group_revokes_readiness(): void
    {
        $this->readiness->markSucceeded(4);
        $semantic = SeoKeywordGroup::query()->create([
            'site_id' => 4,
            'name' => 'Semantic',
            'source' => KeywordGroupSource::SEMANTIC,
            'is_locked' => false,
        ]);

        (new KeywordGroupManualService())->rename(4, (int) $semantic->id, 'Edited');

        self::assertFalse($this->readiness->isReady(4));
        self::assertSame(KeywordGroupSource::MANUAL, (string) SeoKeywordGroup::query()->find($semantic->id)?->source);
    }

    private function phase(int $siteId): string
    {
        return (string) SeoKeywordGroupSemanticState::query()->where('site_id', $siteId)->value('phase');
    }
}
