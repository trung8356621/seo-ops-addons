<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor\Document;

use Omnichannel\Addons\Content\Support\ArticleEditorDocumentErrorCode;

/**
 * Canonical article_document envelope schema (multi TipTap block model).
 */
final class ArticleEditorDocumentSchema
{
    public const CURRENT_VERSION = 1;

    public const TYPE = 'article_document';

    public const STATUS_PENDING = 'pending';

    public const STATUS_MIGRATED = 'migrated';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MANUAL_REVIEW = 'manual_review';

    public const STATUS_STALE = 'stale';

    public const STATUS_CURRENT = 'current';

    public function __construct(
        private readonly ArticleEditorDocumentNodeRegistry $registry,
        private readonly ArticleEditorDocumentHtmlRenderer $renderer,
    ) {}

    public function currentVersion(): int
    {
        return self::CURRENT_VERSION;
    }

    public function maxPayloadBytes(): int
    {
        return max(64_000, (int) $this->configInt(
            'seo-content-ai.article_editor.json_persistence.max_payload_bytes',
            2_000_000,
        ));
    }

    public function maxNodeCount(): int
    {
        return max(100, (int) $this->configInt(
            'seo-content-ai.article_editor.json_persistence.max_node_count',
            50_000,
        ));
    }

    public function maxDepth(): int
    {
        return max(8, (int) $this->configInt(
            'seo-content-ai.article_editor.json_persistence.max_depth',
            40,
        ));
    }

    /**
     * Pure PHPUnit has no Laravel `config` binding — fall back to defaults.
     */
    private function configInt(string $key, int $default): int
    {
        try {
            if (! function_exists('config')) {
                return $default;
            }

            return (int) config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @return array{ok: bool, code?: string, message?: string, document?: array<string, mixed>}
     */
    public function validate(?array $document): array
    {
        if ($document === null) {
            return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => 'Document is null.'];
        }

        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => 'Document is not JSON-serializable.'];
        }

        if (strlen($encoded) > $this->maxPayloadBytes()) {
            return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::TOO_LARGE, 'message' => 'Document payload too large.'];
        }

        $schemaVersion = (int) ($document['schema_version'] ?? 0);
        if ($schemaVersion < 1 || $schemaVersion > self::CURRENT_VERSION) {
            return [
                'ok' => false,
                'code' => ArticleEditorDocumentErrorCode::SCHEMA_UNSUPPORTED,
                'message' => 'Unsupported editor document schema version.',
            ];
        }

        if (($document['type'] ?? '') !== self::TYPE) {
            return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => 'Expected type article_document.'];
        }

        $blocks = $document['blocks'] ?? null;
        if (! is_array($blocks)) {
            return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => 'blocks must be an array.'];
        }

        $nodeCount = 0;
        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => "Block {$index} invalid."];
            }
            $blockType = (string) ($block['type'] ?? 'text');
            if (! $this->registry->isAllowedEnvelopeBlockType($blockType)) {
                return [
                    'ok' => false,
                    'code' => ArticleEditorDocumentErrorCode::NODE_UNSUPPORTED,
                    'message' => "Unsupported envelope block type: {$blockType}",
                ];
            }
            $id = trim((string) ($block['id'] ?? ''));
            if ($id === '') {
                return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => "Block {$index} missing id."];
            }

            if ($blockType === 'image') {
                continue;
            }

            $tipTap = $block['document'] ?? null;
            if (! is_array($tipTap)) {
                return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => "Block {$id} missing TipTap document."];
            }
            $walk = $this->walkValidate($tipTap, 0, $nodeCount);
            if ($walk !== null) {
                return $walk;
            }
        }

        return ['ok' => true, 'document' => $document];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function normalize(array $document): array
    {
        $blocks = [];
        foreach (($document['blocks'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            $id = trim((string) ($block['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if ($type === 'image') {
                $image = is_array($block['image'] ?? null) ? $block['image'] : [];
                $blocks[] = [
                    'id' => $id,
                    'type' => 'image',
                    'image' => [
                        'src' => trim((string) ($image['src'] ?? $image['url'] ?? '')),
                        'alt' => trim((string) ($image['alt'] ?? '')),
                        'title' => trim((string) ($image['title'] ?? '')),
                        'caption' => trim((string) ($image['caption'] ?? '')),
                        'align' => trim((string) ($image['align'] ?? 'none')),
                    ],
                ];
                continue;
            }

            $tipTap = is_array($block['document'] ?? null) ? $block['document'] : ['type' => 'doc', 'content' => []];
            if (($tipTap['type'] ?? '') !== 'doc') {
                $tipTap = ['type' => 'doc', 'content' => is_array($tipTap['content'] ?? null) ? $tipTap['content'] : [$tipTap]];
            }
            $blocks[] = [
                'id' => $id,
                'type' => $type === 'section' || $type === 'content' ? $type : 'text',
                'document' => $tipTap,
            ];
        }

        return [
            'schema_version' => self::CURRENT_VERSION,
            'type' => self::TYPE,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function hash(array $document): string
    {
        $normalized = $this->normalize($document);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function renderHtml(array $document): string
    {
        return $this->renderer->renderEnvelope($this->normalize($document));
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array<string, mixed>
     */
    public function migrate(array $from): array
    {
        $version = (int) ($from['schema_version'] ?? 0);
        if ($version < 1) {
            throw new ArticleEditorDocumentException(
                ArticleEditorDocumentErrorCode::SCHEMA_UNSUPPORTED,
                'Cannot migrate schema version '.$version,
            );
        }

        return $this->normalize($from);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{ok: bool, code?: string, message?: string}|null
     */
    private function walkValidate(array $node, int $depth, int &$nodeCount): ?array
    {
        if ($depth > $this->maxDepth()) {
            return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => 'Document depth exceeded.'];
        }

        $nodeCount++;
        if ($nodeCount > $this->maxNodeCount()) {
            return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::TOO_LARGE, 'message' => 'Document node count exceeded.'];
        }

        $type = (string) ($node['type'] ?? '');
        if ($type === '' || ! $this->registry->isAllowedNodeType($type)) {
            return [
                'ok' => false,
                'code' => ArticleEditorDocumentErrorCode::NODE_UNSUPPORTED,
                'message' => "Unsupported node type: {$type}",
            ];
        }

        if (isset($node['marks']) && is_array($node['marks'])) {
            foreach ($node['marks'] as $mark) {
                if (! is_array($mark)) {
                    return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => 'Invalid mark.'];
                }
                $markType = (string) ($mark['type'] ?? '');
                if (! $this->registry->isAllowedMarkType($markType)) {
                    return [
                        'ok' => false,
                        'code' => ArticleEditorDocumentErrorCode::NODE_UNSUPPORTED,
                        'message' => "Unsupported mark type: {$markType}",
                    ];
                }
                if ($markType === 'link') {
                    $href = trim((string) ($mark['attrs']['href'] ?? ''));
                    if ($href !== '' && preg_match('/^\s*javascript:/i', $href) === 1) {
                        return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => 'Unsafe link href.'];
                    }
                }
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                if (! is_array($child)) {
                    return ['ok' => false, 'code' => ArticleEditorDocumentErrorCode::INVALID, 'message' => 'Invalid child node.'];
                }
                $childFail = $this->walkValidate($child, $depth + 1, $nodeCount);
                if ($childFail !== null) {
                    return $childFail;
                }
            }
        }

        return null;
    }
}
