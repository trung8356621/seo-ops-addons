<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentExecution;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentMessage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Conversation persistence — presentation history only.
 * Deleting a conversation never deletes business operations/audits.
 */
final class AgentConversationService
{
    public function __construct(
        private readonly AgentWorkspaceQuotaService $quotas,
    ) {}

    public function create(AgentWorkspaceContext $context, ?string $title = null): SeoAgentConversation
    {
        $activeCount = SeoAgentConversation::query()
            ->where('tenant_id', $context->tenantId)
            ->where('site_id', $context->siteId)
            ->where('created_by', $context->actorUserId)
            ->where('status', 'active')
            ->count();

        if ($this->quotas->conversationsExceeded($activeCount)) {
            throw new RuntimeException('agent.quota.conversations_exceeded');
        }

        return SeoAgentConversation::query()->create([
            'public_ref' => 'acv_'.Str::lower((string) Str::ulid()),
            'tenant_id' => $context->tenantId,
            'site_id' => $context->siteId,
            'connection_id' => $context->connectionId,
            'title' => $title ?: 'Chat mới',
            'status' => 'active',
            'context_summary' => $context->toSummary(),
            'created_by' => $context->actorUserId,
            'last_message_at' => now(),
        ]);
    }

    public function rename(SeoAgentConversation $conversation, string $title): SeoAgentConversation
    {
        $conversation->title = trim($title) !== '' ? trim($title) : $conversation->title;
        $conversation->save();

        return $conversation;
    }

    public function pin(SeoAgentConversation $conversation, bool $pinned = true): SeoAgentConversation
    {
        $conversation->is_pinned = $pinned;
        $conversation->save();

        return $conversation;
    }

    public function archive(SeoAgentConversation $conversation): SeoAgentConversation
    {
        $conversation->status = 'archived';
        $conversation->archived_at = now();
        $conversation->save();

        return $conversation;
    }

    public function deleteEmpty(SeoAgentConversation $conversation): bool
    {
        $hasMessages = SeoAgentMessage::query()
            ->where('conversation_id', $conversation->id)
            ->exists();

        if ($hasMessages) {
            return false;
        }

        // Executions should not exist without messages in normal flow; still safe.
        SeoAgentExecution::query()->where('conversation_id', $conversation->id)->delete();
        $conversation->delete();

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    public function appendMessage(
        SeoAgentConversation $conversation,
        string $role,
        string $messageType,
        ?string $content = null,
        ?array $structured = null,
        ?string $skillKey = null,
        ?string $operationRef = null,
        ?int $createdBy = null,
    ): SeoAgentMessage {
        $count = SeoAgentMessage::query()
            ->where('conversation_id', $conversation->id)
            ->count();

        if ($this->quotas->messagesExceeded($count)) {
            throw new RuntimeException('agent.quota.messages_exceeded');
        }

        $sanitizer = app(AgentMessageOutputSanitizer::class);
        $content = $sanitizer->sanitize($content);
        $structured = $sanitizer->sanitizeStructured($structured);

        $message = SeoAgentMessage::query()->create([
            'public_ref' => 'amsg_'.Str::lower((string) Str::ulid()),
            'conversation_id' => $conversation->id,
            'role' => $role,
            'message_type' => $messageType,
            'content' => $content,
            'structured_content' => $structured,
            'skill_key' => $skillKey,
            'operation_ref' => $operationRef,
            'created_by' => $createdBy,
            'created_at' => Carbon::now(),
        ]);

        $conversation->last_message_at = $message->created_at;
        if ($skillKey) {
            $conversation->active_skill_key = $skillKey;
        }
        $conversation->save();

        return $message;
    }

    /**
     * @return list<SeoAgentConversation>
     */
    public function listForUser(AgentWorkspaceContext $context, bool $includeArchived = false): array
    {
        $query = SeoAgentConversation::query()
            ->where('tenant_id', $context->tenantId)
            ->where('site_id', $context->siteId)
            ->where('created_by', $context->actorUserId)
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_message_at');

        if (! $includeArchived) {
            $query->where('status', 'active');
        }

        return $query->limit(100)->get()->all();
    }

    public function findForContext(string $publicRef, AgentWorkspaceContext $context): ?SeoAgentConversation
    {
        $conversation = SeoAgentConversation::query()
            ->where('public_ref', $publicRef)
            ->first();

        if (! $conversation instanceof SeoAgentConversation) {
            return null;
        }

        if ((int) $conversation->tenant_id !== $context->tenantId
            || (int) $conversation->site_id !== $context->siteId
            || (int) $conversation->created_by !== $context->actorUserId) {
            return null;
        }

        return $conversation;
    }
}
