<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Phase 5A editor document error codes.
 */
final class ArticleEditorDocumentErrorCode
{
    public const INVALID = 'editor_document_invalid';

    public const SCHEMA_UNSUPPORTED = 'editor_document_schema_unsupported';

    public const NODE_UNSUPPORTED = 'editor_document_node_unsupported';

    public const TOO_LARGE = 'editor_document_too_large';

    public const HASH_CONFLICT = 'editor_document_hash_conflict';

    public const RENDER_FAILED = 'editor_document_render_failed';

    public const INGEST_FAILED = 'editor_document_ingest_failed';

    public const ROUNDTRIP_MISMATCH = 'editor_document_roundtrip_mismatch';

    public const STALE = 'editor_document_stale';

    public const LEGACY_REQUIRES_MIGRATION = 'legacy_body_requires_migration';

    public const REVISION_INVALID = 'revision_document_invalid';
}
