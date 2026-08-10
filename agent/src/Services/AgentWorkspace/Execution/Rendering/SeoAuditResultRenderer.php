<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\AuditListPresenter;

final class SeoAuditResultRenderer implements AgentResultRenderer
{
    public function supports(AgentExecutionResult $result): bool
    {
        return str_starts_with($result->capabilityKey, 'seo_audit.');
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

        return (new AuditListPresenter)->present($result->data);
    }
}
