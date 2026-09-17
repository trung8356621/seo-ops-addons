<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipReconcileService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicManualCreateService;
use PHPUnit\Framework\TestCase;

/**
 * Manual Topic membership + shared drawer contract (no cluster_key restore).
 */
final class TopicManualMembershipAndDrawerContractTest extends TestCase
{
    public function test_manual_create_calls_targeted_reconcile_not_global_recluster(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicManualCreateService.php');
        self::assertStringContainsString('TopicMembershipReconcileService', $src);
        self::assertStringContainsString('$this->reconcile->reconcile(', $src);
        self::assertStringContainsString("'is_seed' => true", $src);
        self::assertStringContainsString('TopicKeywordSource::MANUAL', $src);
        self::assertStringNotContainsString('CreateManualTopicClusterService', $src);
        self::assertStringNotContainsString('ReconcileTopicMembershipJob', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
        self::assertTrue(class_exists(TopicManualCreateService::class));
    }

    public function test_reconcile_service_encodes_lock_and_seed_safety(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicMembershipReconcileService.php');
        self::assertStringContainsString('TopicMembershipMatcher', $src);
        self::assertStringContainsString('skipped_locked', $src);
        self::assertStringContainsString('skipped_seed', $src);
        self::assertStringContainsString('rebuildForTopic', $src);
        self::assertStringContainsString('is_locked', $src);
        self::assertStringContainsString('is_seed', $src);
        self::assertStringContainsString('loadEligibleSeoKeywords', $src);
        self::assertStringNotContainsString('CreateManualTopicClusterService', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
        self::assertTrue(class_exists(TopicMembershipReconcileService::class));
        self::assertTrue(class_exists(TopicMembershipMatcher::class));
    }

    public function test_cluster_engine_uses_shared_matcher(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicClusterEngine.php');
        self::assertStringContainsString('TopicMembershipMatcher', $src);
        self::assertStringContainsString('$this->matcher->matches(', $src);
        self::assertStringNotContainsString('containsCanonicalCore(', $src);
    }

    public function test_duplicate_reuse_is_exact_seed_or_name_only(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicManualCreateService.php');
        self::assertStringContainsString("where('is_seed', true)", $src);
        self::assertStringContainsString('LOWER(name) = ?', $src);
        self::assertStringNotContainsString('TopicMembershipMatcher', $src);
        self::assertStringNotContainsString('LIKE', $src);
    }

    public function test_dictionary_and_topic_share_drawer_row_contract(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 4).'/seo/resources/js/keywordDetailPanel.js');
        $detail = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php');
        $list = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/list-keywords.blade.php');

        self::assertStringContainsString('data-keyword-detail-row', $detail);
        self::assertStringContainsString('data-keyword-id', $detail);
        self::assertStringContainsString("setAttribute('data-keyword-detail-row'", $js);
        self::assertStringContainsString("setAttribute('data-keyword-id'", $js);
        self::assertStringContainsString('ensureDetailViewMode', $js);
        self::assertStringContainsString('keywordRowDelegationBound', $js);
        self::assertStringContainsString('stampKeywordDetailRows', $js);
        self::assertStringContainsString('getLivewireId', $js);
        self::assertStringContainsString('dispose', $js);
        self::assertStringContainsString("'siteId'", $list);
        self::assertStringContainsString("'siteId'", $detail);
        self::assertStringContainsString('isQuickViewMode', $js);
    }

    public function test_dictionary_drawer_is_site_scoped_when_workspace_has_site(): void
    {
        $listPage = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php');
        $drawer = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/InteractsWithKeywordDetailDrawer.php');
        self::assertStringContainsString('keywordDetailDrawerSiteScope', $listPage);
        self::assertStringContainsString('resolveKeywordWorkspaceSiteId', $listPage);
        self::assertStringContainsString('$siteScope', $drawer);
        self::assertStringContainsString("where('site_id', \$siteScope)", $drawer);
    }

    public function test_no_legacy_cluster_runtime_restored(): void
    {
        $files = [
            dirname(__DIR__, 3).'/src/Services/Topic/TopicMembershipMatcher.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicMembershipReconcileService.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicManualCreateService.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicClusterEngine.php',
        ];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src, basename($file));
            self::assertStringNotContainsString('CreateManualTopicClusterService', $src, basename($file));
            self::assertStringNotContainsString('UpdateClusterCanonicalService', $src, basename($file));
            self::assertStringNotContainsString('seo_topic_cluster_meta', $src, basename($file));
            self::assertStringNotContainsString('seo_topic_cluster_aliases', $src, basename($file));
        }
    }
}
