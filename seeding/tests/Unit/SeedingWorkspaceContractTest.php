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
        self::assertStringContainsString('nội dung seeding', $source);
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

    public function test_storage_v7_seed_pool_and_outputs(): void
    {
        $storage = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/storage.js'
        );
        $links = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/linkExtract.js'
        );

        self::assertStringContainsString('SCHEMA_VERSION = 7', $storage);
        self::assertMatchesRegularExpression(
            '/seeding:v5:\$\{installationId\}:\$\{userId\}:workspace/',
            $storage
        );
        self::assertStringContainsString('seed_links', $storage);
        self::assertStringContainsString('seed_batches', $storage);
        self::assertStringContainsString('seed_outputs', $storage);
        self::assertStringContainsString('normalized_url', $storage);
        self::assertStringContainsString('topicHasWorkHistory', $storage);
        self::assertStringContainsString('importLegacyIfNeeded', $storage);
        self::assertStringContainsString('extractLinksFromPaste', $links);
    }

    public function test_react_flexible_seeding_flow_no_claim_workflow(): void
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
        $resources = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ResourceLinks.jsx'
        );

        self::assertStringContainsString('ShareGeneratePanel', $workspace);
        self::assertStringContainsString('LinkPoolPanel', $workspace);
        self::assertStringContainsString('TeamStatsSidebar', $workspace);
        self::assertStringContainsString('generateSeedBatch', $workspace);
        self::assertStringContainsString('canSeedTopic', $workspace);
        self::assertStringNotContainsString('GlobalWorkDrawer', $workspace);
        self::assertStringNotContainsString('completeWithProof', $workspace);
        self::assertStringNotContainsString('Cần ít nhất 1 bình luận', $workspace);
        self::assertStringNotContainsString('/api/seeding/topics', $workspace);
        self::assertStringNotContainsString('siteId', $workspace);

        self::assertStringContainsString('Tạo chủ đề', $composer);
        self::assertStringNotContainsString('Đẩy chia sẻ', $composer);
        self::assertStringNotContainsString('SampleComments', $composer);
        self::assertStringNotContainsString('Bình luận mẫu', $composer);

        self::assertStringContainsString('Chia sẻ', $detail);
        self::assertStringContainsString('canSeedTopic', $detail);
        self::assertStringContainsString('ContentWithLinkPreviews', $detail);
        self::assertStringNotContainsString('TopicCommentsSection', $detail);
        self::assertStringNotContainsString('SampleComments', $detail);
        self::assertStringNotContainsString('CommentWorkList', $detail);

        self::assertStringContainsString('links-readonly', $resources);
        self::assertStringNotContainsString('Thêm link', $resources);
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
            'TeamStatsSidebar.jsx',
            'LinkPoolPanel.jsx',
            'ShareGeneratePanel.jsx',
            'LinkPreviewCard.jsx',
            'ContentWithLinkPreviews.jsx',
            'ResourceLinks.jsx',
            'MetricCards.jsx',
        ] as $file) {
            self::assertFileExists($root.'/'.$file);
        }
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
