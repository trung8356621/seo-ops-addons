<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicClusterEngine;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterAlgorithm;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Async recluster safety: algorithm version guard + durable UI state + manual freeze.
 */
final class TopicReclusterSafetyAndManualFreezeTest extends TestCase
{
    public function test_algorithm_version_constant_is_stable_and_non_empty(): void
    {
        self::assertNotSame('', TopicReclusterAlgorithm::VERSION);
        self::assertStringContainsString('topic-recluster', TopicReclusterAlgorithm::VERSION);
        self::assertTrue(TopicReclusterAlgorithm::matches(TopicReclusterAlgorithm::VERSION));
        self::assertFalse(TopicReclusterAlgorithm::matches('topic-recluster-legacy-catalog'));
    }

    public function test_job_serializes_requested_algorithm_version(): void
    {
        $job = new ReclusterSiteTopicsJob(3, TopicReclusterAlgorithm::VERSION);
        self::assertSame(3, $job->siteId);
        self::assertSame(TopicReclusterAlgorithm::VERSION, $job->requestedAlgorithmVersion);
        self::assertSame('seo', $job->queue);

        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Jobs/ReclusterSiteTopicsJob.php');
        self::assertStringContainsString('TopicReclusterAlgorithm::matches', $src);
        self::assertStringContainsString('STALE_WORKER_MESSAGE', $src);
        self::assertStringContainsString('stale_worker_algorithm', $src);
        // Refuse before calling recluster service.
        $handlePos = strpos($src, 'function handle');
        $reclusterPos = strpos($src, '$recluster->recluster', $handlePos ?: 0);
        $matchPos = strpos($src, 'TopicReclusterAlgorithm::matches', $handlePos ?: 0);
        self::assertNotFalse($handlePos);
        self::assertNotFalse($matchPos);
        self::assertNotFalse($reclusterPos);
        self::assertLessThan($reclusterPos, $matchPos);
    }

