<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeIndex;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeRepository;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeRetriever;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeSourceRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeItemData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentMemoryProposal;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Security\AgentKnowledgeContentSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DefaultAgentKnowledgeOrchestrator implements AgentKnowledgeOrchestrator
{
    /** @var list<string> */
    private const TYPES = [
        'brand', 'audience', 'product', 'service', 'tone', 'style_rule', 'content_rule',
        'seo_rule', 'legal_rule', 'cta', 'prohibited_term', 'preferred_term',
        'project_decision', 'workspace_fact', 'user_preference', 'general_note',
    ];

    public function __construct(
        private readonly AgentKnowledgeRepository $repository,
        private readonly AgentKnowledgeIndex $index,
        private readonly AgentKnowledgeChunker $chunker,
        private readonly AgentKnowledgeSourceRegistry $sources,
        private readonly AgentKnowledgeContentSanitizer $sanitizer,
        private readonly AgentKnowledgeRetriever $retriever,
        private readonly AgentMemoryProposalService $proposals,
        private readonly AgentKnowledgeFreshnessService $freshness,
        private readonly int $maxChunks = 40,
    ) {}

    public function ingest(AgentWorkspaceContext $context, array $input): array
    {
        $this->assertSite($context);

        $sourceType = (string) ($input['source_type'] ?? 'manual');
        if (! $this->sources->supports($sourceType)) {
            return ['ok' => false, 'code' => 'unsupported_source', 'message' => 'Nguồn không hỗ trợ.'];
        }

        // Execution-derived facts only from succeeded executions.
        if ($sourceType === 'execution_result') {
            $status = (string) ($input['status'] ?? '');
            if ($status !== 'succeeded') {
                return ['ok' => false, 'code' => 'execution_not_succeeded', 'message' => 'Chỉ lưu fact từ execution succeeded.'];
            }
        }

        try {
            $extracted = $this->sources->extract($sourceType, $input);
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => $e->getMessage(), 'message' => 'Không đọc được nguồn.'];
        }

        $type = (string) ($input['type'] ?? 'general_note');
        if (! in_array($type, self::TYPES, true)) {
            $type = 'general_note';
        }

        $scopeType = (string) ($input['scope_type'] ?? 'site');
        $scopeRef = $this->canonicalScopeRef($context, $scopeType, $input['scope_ref'] ?? null);

        $sanitized = $this->sanitizer->sanitize($extracted['title'], $extracted['content']);
        if (! $sanitized['ok']) {
            $code = ($sanitized['secrets_found'] ?? false)
                ? 'secret_detected'
                : (string) ($sanitized['reason'] ?? 'sanitize_failed');

            return [
                'ok' => false,
                'code' => $code,
                'message' => ($sanitized['secrets_found'] ?? false)
                    ? 'Phát hiện nội dung giống secret — không lưu/index.'
                    : 'Nội dung không hợp lệ.',
            ];
        }

        $hash = $this->sanitizer->contentHash($sanitized['content']);
        $dup = $this->repository->findDuplicate($context->siteId, $hash, $scopeType, $scopeRef);
        if ($dup !== null) {
            return [
                'ok' => false,
                'code' => 'duplicate_content',
                'message' => 'Nội dung trùng knowledge hiện có.',
                'knowledge_ref' => $dup->hash_id,
            ];
        }

        $chunks = $this->chunker->chunk($sanitized['content'], $sanitized['title']);
        if (count($chunks) > $this->maxChunks) {
            return ['ok' => false, 'code' => 'chunk_limit', 'message' => 'Vượt giới hạn chunk.'];
        }

        $trust = (string) ($input['trust_level'] ?? match ($sourceType) {
            'execution_result', 'system_reference' => 'source_verified',
            'manual', 'conversation' => 'user_confirmed',
            default => 'unverified',
        });
        $status = (string) ($input['status'] ?? 'active');
        if (! in_array($status, ['draft', 'active'], true)) {
            $status = 'active';
        }

        try {
            $item = DB::connection('omi_seo_ai')->transaction(function () use (
                $context, $scopeType, $scopeRef, $type, $sanitized, $sourceType, $input, $extracted, $hash, $trust, $status, $chunks
            ) {
                $item = $this->repository->create([
                    'hash_id' => 'aknow_'.Str::lower((string) Str::ulid()),
                    'connection_hash' => null,
                    'tenant_id' => $context->tenantId,
                    'site_id' => $context->siteId,
                    'scope_type' => $scopeType,
                    'scope_ref' => $scopeRef,
                    'owner_user_id' => $scopeType === 'user_preference' ? $context->actorUserId : null,
                    'type' => $type,
                    'title' => $sanitized['title'],
                    'content' => $sanitized['content'],
                    'summary' => mb_substr($sanitized['content'], 0, 280),
                    'source_type' => $sourceType,
                    'source_ref' => isset($input['source_ref']) ? (string) $input['source_ref'] : null,
                    'source_version' => isset($input['source_version']) ? (string) $input['source_version'] : null,
                    'source_metadata' => $extracted['metadata'],
                    'trust_level' => $trust,
                    'status' => $status,
                    'priority' => (int) ($input['priority'] ?? 50),
                    'content_hash' => $hash,
                    'version' => 1,
                    'index_status' => 'pending',
                    'created_by' => $context->actorUserId,
                    'approved_by' => $status === 'active' ? $context->actorUserId : null,
                    'approved_at' => $status === 'active' ? Carbon::now() : null,
                    'last_verified_at' => $status === 'active' ? Carbon::now() : null,
                ]);

                try {
                    $this->index->indexItem($item, $chunks);
                } catch (Throwable $e) {
                    $item->index_status = 'error';
                    $item->index_error = mb_substr($e->getMessage(), 0, 500);
                    if ($status === 'active') {
                        // Policy: do not claim searchable — keep draft-like index error but record exists.
                        $item->status = 'draft';
                    }
                    $this->repository->save($item);
                    throw $e;
                }

                return $item;
            });
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'code' => 'ingest_failed',
                'message' => 'Ingest thất bại.',
                'index_error' => true,
            ];
        }

        return [
            'ok' => true,
            'code' => 'ingested',
            'message' => 'Đã lưu knowledge.',
            'knowledge_ref' => $item->hash_id,
            'index_status' => $item->index_status,
            'searchable' => $item->index_status === 'indexed' && $item->status === 'active',
            'item' => $this->repository->toData($item)->toArray(),
        ];
    }

    public function list(AgentWorkspaceContext $context, array $filters = []): array
    {
        $this->assertSite($context);
        $rows = $this->repository->listForContext($context, $filters);

        return array_map(
            fn ($item): array => $this->repository->toData($item)->toArray(),
            $rows,
        );
    }

    public function get(AgentWorkspaceContext $context, string $hashId): ?AgentKnowledgeItemData
    {
        $this->assertSite($context);
        $item = $this->repository->findByHash($hashId, $context);

        return $item ? $this->repository->toData($item) : null;
    }

    public function correct(AgentWorkspaceContext $context, string $hashId, array $edits): array
    {
        $this->assertSite($context);
        $old = $this->repository->findByHash($hashId, $context);
        if ($old === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }

        $title = (string) ($edits['title'] ?? $old->title);
        $content = (string) ($edits['content'] ?? $old->content);
        $sanitized = $this->sanitizer->sanitize($title, $content);
        if (! $sanitized['ok']) {
            return ['ok' => false, 'code' => $sanitized['reason'] ?? 'sanitize_failed'];
        }

        $result = $this->ingest($context, [
            'source_type' => 'manual',
            'type' => (string) ($edits['type'] ?? $old->type),
            'scope_type' => (string) $old->scope_type,
            'scope_ref' => $old->scope_ref,
            'title' => $sanitized['title'],
            'content' => $sanitized['content'],
            'trust_level' => 'user_confirmed',
            'status' => 'active',
            'priority' => (int) $old->priority,
        ]);

        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        $old->status = 'superseded';
        $this->index->removeItem($old);
        $this->repository->save($old);

        $new = $this->repository->findByHash((string) $result['knowledge_ref'], $context);
        if ($new !== null) {
            $new->version = (int) $old->version + 1;
            $new->supersedes_id = $old->id;
            $this->repository->save($new);
            $result['item'] = $this->repository->toData($new)->toArray();
            $result['superseded_ref'] = $old->hash_id;
        }

        return $result;
    }

    public function verify(AgentWorkspaceContext $context, string $hashId): array
    {
        $item = $this->repository->findByHash($hashId, $context);
        if ($item === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $this->freshness->markVerified($item);

        return ['ok' => true, 'code' => 'verified', 'knowledge_ref' => $item->hash_id];
    }

    public function disable(AgentWorkspaceContext $context, string $hashId, ?string $reason = null): array
    {
        $item = $this->repository->findByHash($hashId, $context);
        if ($item === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $item->status = 'disabled';
        $item->disabled_by = $context->actorUserId;
        $item->disabled_at = Carbon::now();
        $meta = is_array($item->source_metadata) ? $item->source_metadata : [];
        $meta['disable_reason'] = $reason;
        $item->source_metadata = $meta;
        $this->index->removeItem($item);
        $this->repository->save($item);

        return ['ok' => true, 'code' => 'disabled', 'knowledge_ref' => $item->hash_id, 'business_deleted' => false];
    }

    public function forget(AgentWorkspaceContext $context, string $hashId): array
    {
        $item = $this->repository->findByHash($hashId, $context);
        if ($item === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $this->index->removeItem($item);
        $item->status = 'disabled';
        $item->disabled_by = $context->actorUserId;
        $item->disabled_at = Carbon::now();
        $this->repository->save($item);
        $item->delete(); // soft delete

        return ['ok' => true, 'code' => 'forgotten', 'knowledge_ref' => $hashId, 'business_deleted' => false];
    }

    public function search(AgentWorkspaceContext $context, AgentKnowledgeQuery $query): array
    {
        $this->assertSite($context);
        $package = $this->retriever->retrieve($query);

        return [
            'ok' => true,
            'package' => $package->toArray(),
        ];
    }

    public function createProposal(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        array $candidate,
    ): AgentMemoryProposal {
        $this->assertSite($context);

        return $this->proposals->create($context, $conversation, $candidate);
    }

    public function resolveProposal(
        AgentWorkspaceContext $context,
        string $proposalId,
        string $action,
        array $edits = [],
    ): array {
        $this->assertSite($context);
        $proposal = $this->proposals->find($proposalId, $context);
        if ($proposal === null || $proposal->status !== 'pending') {
            return ['ok' => false, 'code' => 'invalid_or_expired_proposal'];
        }

        if ($action === 'reject' || $action === 'keep_conversation_only') {
            $this->proposals->markResolved($proposal, $action === 'reject' ? 'rejected' : 'kept_conversation', $context->actorUserId);

            return ['ok' => true, 'code' => $action, 'persisted' => false];
        }

        if ($action === 'edit' || $action === 'save') {
            $payload = $this->proposals->applyEdits($proposal, $edits);
            foreach ($payload as $k => $v) {
                $proposal->{$k} = $v;
            }
            // Re-resolve scope from context — ignore browser site override.
            $proposal->proposed_scope_ref = $this->canonicalScopeRef(
                $context,
                (string) $proposal->proposed_scope_type,
                $proposal->proposed_scope_ref,
            );
            $proposal->save();

            if ($action === 'edit') {
                return [
                    'ok' => true,
                    'code' => 'edited',
                    'proposal' => $this->proposals->toDto($proposal)->toArray(),
                    'persisted' => false,
                ];
            }

            $ingest = $this->ingest($context, [
                'source_type' => 'conversation',
                'type' => (string) $proposal->proposed_type,
                'scope_type' => (string) $proposal->proposed_scope_type,
                'scope_ref' => $proposal->proposed_scope_ref,
                'title' => (string) $proposal->title,
                'content' => (string) $proposal->content,
                'trust_level' => 'user_confirmed',
                'status' => 'active',
            ]);

            if (! ($ingest['ok'] ?? false)) {
                return $ingest;
            }

            $knowledgeId = null;
            $ref = (string) ($ingest['knowledge_ref'] ?? '');
            $item = $ref !== '' ? $this->repository->findByHash($ref, $context) : null;
            $knowledgeId = $item?->id;
            $this->proposals->markResolved($proposal, 'approved', $context->actorUserId, $knowledgeId);

            return [
                'ok' => true,
                'code' => 'approved',
                'knowledge_ref' => $ref,
                'persisted' => true,
            ];
        }

        return ['ok' => false, 'code' => 'unknown_action'];
    }

    private function assertSite(AgentWorkspaceContext $context): void
    {
        if ($context->siteId <= 0 || $context->siteRef === '') {
            throw new RuntimeException('missing_site');
        }
    }

    private function canonicalScopeRef(AgentWorkspaceContext $context, string $scopeType, mixed $requested): ?string
    {
        return match ($scopeType) {
            'project' => $context->projectRef,
            'workspace' => $context->workspaceRef,
            'user_preference' => (string) $context->actorUserId,
            'conversation' => is_string($requested) ? $requested : null,
            default => null,
        };
    }
}
