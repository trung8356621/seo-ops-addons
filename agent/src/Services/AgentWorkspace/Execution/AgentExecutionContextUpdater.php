<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;

/**
 * Allowlisted conversation context updates after successful execution.
 */
final class AgentExecutionContextUpdater
{
    /** @var list<string> */
    public const ALLOWED_KEYS = [
        'project_ref',
        'workspace_ref',
        'article_ref',
        'selected_item_refs',
        'keyword_workspace_ref',
        'serp_workspace_ref',
        'last_execution_ref',
    ];

    /**
     * @return array<string, mixed> updated context_summary fragment
     */
    public function apply(SeoAgentConversation $conversation, AgentExecutionResult $result): array
    {
        $summary = is_array($conversation->context_summary) ? $conversation->context_summary : [];
        $patch = [
            'last_execution_ref' => $result->executionRef,
        ];

        $data = $result->data;
        foreach (['project_ref', 'workspace_ref', 'article_ref', 'keyword_workspace_ref', 'serp_workspace_ref'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                $patch[$key] = trim($data[$key]);
            }
        }

        if (isset($data['selected_item_refs']) && is_array($data['selected_item_refs'])) {
            $refs = [];
            foreach ($data['selected_item_refs'] as $ref) {
                if (is_string($ref) && trim($ref) !== '') {
                    $refs[] = trim($ref);
                }
            }
            if ($refs !== []) {
                $patch['selected_item_refs'] = array_values(array_unique($refs));
            }
        }

        // Never change site binding from execution result.
        unset($patch['site_ref'], $patch['site_id'], $patch['tenant_ref'], $patch['connection_hash']);

        $filtered = [];
        foreach ($patch as $key => $value) {
            if (in_array($key, self::ALLOWED_KEYS, true)) {
                $filtered[$key] = $value;
            }
        }

        $conversation->context_summary = array_merge($summary, $filtered);
        $conversation->save();

        return $filtered;
    }
}
