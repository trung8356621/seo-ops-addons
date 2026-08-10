<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentMemoryProposal;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentMemoryProposal;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Security\AgentKnowledgeContentSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AgentMemoryProposalService
{
    /** @var list<string> */
    private const EDIT_ALLOWLIST = ['title', 'content', 'proposed_type', 'proposed_scope_type', 'proposed_scope_ref'];

    public function __construct(
        private readonly AgentKnowledgeContentSanitizer $sanitizer = new AgentKnowledgeContentSanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function create(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        array $candidate,
    ): AgentMemoryProposal {
        $title = (string) ($candidate['title'] ?? '');
        $content = (string) ($candidate['content'] ?? '');
        $sanitized = $this->sanitizer->sanitize($title, $content);
        if (! $sanitized['ok']) {
            throw new RuntimeException($sanitized['reason'] ?? 'invalid_candidate');
        }

        $scopeType = (string) ($candidate['proposed_scope_type'] ?? 'site');
        if (! in_array($scopeType, ['site', 'project', 'workspace', 'conversation', 'user_preference'], true)) {
            $scopeType = 'site';
        }
        $scopeRef = $this->resolveScopeRef($context, $conversation, $scopeType, $candidate['proposed_scope_ref'] ?? null);

        try {
            $row = SeoAgentMemoryProposal::query()->create([
                'hash_id' => 'amem_'.Str::lower((string) Str::ulid()),
                'conversation_id' => $conversation->id,
                'tenant_id' => $context->tenantId,
                'site_id' => $context->siteId,
                'connection_hash' => null,
                'proposed_type' => (string) ($candidate['type'] ?? 'general_note'),
                'title' => $sanitized['title'],
                'content' => $sanitized['content'],
                'proposed_scope_type' => $scopeType,
                'proposed_scope_ref' => $scopeRef,
                'reason' => (string) ($candidate['reason'] ?? ''),
                'confidence' => (float) ($candidate['confidence'] ?? 0.5),
                'warnings' => is_array($candidate['warnings'] ?? null) ? $candidate['warnings'] : [],
                'source_metadata' => [
                    'source_message' => isset($candidate['source_message'])
                        ? mb_substr((string) $candidate['source_message'], 0, 500)
                        : null,
                ],
                'status' => 'pending',
                'created_by' => $context->actorUserId,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('proposal_persist_failed', 0, $e);
        }

        return $this->toDto($row);
    }

    public function find(string $hashId, AgentWorkspaceContext $context): ?SeoAgentMemoryProposal
    {
        try {
            return SeoAgentMemoryProposal::query()
                ->where('hash_id', $hashId)
                ->where('tenant_id', $context->tenantId)
                ->where('site_id', $context->siteId)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    public function applyEdits(SeoAgentMemoryProposal $proposal, array $edits): array
    {
        $payload = [];
        foreach (self::EDIT_ALLOWLIST as $key) {
            if (! array_key_exists($key, $edits)) {
                continue;
            }
            $payload[$key] = $edits[$key];
        }
        if (isset($payload['title'], $payload['content'])) {
            $sanitized = $this->sanitizer->sanitize((string) $payload['title'], (string) $payload['content']);
            if (! $sanitized['ok']) {
                throw new RuntimeException($sanitized['reason'] ?? 'invalid_edit');
            }
            $payload['title'] = $sanitized['title'];
            $payload['content'] = $sanitized['content'];
        }
        // Browser cannot inject arbitrary site via scope_ref outside allowlist resolution —
        // scope_ref only accepted for project/workspace/conversation matching context later by orchestrator.
        return $payload;
    }

    public function markResolved(SeoAgentMemoryProposal $proposal, string $status, int $actorId, ?int $knowledgeItemId = null): void
    {
        $proposal->status = $status;
        $proposal->resolved_by = $actorId;
        $proposal->resolved_at = Carbon::now();
        if ($knowledgeItemId !== null) {
            $proposal->knowledge_item_id = $knowledgeItemId;
        }
        $proposal->save();
    }

    public function toDto(SeoAgentMemoryProposal $row): AgentMemoryProposal
    {
        return new AgentMemoryProposal(
            hashId: (string) $row->hash_id,
            status: (string) $row->status,
            proposedType: (string) $row->proposed_type,
            title: (string) $row->title,
            content: (string) $row->content,
            proposedScopeType: (string) $row->proposed_scope_type,
            proposedScopeRef: $row->proposed_scope_ref !== null ? (string) $row->proposed_scope_ref : null,
            reason: (string) ($row->reason ?? ''),
            confidence: (float) ($row->confidence ?? 0),
            warnings: is_array($row->warnings) ? $row->warnings : [],
            sourceMetadata: is_array($row->source_metadata) ? $row->source_metadata : [],
        );
    }

    /**
     * @param  mixed  $requested
     */
    private function resolveScopeRef(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        string $scopeType,
        mixed $requested,
    ): ?string {
        return match ($scopeType) {
            'project' => $context->projectRef,
            'workspace' => $context->workspaceRef,
            'conversation' => (string) ($conversation->public_ref ?? $conversation->id),
            'user_preference' => (string) $context->actorUserId,
            default => null, // site — ignore browser-supplied ref
        };
    }
}
