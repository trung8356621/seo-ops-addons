<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering;

use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentErrorCategory;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;

final class AgentErrorRenderer implements AgentResultRenderer
{
    public function supports(AgentExecutionResult $result): bool
    {
        return ! $result->ok;
    }

    public function render(AgentExecutionResult $result): array
    {
        $category = $result->errorCategory ?? AgentErrorCategory::InternalError;
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
            'title' => 'Lỗi thực thi',
            'summary' => $result->message,
            'metrics' => [
                'category' => $category->value,
                'code' => $result->code,
                'retryable' => $category->retryable(),
            ],
            'badges' => [$category->value, $result->status->value],
            'warnings' => $result->warnings,
            'links' => $result->operationReference ? [[
                'label' => 'Operation',
                'ref' => $result->operationReference,
                'type' => 'operation',
            ]] : [],
            'suggested_skills' => $suggested,
            'operation_reference' => $result->operationReference,
            'details' => [
                'retryable' => $category->retryable(),
                'edit_input' => $category === AgentErrorCategory::ValidationError,
                'open_settings' => in_array($category, [
                    AgentErrorCategory::NotConfigured,
                    AgentErrorCategory::PermissionDenied,
                ], true),
                'execution_ref' => $result->executionRef,
            ],
        ];
    }
}
