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
 * Outline split runtime follows route_cost_auto generation_shape — not outline_split_enabled.
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

    public function test_k_outline_split_gate_uses_generation_shape(): void
    {
        $runnerSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(TaskWorkflowTestRunner::class))->getFileName(),
        );
        $this->assertMatchesRegularExpression(
            '/isOutlineRoleNode\([^)]+\)\s*&&\s*\$this->isOutlineSplitEnabled\(/',
            $runnerSrc,
        );
        $this->assertStringContainsString('outlineSplitExecutor->execute', $runnerSrc);
        $this->assertStringContainsString('ensureRouteCostGenerationShapeSnapshot', $runnerSrc);
        $this->assertStringContainsString('GenerationShapeResolver', $runnerSrc);
        $this->assertStringContainsString('generation_shape', $runnerSrc);
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

    public function test_m_settings_outline_split_is_legacy_not_runtime_authority(): void
    {
        $this->assertSame('writing_split_enabled', WritingSplitPreference::META_KEY);
        $this->assertSame('outline_split_enabled', SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED);
        $this->assertNotSame(WritingSplitPreference::META_KEY, SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED);

        $settings = app(SeoCreateArticleSettingsService::class);
        $settings->saveSettings([SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED => true]);
        $this->assertTrue($settings->isOutlineSplitEnabled());

        $settings->saveSettings([SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED => false]);
        $this->assertFalse($settings->isOutlineSplitEnabled());

        $runnerSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(TaskWorkflowTestRunner::class))->getFileName(),
        );
        $this->assertStringContainsString('isOutlineSplitEnabled', $runnerSrc);
        $this->assertStringNotContainsString('createArticleSettings->isOutlineSplitEnabled', $runnerSrc);
        $this->assertStringContainsString('GenerationShapeResolver', $runnerSrc);
    }

    public function test_default_missing_key_reader_still_true_but_ignored_at_runtime(): void
    {
        // Isolate from prior tests that wrote outline_split_enabled=false.
        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('yes');
            $table->timestamps();
        });
        \App\Models\WpOption::clearRequestCache();

        $settings = app(SeoCreateArticleSettingsService::class);
        $this->assertTrue($settings->isOutlineSplitEnabled());

        $settingsSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(SeoCreateArticleSettingsService::class))->getFileName(),
        );
        $this->assertStringContainsString('@deprecated', $settingsSrc);
        $this->assertStringContainsString('route_cost_auto', $settingsSrc);
    }
}
