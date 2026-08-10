<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeCitation;

final class AgentKnowledgeCitationPresenter
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<AgentKnowledgeCitation>
     */
    public function present(array $items): array
    {
        $out = [];
        $i = 1;
        foreach ($items as $item) {
            $ref = (string) ($item['hash_id'] ?? '');
            if ($ref === '') {
                continue;
            }
            $content = (string) ($item['summary'] ?? $item['content'] ?? '');
            $out[] = new AgentKnowledgeCitation(
                handle: 'K'.$i,
                knowledgeRef: $ref,
                title: (string) ($item['title'] ?? $ref),
                version: (int) ($item['version'] ?? 1),
                sourceType: (string) ($item['source_type'] ?? 'manual'),
                scopeType: (string) ($item['scope_type'] ?? 'site'),
                trustLevel: (string) ($item['trust_level'] ?? 'unverified'),
                excerpt: mb_substr(strip_tags($content), 0, 240),
                lastVerifiedAt: isset($item['last_verified_at']) ? (string) $item['last_verified_at'] : null,
            );
            $i++;
        }

        return $out;
    }

    /**
     * @param  list<AgentKnowledgeCitation>  $citations
     * @param  list<string>  $modelHandles
     * @return array{valid: list<string>, rejected: list<string>}
     */
    public function validateHandles(array $citations, array $modelHandles): array
    {
        $allowed = [];
        foreach ($citations as $c) {
            $allowed[$c->handle] = true;
        }
        $valid = [];
        $rejected = [];
        foreach ($modelHandles as $handle) {
            $h = strtoupper(trim($handle));
            if (! preg_match('/^K\d+$/', $h)) {
                $rejected[] = $handle;
                continue;
            }
            if (! isset($allowed[$h])) {
                $rejected[] = $handle;
                continue;
            }
            $valid[] = $h;
        }

        return ['valid' => $valid, 'rejected' => $rejected];
    }
}
