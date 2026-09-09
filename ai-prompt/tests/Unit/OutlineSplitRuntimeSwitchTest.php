<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\Content\Support\WritingSplitPreference;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Tests\TestCase;

/**
 * Outline Split (Structure + Vocabulary) ≠ writing_split_enabled ≠ PromptBudget supportsSplit.
 */
final class OutlineSplitRuntimeSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('yes');
            $table->timestamps();
        });
    }

    public function test_k_outline_split_off_bypasses_split_executor_gate(): void
    {
        $runnerSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(TaskWorkflowTestRunner::class))->getFileName(),
        );
        $this->assertMatchesRegularExpression(
            '/isOutlineRoleNode\([^)]+\)\s*&&\s*\$this->isOutlineSplitEnabled\(/',
            $runnerSrc,
        );
        $this->assertStringContainsString('outlineSplitExecutor->execute', $runnerSrc);
        $this->assertStringContainsString('hookBindingExecutor->execute', $runnerSrc);
        $this->assertStringContainsString('KEY_OUTLINE_SPLIT_ENABLED', $runnerSrc);
    }

    public function test_l_outline_split_on_uses_structure_and_vocabulary_hooks(): void
    {
        $this->assertSame('article.outline.structure.generate', ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK);
        $this->assertSame('article.vocabulary.generate', ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK);
        $executorSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))->getFileName(),
        );
        $this->assertStringContainsString('OUTLINE_STRUCTURE_HOOK', $executorSrc);
        $this->assertStringContainsString('VOCABULARY_HOOK', $executorSrc);
        $this->assertStringContainsString("execution_source' => 'split_outline_vocabulary'", $executorSrc);
    }

    public function test_m_writing_split_does_not_control_outline_split(): void
    {
        $this->assertSame('writing_split_enabled', WritingSplitPreference::META_KEY);
        $this->assertSame('outline_split_enabled', SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED);
        $this->assertNotSame(WritingSplitPreference::META_KEY, SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED);

        $settings = app(SeoCreateArticleSettingsService::class);
        $settings->saveSettings([SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED => true]);
        $this->assertTrue($settings->isOutlineSplitEnabled());

        $settings->saveSettings([SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED => false]);
        $this->assertFalse($settings->isOutlineSplitEnabled());

        // Missing key keeps production default (always-on split).
        $settings->saveSettings([SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED => true]);
        $this->assertTrue($settings->isOutlineSplitEnabled());

        $runnerSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(TaskWorkflowTestRunner::class))->getFileName(),
        );
        $this->assertStringContainsString('isOutlineSplitEnabled', $runnerSrc);
        $this->assertStringContainsString('KEY_OUTLINE_SPLIT_ENABLED', $runnerSrc);
        $this->assertStringNotContainsString('WritingSplitPreference', $runnerSrc);
        $this->assertStringContainsString('createArticleSettings->isOutlineSplitEnabled', $runnerSrc);
    }

    public function test_default_missing_key_is_outline_split_on(): void
    {
        $settings = app(SeoCreateArticleSettingsService::class);
        $this->assertTrue($settings->isOutlineSplitEnabled());
    }
}