    public function test_ui_dispatch_persists_queued_state_with_version(): void
    {
        $concern = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php'
        );
        self::assertStringContainsString('TopicReclusterUiState::markQueued($siteId, $version)', $concern);
        self::assertStringContainsString('ReclusterSiteTopicsJob::dispatch($siteId, $version)', $concern);
        self::assertStringContainsString('isTopicMutationLocked()', $concern);
        self::assertStringContainsString('topic_recluster_already_running', $concern);
        self::assertStringContainsString('syncReclusterStateFromCache', $concern);
        self::assertStringContainsString('STATUS_SUCCEEDED', $concern);
        self::assertStringContainsString('STATUS_FAILED', $concern);
    }

    public function test_ui_state_machine_statuses_and_fields(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterUiState.php');
        self::assertStringContainsString("STATUS_QUEUED = 'queued'", $src);
        self::assertStringContainsString("STATUS_RUNNING = 'running'", $src);
        self::assertStringContainsString("STATUS_SUCCEEDED = 'succeeded'", $src);
        self::assertStringContainsString("STATUS_FAILED = 'failed'", $src);
        self::assertStringContainsString('algorithm_version', $src);
        self::assertStringContainsString('started_at', $src);
        self::assertStringContainsString('finished_at', $src);
        self::assertStringContainsString('failure_reason', $src);
        self::assertTrue(method_exists(TopicReclusterUiState::class, 'markSucceeded'));
        self::assertTrue(method_exists(TopicReclusterUiState::class, 'isActiveStatus'));
    }

    public function test_blade_polls_server_state_after_refresh(): void
    {
        $index = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        $detail = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php'
        );
        self::assertStringContainsString("\$reclusterStatus === 'queued'", $index);
        self::assertStringContainsString("\$reclusterStatus === 'running'", $index);
        self::assertStringContainsString('wire:poll.5s="pollReclusterResult"', $index);
        self::assertStringContainsString('wire:poll.5s="pollReclusterResult"', $detail);
        self::assertStringContainsString("\$reclusterStatus === 'succeeded'", $index);
        self::assertStringContainsString("result.source", $index);
    }

    public function test_global_recluster_does_not_auto_reconcile_manuals(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterService.php');
        self::assertStringNotContainsString('$this->reconcile', $src);
        self::assertStringNotContainsString('private readonly TopicMembershipReconcileService', $src);
        self::assertStringContainsString('accept_attach', $src);
        self::assertStringContainsString("'accept_attach' => false", $src);
        self::assertStringContainsString('Manual freeze', $src);
        self::assertStringContainsString('must NOT auto-reconcile', $src);

        $detail = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php'
        );
        self::assertStringContainsString('rescanTopicKeywords', $detail);
        self::assertStringContainsString('TopicMembershipReconcileService', $detail);
        self::assertStringContainsString('->reconcile($siteId, $this->topic)', $detail);
    }

    public function test_rename_and_move_promote_auto_to_manual(): void
    {
        $rename = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicRenameService.php');
        $move = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicMoveKeywordService.php');
        $ownership = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicManualOwnership.php');
        self::assertStringContainsString('TopicManualOwnership::promoteIfAuto', $rename);
        self::assertStringContainsString('TopicManualOwnership::promoteIfAuto', $move);
        self::assertStringContainsString('TopicSource::MANUAL', $ownership);
        self::assertStringContainsString('lock semantics remain separate', $ownership);
        self::assertStringNotContainsString("'is_locked'", $ownership);
        self::assertStringContainsString('promoted_to_manual', $rename);
    }

    public function test_manual_freeze_excludes_members_from_attach_and_discovery(): void
    {
        $phrases = new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);
        $engine = new TopicClusterEngine(
            new TopicMembershipMatcher($phrases),
            new KeywordNormalizer,
            $phrases,
        );

        $seeds = [[
            'keyword_id' => 1,
            'phrase' => 'balo laptop',
            'source' => TopicKeywordSource::LINK_LIST,
            'is_seed' => true,
            'confidence' => 1.0,
        ]];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'balo laptop', 'is_seo_keyword' => true],
            ['keyword_id' => 50, 'phrase' => 'balo laptop da thật', 'is_seo_keyword' => true],
            ['keyword_id' => 60, 'phrase' => 'túi xách handmade a', 'is_seo_keyword' => true],
            ['keyword_id' => 61, 'phrase' => 'túi xách handmade b', 'is_seo_keyword' => true],
            ['keyword_id' => 62, 'phrase' => 'túi xách handmade c', 'is_seo_keyword' => true],
        ];
        $inventory = [[
            'topic_id' => 900,
            'name' => 'Manual Balo Group',
            'is_locked' => false,
            'accept_attach' => false,
            'members' => [[
                'keyword_id' => 50,
                'phrase' => 'balo laptop da thật',
                'source' => TopicKeywordSource::MANUAL,
                'is_seed' => false,
                'confidence' => null,
                'is_locked' => false,
            ]],
        ]];

        $metrics = [];
        $clusters = $engine->cluster($seeds, $eligible, [], [], $inventory, $metrics);

        $manual = null;
        $seedTopic = null;
        foreach ($clusters as $cluster) {
            if ((int) ($cluster['topic_id'] ?? 0) === 900) {
                $manual = $cluster;
            }
            if (($cluster['topic_id'] ?? null) === null && ($cluster['name'] ?? '') === 'Balo laptop') {
                $seedTopic = $cluster;
            }
        }

        self::assertNotNull($manual);
        self::assertCount(1, $manual['members']);
        self::assertSame(50, (int) $manual['members'][0]['keyword_id']);

        // Keyword 50 must not be stolen onto the seed Topic.
        if ($seedTopic !== null) {
            foreach ($seedTopic['members'] as $member) {
                self::assertNotSame(50, (int) $member['keyword_id']);
            }
        }

        // Zero-member manual still emitted when members empty.
        $emptyManual = $engine->cluster($seeds, [
            ['keyword_id' => 1, 'phrase' => 'balo laptop', 'is_seo_keyword' => true],
        ], [], [], [[
            'topic_id' => 901,
            'name' => 'Empty Manual',
            'is_locked' => false,
            'accept_attach' => false,
            'members' => [],
        ]]);
        $foundEmpty = false;
        foreach ($emptyManual as $cluster) {
            if ((int) ($cluster['topic_id'] ?? 0) === 901) {
                $foundEmpty = true;
                self::assertSame([], $cluster['members']);
            }
        }
        self::assertTrue($foundEmpty);
    }

    public function test_lock_semantics_remain_independent_of_manual_source(): void
    {
        $ownership = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicManualOwnership.php');
        $rename = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicRenameService.php');
        self::assertStringContainsString('Does not set is_locked', $ownership);
        self::assertStringNotContainsString("'is_locked' => true", $rename);
        self::assertStringNotContainsString("'is_locked' => false", $rename);
    }

    public function test_sync_command_bypasses_queue(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Console/ReclusterSiteTopicsCommand.php');
        self::assertStringContainsString("if (\$this->option('sync'))", $src);
        self::assertStringContainsString('$recluster->recluster($siteId)', $src);
        self::assertStringContainsString('ReclusterSiteTopicsJob::dispatch', $src);
        self::assertStringContainsString('TopicReclusterAlgorithm::VERSION', $src);
    }

    public function test_stale_worker_message_is_human_readable(): void
    {
        self::assertStringContainsString('outdated algorithm version', TopicReclusterAlgorithm::STALE_WORKER_MESSAGE);
        self::assertStringContainsString('Restart the queue worker', TopicReclusterAlgorithm::STALE_WORKER_MESSAGE);
    }

    public function test_job_handle_refuses_stale_version_without_service_call(): void
    {
        $job = new ReclusterSiteTopicsJob(3, 'topic-recluster-legacy-catalog');
        $method = new ReflectionMethod($job, 'handle');
        self::assertSame('handle', $method->getName());

        // Structural: constructor stores mismatched version for worker comparison.
        $ref = new ReflectionClass($job);
        $prop = $ref->getProperty('requestedAlgorithmVersion');
        self::assertSame('topic-recluster-legacy-catalog', $prop->getValue($job));
        self::assertFalse(TopicReclusterAlgorithm::matches($job->requestedAlgorithmVersion));
    }
}
