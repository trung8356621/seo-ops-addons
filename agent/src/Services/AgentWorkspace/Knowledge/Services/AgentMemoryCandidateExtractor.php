<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentMemoryCandidate;

/**
 * Deterministic memory candidate extraction — never auto-persists.
 */
final class AgentMemoryCandidateExtractor
{
    /**
     * @return list<AgentMemoryCandidate>
     */
    public function extract(string $message, AgentWorkspaceContext $context): array
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return [];
        }

        $normalized = mb_strtolower($trimmed);
        $candidates = [];

        if (preg_match('/(?:nhớ|ghi nhớ|remember|lưu lại)\s*[:\-–]?\s*(.+)$/iu', $trimmed, $m) === 1) {
            $content = trim($m[1]);
            if ($content !== '') {
                $candidates[] = new AgentMemoryCandidate(
                    type: $context->projectRef ? 'project_decision' : 'general_note',
                    title: mb_substr($content, 0, 80),
                    content: $content,
                    proposedScopeType: $context->projectRef ? 'project' : 'site',
                    proposedScopeRef: $context->projectRef,
                    reason: 'User explicitly asked to remember.',
                    confidence: 0.85,
                    warnings: ['requires_user_approval'],
                    sourceMessage: $trimmed,
                );
            }
        }

        if (str_contains($normalized, 'tone') || str_contains($normalized, 'giọng') || str_contains($normalized, 'phong cách')) {
            $candidates[] = new AgentMemoryCandidate(
                type: 'tone',
                title: 'Tone preference',
                content: $trimmed,
                proposedScopeType: 'site',
                proposedScopeRef: null,
                reason: 'Possible tone/style preference.',
                confidence: 0.6,
                warnings: ['low_confidence', 'requires_user_approval'],
                sourceMessage: $trimmed,
            );
        }

        if (str_contains($normalized, 'không dùng') || str_contains($normalized, 'cấm') || str_contains($normalized, 'prohibit')) {
            $candidates[] = new AgentMemoryCandidate(
                type: 'prohibited_term',
                title: 'Prohibited term/rule',
                content: $trimmed,
                proposedScopeType: 'site',
                proposedScopeRef: null,
                reason: 'Possible prohibition rule.',
                confidence: 0.7,
                warnings: ['requires_user_approval'],
                sourceMessage: $trimmed,
            );
        }

        return $candidates;
    }
}
