<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;

final class GenericAgentResultRenderer implements AgentResultRenderer
{
    public function supports(AgentExecutionResult $result): bool
    {
        return true;
    }

    public function render(AgentExecutionResult $result): array
    {
        $badges = [$result->status->value, $result->capabilityKey];
        if ($result->idempotentReplay) {
            $badges[] = 'idempotent_replay';
        }

        $links = [];
        if ($result->operationReference) {
            $links[] = [
                'label' => 'Operation',
                'ref' => $result->operationReference,
                'type' => 'operation',
            ];
        }
        if (isset($result->data['project_ref']) && is_string($result->data['project_ref'])) {
            $links[] = [
                'label' => 'Content Project',
                'ref' => $result->data['project_ref'],
                'type' => 'content_project',
            ];
        }

        $suggested = [];
        foreach ($result->nextActions as $action) {
            if (isset($action['skill_key']) && is_string($action['skill_key'])) {
                $suggested[] = [
                    'skill_key' => $action['skill_key'],
                    'name' => (string) ($action['reason'] ?? $action['skill_key']),
                ];
            }
        }

        return [
            'title' => $result->ok ? 'Hoàn tất' : 'Thất bại',
            'summary' => $result->message,
            'metrics' => [
                'attempt' => $result->attempt,
                'code' => $result->code,
            ],
            'badges' => $badges,
            'warnings' => $result->warnings,
            'links' => $links,
            'suggested_skills' => $suggested,
            'operation_reference' => $result->operationReference,
            'details' => [
                'skill_key' => $result->skillKey,
                'capability_key' => $result->capabilityKey,
                'data_keys' => array_keys($result->data),
            ],
        ];
    }
}
