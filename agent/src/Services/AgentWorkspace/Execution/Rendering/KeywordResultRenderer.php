<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\KeywordSuggestionPresenter;

final class KeywordResultRenderer implements AgentResultRenderer
{
    public function supports(AgentExecutionResult $result): bool
    {
        return str_starts_with($result->capabilityKey, 'keyword.')
            || str_starts_with($result->capabilityKey, 'keyword_intelligence.');
    }

    public function render(AgentExecutionResult $result): array
    {
        if (! $result->ok) {
            return [
                'title' => 'Không thực hiện được',
                'summary' => trim((string) $result->message) !== '' ? $result->message : 'Không thực hiện được thao tác này.',
                'body' => trim((string) $result->message) !== '' ? $result->message : 'Không thực hiện được thao tác này.',
                'user_facing' => true,
                'hide_envelope' => true,
                'badges' => [],
                'links' => [],
                'metrics' => [],
                'warnings' => [],
                'suggested_skills' => [],
                'operation_reference' => null,
                'details' => [],
            ];
        }

        if (str_contains($result->capabilityKey, 'analyze') || isset($result->data['keywords']) || isset($result->data['suggestions'])) {
            return (new KeywordSuggestionPresenter)->present($result->data);
        }

        $summary = trim((string) $result->message);
        if ($summary === '' || strcasecmp($summary, 'Read successful.') === 0) {
            $summary = 'Hoàn tất.';
        }

        return [
            'title' => 'Keyword',
            'summary' => $summary,
            'body' => $summary,
            'user_facing' => true,
            'hide_envelope' => true,
            'badges' => [],
            'links' => [],
            'metrics' => [],
            'warnings' => $result->warnings,
            'suggested_skills' => [],
            'operation_reference' => null,
            'details' => [],
        ];
    }
}
