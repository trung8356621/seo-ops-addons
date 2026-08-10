<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentKnowledgeItem;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeRepository;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeItemData;
use Throwable;

final class EloquentAgentKnowledgeRepository implements AgentKnowledgeRepository
{
    public function create(array $attrs): SeoAgentKnowledgeItem
    {
        return SeoAgentKnowledgeItem::query()->create($attrs);
    }

    public function findByHash(string $hashId, AgentWorkspaceContext $context): ?SeoAgentKnowledgeItem
    {
        try {
            return SeoAgentKnowledgeItem::query()
                ->where('hash_id', $hashId)
                ->where('tenant_id', $context->tenantId)
                ->where('site_id', $context->siteId)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    public function findDuplicate(int $siteId, string $contentHash, string $scopeType, ?string $scopeRef): ?SeoAgentKnowledgeItem
    {
        try {
            $q = SeoAgentKnowledgeItem::query()
                ->where('site_id', $siteId)
                ->where('content_hash', $contentHash)
                ->where('scope_type', $scopeType)
                ->whereIn('status', ['active', 'draft']);
            if ($scopeRef === null) {
                $q->whereNull('scope_ref');
            } else {
                $q->where('scope_ref', $scopeRef);
            }

            return $q->first();
        } catch (Throwable) {
            return null;
        }
    }

    public function listForContext(AgentWorkspaceContext $context, array $filters = []): array
    {
        try {
            $q = SeoAgentKnowledgeItem::query()
                ->where('tenant_id', $context->tenantId)
                ->where('site_id', $context->siteId);

            if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
                $q->where('status', $filters['status']);
            }
            if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
                $q->where('type', $filters['type']);
            }
            if (isset($filters['scope_type']) && is_string($filters['scope_type']) && $filters['scope_type'] !== '') {
                $q->where('scope_type', $filters['scope_type']);
            }
            if (isset($filters['trust_level']) && is_string($filters['trust_level']) && $filters['trust_level'] !== '') {
                $q->where('trust_level', $filters['trust_level']);
            }
            if (isset($filters['source_type']) && is_string($filters['source_type']) && $filters['source_type'] !== '') {
                $q->where('source_type', $filters['source_type']);
            }

            return $q->orderByDesc('updated_at')->limit(100)->get()->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function toData(SeoAgentKnowledgeItem $item): AgentKnowledgeItemData
    {
        return new AgentKnowledgeItemData(
            hashId: (string) $item->hash_id,
            scopeType: (string) $item->scope_type,
            scopeRef: $item->scope_ref !== null ? (string) $item->scope_ref : null,
            type: (string) $item->type,
            title: (string) $item->title,
            content: (string) $item->content,
            summary: $item->summary !== null ? (string) $item->summary : null,
            sourceType: (string) $item->source_type,
            sourceRef: $item->source_ref !== null ? (string) $item->source_ref : null,
            trustLevel: (string) $item->trust_level,
            status: (string) $item->status,
            priority: (int) $item->priority,
            version: (int) $item->version,
            contentHash: (string) $item->content_hash,
            indexStatus: $item->index_status !== null ? (string) $item->index_status : null,
            sourceMetadata: is_array($item->source_metadata) ? $item->source_metadata : [],
            validUntil: $item->valid_until?->toIso8601String(),
            lastVerifiedAt: $item->last_verified_at?->toIso8601String(),
            supersedesId: $item->supersedes_id !== null ? (int) $item->supersedes_id : null,
        );
    }

    public function save(SeoAgentKnowledgeItem $item): void
    {
        $item->save();
    }
}
