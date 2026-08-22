<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor;

use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Content\Enums\ArticleEditorSessionStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleEditorSession;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter;
use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use App\Support\LocalArticleSaveTimer;
use App\Support\RuntimeLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-authoritative article-scoped edit lease.
 * Same-user tabs may hold independent leases; document_version prevents stale overwrites.
 * Cache mutex via ActionSupport::withArticleLock (article-write:{id}) is fail-fast.
 */
final class ArticleEditorSessionService
{
    public function __construct(
        private readonly ArticleDocumentVersionService $documentVersions,
        private readonly ArticleContentConflictGuard $conflictGuard,
        private readonly ArticleEditorDocumentWriter $documentWriter,
    ) {}

    public function lockTtlSeconds(): int
    {
        return max(180, (int) config('seo-content-ai.article_editor.lock_ttl_seconds', 240));
    }

    /**
     * @return array{session: array<string, mixed>, article: array<string, mixed>, lock: array<string, mixed>}
     *
     * @throws ArticleEditorSessionException
     */
    public function acquire(
        SeoArticle $article,
        User $user,
        string $clientInstanceId,
        int|string|null $knownDocumentVersion = null,
        ?string $userAgent = null,
    ): array {
        $this->assertArticleEditable($article);

        $clientInstanceId = $this->normalizeUuid($clientInstanceId, 'client_instance_id');

        return $this->withArticleWriteLock((int) $article->getKey(), function () use (
            $article,
            $user,
            $clientInstanceId,
            $knownDocumentVersion,
            $userAgent,
        ): array {
            return DB::connection('omi_seo_ai')->transaction(function () use (
                $article,
                $user,
                $clientInstanceId,
                $knownDocumentVersion,
                $userAgent,
            ): array {
                $fresh = SeoArticle::query()->lockForUpdate()->findOrFail((int) $article->getKey());
                $this->expireStaleSessionsForArticle($fresh);

                $activeSessions = $this->findActiveSessionsLocked($fresh);
                $owned = $activeSessions->first(static fn (SeoArticleEditorSession $session): bool =>
                    (int) $session->user_id === (int) $user->getKey()
                    && (string) $session->client_instance_id === $clientInstanceId
                );
                if ($owned instanceof SeoArticleEditorSession) {
                    $this->touchHeartbeat($owned);

                    return $this->ownedAcquirePayload($fresh, $owned->fresh() ?? $owned, $knownDocumentVersion);
                }

                $foreign = $activeSessions->first(static fn (SeoArticleEditorSession $session): bool =>
                    (int) $session->user_id !== (int) $user->getKey()
                );
                if ($foreign instanceof SeoArticleEditorSession) {
                    throw ArticleEditorSessionException::locked($this->publicLockPayload($foreign, $user));
                }

                $now = now();
                $session = new SeoArticleEditorSession([
                    'id' => (string) Str::uuid(),
                    'article_id' => (int) $fresh->getKey(),
                    'user_id' => (int) $user->getKey(),
                    'site_id' => (int) ($fresh->site_id ?? 0) ?: null,
                    'status' => ArticleEditorSessionStatus::Active,
                    'client_instance_id' => $clientInstanceId,
                    'acquired_at' => $now,
                    'heartbeat_at' => $now,
                    'expires_at' => $now->copy()->addSeconds($this->lockTtlSeconds()),
                    'user_agent' => $this->truncateUserAgent($userAgent),
                ]);
                $session->save();

                RuntimeLogger::info('seo.editor.session_acquired', [
                    'article_id' => (int) $fresh->getKey(),
                    'session_id' => (string) $session->id,
                    'user_id' => (int) $user->getKey(),
                    'client_instance_id' => $clientInstanceId,
                    'same_user_active_leases' => $activeSessions
                        ->where('user_id', (int) $user->getKey())
                        ->count(),
                ]);

                return $this->ownedAcquirePayload($fresh, $session, $knownDocumentVersion);
            });
        });
    }

    /**
     * @return array{status: string, expires_at: string|null, document_version: int}
     *
     * @throws ArticleEditorSessionException
     */
    public function heartbeat(SeoArticle $article, string $sessionId, User $user): array
    {
        $session = $this->requireOwnedActiveSession($article, $sessionId, $user);
        $this->touchHeartbeat($session);
        $fresh = $session->fresh() ?? $session;

        return [
            'status' => ArticleEditorSessionStatus::Active->value,
            'expires_at' => $fresh->expires_at?->toIso8601String(),
            'document_version' => $this->documentVersions->current($article->fresh() ?? $article),
        ];
    }

