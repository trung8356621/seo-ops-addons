<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeConflictResult;

final class AgentKnowledgeConflictResolver
{
    private const SCOPE_RANK = [
        'conversation' => 5,
        'workspace' => 4,
        'project' => 3,
        'site' => 2,
        'user_preference' => 1,
    ];

    private const TRUST_RANK = [
        'system_verified' => 4,
        'user_confirmed' => 3,
        'source_verified' => 2,
        'unverified' => 1,
    ];

    private const LEGAL_TYPES = ['legal_rule', 'prohibited_term'];

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, conflicts: list<array<string, mixed>>}
     */
    public function resolve(array $items): array
    {
        $byType = [];
        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? 'general_note');
            $byType[$type][] = $item;
        }

        $kept = [];
        $conflicts = [];

        foreach ($byType as $type => $group) {
            if (count($group) === 1) {
                $kept[] = $group[0];
                continue;
            }

            usort($group, function (array $a, array $b): int {
                $sa = self::SCOPE_RANK[(string) ($a['scope_type'] ?? '')] ?? 0;
                $sb = self::SCOPE_RANK[(string) ($b['scope_type'] ?? '')] ?? 0;
                if ($sa !== $sb) {
                    return $sb <=> $sa;
                }
                $ta = self::TRUST_RANK[(string) ($a['trust_level'] ?? '')] ?? 0;
                $tb = self::TRUST_RANK[(string) ($b['trust_level'] ?? '')] ?? 0;

                return $tb <=> $ta;
            });

            $winner = $group[0];
            $runner = $group[1];
            $winnerTrustLevel = (string) ($winner['trust_level'] ?? '');
            $runnerTrustLevel = (string) ($runner['trust_level'] ?? '');
            $winnerTrust = self::TRUST_RANK[$winnerTrustLevel] ?? 0;
            $runnerTrust = self::TRUST_RANK[$runnerTrustLevel] ?? 0;
            $winnerScope = self::SCOPE_RANK[(string) ($winner['scope_type'] ?? '')] ?? 0;
            $runnerScope = self::SCOPE_RANK[(string) ($runner['scope_type'] ?? '')] ?? 0;

            // System-verified cannot be silently overridden by unverified (even more specific scope).
            if ($winnerTrustLevel === 'unverified' && $runnerTrustLevel === 'system_verified') {
                $conflicts[] = (new AgentKnowledgeConflictResult(
                    status: AgentKnowledgeConflictResult::REQUIRES_USER_REVIEW,
                    summary: 'Unverified không được silent override system_verified ('.$type.')',
                    itemRefs: array_map(static fn (array $i): string => (string) $i['hash_id'], $group),
                ))->toArray();
                $kept[] = $runner;

                continue;
            }

            if ($winnerScope > $runnerScope) {
                $kept[] = $winner;
                $conflicts[] = (new AgentKnowledgeConflictResult(
                    status: AgentKnowledgeConflictResult::SCOPE_OVERRIDE,
                    summary: 'Scope cụ thể hơn override scope rộng hơn cho type '.$type,
                    itemRefs: [(string) $winner['hash_id'], (string) $runner['hash_id']],
                ))->toArray();
                continue;
            }

            if ($winnerTrust > $runnerTrust) {
                $kept[] = $winner;
                $conflicts[] = (new AgentKnowledgeConflictResult(
                    status: AgentKnowledgeConflictResult::RESOLVED,
                    summary: 'Higher trust wins for '.$type,
                    itemRefs: [(string) $winner['hash_id'], (string) $runner['hash_id']],
                ))->toArray();
                continue;
            }

            if (in_array($type, self::LEGAL_TYPES, true)) {
                $legal = null;
                foreach ($group as $row) {
                    if (in_array((string) ($row['type'] ?? ''), self::LEGAL_TYPES, true)) {
                        $legal = $row;
                        break;
                    }
                }
                if ($legal !== null) {
                    $kept[] = $legal;
                    $conflicts[] = (new AgentKnowledgeConflictResult(
                        status: AgentKnowledgeConflictResult::RESOLVED,
                        summary: 'Legal/prohibition rule ưu tiên hơn preference',
                        itemRefs: [(string) $legal['hash_id']],
                    ))->toArray();
                    continue;
                }
            }

            $conflicts[] = (new AgentKnowledgeConflictResult(
                status: AgentKnowledgeConflictResult::REQUIRES_USER_REVIEW,
                summary: 'Mâu thuẫn cùng scope/trust — cần user review ('.$type.')',
                itemRefs: array_map(static fn (array $i): string => (string) $i['hash_id'], $group),
            ))->toArray();
            // Do not silently pick — omit both from auto facts, surface conflict.
        }

        return ['items' => $kept, 'conflicts' => $conflicts];
    }
}
