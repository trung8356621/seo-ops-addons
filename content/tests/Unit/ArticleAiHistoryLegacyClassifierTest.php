<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryLegacyClassifier;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use PHPUnit\Framework\TestCase;

final class ArticleAiHistoryLegacyClassifierTest extends TestCase
{
    private function classifier(): ArticleAiHistoryLegacyClassifier
    {
        $outlineResolver = new ArticleOutlineResolver(
            new WorkflowParserService(new SeoPromptSettingsService, new SeoOverviewSettingsService),
        );

        return new ArticleAiHistoryLegacyClassifier($outlineResolver, new ArtifactReusePolicy);
    }

    public function test_typed_outline_succeeded_with_markers_is_typed_and_can_apply(): void
    {
        $output = "[START_TASK_1_OUTLINE]\n## Heading one\n### Sub heading\n[END_TASK_1_OUTLINE]\n"
            .'[START_TASK_2_VOCABULARY]'."\nSome vocabulary text.\n".'[END_TASK_2_VOCABULARY]';

        $result = $this->classifier()->classify([
            'artifact_type' => WorkflowArtifactType::ArticleOutline->value,
            'status' => 'success',
            'output' => $output,
        ], $output);

        self::assertSame(WorkflowArtifactType::ArticleOutline->value, $result['artifact_type']);
        self::assertSame('typed', $result['classification']);
        self::assertTrue($result['can_apply']);
        self::assertStringNotContainsString('[START_TASK_1_OUTLINE]', $result['normalized_payload']);
        self::assertStringNotContainsString('VOCABULARY', $result['normalized_payload']);
        self::assertStringContainsString('Heading one', $result['normalized_payload']);
    }

    public function test_typed_content_succeeded_valid_payload_is_typed_and_can_apply(): void
    {
        $output = "# Real Article\n\nBody paragraph with enough real text to be an article.";

        $result = $this->classifier()->classify([
            'artifact_type' => WorkflowArtifactType::ArticleContent->value,
            'status' => 'completed',
            'output' => $output,
        ], $output);

        self::assertSame(WorkflowArtifactType::ArticleContent->value, $result['artifact_type']);
        self::assertSame('typed', $result['classification']);
        self::assertTrue($result['can_apply']);
        self::assertSame($output, $result['normalized_payload']);
    }

    public function test_typed_artifact_with_non_succeeded_status_is_unknown_fail_closed(): void
    {
        $output = "# Real Article\n\nBody paragraph with enough real text.";

        $result = $this->classifier()->classify([
            'artifact_type' => WorkflowArtifactType::ArticleContent->value,
            'status' => 'failed',
            'output' => $output,
        ], $output);

        self::assertNull($result['artifact_type']);
        self::assertSame('unknown', $result['classification']);
        self::assertFalse($result['can_apply']);
        self::assertSame('typed_artifact_status_not_succeeded', $result['reason']);
    }

    public function test_legacy_outline_by_hook_key_is_legacy_and_can_apply(): void
    {
        $output = "[START_TASK_1_OUTLINE]\n## Heading one\n[END_TASK_1_OUTLINE]";

        $result = $this->classifier()->classify([
            'hook_key' => 'article.outline.generate',
            'status' => 'success',
            'output' => $output,
        ], $output);

        self::assertSame(WorkflowArtifactType::ArticleOutline->value, $result['artifact_type']);
        self::assertSame('legacy', $result['classification']);
        self::assertTrue($result['can_apply']);
        self::assertStringNotContainsString('[START_TASK_1_OUTLINE]', $result['normalized_payload']);
    }

    public function test_legacy_outline_by_persists_as_outline_flag(): void
    {
        $output = "## Heading one\n### Sub one\nSome outline body text.";

        $result = $this->classifier()->classify([
            'persists_as_outline' => true,
            'status' => 'success',
            'output' => $output,
        ], $output);

        self::assertSame(WorkflowArtifactType::ArticleOutline->value, $result['artifact_type']);
        self::assertSame('legacy', $result['classification']);
        self::assertTrue($result['can_apply']);
    }

    public function test_legacy_content_by_hook_key_is_legacy_and_can_apply(): void
    {
        $output = '# Real Article'."\n\n".'Body paragraph with enough real text to be an article.';

        $result = $this->classifier()->classify([
            'hook_key' => 'article.content.generate',
            'status' => 'success',
            'output' => $output,
        ], $output);

        self::assertSame(WorkflowArtifactType::ArticleContent->value, $result['artifact_type']);
        self::assertSame('legacy', $result['classification']);
        self::assertTrue($result['can_apply']);
    }

    public function test_legacy_content_rewrite_role_is_legacy(): void
    {
        $output = '# Rewritten Article'."\n\n".'Body paragraph rewritten with enough real text.';

        $result = $this->classifier()->classify([
            'execution_role' => 'article.content.rewrite',
            'status' => 'success',
            'output' => $output,
        ], $output);

        self::assertSame(WorkflowArtifactType::ArticleContent->value, $result['artifact_type']);
        self::assertSame('legacy', $result['classification']);
        self::assertTrue($result['can_apply']);
    }

    public function test_html_with_outline_markers_is_never_classified_as_content(): void
    {
        $output = '<p>[START_TASK_1_OUTLINE]</p><p>## Heading</p><p>[END_TASK_1_OUTLINE]</p>';

        $result = $this->classifier()->classify([
            'hook_key' => 'article.content.generate',
            'status' => 'success',
            'output' => $output,
        ], $output);

        self::assertNotSame(WorkflowArtifactType::ArticleContent->value, $result['artifact_type']);
        self::assertSame('unknown', $result['classification']);
        self::assertFalse($result['can_apply']);
        self::assertSame('legacy_content_contains_outline_marker', $result['reason']);
    }

    public function test_ambiguous_step_without_any_signal_is_unknown_fail_closed(): void
    {
        $result = $this->classifier()->classify([
            'status' => 'success',
            'output' => 'Some random step output with no role signal.',
        ], 'Some random step output with no role signal.');

        self::assertNull($result['artifact_type']);
        self::assertSame('unknown', $result['classification']);
        self::assertFalse($result['can_apply']);
        self::assertSame('ambiguous_step_signature', $result['reason']);
    }

    public function test_strip_outline_markers_keeps_only_outline_section(): void
    {
        $raw = "[START_TASK_1_OUTLINE]\n## Heading one\n[END_TASK_1_OUTLINE]\n"
            .'[START_TASK_2_VOCABULARY]'."\nSome vocabulary text.\n".'[END_TASK_2_VOCABULARY]';

        $stripped = $this->classifier()->stripOutlineMarkers($raw);

        self::assertStringContainsString('Heading one', $stripped);
        self::assertStringNotContainsString('VOCABULARY', $stripped);
        self::assertStringNotContainsString('[START_TASK_1_OUTLINE]', $stripped);
        self::assertStringNotContainsString('[END_TASK_1_OUTLINE]', $stripped);
    }

    public function test_strip_outline_markers_passthrough_when_no_markers_present(): void
    {
        $plain = 'Plain text with no markers at all.';

        self::assertSame($plain, $this->classifier()->stripOutlineMarkers($plain));
    }
}
