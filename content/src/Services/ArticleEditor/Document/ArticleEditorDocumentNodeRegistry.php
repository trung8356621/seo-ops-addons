<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor\Document;

/**
 * Allowed TipTap/PM node & mark registry for persistence (Phase 5A).
 *
 * @phpstan-type NodeSpec array{type: string, schemaVersion: int, validateAttrs?: bool}
 */
final class ArticleEditorDocumentNodeRegistry
{
    /** @var list<string> */
    public const BLOCK_TYPES = [
        'doc',
        'paragraph',
        'heading',
        'blockquote',
        'bulletList',
        'orderedList',
        'listItem',
        'table',
        'tableRow',
        'tableCell',
        'tableHeader',
        'hardBreak',
        'horizontalRule',
        'codeBlock',
        'image',
        'articleImage',
        'text',
    ];

    /** @var list<string> */
    public const MARK_TYPES = [
        'bold',
        'italic',
        'underline',
        'strike',
        'link',
        'code',
        'highlight',
        'subscript',
        'superscript',
        'textStyle',
    ];

    /** @var list<string> */
    public const ENVELOPE_BLOCK_TYPES = [
        'text',
        'image',
        'section',
        'content',
    ];

    public function isAllowedNodeType(string $type): bool
    {
        return in_array($type, self::BLOCK_TYPES, true);
    }

    public function isAllowedMarkType(string $type): bool
    {
        return in_array($type, self::MARK_TYPES, true);
    }

    public function isAllowedEnvelopeBlockType(string $type): bool
    {
        return in_array($type, self::ENVELOPE_BLOCK_TYPES, true);
    }
}
