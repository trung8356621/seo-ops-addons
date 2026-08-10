<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor\Document;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorHtmlSanitizeService;
use Omnichannel\Addons\Content\Support\ArticleEditorDocumentErrorCode;
use App\Support\RuntimeLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic writer for canonical editor_document + derived body HTML.
 */
final class ArticleEditorDocumentWriter
{
    public function __construct(
        private readonly ArticleEditorDocumentSchema $schema,
        private readonly ArticleEditorDocumentHtmlIngest $ingest,
        private readonly ArticleEditorHtmlSanitizeService $htmlSanitize,
        private readonly ArticleEditorDocumentRoundTripGuard $roundTrip,
    ) {}

    public function persistenceEnabled(): bool
    {
        return $this->configBool('seo-content-ai.article_editor.json_persistence.enabled', true);
    }

    public function dualWriteEnabled(): bool
    {
        return $this->configBool('seo-content-ai.article_editor.json_persistence.dual_write', true);
    }

    public function readPreferred(): bool
    {
        return $this->configBool('seo-content-ai.article_editor.json_persistence.read_preferred', true);
    }

    public function columnsReady(SeoArticle $article): bool
    {
        static $cache = [];

        try {
            $connection = $article->getConnectionName() ?: 'omi_seo_ai';
            $table = $article->getTable();
            $key = $connection.'.'.$table.'.editor_document';
            if (array_key_exists($key, $cache)) {
                return $cache[$key];
            }

            return $cache[$key] = Schema::connection($connection)->hasColumn($table, 'editor_document');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Canonical document hash without HTML render (autosave no-op precheck).
     *
     * @param  array<string, mixed>  $document
     */
    public function canonicalHash(array $document): string
    {
        $validated = $this->schema->validate($document);
        if (! ($validated['ok'] ?? false)) {
            throw new ArticleEditorDocumentException(
                (string) ($validated['code'] ?? ArticleEditorDocumentErrorCode::INVALID),
                (string) ($validated['message'] ?? 'Invalid editor document.'),
            );
        }

        return $this->schema->hash($this->schema->normalize($document));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{document: array<string, mixed>, hash: string, html: string, schema_version: int}
     */
    public function prepareCanonicalDocument(array $document): array
    {
        $validated = $this->schema->validate($document);
        if (! ($validated['ok'] ?? false)) {
            throw new ArticleEditorDocumentException(
                (string) ($validated['code'] ?? ArticleEditorDocumentErrorCode::INVALID),
                (string) ($validated['message'] ?? 'Invalid editor document.'),
            );
        }

        $normalized = $this->schema->normalize($document);
        $hash = $this->schema->hash($normalized);
        $html = $this->htmlSanitize->stripTransientEditorMarkup($this->schema->renderHtml($normalized));

        return [
            'document' => $normalized,
            'hash' => $hash,
            'html' => $html,
            'schema_version' => ArticleEditorDocumentSchema::CURRENT_VERSION,
        ];
    }

    /**
     * Apply JSON fields onto the model (caller still sets body + save/update).
     *
     * @param  array<string, mixed>  $document
     * @return array{document: array<string, mixed>, hash: string, html: string, schema_version: int}
     */
    public function applyCanonicalFields(SeoArticle $article, array $document, ?string $expectedHash = null): array
    {
        $prepared = $this->prepareCanonicalDocument($document);

        if ($expectedHash !== null && $expectedHash !== '') {
            $currentHash = trim((string) ($article->editor_document_hash ?? ''));
            if ($currentHash !== '' && ! hash_equals($currentHash, $expectedHash)) {
                throw new ArticleEditorDocumentException(
                    ArticleEditorDocumentErrorCode::HASH_CONFLICT,
                    'Editor document hash conflict.',
                    [
                        'expected_editor_document_hash' => $expectedHash,
                        'actual_editor_document_hash' => $currentHash,
                    ],
                );
            }
        }

        if ($this->columnsReady($article) && $this->dualWriteEnabled()) {
            $article->editor_document = $prepared['document'];
            $article->editor_document_schema_version = $prepared['schema_version'];
            $article->editor_document_hash = $prepared['hash'];
            $article->editor_document_status = ArticleEditorDocumentSchema::STATUS_CURRENT;
            $article->editor_document_updated_at = Carbon::now();
        }

        return $prepared;
    }

    public function invalidateForLegacyBodyWrite(SeoArticle $article, string $origin = 'legacy_body_writer'): void
    {
        if (! $this->columnsReady($article)) {
            return;
        }

        if ($article->editor_document === null) {
            return;
        }

        $article->editor_document_status = ArticleEditorDocumentSchema::STATUS_STALE;
        RuntimeLogger::warning('seo.editor.document_stale', [
            'article_id' => (int) $article->getKey(),
            'origin' => $origin,
        ]);
    }

    /**
     * Canonical dual-write API for system writers that already have TipTap envelope JSON.
     *
     * @param  array<string, mixed>  $document
     * @return array{document: array<string, mixed>, hash: string, html: string, schema_version: int}
     */
    public function writeCanonicalEditorDocument(
        SeoArticle $article,
        array $document,
        ?string $expectedHash = null,
        bool $persist = true,
    ): array {
        $prepared = $this->applyCanonicalFields($article, $document, $expectedHash);
        $article->body = $prepared['html'];

        if ($persist) {
            $payload = [
                'body' => $prepared['html'],
            ];
            if ($this->columnsReady($article) && $this->dualWriteEnabled()) {
                $payload['editor_document'] = $article->editor_document;
                $payload['editor_document_schema_version'] = $article->editor_document_schema_version;
                $payload['editor_document_hash'] = $article->editor_document_hash;
                $payload['editor_document_status'] = $article->editor_document_status;
                $payload['editor_document_updated_at'] = $article->editor_document_updated_at;
            }
            $article->forceFill($payload)->save();
        }

        return $prepared;
    }

    /**
     * Legacy HTML writer — updates body and marks JSON stale so bootstrap/publish cannot trust old JSON.
     */
    public function writeLegacyHtmlAndInvalidateDocument(
        SeoArticle $article,
        string $html,
        string $origin = 'legacy_body_writer',
        bool $persist = true,
    ): void {
        $article->body = $html;
        $this->invalidateForLegacyBodyWrite($article, $origin);

        if (! $persist) {
            return;
        }

        $payload = ['body' => $html];
        if ($this->columnsReady($article) && $article->isDirty('editor_document_status')) {
            $payload['editor_document_status'] = $article->editor_document_status;
        }
        $article->forceFill($payload)->save();
    }

    public function publishFromJsonEnabled(): bool
    {
        return $this->configBool('seo-content-ai.article_editor.json_persistence.publish_from_json', false);
    }

    /**
     * Pure PHPUnit has no Laravel `config` binding — fall back to defaults.
     */
    private function configBool(string $key, bool $default): bool
    {
        try {
            if (! function_exists('config')) {
                return $default;
            }

            return (bool) config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * When publish_from_json flag on: re-render body from canonical JSON if status is current.
     * Does not mutate editor_document. Returns HTML to publish (may refresh persisted body).
     */
    public function ensureDerivedBodyForPublish(SeoArticle $article, bool $persistRefresh = true): string
    {
        $body = trim((string) ($article->body ?? ''));
        $boundary = new InlineMarkBoundaryWhitespace;

        if (! $this->publishFromJsonEnabled() || ! $this->columnsReady($article)) {
            return $boundary->repair($body);
        }

        $status = (string) ($article->editor_document_status ?? '');
        if (
            $status === ArticleEditorDocumentSchema::STATUS_STALE
            || $status === ArticleEditorDocumentSchema::STATUS_FAILED
            || $status === ArticleEditorDocumentSchema::STATUS_MANUAL_REVIEW
            || ! is_array($article->editor_document)
        ) {
            return $boundary->repair($body);
        }

        try {
            $prepared = $this->prepareCanonicalDocument($article->editor_document);
            $rendered = trim($prepared['html']);
            if ($rendered === '') {
                return $boundary->repair($body);
            }

            $rendered = $boundary->repair($rendered);

            $bodyHash = hash('sha256', $body);
            $renderedHash = hash('sha256', $rendered);
            if (! hash_equals($bodyHash, $renderedHash) && $persistRefresh) {
                $article->forceFill(['body' => $rendered])->save();
                RuntimeLogger::warning('seo.editor.document_body_refreshed_for_publish', [
                    'article_id' => (int) $article->getKey(),
                    'editor_document_hash' => $prepared['hash'],
                ]);
            }

            return $rendered;
        } catch (\Throwable $exception) {
            RuntimeLogger::warning('seo.editor.document_publish_render_failed', [
                'article_id' => (int) $article->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return $boundary->repair($body);
        }
    }

    /**
     * @return array{ok: bool, status: string, document?: array<string, mixed>, html?: string, code?: string}
     */
    public function lazyMigrateFromBody(SeoArticle $article, bool $persist = true): array
    {
        $html = trim((string) ($article->body ?? ''));
        if ($html === '') {
            return ['ok' => false, 'status' => ArticleEditorDocumentSchema::STATUS_PENDING, 'code' => ArticleEditorDocumentErrorCode::INGEST_FAILED];
        }

        try {
            $envelope = $this->ingest->ingestHtmlToEnvelope($html, 'block-legacy-'.(int) $article->getKey());
            $prepared = $this->prepareCanonicalDocument($envelope);
            $guard = $this->roundTrip->compare($html, $prepared['html']);
            if (! $guard['equivalent']) {
                if ($persist && $this->columnsReady($article)) {
                    $article->forceFill([
                        'editor_document_status' => ArticleEditorDocumentSchema::STATUS_MANUAL_REVIEW,
                    ])->save();
                }

                return [
                    'ok' => false,
                    'status' => ArticleEditorDocumentSchema::STATUS_MANUAL_REVIEW,
                    'code' => ArticleEditorDocumentErrorCode::ROUNDTRIP_MISMATCH,
                    'document' => $prepared['document'],
                    'html' => $prepared['html'],
                ];
            }

            if ($persist && $this->columnsReady($article) && $this->dualWriteEnabled()) {
                $article->forceFill([
                    'editor_document' => $prepared['document'],
                    'editor_document_schema_version' => $prepared['schema_version'],
                    'editor_document_hash' => $prepared['hash'],
                    'editor_document_status' => ArticleEditorDocumentSchema::STATUS_MIGRATED,
                    'editor_document_updated_at' => Carbon::now(),
                ])->save();
            }

            return [
                'ok' => true,
                'status' => ArticleEditorDocumentSchema::STATUS_MIGRATED,
                'document' => $prepared['document'],
                'html' => $prepared['html'],
            ];
        } catch (\Throwable $exception) {
            RuntimeLogger::warning('seo.editor.document_ingest_failed', [
                'article_id' => (int) $article->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => ArticleEditorDocumentSchema::STATUS_FAILED,
                'code' => ArticleEditorDocumentErrorCode::INGEST_FAILED,
            ];
        }
    }

    /**
     * @return array{source: string, document: array<string, mixed>|null, body_html: string, hash: string|null, schema_version: int|null, status: string|null, inline_whitespace_repaired?: bool}
     */
    public function resolveForBootstrap(SeoArticle $article): array
    {
        $rawBodyHtml = (string) ($article->body ?? '');
        $boundary = new InlineMarkBoundaryWhitespace;
        $bodyRepair = $boundary->repairWithReport($rawBodyHtml);
        $bodyHtml = $bodyRepair['html'];
        $bodyWasRepaired = $bodyRepair['repaired'] === true;
        $status = $article->editor_document_status ?? null;

        // Already-persisted glue in body: force HTML path with repaired markup so client can recover.
        if ($bodyWasRepaired) {
            RuntimeLogger::info('seo.editor.bootstrap_inline_whitespace_repaired', [
                'article_id' => (int) $article->getKey(),
                'glued_before' => $bodyRepair['glued_before'],
                'glued_after' => $bodyRepair['glued_after'],
            ]);

            return [
                'source' => 'body_html_repaired',
                'document' => null,
                'body_html' => $bodyHtml,
                'hash' => null,
                'schema_version' => null,
                'status' => is_string($status) ? $status : ArticleEditorDocumentSchema::STATUS_PENDING,
                'inline_whitespace_repaired' => true,
            ];
        }

        if (
            $this->readPreferred()
            && $this->columnsReady($article)
            && is_array($article->editor_document)
            && $status !== ArticleEditorDocumentSchema::STATUS_STALE
            && $status !== ArticleEditorDocumentSchema::STATUS_FAILED
        ) {
            $document = $article->editor_document;
            $validated = $this->schema->validate($document);
            if (($validated['ok'] ?? false) === true) {
                $normalized = $this->schema->normalize($document);
                if ($this->isUsableBootstrapDocument($normalized, $bodyHtml)) {
                    return [
                        'source' => 'editor_document',
                        'document' => $normalized,
                        'body_html' => $bodyHtml,
                        'hash' => (string) ($article->editor_document_hash ?? $this->schema->hash($normalized)),
                        'schema_version' => (int) ($article->editor_document_schema_version ?? ArticleEditorDocumentSchema::CURRENT_VERSION),
                        'status' => (string) ($status ?? ArticleEditorDocumentSchema::STATUS_CURRENT),
                    ];
                }

                RuntimeLogger::info('seo.editor.bootstrap_json_rejected_fallback_html', [
                    'article_id' => (int) $article->getKey(),
                    'status' => is_string($status) ? $status : null,
                    'block_count' => count($normalized['blocks'] ?? []),
                    'body_length' => strlen($bodyHtml),
                ]);
            }
        }

        return [
            'source' => 'body_html',
            'document' => null,
            'body_html' => $bodyHtml,
            'hash' => null,
            'schema_version' => null,
            'status' => is_string($status) ? $status : ArticleEditorDocumentSchema::STATUS_PENDING,
        ];
    }

    /**
     * Bootstrap must not prefer a schema-valid but content-hollow JSON envelope
     * (e.g. image blocks + empty TipTap docs) when body HTML still has text.
     *
     * @param  array<string, mixed>  $document  Normalized envelope
     */
    public function isUsableBootstrapDocument(array $document, string $bodyHtml = ''): bool
    {
        if (($document['type'] ?? '') !== ArticleEditorDocumentSchema::TYPE) {
            return false;
        }

        $blocks = $document['blocks'] ?? null;
        if (! is_array($blocks) || $blocks === []) {
            return false;
        }

        $hasValidBlock = false;
        $hasMeaningfulText = false;
        $textBlockCount = 0;
        $emptyTextBlockCount = 0;
        $jsonPlainLength = 0;
        $jsonPlainJoined = '';
        $jsonHasTableContent = false;

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            if ($type === 'image') {
                $image = is_array($block['image'] ?? null) ? $block['image'] : [];
                $src = trim((string) ($image['src'] ?? $image['url'] ?? ''));
                if ($src !== '') {
                    $hasValidBlock = true;
                }
                continue;
            }

            $tipTap = is_array($block['document'] ?? null) ? $block['document'] : null;
            if ($tipTap === null) {
                continue;
            }
            $hasValidBlock = true;
            $textBlockCount++;
            $plain = $this->tipTapPlainText($tipTap);
            $jsonPlainLength += mb_strlen($plain);
            if ($plain !== '') {
                $hasMeaningfulText = true;
                $jsonPlainJoined = trim($jsonPlainJoined.' '.$plain);
            } else {
                $emptyTextBlockCount++;
            }
            if ($this->tipTapHasTableContent($tipTap)) {
                $jsonHasTableContent = true;
            }
        }

        if (! $hasValidBlock) {
            return false;
        }

        $bodyPlain = $this->htmlPlainText($bodyHtml);
        $bodyPlainLength = mb_strlen($bodyPlain);
        $bodyHasTable = preg_match('/<table\b/i', $bodyHtml) === 1;

        // Body still has prose but JSON text nodes are empty → force HTML fallback.
        if ($bodyPlainLength > 0 && ! $hasMeaningfulText) {
            return false;
        }

        // Body still has real <table> but JSON dropped/emptied tables.
        if ($bodyHasTable && ! $jsonHasTableContent) {
            return false;
        }

        // Partial hollow JSON (1 intro + many empty TipTap blocks) while body keeps full article.
        if (
            $bodyPlainLength >= 80
            && (
                ($textBlockCount > 0 && $emptyTextBlockCount * 2 >= $textBlockCount)
                || $jsonPlainLength * 2 < $bodyPlainLength
                || ($bodyPlainLength - $jsonPlainLength) >= 120
            )
        ) {
            return false;
        }

        // JSON lost inter-word spaces around inline marks vs body → prefer HTML fallback.
        if ($bodyPlainLength > 0 && $this->hasInlineWhitespaceCorruption($bodyPlain, $jsonPlainJoined)) {
            return false;
        }

        return true;
    }

    /**
     * Same letters but lost multiple inter-word spaces → likely mark-boundary corruption.
     */
    public function hasInlineWhitespaceCorruption(string $basePlain, string $candidatePlain, int $minLostSpaces = 2): bool
    {
        $base = trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $basePlain)) ?? $basePlain);
        $candidate = trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $candidatePlain)) ?? $candidatePlain);
        if ($base === '' || $candidate === '' || $base === $candidate) {
            return false;
        }

        $strip = static fn (string $value): string => preg_replace('/\s+/u', '', $value) ?? '';
        if ($strip($base) !== $strip($candidate)) {
            return false;
        }

        $count = static function (string $value): int {
            return preg_match_all('/[\p{L}\p{N}]\s+[\p{L}\p{N}]/u', $value) ?: 0;
        };

        return ($count($base) - $count($candidate)) >= max(2, $minLostSpaces);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function tipTapHasTableContent(array $node): bool
    {
        $type = (string) ($node['type'] ?? '');
        if ($type === 'table') {
            $rows = is_array($node['content'] ?? null) ? $node['content'] : [];

            return $rows !== [];
        }

        $content = $node['content'] ?? null;
        if (! is_array($content)) {
            return false;
        }

        foreach ($content as $child) {
            if (is_array($child) && $this->tipTapHasTableContent($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function tipTapHasMeaningfulText(array $node): bool
    {
        return $this->tipTapPlainText($node) !== '';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function tipTapPlainText(array $node): string
    {
        // Keep interior spaces (mark boundaries); only trim the joined result.
        $raw = (string) ($node['text'] ?? '');
        $parts = $raw !== '' ? [$raw] : [];

        $content = $node['content'] ?? null;
        if (is_array($content)) {
            foreach ($content as $child) {
                if (is_array($child)) {
                    $childText = $this->tipTapPlainText($child);
                    if ($childText !== '') {
                        $parts[] = $childText;
                    }
                }
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
    }

    private function htmlHasMeaningfulText(string $html): bool
    {
        return $this->htmlPlainText($html) !== '';
    }

    private function htmlPlainText(string $html): string
    {
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return trim($plain);
    }
}
