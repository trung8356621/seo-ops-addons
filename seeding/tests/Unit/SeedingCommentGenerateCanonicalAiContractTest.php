<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Gen-comment AI must use canonical routing + verified budget plan (no stale direct provider path).
 */
final class SeedingCommentGenerateCanonicalAiContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_service_uses_canonical_text_executor_not_first_active_model(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentGenerateService::class))->getFileName()
        );

        self::assertStringContainsString('CanonicalAiTextExecutionService', $source);
        self::assertStringContainsString('seeding.comment_generate', $source);
        self::assertStringContainsString('AiExecutionProfile::TextFast', $source);
        self::assertStringContainsString('HOOK_KEY', $source);

        self::assertStringNotContainsString('SeoAiModel::query', $source);
        self::assertStringNotContainsString('orderByDesc(\'priority\')', $source);
        self::assertStringNotContainsString('AiProviderResolver', $source);
        self::assertStringNotContainsString('allow_unverified_outbound', $source);
        self::assertStringNotContainsString('PromptRunnerService', $source);
        self::assertStringNotContainsString('SeoPrompt', $source);
    }

    public function test_controller_contract_unchanged(): void
    {
        $controller = (string) file_get_contents(
            $this->addonRoot().'/src/Http/Controllers/SeedingCommentGenerateController.php'
        );
        $provider = (string) file_get_contents(
            $this->addonRoot().'/src/SeedingServiceProvider.php'
        );

        self::assertStringContainsString("'count' => ['nullable', 'integer', 'min:1', 'max:12']", $controller);
        self::assertStringContainsString("'comments' => \$comments", $controller);
        self::assertStringContainsString("'persisted' => false", $controller);
        self::assertStringContainsString('comments/generate', $provider);
    }

    public function test_inline_gen_panel_not_right_drawer(): void
    {
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        $feed = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicFeed.jsx'
        );
        $panel = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ShareGeneratePanel.jsx'
        );

        self::assertStringNotContainsString('data-drawer="share-generate"', $workspace);
        self::assertStringContainsString('data-drawer="link-pool"', $workspace);

        self::assertStringNotContainsString('ShareGeneratePanel', $workspace);
        self::assertStringContainsString('activeGenTopicId', $workspace);
        self::assertStringContainsString('outputsForTopic', $workspace);
        self::assertStringContainsString('ShareGeneratePanel', $feed);
        self::assertStringContainsString('is-gen-open', $feed);
        self::assertStringContainsString('data-inline', $panel);
        self::assertStringContainsString('seeding-ws__panel--inline', $panel);
        self::assertStringContainsString('linkPoolOpen ? \'has-drawer\'', $workspace);
    }
}
