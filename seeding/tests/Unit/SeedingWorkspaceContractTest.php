<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Http\Controllers\SeedingBootstrapController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingCommentGenerateController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingHealthController;
use Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage;
use Omnichannel\Addons\Seeding\Providers\SeedingPanelProvider;
use Omnichannel\Addons\Seeding\SeedingServiceProvider;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingVite;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeedingWorkspaceContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_provider_registers_bootstrap_health_and_stateless_ai_generate(): void
    {
        $provider = (string) file_get_contents(
            (new ReflectionClass(SeedingServiceProvider::class))->getFileName()
        );

        self::assertStringContainsString(SeedingBootstrapController::class, $provider);
        self::assertStringContainsString(SeedingHealthController::class, $provider);
        self::assertStringContainsString(SeedingCommentGenerateController::class, $provider);
        self::assertStringContainsString('comments/generate', $provider);
        self::assertStringContainsString(SeedingCommentGenerateService::class, $provider);
        self::assertStringNotContainsString('$this->loadMigrationsFrom', $provider);
    }

    public function test_ai_generate_service_uses_shared_ai_prompt_not_seo_business(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentGenerateService::class))->getFileName()
        );
        self::assertStringContainsString('AiProviderResolver', $source);
        self::assertStringContainsString('AiTextRequest', $source);
        self::assertStringNotContainsString('Omnichannel\\Addons\\Seo\\', $source);
        self::assertStringNotContainsString('SeoPrompt', $source);
        self::assertStringNotContainsString('PromptRunnerService', $source);
    }

    public function test_workspace_page_has_no_domain_dependency(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeedingTopicsPage::class))->getFileName()
        );
        self::assertStringNotContainsString('$siteId', $source);
        self::assertStringNotContainsString('domain-context-changed', $source);
        self::assertStringContainsString('seeding::layouts.bare', $source);
    }

    public function test_panel_is_standalone(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeedingPanelProvider::class))->getFileName()
        );
        self::assertStringContainsString("->path('seeding')", $source);
        self::assertStringContainsString('->navigation(false)', $source);
    }

    public function test_storage_v5_links_comments_reports_and_proof_boundary(): void
    {
        $storage = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/storage.js'
        );
        $proof = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/proofStore.js'
        );
        $links = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/linkExtract.js'
        );

        self::assertStringContainsString('SCHEMA_VERSION = 5', $storage);
        self::assertMatchesRegularExpression(
            '/seeding:v5:\$\{installationId\}:\$\{userId\}:workspace/',
            $storage
        );
        self::assertStringContainsString('reports', $storage);
        self::assertStringContainsString('normalized_url', $storage);
        self::assertStringContainsString('topicHasWorkHistory', $storage);
        self::assertStringContainsString('findReportForComment', $storage);
        self::assertStringContainsString('importLegacyIfNeeded', $storage);

        self::assertStringContainsString('indexedDB', $proof);
        self::assertStringContainsString('saveProof', $proof);
        self::assertStringContainsString('extractImageFromClipboard', $proof);

        self::assertStringContainsString('extractLinksFromPaste', $links);
        self::assertStringContainsString('href', $links);
    }

    public function test_react_workflow_immutable_share_drawer_and_no_topic_crud(): void
    {
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        $composer = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicComposer.jsx'
        );
        $detail = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicDetail.jsx'
        );
        $comments = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicCommentsSection.jsx'
        );
        $resources = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ResourceLinks.jsx'
        );
        $drawer = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/GlobalWorkDrawer.jsx'
        );

        self::assertStringContainsString('activeWorkItemId', $workspace);
        self::assertStringContainsString('GlobalWorkDrawer', $workspace);
        self::assertStringContainsString('completeWithProof', $workspace);
        self::assertStringContainsString('findReportForComment', $workspace);
        self::assertStringContainsString('Hoàn tất +1', $workspace);
        self::assertStringContainsString('Cần ít nhất 1 bình luận', $workspace);
        self::assertStringNotContainsString('/api/seeding/topics', $workspace);
        self::assertStringNotContainsString('siteId', $workspace);
        self::assertStringNotContainsString('Gợi ý triển khai', $workspace);

        self::assertStringContainsString('Tạo chủ đề', $composer);
        self::assertStringNotContainsString('Đẩy chia sẻ', $composer);
        self::assertStringNotContainsString('SampleComments', $composer);
        self::assertStringNotContainsString('Bình luận mẫu', $composer);

        self::assertStringContainsString('Đẩy chia sẻ', $detail);
        self::assertStringContainsString('Chỉ đọc', $detail);
        self::assertStringContainsString('Cần ít nhất 1 bình luận', $detail);
        self::assertStringContainsString('TopicCommentsSection', $detail);
        self::assertStringNotContainsString('SampleComments', $detail);
        self::assertStringNotContainsString('CommentWorkList', $detail);
        self::assertStringNotContainsString('Gợi ý triển khai', $detail);

        self::assertStringContainsString('Thêm bình luận', $comments);
        self::assertStringContainsString('Gen bình luận', $comments);
        self::assertStringContainsString('data-actions="comment-create"', $comments);
        self::assertStringContainsString('data-empty="comment-work"', $comments);
        self::assertStringContainsString('Nhận', $comments);
        self::assertStringContainsString("source: 'ai'", $comments);
        self::assertStringContainsString("source: 'manual'", $comments);

        self::assertStringContainsString('links-readonly', $resources);
        self::assertStringNotContainsString('Thêm link', $resources);

        self::assertStringContainsString('Ctrl + V', $drawer);
        self::assertStringContainsString('Copy bình luận', $drawer);
        self::assertStringContainsString('NOT concurrency-safe', $drawer);
    }

    public function test_components_exist(): void
    {
        $root = $this->addonRoot().'/resources/js/seeding/components';
        foreach ([
            'FeedToolbar.jsx',
            'TopicFeed.jsx',
            'TopicCard.jsx',
            'TopicComposer.jsx',
            'TopicDetail.jsx',
            'TopicCommentsSection.jsx',
            'CommentWorkList.jsx',
            'SampleComments.jsx',
            'ResourceLinks.jsx',
            'GlobalWorkDrawer.jsx',
            'LocalReport.jsx',
            'MetricCards.jsx',
        ] as $file) {
            self::assertFileExists($root.'/'.$file);
        }
    }

    public function test_shared_zero_comments_is_repaired_to_draft_in_storage(): void
    {
        $storage = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/storage.js'
        );
        self::assertStringContainsString('Repair illegal state', $storage);
        self::assertStringContainsString("state === 'shared'", $storage);
        self::assertStringContainsString('count === 0', $storage);
    }

    public function test_db_plane_and_build_boundary(): void
    {
        self::assertSame('omi_seeding', SeedingServiceConfig::CONNECTION);
        self::assertSame([], glob($this->addonRoot().'/database/migrations/*.php') ?: []);

        $vite = (string) file_get_contents($this->addonRoot().'/vite.config.js');
        self::assertStringContainsString('build-seeding', $vite);

        $resolver = (string) file_get_contents(
            (new ReflectionClass(SeedingVite::class))->getFileName()
        );
        self::assertStringContainsString("BUILD_DIRECTORY = 'build-seeding'", $resolver);
    }
}
