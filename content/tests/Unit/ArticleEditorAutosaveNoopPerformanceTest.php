<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSessionController;
use Omnichannel\Addons\Content\Http\Requests\ArticleEditorActionRequest;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 2 performance â€” autosave no-op / unchanged-skip contracts (source + symbols).
 * Runtime query counts require remote measurement (see docs/audits/ARTICLE_EDITOR_PERFORMANCE_PHASE2.md).
 */
final class ArticleEditorAutosaveNoopPerformanceTest extends TestCase
{
    public function test_session_service_has_document_noop_short_circuit(): void
    {
        $class = new ReflectionClass(ArticleEditorSessionService::class);
        self::assertTrue($class->hasMethod('tryDocumentNoopAck'));
        self::assertTrue($class->hasMethod('saveDocument'));

        $save = $this->methodSource(new ReflectionMethod(ArticleEditorSessionService::class, 'saveDocument'));
        self::assertStringContainsString('tryDocumentNoopAck', $save);
        $noopPos = strpos($save, 'tryDocumentNoopAck');
        $persistPos = strpos($save, '$persist(');
        self::assertNotFalse($noopPos);
        self::assertNotFalse($persistPos);
        self::assertTrue($noopPos < $persistPos, 'noop must run before persist');
        self::assertStringContainsString("'noop' => false", $save);
    }

    public function test_noop_ack_skips_html_render_and_revision_paths(): void
    {
        $noop = $this->methodSource(new ReflectionMethod(ArticleEditorSessionService::class, 'tryDocumentNoopAck'));
        self::assertStringContainsString('canonicalHash', $noop);
        self::assertStringContainsString("'noop' => true", $noop);
        self::assertStringNotContainsString('prepareCanonicalDocument', $noop);
        self::assertStringNotContainsString('renderHtml', $noop);
        self::assertStringNotContainsString('captureAfterSave', $noop);
        self::assertStringContainsString('expectedVersion > $currentVersion', $noop);
    }

    public function test_document_writer_exposes_hash_without_html_render(): void
    {
        $class = new ReflectionClass(ArticleEditorDocumentWriter::class);
        self::assertTrue($class->hasMethod('canonicalHash'));
        $hash = $this->methodSource(new ReflectionMethod(ArticleEditorDocumentWriter::class, 'canonicalHash'));
        self::assertStringContainsString('normalize', $hash);
        self::assertStringContainsString('->hash(', $hash);
        self::assertStringNotContainsString('renderHtml', $hash);
    }

    public function test_controller_skips_bundle_apply_on_noop(): void
    {
        $doc = $this->methodSource(new ReflectionMethod(ArticleEditorSessionController::class, 'document'));
        self::assertStringContainsString("\$payload['noop']", $doc);
        $noopPos = strpos($doc, "\$payload['noop']");
        $applyPos = strpos($doc, 'bundleApply->apply');
        self::assertNotFalse($noopPos);
        self::assertNotFalse($applyPos);
        self::assertTrue($noopPos < $applyPos);
        self::assertStringContainsString('return response()->json([', $doc);
    }

    public function test_action_request_keeps_editor_document_fields(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorActionRequest::class))->getFileName(),
        );
        self::assertStringContainsString("'editor_document'", $source);
        self::assertStringContainsString("'expected_editor_document_hash'", $source);
        self::assertStringContainsString('editorBundle', $source);
        self::assertStringContainsString("\$bundle['meta']", $source);
        self::assertStringContainsString('article_meta', $source);
    }

    public function test_client_skips_unchanged_autosave_without_clearing_retry(): void
    {
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('serverAutosaveNeedsRetryRef', $editor);
        self::assertStringContainsString('lastAutosaveHashRef', $editor);
        self::assertStringContainsString('currentBodyHash === ackBodyHash', $editor);
        self::assertStringContainsString('serverAutosaveNeedsRetryRef.current = true', $editor);
        self::assertStringContainsString("payload.save_mode = 'autosave'", $editor);
        self::assertStringContainsString('client_document_hash', $editor);
    }

    public function test_stable_document_hash_helper_exists(): void
    {
        $doc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorDocument.js',
        );
        self::assertStringContainsString('export function stableSerialize', $doc);
        self::assertStringContainsString('export function hashEditorDocumentEnvelope', $doc);
        self::assertStringContainsString('Object.keys(input).sort()', $doc);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