    /**
     * @param  callable(SeoArticle, array<string, mixed>): array{success: bool, message?: string, content_hash?: string, updated_at?: string|null, content_project_handoff?: array<string, mixed>|null}  $persist
     * @return array{saved: bool, document_version: int, content_hash: string, saved_at: string|null, content_project_handoff?: array<string, mixed>|null}
     *
     * @throws ArticleEditorSessionException
     */
    public function saveDocument(
        SeoArticle $article,
        string $sessionId,
        User $user,
        array $document,
        int|string|null $expectedDocumentVersion,
        ?string $expectedContentHash,
        string $saveMode,
        callable $persist,
    ): array {
        $this->assertArticleEditable($article);
        $session = $this->requireOwnedActiveSession($article, $sessionId, $user);
        $articleId = (int) $article->getKey();

        return LocalArticleSaveTimer::measure($articleId, 'saveDocument.total', function () use (
            $article,
            $session,
            $document,
            $expectedDocumentVersion,
            $expectedContentHash,
            $saveMode,
            $persist,
            $articleId,
        ): array {
            return $this->withArticleWriteLock($articleId, function () use (
                $article,
                $session,
                $document,
                $expectedDocumentVersion,
                $expectedContentHash,
                $saveMode,
                $persist,
                $articleId,
            ): array {
                return LocalArticleSaveTimer::measure($articleId, 'saveDocument.dbTransaction', function () use (
                    $article,
                    $session,
                    $document,
                    $expectedDocumentVersion,
                    $expectedContentHash,
                    $saveMode,
                    $persist,
                    $articleId,
                ): array {
                    return DB::connection('omi_seo_ai')->transaction(function () use (
                        $article,
                        $session,
                        $document,
                        $expectedDocumentVersion,
                        $expectedContentHash,
                        $saveMode,
                        $persist,
                        $articleId,
                    ): array {
                        $freshSession = SeoArticleEditorSession::query()->lockForUpdate()->find($session->id);
                        if (! $freshSession instanceof SeoArticleEditorSession || ! $freshSession->isActiveLock()) {
                            throw $this->sessionInactiveException($freshSession);
                        }

                        $freshArticle = SeoArticle::query()->lockForUpdate()->findOrFail($articleId);

                        // Same-content short-circuit (duplicate autosave / lost ACK) before persist.
                        $noop = $this->tryDocumentNoopAck(
                            $freshArticle,
                            $document,
                            $expectedDocumentVersion,
                            $saveMode,
                        );
                        if ($noop !== null) {
                            $this->touchHeartbeat($freshSession);
                            RuntimeLogger::info('seo.editor.session_document_noop', [
                                'article_id' => $articleId,
                                'session_id' => (string) $freshSession->id,
                                'save_mode' => $saveMode,
                                'document_version' => $noop['document_version'],
                            ]);

                            return [
                                ...$noop,
                                'lease_expires_at' => $freshSession->expires_at?->toIso8601String(),
                            ];
                        }

                        $this->documentVersions->assertExpected($freshArticle, $expectedDocumentVersion, [
                            'operation' => 'save_document.'.$saveMode,
                            'editor_session_id' => (string) $freshSession->id,
                            'article_id' => $articleId,
                            'request_id' => is_string($document['client_request_id'] ?? null)
                                ? (string) $document['client_request_id']
                                : null,
                        ]);
                        $this->assertContentHash($freshArticle, $expectedContentHash, $expectedDocumentVersion);

                        $result = LocalArticleSaveTimer::measure(
                            $articleId,
                            'persist.callback',
                            fn (): array => $persist($freshArticle, $document),
                        );
                        if (! ($result['success'] ?? false)) {
                            $persistCode = (string) ($result['code'] ?? 'persist_rejected');
                            $http = $persistCode === 'article_write_busy' ? 409 : 422;
                            throw ArticleEditorSessionException::make(
                                $persistCode,
                                (string) ($result['message'] ?? 'Persist failed.'),
                                ['code' => $persistCode],
                                $http,
                            );
                        }

                        $this->touchHeartbeat($freshSession);
                        $saved = $freshArticle->fresh() ?? $freshArticle;

                        RuntimeLogger::info('seo.editor.session_document_saved', [
                            'article_id' => (int) $saved->getKey(),
                            'session_id' => (string) $freshSession->id,
                            'save_mode' => $saveMode,
                            'document_version' => $this->documentVersions->current($saved),
                        ]);

                        $payload = [
                            'saved' => true,
                            'noop' => false,
                            'document_version' => $this->documentVersions->current($saved),
                            'content_hash' => (string) ($result['content_hash']
                                ?? $this->conflictGuard->contentHash((string) ($saved->body ?? ''))),
                            'editor_document_hash' => (string) ($saved->editor_document_hash ?? ''),
                            'editor_document_schema_version' => (int) ($saved->editor_document_schema_version ?? 0),
                            'saved_at' => $saved->updated_at?->toIso8601String(),
                            'lease_expires_at' => $freshSession->expires_at?->toIso8601String(),
                        ];

                        if (isset($result['content_project_handoff']) && is_array($result['content_project_handoff'])) {
                            $payload['content_project_handoff'] = $result['content_project_handoff'];
                        }

                        return $payload;
                    });
                });
            });
        });
    }

