<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiProductionRouteEligibility;
use App\Models\ApiConnection;
use PHPUnit\Framework\TestCase;

final class SplitOutlineInputContractAndDeepSeekEligibilityTest extends TestCase
{
    public function test_outline_markdown_is_self_contained_with_input(): void
    {
        $md = DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN;
        self::assertStringContainsString('{{input}}', $md);
        self::assertStringNotContainsString('START_TASK_1_OUTLINE', $md);
        self::assertStringNotContainsString('2 loại đầu ra riêng biệt', $md);
        self::assertStringNotContainsString('START_TASK_2_VOCABULARY', $md);
        self::assertStringNotContainsString('{{post_title}}', $md);
    }

    public function test_vocabulary_markdown_is_self_contained_with_input(): void
    {
        $md = DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN;
        self::assertStringContainsString('{{input}}', $md);
        self::assertStringNotContainsString('START_TASK_2_VOCABULARY', $md);
        self::assertStringContainsString('Holonymy', $md);
        self::assertStringNotContainsString('START_TASK_1_OUTLINE', $md);
        self::assertStringNotContainsString('một bài viết cụ thể', $md);
    }

    public function test_installer_guards_custom_prompts(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/PromptOwnership/DefaultSplitOutlinePromptsInstaller.php',
        );
        self::assertStringContainsString('mayRefreshSystemDefault', $src);
        self::assertStringContainsString('is_system_default', $src);
        self::assertStringContainsString('refreshSplitPromptMarkerlessContract', $src);
        self::assertStringContainsString('containsLegacyMarkerProtocol', $src);
        self::assertStringContainsString('LEGACY_OUTLINE_SIGNATURE', $src);
    }

    public function test_deepseek_excluded_from_outline_reasoning_but_allowed_for_keyword_hook(): void
    {
        $policy = new AiProductionRouteEligibility;
        $deepseek = $this->candidate('deepseek', 'deepseek-reasoner');
        $claude = $this->candidate('claude', 'claude-sonnet-4-20250514');

        $outlineCtx = new AiRoutingContext(userId: 1, hookKey: 'article.outline.structure.generate');
        $filtered = $policy->filter([$deepseek, $claude], AiExecutionProfile::TextReasoning, $outlineCtx);
        self::assertCount(1, $filtered);
        self::assertSame('claude-sonnet-4-20250514', $filtered[0]->model);

        $kdCtx = new AiRoutingContext(userId: 1, hookKey: 'keyword.discovery.structured');
        $kd = $policy->filter(
            [$this->candidate('deepseek', 'deepseek-chat'), $claude],
            AiExecutionProfile::TextLongform,
            $kdCtx,
        );
        self::assertCount(2, $kd);

        $articleCtx = new AiRoutingContext(userId: 1, hookKey: 'article.content.generate');
        $article = $policy->filter(
            [$this->candidate('deepseek', 'deepseek-chat'), $claude],
            AiExecutionProfile::TextLongform,
            $articleCtx,
        );
        self::assertCount(1, $article);
        self::assertSame('claude-sonnet-4-20250514', $article[0]->model);
    }

    public function test_vocabulary_binder_requires_input_not_only_post_title(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ArticleOutlineVocabularySplitExecutor.php',
        );
        self::assertStringContainsString("missing required input", $src);
        self::assertStringNotContainsString("missing required post_title", $src);
        self::assertStringContainsString("\$out['input'] = \$input", $src);
    }

    private function candidate(string $provider, string $model): RoutedAiCandidate
    {
        $connection = new class extends ApiConnection
        {
            public $id = 1;

            public $provider = 'deepseek';

            public $status = 'active';

            public $api_key = 'x';

            public $name = 'test';
        };
        $connection->provider = $provider;
        $connection->name = $provider;

        return new RoutedAiCandidate(
            profile: AiExecutionProfile::TextReasoning->value,
            connection: $connection,
            provider: $provider,
            model: $model,
            capabilities: [],
            priority: 1,
            options: [],
            seoAiModelId: 1,
            isFree: false,
        );
    }
}
