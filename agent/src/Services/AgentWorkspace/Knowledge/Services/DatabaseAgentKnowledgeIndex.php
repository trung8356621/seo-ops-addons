<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentKnowledgeChunk;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentKnowledgeItem;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeIndex;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeSearchResult;
use Carbon\Carbon;
use Throwable;

/**
 * Default DB keyword/FULLTEXT index — no vector dependency.
 */
final class DatabaseAgentKnowledgeIndex implements AgentKnowledgeIndex
{
    public function adapterName(): string
    {
        return 'database_keyword';
    }

    public function indexItem(SeoAgentKnowledgeItem $item, array $chunks): void
    {
        SeoAgentKnowledgeChunk::query()->where('knowledge_item_id', $item->id)->delete();

        foreach ($chunks as $chunk) {
            SeoAgentKnowledgeChunk::query()->create([
                'knowledge_item_id' => $item->id,
                'chunk_index' => $chunk->chunkIndex,
                'heading' => $chunk->heading,
                'content' => $chunk->content,
                'token_estimate' => $chunk->tokenEstimate,
                'content_hash' => $chunk->contentHash,
                'metadata' => $chunk->metadata,
            ]);
        }

        $item->index_status = 'indexed';
        $item->index_error = null;
        $item->save();
    }

    public function removeItem(SeoAgentKnowledgeItem $item): void
    {
        SeoAgentKnowledgeChunk::query()->where('knowledge_item_id', $item->id)->delete();
        $item->index_status = 'removed';
        $item->save();
    }

