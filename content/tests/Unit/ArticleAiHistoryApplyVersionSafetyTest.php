<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplyService;
use Omnichannel\Addons\Content\Services\SeoArticleRevisionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Versioned Content Apply reuses SeoArticleRevisionService + canonical Save.
 * Outline Apply remains pending-only.
 */
final class ArticleAiHistoryApplyVersionSafetyTest extends TestCase
{
    public function test_revision_service_exposes_baseline_helper_with_force_and_dedupe(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoArticleRevisionService::class))->getFileName(),
        );

        self::assertStringContainsString('function ensureBaselineBeforeAiApply', $src);
        self::assertStringContainsString('function hasRevisionWithContent', $src);
        self::assertStringContainsString('ai_apply_baseline', $src);
        self::assertStringContainsString('force: true', $src);
        self::assertStringContainsString('hash(\'sha256\'', $src);
    }

    public function test_content_apply_baselines_then_persists_then_force_captures_ai_body(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleAiHistoryApplyService::class))->getFileName(),
        );

        self::assertStringContainsString('ensureBaselineBeforeAiApply', $src);
        self::assertStringContainsString('writeArticleRow', $src);
        self::assertStringContainsString('runAfterPersistSideEffects', $src);
        self::assertStringContainsString('hasRevisionWithContent', $src);
        self::assertStringContainsString('force: true', $src);
        self::assertStringContainsString('ai_apply_save', $src);
        self::assertStringContainsString("'committed' => true", $src);
        self::assertStringContainsString('content_apply_saved', $src);

        $versionedStart = strpos($src, 'private function applyContentVersioned');
        self::assertNotFalse($versionedStart);
        $versionedEnd = strpos($src, 'private function applyOutlinePending', $versionedStart);
        self::assertNotFalse($versionedEnd);
        $slice = substr($src, $versionedStart, $versionedEnd - $versionedStart);

        $baselinePos = strpos($slice, 'ensureBaselineBeforeAiApply');
        $writePos = strpos($slice, 'writeArticleRow');
        $clearPos = strpos($slice, 'draftStore->clear');
        self::assertNotFalse($baselinePos);
        self::assertNotFalse($writePos);
        self::assertNotFalse($clearPos);
        self::assertLessThan($writePos, $baselinePos);
        self::assertLessThan($clearPos, $writePos);
    }

    public function test_outline_apply_remains_pending_without_body_write(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleAiHistoryApplyService::class))->getFileName(),
        );

        self::assertStringContainsString('function applyOutlinePending', $src);
        self::assertStringContainsString('outline_apply_pending', $src);
        self::assertStringContainsString("'committed' => false", $src);
    }

    public function test_edit_article_still_supports_legacy_pending_save_force_for_wp(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(EditArticle::class))->getFileName(),
        );

        self::assertStringContainsString('$hadAiHistoryPending', $src);
        self::assertStringContainsString('$forceAiRevision', $src);
        self::assertStringContainsString('captureArticleRevisionAfterSave($writtenHtml, force: $forceAiRevision)', $src);
        self::assertStringContainsString('commitPendingOnSave', $src);
    }

    public function test_legacy_pending_bootstrap_does_not_auto_commit_on_load(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(EditArticle::class))->getFileName(),
        );

        self::assertStringContainsString('ensureBaselineBeforeAiApply', $src);
        self::assertStringContainsString('commitPendingOnSave', $src);
        // Versioned Apply lives on History page — EditArticle must not auto-Apply on boot.
        self::assertStringNotContainsString('content_apply_saved', $src);
        self::assertStringNotContainsString('applyContentVersioned', $src);
    }

    public function test_capture_after_save_signature_supports_force(): void
    {
        $method = new ReflectionMethod(SeoArticleRevisionService::class, 'captureAfterSave');
        $params = [];
        foreach ($method->getParameters() as $param) {
            $params[$param->getName()] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
        }

        self::assertArrayHasKey('force', $params);
        self::assertFalse($params['force']);
    }
}
