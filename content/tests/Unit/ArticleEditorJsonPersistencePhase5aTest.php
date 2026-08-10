<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentHtmlIngest;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentHtmlRenderer;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentNodeRegistry;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentRoundTripGuard;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentSchema;
use Omnichannel\Addons\Content\Support\ArticleEditorDocumentErrorCode;
use PHPUnit\Framework\TestCase;

final class ArticleEditorJsonPersistencePhase5aTest extends TestCase
{
    private function schema(): ArticleEditorDocumentSchema
    {
        return new ArticleEditorDocumentSchema(
            new ArticleEditorDocumentNodeRegistry(),
            new ArticleEditorDocumentHtmlRenderer(),
        );
    }

    public function test_valid_envelope_renders_html_and_hashes(): void
    {
        $schema = $this->schema();
        $doc = [
            'schema_version' => 1,
            'type' => 'article_document',
            'blocks' => [[
                'id' => 'b1',
                'type' => 'text',
                'document' => [
                    'type' => 'doc',
                    'content' => [
                        [
                            'type' => 'heading',
                            'attrs' => ['level' => 2],
                            'content' => [['type' => 'text', 'text' => 'Xin chÃƒÂ o']],
                        ],
                        [
                            'type' => 'paragraph',
                            'content' => [
                                ['type' => 'text', 'text' => 'NÃ¡Â»â„¢i dung '],
                                [
                                    'type' => 'text',
                                    'text' => 'link',
                                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]],
                                ],
                            ],
                        ],
                    ],
                ],
            ]],
        ];

        $validated = $schema->validate($doc);
        self::assertTrue($validated['ok']);
        $html = $schema->renderHtml($doc);
        self::assertStringContainsString('<h2>', $html);
        self::assertStringContainsString('Xin chÃƒÂ o', $html);
        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertNotSame('', $schema->hash($doc));
    }

    public function test_javascript_link_rejected(): void
    {
        $schema = $this->schema();
        $doc = [
            'schema_version' => 1,
            'type' => 'article_document',
            'blocks' => [[
                'id' => 'b1',
                'type' => 'text',
                'document' => [
                    'type' => 'doc',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => 'x',
                            'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
                        ]],
                    ]],
                ],
            ]],
        ];
        $validated = $schema->validate($doc);
        self::assertFalse($validated['ok']);
        self::assertSame(ArticleEditorDocumentErrorCode::INVALID, $validated['code']);
    }

    public function test_image_block_align_center_survives_server_render(): void
    {
        $schema = $this->schema();
        $doc = [
            'schema_version' => 1,
            'type' => 'article_document',
            'blocks' => [[
                'id' => 'img1',
                'type' => 'image',
                'image' => [
                    'src' => 'https://example.com/wp-content/uploads/banner.webp',
                    'alt' => 'Banner',
                    'title' => '',
                    'caption' => '',
                    'align' => 'center',
                ],
            ]],
        ];

        $validated = $schema->validate($doc);
        self::assertTrue($validated['ok']);

        $html = $schema->renderHtml($doc);
        self::assertStringContainsString('<figure class="wp-block-image aligncenter">', $html);
        self::assertStringContainsString('src="https://example.com/wp-content/uploads/banner.webp"', $html);
    }

    public function test_unsupported_schema_rejected(): void
    {
        $schema = $this->schema();
        $validated = $schema->validate([
            'schema_version' => 99,
            'type' => 'article_document',
            'blocks' => [],
        ]);
        self::assertFalse($validated['ok']);
        self::assertSame(ArticleEditorDocumentErrorCode::SCHEMA_UNSUPPORTED, $validated['code']);
    }

    public function test_html_ingest_roundtrip_guard_accepts_simple_paragraph(): void
    {
        $ingest = new ArticleEditorDocumentHtmlIngest();
        $schema = $this->schema();
        $guard = new ArticleEditorDocumentRoundTripGuard();
        $html = '<p>Hello <strong>world</strong></p>';
        $envelope = $ingest->ingestHtmlToEnvelope($html, 'b1');
        $rendered = $schema->renderHtml($envelope);
        $compare = $guard->compare($html, $rendered);
        self::assertTrue($compare['equivalent'], implode(',', $compare['reasons']));
    }

    public function test_migration_and_writer_and_client_contracts_exist(): void
    {
        $migration = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_08_03_000100_add_editor_document_to_articles_table.php');
        self::assertFileExists($migration);
        $source = (string) file_get_contents($migration);
        self::assertStringContainsString('editor_document', $source);
        self::assertStringContainsString('editor_document_hash', $source);

        $persist = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorPersistService.php',
        );
        self::assertStringContainsString('ArticleEditorDocumentWriter', $persist);
        self::assertStringContainsString('editorDocument', $persist);

        $client = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorDocument.js',
        );
        self::assertStringContainsString('buildEditorDocumentEnvelope', $client);
        self::assertStringContainsString('article_document', $client);

        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js',
        );
        self::assertStringContainsString('editor_document', $api);

        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Console/ArticleEditorDocumentBackfillCommand.php',
        );
        self::assertStringContainsString('seo:article-editor-document-backfill', $cmd);
        self::assertStringContainsString('dry-run', $cmd);

        $doc = ProjectRoot::path().'/docs/architecture/ARTICLE_EDITOR_JSON_PERSISTENCE.md';
        self::assertFileExists($doc);
    }

    public function test_bootstrap_prefers_editor_document_fields(): void
    {
        $edit = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringContainsString("'editorDocument'", $edit);
        self::assertStringContainsString('resolveForBootstrap', $edit);
    }

    public function test_writer_has_canonical_and_legacy_apis(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditor/Document/ArticleEditorDocumentWriter.php',
        );
        self::assertStringContainsString('function writeCanonicalEditorDocument', $source);
        self::assertStringContainsString('function writeLegacyHtmlAndInvalidateDocument', $source);
        self::assertStringContainsString('function ensureDerivedBodyForPublish', $source);
    }

    public function test_explicit_save_bundle_includes_editor_document(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('__seoCollectEditorHeavyBundle', $source);
        // Search whole file Ã¢â‚¬â€ collector body is long; avoid brittle char-window / regex limits.
        self::assertStringContainsString('editor_document:', $source);
        self::assertStringContainsString('expected_editor_document_hash', $source);
        self::assertStringContainsString('client_rendered_html', $source);
        self::assertTrue(
            str_contains($source, 'editor_document: editorDocument')
            || str_contains($source, 'editor_document: buildEditorDocumentEnvelope'),
            'Heavy bundle must send editor_document from envelope.',
        );
    }

    public function test_local_draft_stores_editor_document_fields(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorStorage.js',
        );
        self::assertStringContainsString('editor_document: editorDocument', $source);
        self::assertStringContainsString('base_editor_document_hash', $source);
    }

    public function test_error_codes_have_locale_keys(): void
    {
        $en = (string) file_get_contents(LegacyAddonPath::resolve('lang/en/filament.php'));
        $vi = (string) file_get_contents(LegacyAddonPath::resolve('lang/vi/filament.php'));
        self::assertStringContainsString("'editor_document_hash_conflict'", $en);
        self::assertStringContainsString("'editor_document_hash_conflict'", $vi);
    }
}