    /**
     * Atomic save + release. On validation/persist failure lock stays active.
     *
     * @param  callable(SeoArticle, array<string, mixed>): array{success: bool, message?: string, content_hash?: string}  $persist
     * @return array{saved: bool, released: bool, document_version: int, content_hash: string, saved_at: string|null}
     *
     * @throws ArticleEditorSessionException
     */
    public function close(
        SeoArticle $article,
        string $sessionId,
        User $user,
        array $document,
        int|string|null $expectedDocumentVersion,
        ?string $expectedContentHash,
        string $closeReason,
        callable $persist,
    ): array {
        $this->assertArticleEditable($article);
        $session = $this->requireOwnedActiveSession($article, $sessionId, $user);

        return $this->withArticleWriteLock((int) $article->getKey(), function () use (
            $article,
            $session,
            $document,
            $expectedDocumentVersion,
            $expectedContentHash,
            $closeReason,
            $persist,
        ): array {
            return DB::connection('omi_seo_ai')->transaction(function () use (
                $article,
                $session,
                $document,
                $expectedDocumentVersion,
                $expectedContentHash,
                $closeReason,
                $persist,
            ): array {
                $freshSession = SeoArticleEditorSession::query()->lockForUpdate()->find($session->id);
                if (! $freshSession instanceof SeoArticleEditorSession || ! $freshSession->isActiveLock()) {
                    throw $this->sessionInactiveException($freshSession);
                }

                $freshArticle = SeoArticle::query()->lockForUpdate()->findOrFail((int) $article->getKey());
                $this->documentVersions->assertExpected($freshArticle, $expectedDocumentVersion, [
                    'operation' => 'close_document',
                    'editor_session_id' => (string) $freshSession->id,
                ]);
                $this->assertContentHash($freshArticle, $expectedContentHash, $expectedDocumentVersion);

                $result = $persist($freshArticle, $document);
                if (! ($result['success'] ?? false)) {
                    $persistCode = (string) ($result['code'] ?? 'persist_rejected');
                    $http = $persistCode === 'article_write_busy' ? 409 : 422;
                    throw ArticleEditorSessionException::make(
                        $persistCode,
                        (string) ($result['message'] ?? 'Persist failed.'),
                        ['code' => $persistCode],
                        $http,
                    );
                }

                $now = now();
                $freshSession->status = ArticleEditorSessionStatus::Released;
                $freshSession->released_at = $now;
                $freshSession->save();

                $saved = $freshArticle->fresh() ?? $freshArticle;

                RuntimeLogger::info('seo.editor.session_closed', [
                    'article_id' => (int) $saved->getKey(),
                    'session_id' => (string) $freshSession->id,
                    'close_reason' => $closeReason,
                    'document_version' => $this->documentVersions->current($saved),
                ]);

                return [
                    'saved' => true,
                    'released' => true,
                    'document_version' => $this->documentVersions->current($saved),
                    'content_hash' => (string) ($result['content_hash']
                        ?? $this->conflictGuard->contentHash((string) ($saved->body ?? ''))),
                    'saved_at' => $saved->updated_at?->toIso8601String(),
                ];
            });
        });
    }

    /**
     * Explicit release without document — only after latest save ACK.
     *
     * @throws ArticleEditorSessionException
     */
    public function release(SeoArticle $article, string $sessionId, User $user): void
    {
        $session = $this->requireOwnedSession($article, $sessionId, $user);

        $this->withArticleWriteLock((int) $article->getKey(), function () use ($session): void {
            DB::connection('omi_seo_ai')->transaction(function () use ($session): void {
                $fresh = SeoArticleEditorSession::query()->lockForUpdate()->find($session->id);
                if (! $fresh instanceof SeoArticleEditorSession) {
                    return;
                }

                if ($fresh->status === ArticleEditorSessionStatus::Active) {
                    $fresh->status = ArticleEditorSessionStatus::Released;
                    $fresh->released_at = now();
                    $fresh->save();

                    RuntimeLogger::info('seo.editor.session_released', [
                        'article_id' => (int) $fresh->article_id,
                        'session_id' => (string) $fresh->id,
                        'user_id' => (int) $fresh->user_id,
                    ]);
                }
            });
        });
    }