    public function search(AgentKnowledgeQuery $query): AgentKnowledgeSearchResult
    {
        $started = microtime(true);
        try {
            $q = SeoAgentKnowledgeItem::query()
                ->where('tenant_id', $query->tenantId)
                ->where('site_id', $query->siteId)
                ->where('status', 'active')
                ->where('index_status', 'indexed')
                ->whereIn('scope_type', $query->scopeTypes);

            if ($query->connectionHash !== null && $query->connectionHash !== '') {
                $q->where(function ($inner) use ($query): void {
                    $inner->whereNull('connection_hash')
                        ->orWhere('connection_hash', $query->connectionHash);
                });
            }

            $q->where(function ($inner) use ($query): void {
                $inner->where(function ($s) use ($query): void {
                    $s->where('scope_type', 'site');
                });
                if ($query->projectRef) {
                    $inner->orWhere(function ($s) use ($query): void {
                        $s->where('scope_type', 'project')->where('scope_ref', $query->projectRef);
                    });
                }
                if ($query->workspaceRef) {
                    $inner->orWhere(function ($s) use ($query): void {
                        $s->where('scope_type', 'workspace')->where('scope_ref', $query->workspaceRef);
                    });
                }
                if ($query->conversationRef) {
                    $inner->orWhere(function ($s) use ($query): void {
                        $s->where('scope_type', 'conversation')->where('scope_ref', $query->conversationRef);
                    });
                }
                if ($query->ownerUserId) {
                    $inner->orWhere(function ($s) use ($query): void {
                        $s->where('scope_type', 'user_preference')
                            ->where('owner_user_id', $query->ownerUserId);
                    });
                }
            });

            if ($query->types !== []) {
                $q->whereIn('type', $query->types);
            }
            if ($query->minTrustLevels !== []) {
                $q->whereIn('trust_level', $query->minTrustLevels);
            }

            $now = Carbon::now();
            $q->where(function ($inner) use ($now): void {
                $inner->whereNull('valid_until')->orWhere('valid_until', '>', $now);
            });
            $q->where(function ($inner) use ($now): void {
                $inner->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            });

            $terms = $this->tokenize($query->message);
            if ($terms !== []) {
                $q->where(function ($inner) use ($terms): void {
                    foreach ($terms as $term) {
                        $like = '%'.$term.'%';
                        $inner->orWhere('title', 'like', $like)
                            ->orWhere('content', 'like', $like)
                            ->orWhere('summary', 'like', $like);
                    }
                });
            }

            $rows = $q->orderByDesc('priority')->limit(max(50, $query->maxResults * 4))->get();
        } catch (Throwable $e) {
            return new AgentKnowledgeSearchResult(
                items: [],
                omittedCount: 0,
                diagnostics: [
                    'adapter' => $this->adapterName(),
                    'error' => 'search_failed',
                    'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                ],
            );
        }

        $scored = [];
        foreach ($rows as $item) {
            if (! $this->scopeAllowed($item, $query)) {
                continue;
            }
            $score = $this->score($item, $query, $terms ?? []);
            $scored[] = [
                'score' => $score,
                'item' => $item,
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $selected = array_slice($scored, 0, $query->maxResults);
        $omitted = max(0, count($scored) - count($selected));

        $items = [];
        foreach ($selected as $row) {
            /** @var SeoAgentKnowledgeItem $item */
            $item = $row['item'];
            $items[] = [
                'hash_id' => (string) $item->hash_id,
                'title' => (string) $item->title,
                'type' => (string) $item->type,
                'scope_type' => (string) $item->scope_type,
                'scope_ref' => $item->scope_ref,
                'trust_level' => (string) $item->trust_level,
                'priority' => (int) $item->priority,
                'version' => (int) $item->version,
                'source_type' => (string) $item->source_type,
                'content' => (string) $item->content,
                'summary' => $item->summary,
                'score' => $row['score'],
                'last_verified_at' => $item->last_verified_at?->toIso8601String(),
                'valid_until' => $item->valid_until?->toIso8601String(),
            ];
        }

        return new AgentKnowledgeSearchResult(
            items: $items,
            omittedCount: $omitted,
            diagnostics: [
                'adapter' => $this->adapterName(),
                'candidates' => count($scored),
                'selected' => count($items),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'terms' => $terms ?? [],
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $message): array
    {
        $parts = preg_split('/[\s,.;:!?]+/u', mb_strtolower(trim($message))) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3) {
                continue;
            }
            $out[] = $p;
        }

        return array_values(array_unique(array_slice($out, 0, 12)));
    }

    private function scopeAllowed(SeoAgentKnowledgeItem $item, AgentKnowledgeQuery $query): bool
    {
        // Fail closed: never return other site (already filtered). Extra scope checks:
        return match ((string) $item->scope_type) {
            'site' => true,
            'project' => $query->projectRef !== null && $item->scope_ref === $query->projectRef,
            'workspace' => $query->workspaceRef !== null && $item->scope_ref === $query->workspaceRef,
            'conversation' => $query->conversationRef !== null && $item->scope_ref === $query->conversationRef,
            'user_preference' => $query->ownerUserId !== null && (int) $item->owner_user_id === $query->ownerUserId,
            default => false,
        };
    }

    /**
     * @param  list<string>  $terms
     */
    private function score(SeoAgentKnowledgeItem $item, AgentKnowledgeQuery $query, array $terms): float
    {
        $scopeWeight = match ((string) $item->scope_type) {
            'conversation' => 50.0,
            'workspace' => 40.0,
            'project' => 30.0,
            'site' => 20.0,
            'user_preference' => 15.0,
            default => 0.0,
        };
        $trustWeight = match ((string) $item->trust_level) {
            'system_verified' => 25.0,
            'user_confirmed' => 20.0,
            'source_verified' => 15.0,
            default => 5.0,
        };
        $text = mb_strtolower(($item->title ?? '').' '.($item->content ?? '').' '.($item->summary ?? ''));
        $rel = 0.0;
        foreach ($terms as $term) {
            if (str_contains($text, $term)) {
                $rel += 8.0;
            }
        }
        if ($query->types !== [] && in_array((string) $item->type, $query->types, true)) {
            $rel += 10.0;
        }
        $fresh = 5.0;
        if ($item->last_verified_at !== null) {
            $days = max(0, $item->last_verified_at->diffInDays(Carbon::now()));
            $fresh = max(0.0, 10.0 - ($days / 30));
        }

        return $scopeWeight + $trustWeight + $rel + $fresh + ((int) $item->priority / 10);
    }
}
