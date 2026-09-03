<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReconcileTopicMembershipJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\CreateManualTopicClusterService;
use Tests\Support\LegacyAddonPath;
use Tests\TestCase as LaravelTestCase;

final class QuickCreateAsyncUxContractTest extends LaravelTestCase
{
    private const SITE_A = 61;

    private const SITE_B = 62;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('omi_seo_ai');
        $this->ensureTables();
        Cache::flush();
    }

    public function test_toolbar_has_no_pre_submit_duplicate_gate(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');
        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));

        self::assertStringNotContainsString('function quickCreateClusterExists', $page);
        self::assertStringNotContainsString('quickCreateClusterExists', $index);
        self::assertStringNotContainsString("exists: @js", $index);
        self::assertStringNotContainsString('x-bind:disabled="exists', $index);
        self::assertStringNotContainsString('topic_quick_create_exists', $index);
        self::assertStringNotContainsString('topic_quick_create_resolving', $index);
        self::assertStringContainsString('topic_quick_create_action', $index);
        self::assertStringContainsString("!String(phrase || '').trim()", $index);
        self::assertStringContainsString('topicMutationsLocked', $index);
    }

    public function test_prepare_new_phrase_creates_and_create_dispatches_job(): void
    {
        Bus::fake([ReconcileTopicMembershipJob::class]);

        $service = app(CreateManualTopicClusterService::class);
        $prepared = $service->prepareManualTopic(self::SITE_A, 'Cặp Laptop');

        self::assertTrue($prepared['created']);
        self::assertSame('Cặp Laptop', $prepared['canonical_phrase']);
        self::assertTrue(
            SeoTopicClusterMeta::query()
                ->where('site_id', self::SITE_A)
                ->where('cluster_key', $prepared['cluster_key'])
                ->exists(),
        );

        $dispatched = $service->create(self::SITE_A, 'Balo Laptop');
        self::assertTrue($dispatched['created']);
        Bus::assertDispatched(ReconcileTopicMembershipJob::class, function (ReconcileTopicMembershipJob $job) use ($dispatched): bool {
            return $job->siteId === self::SITE_A
                && $job->clusterKey === $dispatched['cluster_key'];
        });
    }

    public function test_existing_normalized_phrase_resolves_without_duplicate(): void
    {
        Bus::fake([ReconcileTopicMembershipJob::class]);

        $service = app(CreateManualTopicClusterService::class);
        $first = $service->prepareManualTopic(self::SITE_A, 'Cặp Laptop');
        $second = $service->create(self::SITE_A, 'CAP LAPTOP');

        self::assertFalse($second['created']);
        self::assertSame($first['cluster_key'], $second['cluster_key']);
        self::assertSame(
            1,
            SeoTopicClusterMeta::query()->where('site_id', self::SITE_A)->count(),
        );
        Bus::assertDispatched(ReconcileTopicMembershipJob::class, function (ReconcileTopicMembershipJob $job) use ($first): bool {
            return $job->siteId === self::SITE_A && $job->clusterKey === $first['cluster_key'];
        });
    }

    public function test_job_calls_reconcile_membership_and_uses_site_lock(): void
    {
        $jobSrc = (string) file_get_contents(dirname(__DIR__, 2).'/src/Jobs/ReconcileTopicMembershipJob.php');
        self::assertStringContainsString('->reconcileMembership(', $jobSrc);
        self::assertStringContainsString('UpdateClusterCanonicalService', $jobSrc);
        self::assertStringContainsString('topic_cluster_membership_mutation:', $jobSrc);
        self::assertStringContainsString('ShouldBeUnique', $jobSrc);
        self::assertStringContainsString('topic-membership-reconcile-', $jobSrc);
        self::assertStringContainsString('$this->release(5)', $jobSrc);
        self::assertStringContainsString('TopicClusterReclusterState::isMutationLocked', $jobSrc);

        $job = new ReconcileTopicMembershipJob(self::SITE_A, 'ck_quick', 9);
        self::assertSame('topic-membership-reconcile-'.self::SITE_A.'-ck_quick', $job->uniqueId());
        self::assertSame(self::SITE_A, $job->siteId);
        self::assertSame('ck_quick', $job->clusterKey);
        self::assertSame(9, $job->requestedBy);
    }

    public function test_site_membership_lock_key_is_scoped_per_site(): void
    {
        self::assertSame(
            'topic_cluster_membership_mutation:'.self::SITE_A,
            ReconcileTopicMembershipJob::membershipLockKey(self::SITE_A),
        );
        self::assertSame(
            'topic_cluster_membership_mutation:'.self::SITE_B,
            ReconcileTopicMembershipJob::membershipLockKey(self::SITE_B),
        );
        self::assertNotSame(
            ReconcileTopicMembershipJob::membershipLockKey(self::SITE_A),
            ReconcileTopicMembershipJob::membershipLockKey(self::SITE_B),
        );
    }

    public function test_livewire_toast_keys_for_create_or_repair(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');

        self::assertStringContainsString('topic_quick_create_queued', $page);
        self::assertStringContainsString('topic_quick_create_existing_queued', $page);
        self::assertStringNotContainsString('topic_quick_create_duplicate', $page);
        self::assertStringNotContainsString("duplicate_cluster", $page);
        self::assertStringContainsString('TopicClusterReclusterState::assertMutationAllowed', $page);
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('cluster_key', 120);
            $table->string('canonical_phrase');
            $table->string('normalized_canonical');
            $table->string('confidence')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('canonical_source')->nullable();
            $table->boolean('mcp_excluded')->default(false);
            $table->boolean('seo_excluded')->default(false);
            $table->timestamps();
            $table->unique(['site_id', 'cluster_key']);
        });
        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('cluster_key', 120);
            $table->string('alias_phrase');
            $table->string('normalized_alias');
            $table->timestamps();
        });
    }
}