    /**
     * @return array{session: array<string, mixed>, article: array<string, mixed>, lock: array<string, mixed>}
    /**
     * @deprecated Exclusive lock UI has no takeover path. Keep for API/admin escape hatch until product/ops ACK.
     *
     * @throws ArticleEditorSessionException
     */
    public function takeover(
        SeoArticle $article,
        User $user,
        string $clientInstanceId,
        int|string|null $knownDocumentVersion,
        bool $confirmation,
        ?string $userAgent = null,
    ): array {
        if (! $confirmation) {
            throw ArticleEditorSessionException::make(
                ArticleEditorSessionErrorCode::TAKEOVER_FORBIDDEN,
                'Takeover requires explicit confirmation.',
                [],
                422,
            );
        }

        if (! $this->userCanTakeover($user)) {
            throw ArticleEditorSessionException::make(
                ArticleEditorSessionErrorCode::TAKEOVER_FORBIDDEN,
                'Takeover forbidden for current role.',
                [],
                403,
            );
        }

        $this->assertArticleEditable($article);
        $clientInstanceId = $this->normalizeUuid($clientInstanceId, 'client_instance_id');

        return $this->withArticleWriteLock((int) $article->getKey(), function () use (
            $article,
            $user,
            $clientInstanceId,
            $knownDocumentVersion,
            $userAgent,
        ): array {
            return DB::connection('omi_seo_ai')->transaction(function () use (
                $article,
                $user,
                $clientInstanceId,
                $knownDocumentVersion,
                $userAgent,
            ): array {
                $fresh = SeoArticle::query()->lockForUpdate()->findOrFail((int) $article->getKey());
                $this->expireStaleSessionsForArticle($fresh);

                $active = $this->findActiveSessionLocked($fresh);
                $oldSessionId = $active?->id;

                if ($active instanceof SeoArticleEditorSession) {
                    $active->status = ArticleEditorSessionStatus::TakenOver;
                    $active->revoked_at = now();
                    $active->takeover_by_user_id = (int) $user->getKey();
                    $active->save();
                }

                $now = now();
                $session = new SeoArticleEditorSession([
                    'id' => (string) Str::uuid(),
                    'article_id' => (int) $fresh->getKey(),
                    'user_id' => (int) $user->getKey(),
                    'site_id' => (int) ($fresh->site_id ?? 0) ?: null,
                    'status' => ArticleEditorSessionStatus::Active,
                    'client_instance_id' => $clientInstanceId,
                    'acquired_at' => $now,
                    'heartbeat_at' => $now,
                    'expires_at' => $now->copy()->addSeconds($this->lockTtlSeconds()),
                    'user_agent' => $this->truncateUserAgent($userAgent),
                ]);
                $session->save();

                RuntimeLogger::info('seo.editor.session_takeover', [
                    'article_id' => (int) $fresh->getKey(),
                    'old_session_id' => $oldSessionId,
                    'new_session_id' => (string) $session->id,
                    'user_id' => (int) $user->getKey(),
                ]);

                return $this->ownedAcquirePayload($fresh, $session, $knownDocumentVersion);
            });
        });
    }

