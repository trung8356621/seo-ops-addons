<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeIndex;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeRetriever;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentGroundedContextPackage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security\AgentUntrustedContentMarker;

final class DefaultAgentKnowledgeRetriever implements AgentKnowledgeRetriever
{
    /** @var list<string> */
    private const RULE_TYPES = [
        'tone', 'style_rule', 'content_rule', 'seo_rule', 'legal_rule',
        'cta', 'prohibited_term', 'preferred_term',
    ];

    /** @var list<string> */
    private const PREF_TYPES = ['user_preference'];

    public function __construct(
        private readonly AgentKnowledgeIndex $index,
        private readonly AgentKnowledgeConflictResolver $conflicts,
        private readonly AgentKnowledgeFreshnessService $freshness,
        private readonly AgentKnowledgeCitationPresenter $citations,
        private readonly AgentUntrustedContentMarker $untrustedMarker = new AgentUntrustedContentMarker,
    ) {}

    public function retrieve(AgentKnowledgeQuery $query): AgentGroundedContextPackage
    {
        if ($query->siteId <= 0 || $query->siteRef === '') {
            return new AgentGroundedContextPackage(
                warnings: ['missing_site_fail_closed'],
                diagnostics: ['error' => 'missing_site'],
            );
        }

        $search = $this->index->search($query);
        $usable = [];
        $warnings = [];

        foreach ($search->items as $item) {
            $fresh = $this->freshness->evaluate($item, $query->allowStaleWithWarning);
            if (! $fresh['usable']) {
                continue;
            }
            if ($fresh['stale'] && $fresh['warning']) {
                $warnings[] = 'stale:'.$item['hash_id'].':'.$fresh['warning'];
            }
            $item['content'] = $this->untrustedMarker->wrap((string) $item['content'], 'knowledge');
            $usable[] = $item;
        }

        $resolved = $this->conflicts->resolve($usable);
        $kept = $resolved['items'];
        $conflictRows = $resolved['conflicts'];

        // Token budget: drop lowest score first.
        $budget = $query->tokenBudget;
        $used = 0;
        $final = [];
        foreach ($kept as $item) {
            $est = (int) ceil(mb_strlen((string) ($item['content'] ?? '')) / 4);
            if ($used + $est > $budget && $final !== []) {
                break;
            }
            $final[] = $item;
            $used += $est;
        }
        $omitted = $search->omittedCount + max(0, count($kept) - count($final));

        $facts = [];
        $rules = [];
        $prefs = [];
        foreach ($final as $item) {
            $type = (string) ($item['type'] ?? '');
            $row = [
                'hash_id' => $item['hash_id'],
                'title' => $item['title'],
                'type' => $type,
                'scope_type' => $item['scope_type'],
                'trust_level' => $item['trust_level'],
                'version' => $item['version'],
                'excerpt' => mb_substr(strip_tags((string) ($item['summary'] ?? $item['content'] ?? '')), 0, 320),
                'handle' => null,
            ];
            if (in_array($type, self::PREF_TYPES, true) || ($item['scope_type'] ?? '') === 'user_preference') {
                $prefs[] = $row;
            } elseif (in_array($type, self::RULE_TYPES, true)) {
                $rules[] = $row;
            } else {
                $facts[] = $row;
            }
        }

        $citationList = $this->citations->present($final);
        foreach ($citationList as $i => $citation) {
            $handle = $citation->handle;
            if (isset($facts[$i])) {
                // handles assigned globally — remap below
            }
        }
        // Attach handles by hash
        $byHash = [];
        foreach ($citationList as $c) {
            $byHash[$c->knowledgeRef] = $c->handle;
        }
        foreach ([&$facts, &$rules, &$prefs] as &$group) {
            foreach ($group as &$row) {
                $row['handle'] = $byHash[$row['hash_id']] ?? null;
            }
        }
        unset($group, $row);

        foreach ($conflictRows as $c) {
            if (($c['status'] ?? '') === 'requires_user_review' || ($c['status'] ?? '') === 'unresolved') {
                $warnings[] = 'conflict:'.($c['summary'] ?? 'unresolved');
            }
        }

        return new AgentGroundedContextPackage(
            facts: $facts,
            rules: $rules,
            preferences: $prefs,
            conflicts: $conflictRows,
            warnings: array_values(array_unique($warnings)),
            citations: $citationList,
            omittedCount: $omitted,
            diagnostics: array_merge($search->diagnostics, [
                'token_used_estimate' => $used,
                'token_budget' => $budget,
                'resolved_scopes' => $query->scopeTypes,
            ]),
        );
    }
}
