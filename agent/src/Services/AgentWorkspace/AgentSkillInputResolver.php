<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use InvalidArgumentException;

/**
 * Resolves user-facing form fields into capability input.
 * Hides tenant_ref / site_ref / connection_hash / actor refs from the form.
 */
final class AgentSkillInputResolver
{
    /**
     * @param  array<string, mixed>  $prefill
     * @return array<string, mixed>
     */
    public function prefill(AgentSkillDefinition $skill, AgentWorkspaceContext $context, array $prefill = []): array
    {
        $out = $prefill;

        if ($context->projectRef && ! isset($out['project_ref'])) {
            $out['project_ref'] = $context->projectRef;
        }
        if ($context->workspaceRef && ! isset($out['workspace_ref'])) {
            $out['workspace_ref'] = $context->workspaceRef;
        }
        if ($context->articleRef && ! isset($out['article_ref'])) {
            $out['article_ref'] = $context->articleRef;
        }

        if ($skill->key === 'content_project.create') {
            if (! isset($out['month'])) {
                $out['month'] = now()->format('Y-m');
            }
            if (! isset($out['project_name'])) {
                $monthLabel = now()->format('m/Y');
                $out['project_name'] = 'Content tháng '.$monthLabel;
            }
            if (! isset($out['seed_mode'])) {
                $out['seed_mode'] = 'none';
            }
        }

        if ($skill->key === 'content_project.rerun' && ! isset($out['rerun_step'])) {
            $out['rerun_step'] = 'image';
        }

        foreach ($skill->formSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            if (array_key_exists('default', $field)) {
                $out[$key] = $field['default'];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $formInput
     * @return array<string, mixed>
     */
    public function resolve(AgentSkillDefinition $skill, AgentWorkspaceContext $context, array $formInput): array
    {
        $input = $this->prefill($skill, $context, $formInput);

        // Context-bound refs — never accept cross-site overrides from form for site/tenant.
        $input['site_ref'] = $context->siteRef;
        $input['tenant_ref'] = $context->tenantRef;

        if ($skill->key === 'content_project.create') {
            $attributes = [
                'name' => (string) ($input['project_name'] ?? ''),
                'description' => (string) ($input['description'] ?? ''),
                'month' => (string) ($input['month'] ?? ''),
            ];

            $assigneeRef = trim((string) ($input['assignee_ref'] ?? ''));
            if ($assigneeRef === '' || ! preg_match('/^\d+$/', $assigneeRef)) {
                throw new InvalidArgumentException(
                    'Member ID phụ trách là bắt buộc. Dùng `/member-list` rồi nhập ID số.',
                );
            }

            $userId = (int) $assigneeRef;
            $eligible = app(ContentProjectStaffAvailabilityService::class)
                ->baseAssignableStaffQuery()
                ->whereKey($userId)
                ->exists();
            if (! $eligible) {
                throw new InvalidArgumentException(
                    'Member ID '.$userId.' không hợp lệ hoặc không được phép nhận Content Project trong tenant hiện tại.',
                );
            }

            // Canonical create field: seo_projects.user_id (never silent actor fallback).
            $attributes['user_id'] = $userId;
            $attributes['assignee_ref'] = (string) $userId;

            $tasksData = [];
            if (($input['seed_mode'] ?? 'none') === 'manual' && ! empty($input['items_text'])) {
                $tasksData = $this->linesToItems((string) $input['items_text']);
            }

            return [
                'attributes' => $attributes,
                'tasksData' => $tasksData,
            ];
        }

        if ($skill->key === 'content_project.add_items') {
            return [
                'project_ref' => (string) ($input['project_ref'] ?? ''),
                'items' => $this->linesToItems((string) ($input['items_text'] ?? '')),
            ];
        }

        if ($skill->key === 'keyword.import') {
            return [
                'workspace_ref' => (string) ($input['workspace_ref'] ?? ''),
                'keywords' => $this->linesToKeywords((string) ($input['keywords_text'] ?? '')),
            ];
        }

        if ($skill->key === 'keyword.analyze') {
            $useSiteMcp = $input['use_site_mcp'] ?? $input['use-site-mcp'] ?? 'yes';
            if (is_bool($useSiteMcp)) {
                $useSiteMcpFlag = $useSiteMcp;
            } else {
                $normalized = mb_strtolower(trim((string) $useSiteMcp));
                $useSiteMcpFlag = ! in_array($normalized, ['no', '0', 'false', 'off'], true);
            }

            return [
                'site_ref' => $context->siteRef,
                'keyword' => trim((string) ($input['keyword'] ?? '')),
                'limit' => (int) ($input['limit'] ?? 10),
                'use_site_mcp' => $useSiteMcpFlag,
                // Internal workspace remains optional fallback — never required from CLI.
                'workspace_ref' => (string) ($input['workspace_ref'] ?? $context->workspaceRef ?? ''),
                'scope' => (string) ($input['scope'] ?? 'unanalyzed'),
                'strategy' => (string) ($input['strategy'] ?? 'balanced'),
                'use_ai_intent' => (bool) ($input['use_ai_intent'] ?? true),
            ];
        }

        if ($skill->key === 'keyword.build_topical_map') {
            return [
                'workspace_ref' => (string) ($input['workspace_ref'] ?? ''),
                'mode' => (string) ($input['mode'] ?? 'balanced'),
                'source' => (string) ($input['source'] ?? 'approved'),
                'keep_manual_structure' => (bool) ($input['keep_manual_structure'] ?? true),
            ];
        }

        // Strip UI-only fields.
        unset($input['items_text'], $input['keywords_text'], $input['seed_mode'], $input['project_name'], $input['raw_args']);

        return $input;
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    public function summarize(array $resolved): array
    {
        $summary = $resolved;
        unset(
            $summary['credentials'],
            $summary['api_key'],
            $summary['raw_prompt'],
            $summary['site_ref'],
            $summary['tenant_ref'],
            $summary['connection_hash'],
            $summary['actor_ref'],
            $summary['actor_user_id'],
        );

        if (isset($summary['tasksData']) && is_array($summary['tasksData'])) {
            $summary['items_count'] = count($summary['tasksData']);
            unset($summary['tasksData']);
        }
        if (isset($summary['items']) && is_array($summary['items'])) {
            $summary['items_count'] = count($summary['items']);
            unset($summary['items']);
        }
        if (isset($summary['keywords']) && is_array($summary['keywords'])) {
            $summary['keywords_count'] = count($summary['keywords']);
            unset($summary['keywords']);
        }
        if (isset($summary['attributes']) && is_array($summary['attributes'])) {
            $attrs = $summary['attributes'];
            if (isset($attrs['name'])) {
                $summary['project'] = $attrs['name'];
            }
            if (isset($attrs['month'])) {
                $summary['month'] = $attrs['month'];
            }
            $memberId = $attrs['user_id'] ?? $attrs['assignee_ref'] ?? null;
            if ($memberId !== null && $memberId !== '') {
                $summary['member_id'] = (string) $memberId;
            }
            unset($summary['attributes'], $summary['assignee_ref']);
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linesToItems(string $text): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $items[] = [
                'title' => $line,
                'focus_keyword' => $line,
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function linesToKeywords(string $text): array
    {
        $keywords = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $keywords[] = $line;
        }

        return $keywords;
    }
}