    public function revokeActiveSessionsForArticle(int $articleId, string $reason = 'revoked'): int
    {
        if ($articleId <= 0) {
            return 0;
        }

        $now = now();
        $count = SeoArticleEditorSession::query()
            ->where('article_id', $articleId)
            ->where('status', ArticleEditorSessionStatus::Active)
            ->update([
                'status' => ArticleEditorSessionStatus::Revoked,
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);

        if ($count > 0) {
            RuntimeLogger::info('seo.editor.sessions_revoked', [
                'article_id' => $articleId,
                'count' => $count,
                'reason' => $reason,
            ]);
        }

        return $count;
    }

    /**
     * @param  list<int>  $articleIds
     */
    public function revokeActiveSessionsForArticles(array $articleIds, string $reason = 'content_project_archived'): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $now = now();
        $count = SeoArticleEditorSession::query()
            ->whereIn('article_id', $ids)
            ->where('status', ArticleEditorSessionStatus::Active)
            ->update([
                'status' => ArticleEditorSessionStatus::Revoked,
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);

        if ($count > 0) {
            RuntimeLogger::info('seo.editor.sessions_revoked_bulk', [
                'article_ids_count' => count($ids),
                'count' => $count,
                'reason' => $reason,
            ]);
        }

        return $count;
    }

    public function findActiveSession(SeoArticle|int $article): ?SeoArticleEditorSession
    {
        $articleId = $article instanceof SeoArticle ? (int) $article->getKey() : $article;
        $this->expireStaleSessionsForArticleId($articleId);

        $session = SeoArticleEditorSession::query()
            ->where('article_id', $articleId)
            ->where('status', ArticleEditorSessionStatus::Active)
            ->where('expires_at', '>', now())
            ->orderByDesc('acquired_at')
            ->first();

        return $session instanceof SeoArticleEditorSession ? $session : null;
    }

    public function userFacingSaveBlockedByForeignSession(SeoArticle $article, ?User $user): ?SeoArticleEditorSession
    {
        $active = $this->findActiveSession($article);
        if (! $active instanceof SeoArticleEditorSession) {
            return null;
        }

        if ($user instanceof User && (int) $active->user_id === (int) $user->getKey()) {
            return null;
        }

        return $active;
    }

    public function userCanTakeover(?User $user = null): bool
    {
        if ($user === null) {
            return SeoAccessControl::canAccessManagerFeatures();
        }

        $role = SeoAccessControl::normalizeRole((string) ($user->seo_role ?? SeoAccessControl::ROLE_CONTENT_MANAGER));

        return $role === SeoAccessControl::ROLE_MANAGER;
    }

    /**
     * User-facing write from editor shell (Livewire/API) must own active session.
     *
     * @throws ArticleEditorSessionException
     */
    public function assertOwningActiveSessionForWrite(
        SeoArticle $article,
        User $user,
        ?string $sessionId,
        int|string|null $expectedDocumentVersion = null,
    ): SeoArticleEditorSession {
        $sessionId = trim((string) $sessionId);
        if ($sessionId === '') {
            throw ArticleEditorSessionException::make(
                ArticleEditorSessionErrorCode::LOCK_NOT_OWNED,
                'Editor session id required for body write.',
                [],
                409,
            );
        }

        $this->assertArticleEditable($article);
        $session = $this->requireOwnedActiveSession($article, $sessionId, $user);
        $this->documentVersions->assertExpected($article->fresh() ?? $article, $expectedDocumentVersion, [
            'operation' => 'owning_session_write',
            'editor_session_id' => $sessionId,
        ]);

        return $session;
    }

    /**
     * Media/body rewrite from owning editor (Fix Slug All, URL rewrite).
     * - No active session → allow (returns null).
     * - Owning active session → allow.
     * - Foreign active session → 423.
     *
     * @throws ArticleEditorSessionException
     */
    public function assertOwningActiveSessionForMediaMutation(
        SeoArticle $article,
        User $user,
        ?string $sessionId,
        string $operation = 'media_url_rewrite',
    ): ?SeoArticleEditorSession {
        $this->assertArticleEditable($article);
        $this->expireStaleSessionsForArticle($article);

        $active = $this->findActiveSession($article);
        if (! $active instanceof SeoArticleEditorSession) {
            return null;
        }

        $sessionId = trim((string) $sessionId);
        if ((int) $active->user_id === (int) $user->getKey()) {
            return $active;
        }

        if (
            $sessionId !== ''
            && (string) $active->id === $sessionId
            && (int) $active->user_id === (int) $user->getKey()
            && $active->isActiveLock()
        ) {
            return $active;
        }

        RuntimeLogger::warning('seo.editor.media_mutation_blocked_by_session', [
            'article_id' => (int) $article->getKey(),
            'session_id' => (string) $active->id,
            'operation' => $operation,
            'provided_session_id' => $sessionId !== '' ? $sessionId : null,
        ]);

        throw ArticleEditorSessionException::locked($this->publicLockPayload($active, $user));
    }

    /**
     * Media/body rewrite policy:
     * - no active session → allow (CLI/job/legacy paths);
     * - owning session id + user → allow;
     * - other active session → locked.
     *
     * @throws ArticleEditorSessionException
     */
    public function assertBodyRewriteAllowed(
        SeoArticle $article,
        string $operation = 'media_url_rewrite',
        ?string $editorSessionId = null,
        ?User $user = null,
    ): void {
        $active = $this->findActiveSession($article);
        if (! $active instanceof SeoArticleEditorSession) {
            return;
        }

        $sessionId = trim((string) $editorSessionId);
        $actor = $user instanceof User
            ? $user
            : (auth()->user() instanceof User ? auth()->user() : null);

        if ($actor instanceof User && (int) $active->user_id === (int) $actor->getKey()) {
            return;
        }

        if (
            $sessionId !== ''
            && $actor instanceof User
            && (string) $active->id === $sessionId
            && (int) $active->user_id === (int) $actor->getKey()
            && $active->isActiveLock()
        ) {
            return;
        }

        RuntimeLogger::warning('seo.editor.media_rewrite_blocked_by_session', [
            'article_id' => (int) $article->getKey(),
            'session_id' => (string) $active->id,
            'operation' => $operation,
            'provided_session_id' => $sessionId !== '' ? $sessionId : null,
        ]);

        throw ArticleEditorSessionException::locked($this->publicLockPayload($active, $actor));
    }

    /**
     * External user-facing body mutation (revision restore, AI apply outside owning session).
     *
     * @throws ArticleEditorSessionException
     */
    public function assertNoActiveEditorSession(SeoArticle $article, string $operation = 'body_write'): void
    {
        $active = $this->findActiveSession($article);
        if (! $active instanceof SeoArticleEditorSession) {
            return;
        }

        $actor = auth()->user();
        if ($actor instanceof User && (int) $active->user_id === (int) $actor->getKey()) {
            return;
        }

        RuntimeLogger::warning('seo.editor.external_write_blocked_by_session', [
            'article_id' => (int) $article->getKey(),
            'session_id' => (string) $active->id,
            'operation' => $operation,
        ]);

        throw ArticleEditorSessionException::locked($this->publicLockPayload(
            $active,
            $actor instanceof User ? $actor : null,
        ));
    }

    /**
     * @throws ArticleEditorSessionException
     */
    public function assertArticleEditable(SeoArticle $article): void
    {
        if (method_exists($article, 'trashed') && $article->trashed()) {
            throw ArticleEditorSessionException::make(
                ArticleEditorSessionErrorCode::NOT_EDITABLE,
                'Article is not editable.',
                [],
                422,
            );
        }

        // Archived Content Project association is historical/reporting only.
        // It must NOT deny writable Article Editor sessions — article returns to standalone behavior.
        // CONTENT_PROJECT_ARCHIVED remains a revoke reason during archive; post-archive acquire succeeds.
    }

    private function expireStaleSessionsForArticle(SeoArticle $article): void
    {
        $this->expireStaleSessionsForArticleId((int) $article->getKey());
    }

    private function expireStaleSessionsForArticleId(int $articleId): void
    {
        if ($articleId <= 0) {
            return;
        }

        $now = now();
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $expired = SeoArticleEditorSession::query()
                    ->where('article_id', $articleId)
                    ->where('status', ArticleEditorSessionStatus::Active)
                    ->where('expires_at', '<=', $now)
                    ->update([
                        'status' => ArticleEditorSessionStatus::Expired,
                        'updated_at' => $now,
                    ]);

                if ($expired > 0) {
                    RuntimeLogger::info('seo.editor.sessions_expired', [
                        'article_id' => $articleId,
                        'count' => $expired,
                    ]);
                }

                return;
            } catch (QueryException $exception) {
                if ($attempt < $maxAttempts && $this->isTransientSessionLockFailure($exception)) {
                    usleep(50_000 * $attempt);

                    continue;
                }

                if ($this->isTransientSessionLockFailure($exception)) {
                    // Best-effort sweep — never fail heartbeat/save solely because expire raced.
                    RuntimeLogger::warning('seo.editor.sessions_expire_skipped_lock', [
                        'article_id' => $articleId,
                        'attempt' => $attempt,
                        'sql_state' => (string) ($exception->errorInfo[0] ?? ''),
                        'driver_code' => (int) ($exception->errorInfo[1] ?? 0),
                    ]);

                    return;
                }

                throw $exception;
            }
        }
    }

    private function isTransientSessionLockFailure(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        return $driverCode === 1205
            || $driverCode === 1213
            || ($sqlState === '40001')
            || ($sqlState === 'HY000' && str_contains($message, 'Lock wait timeout'))
            || str_contains($message, 'Deadlock found')
            || str_contains($message, 'SQLSTATE[40001]');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, SeoArticleEditorSession>
     */
    private function findActiveSessionsLocked(SeoArticle $article): \Illuminate\Database\Eloquent\Collection
    {
        return SeoArticleEditorSession::query()
            ->where('article_id', (int) $article->getKey())
            ->where('status', ArticleEditorSessionStatus::Active)
            ->where('expires_at', '>', now())
            ->orderByDesc('acquired_at')
            ->lockForUpdate()
            ->get();
    }

    private function touchHeartbeat(SeoArticleEditorSession $session): void
    {
        $now = now();
        $session->heartbeat_at = $now;
        $session->expires_at = $now->copy()->addSeconds($this->lockTtlSeconds());
        $session->save();
    }

    /**
     * @throws ArticleEditorSessionException
     */
    private function requireOwnedActiveSession(SeoArticle $article, string $sessionId, User $user): SeoArticleEditorSession
    {
        $session = $this->requireOwnedSession($article, $sessionId, $user);
        $this->expireStaleSessionsForArticle($article);
        $session->refresh();

        if (! $session->isActiveLock()) {
            throw $this->sessionInactiveException($session);
        }

        return $session;
    }

    /**
     * @throws ArticleEditorSessionException
     */
    private function requireOwnedSession(SeoArticle $article, string $sessionId, User $user): SeoArticleEditorSession
    {
        $session = SeoArticleEditorSession::query()->find($sessionId);
        if (! $session instanceof SeoArticleEditorSession) {
            throw ArticleEditorSessionException::make(
                ArticleEditorSessionErrorCode::SESSION_NOT_FOUND,
                'Editor session not found.',
                [],
                404,
            );
        }

        if ((int) $session->article_id !== (int) $article->getKey()) {
            throw ArticleEditorSessionException::make(
                ArticleEditorSessionErrorCode::SESSION_NOT_FOUND,
                'Editor session not found for article.',
                [],
                404,
            );
        }

        if ((int) $session->user_id !== (int) $user->getKey()) {
            throw ArticleEditorSessionException::make(
                ArticleEditorSessionErrorCode::LOCK_NOT_OWNED,
                'Editor session is not owned by current user.',
                [],
                409,
            );
        }

        return $session;
    }

    private function sessionInactiveException(?SeoArticleEditorSession $session): ArticleEditorSessionException
    {
        $status = $session?->status;

        $code = match ($status) {
            ArticleEditorSessionStatus::Expired => ArticleEditorSessionErrorCode::SESSION_EXPIRED,
            ArticleEditorSessionStatus::Revoked => ArticleEditorSessionErrorCode::SESSION_REVOKED,
            ArticleEditorSessionStatus::TakenOver => ArticleEditorSessionErrorCode::SESSION_TAKEN_OVER,
            ArticleEditorSessionStatus::Released => ArticleEditorSessionErrorCode::LOCK_NOT_OWNED,
            default => ArticleEditorSessionErrorCode::SESSION_EXPIRED,
        };

        return ArticleEditorSessionException::make(
            $code,
            'Editor session is no longer active.',
            [
                'session_id' => $session?->id,
                'status' => $status?->value,
            ],
            409,
        );
    }

    /**
     * Same-content short-circuit after session ownership + row locks.
     * Skips HTML render, article update, revision, and document-changed side effects.
     *
     * Policy:
     * - expected version matches → noop when canonical hash matches;
     * - expected version stale but same owning session + same hash → lost-ACK idempotent noop;
     * - hash differs with version skew → fall through to assertExpected (conflict).
     *
     * @param  array<string, mixed>  $bundle
     * @return array{
     *     saved: bool,
     *     noop: bool,
     *     success: bool,
     *     reconciled?: bool,
     *     document_version: int,
     *     content_hash: string,
     *     body_hash: string,
     *     editor_document_hash: string,
     *     editor_document_schema_version: int,
     *     saved_at: string|null
     * }|null
     */
    private function tryDocumentNoopAck(
        SeoArticle $article,
        array $bundle,
        int|string|null $expectedDocumentVersion,
        string $saveMode,
    ): ?array {
        if (! in_array($saveMode, ['autosave', 'explicit'], true)) {
            return null;
        }

        $currentBodyHash = $this->conflictGuard->contentHash((string) ($article->body ?? ''));
        $currentEditorHash = trim((string) ($article->editor_document_hash ?? ''));
        $currentVersion = $this->documentVersions->current($article);

        $incomingDocument = is_array($bundle['editor_document'] ?? null)
            ? $bundle['editor_document']
            : null;

        $matchedEditorHash = '';
        if ($incomingDocument !== null) {
            try {
                $incomingHash = $this->documentWriter->canonicalHash($incomingDocument);
            } catch (\Throwable) {
                // Invalid document must reject via persist path — not silent noop.
                return null;
            }

            if ($currentEditorHash !== '' && hash_equals($currentEditorHash, $incomingHash)) {
                $matchedEditorHash = $currentEditorHash;
            } else {
                return null;
            }
        } else {
            $html = (string) ($bundle['html'] ?? $bundle['document'] ?? '');
            if ($html === '' || ! hash_equals($currentBodyHash, $this->conflictGuard->contentHash($html))) {
                return null;
            }
            $matchedEditorHash = $currentEditorHash;
        }

        $expectedVersion = ($expectedDocumentVersion === null || $expectedDocumentVersion === '')
            ? null
            : (int) $expectedDocumentVersion;

        $reconciled = false;
        if ($expectedVersion !== null && $expectedVersion !== $currentVersion) {
            // Lost ACK / same-session retry: client behind, content identical.
            // Client ahead of server is unsafe — fall through to conflict assert.
            if ($expectedVersion > $currentVersion) {
                return null;
            }
            $reconciled = true;
        }

        if (config('app.debug')) {
            RuntimeLogger::info('seo.editor.version_debug', [
                'event' => 'document_noop_ack',
                'article_id' => (int) $article->getKey(),
                'expected_document_version' => $expectedVersion,
                'document_version' => $currentVersion,
                'editor_document_hash' => $matchedEditorHash !== ''
                    ? substr($matchedEditorHash, 0, 12)
                    : null,
                'content_hash' => substr($currentBodyHash, 0, 12),
                'reconciled' => $reconciled,
                'save_mode' => $saveMode,
            ]);
        }

        return [
            'saved' => true,
            'noop' => true,
            'success' => true,
            'reconciled' => $reconciled,
            'document_version' => $currentVersion,
            'content_hash' => $currentBodyHash,
            'body_hash' => $currentBodyHash,
            'editor_document_hash' => $matchedEditorHash,
            'editor_document_schema_version' => (int) ($article->editor_document_schema_version ?? 0),
            'saved_at' => $article->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @throws ArticleEditorSessionException
     */
    private function assertContentHash(
        SeoArticle $article,
        ?string $expectedContentHash,
        int|string|null $expectedDocumentVersion = null,
    ): void {
        if (! is_string($expectedContentHash) || $expectedContentHash === '') {
            return;
        }

        $conflict = $this->conflictGuard->assertCompatible($article, [
            'expected_content_hash' => $expectedContentHash,
            // Pass version so matching canonical lock does not let legacy hash veto.
            'expected_document_version' => $expectedDocumentVersion,
        ]);

        if ($conflict !== null) {
            RuntimeLogger::warning('seo.editor.content_hash_conflict', [
                'article_id' => (int) $article->getKey(),
            ]);

            throw ArticleEditorSessionException::make(
                ArticleEditorSessionErrorCode::CONTENT_HASH_CONFLICT,
                (string) ($conflict->error['message'] ?? 'Content hash conflict.'),
                is_array($conflict->error) ? $conflict->error : [],
                409,
            );
        }
    }

    /**
     * @return array{session: array<string, mixed>, article: array<string, mixed>, lock: array<string, mixed>}
     */
    private function ownedAcquirePayload(
        SeoArticle $article,
        SeoArticleEditorSession $session,
        int|string|null $knownDocumentVersion,
    ): array {
        $version = $this->documentVersions->current($article);
        if ($knownDocumentVersion !== null && $knownDocumentVersion !== '' && (int) $knownDocumentVersion !== $version) {
            // Soft signal only — client must reload document; acquire still succeeds for owner.
            RuntimeLogger::info('seo.editor.acquire_version_skew', [
                'article_id' => (int) $article->getKey(),
                'known' => (int) $knownDocumentVersion,
                'actual' => $version,
            ]);
        }

        return [
            'session' => [
                'id' => (string) $session->id,
                'status' => ArticleEditorSessionStatus::Active->value,
                'lease_ttl_seconds' => $this->lockTtlSeconds(),
                'started_at' => $session->acquired_at?->toIso8601String(),
                'last_seen_at' => $session->heartbeat_at?->toIso8601String(),
                'expires_at' => $session->expires_at?->toIso8601String(),
                'tab_id' => (string) $session->client_instance_id,
                'client_instance_id' => (string) $session->client_instance_id,
            ],
            'article' => [
                'document_version' => $version,
                'updated_at' => $article->updated_at?->toIso8601String(),
                'content_hash' => $this->conflictGuard->contentHash((string) ($article->body ?? '')),
            ],
            'lock' => [
                'owned_by_current_session' => true,
            ],
        ];
    }

    /**
     * @return array{editor_name: string, acquired_at: string|null, heartbeat_at: string|null, expires_at: string|null, can_takeover: bool}
     */
    private function publicLockPayload(SeoArticleEditorSession $session, ?User $viewer = null): array
    {
        $editorName = 'Another editor';
        try {
            $owner = User::query()->find((int) $session->user_id);
            if ($owner instanceof User) {
                $name = trim((string) ($owner->name ?? ''));
                if ($name !== '') {
                    $editorName = $name;
                }
            }
        } catch (\Throwable) {
            // keep generic name
        }

        return [
            'editor_name' => $editorName,
            'acquired_at' => $session->acquired_at?->toIso8601String(),
            'heartbeat_at' => $session->heartbeat_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'can_takeover' => $this->userCanTakeover($viewer),
        ];
    }

    private function normalizeUuid(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || ! Str::isUuid($value)) {
            throw ArticleEditorSessionException::make(
                'invalid_'.$field,
                $field.' must be a UUID.',
                [],
                422,
            );
        }

        return strtolower($value);
    }

    private function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $trimmed = trim($userAgent);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 255);
    }

    /**
     * Editor UI requests must never occupy a PHP worker waiting for a mutex.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withArticleWriteLock(int $articleId, callable $callback): mixed
    {
        try {
            return ActionSupport::withArticleLock($articleId, $callback);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== 'article_write_busy') {
                throw $exception;
            }

            throw ArticleEditorSessionException::make(
                'article_write_busy',
                'Article is currently being updated.',
                ['article_id' => $articleId],
                409,
            );
        }
    }
}
