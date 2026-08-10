<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Stable machine codes for editor session / document version protocol.
 * Frontend must branch on these codes, not message text.
 */
final class ArticleEditorSessionErrorCode
{
    public const LOCKED = 'article_editor_locked';

    public const SESSION_NOT_FOUND = 'article_editor_session_not_found';

    public const SESSION_EXPIRED = 'article_editor_session_expired';

    public const SESSION_REVOKED = 'article_editor_session_revoked';

    public const SESSION_TAKEN_OVER = 'article_editor_session_taken_over';

    public const LOCK_NOT_OWNED = 'article_editor_lock_not_owned';

    public const DOCUMENT_VERSION_CONFLICT = 'article_document_version_conflict';

    public const CONTENT_HASH_CONFLICT = 'article_content_hash_conflict';

    public const NOT_EDITABLE = 'article_not_editable';

    public const CONTENT_PROJECT_ARCHIVED = 'content_project_archived';

    public const TAKEOVER_FORBIDDEN = 'takeover_forbidden';
}
