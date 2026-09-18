<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\System;

use App\System\Capability\SystemCapabilityHandler;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use RuntimeException;

/**
 * System AI capability for Content Project writing (canonical hook key).
 * Delegates to existing PromptHook executor — preserves routing / PromptResult / omi_seo_ai.
 */
final class ArticleContentGenerateCapabilityHandler implements SystemCapabilityHandler
{
    public const KEY = 'article.content.generate';

    public function __construct(
        private readonly PromptHookBindingRunner $hookExecutor,
    ) {}

    public function handle(array $input, array $context = []): array
    {
        if (! (bool) ($context['allow_domain_side_effects'] ?? true)) {
            return [
                'status' => 'shadow_skipped',
                'reason' => 'no_domain_write',
                'capability' => self::KEY,
            ];
        }

        $promptId = (int) ($input['prompt_id'] ?? 0);
        if ($promptId <= 0) {
            throw new RuntimeException('article.content.generate requires prompt_id.');
        }

        /** @var SeoPrompt|null $prompt */
        $prompt = SeoPrompt::query()->find($promptId);
        if (! $prompt instanceof SeoPrompt) {
            throw new RuntimeException("SeoPrompt [{$promptId}] not found on omi_seo_ai.");
        }

        $variables = is_array($input['variables'] ?? null) ? $input['variables'] : [];
        $contextExtras = is_array($input['context_extras'] ?? null) ? $input['context_extras'] : [];
        $previousOutputs = is_array($input['previous_outputs'] ?? null) ? $input['previous_outputs'] : [];

        // Propagate System AI correlation into hook context_extras (domain adapter mapping).
        $correlation = is_array($context['correlation'] ?? null) ? $context['correlation'] : [];
        foreach ([
            'article_id',
            'project_item_id',
            'content_project_id',
            'run_id',
            'node_id',
            'canonical_prompt_key',
            'retry_attempt',
            'correlation_id',
            'stage',
        ] as $key) {
            if (! array_key_exists($key, $correlation) || $correlation[$key] === null || $correlation[$key] === '') {
                continue;
            }
            if (! array_key_exists($key, $contextExtras) || $contextExtras[$key] === null || $contextExtras[$key] === '') {
                $contextExtras[$key] = $correlation[$key];
            }
        }

        // PromptExecutionPersistence reads project_item_id; CP runtime uses project_task_id.
        if (! isset($contextExtras['project_item_id']) && isset($contextExtras['project_task_id'])) {
            $contextExtras['project_item_id'] = (int) $contextExtras['project_task_id'];
        }
        if (! isset($variables['project_item_id']) && isset($contextExtras['project_item_id'])) {
            $variables['project_item_id'] = (int) $contextExtras['project_item_id'];
        }
        if (! isset($variables['project_id']) && isset($contextExtras['content_project_id'])) {
            $variables['project_id'] = (int) $contextExtras['content_project_id'];
        }
        if (! isset($contextExtras['project_id']) && isset($contextExtras['content_project_id'])) {
            $contextExtras['project_id'] = (int) $contextExtras['content_project_id'];
        }

        $contextExtras['via_system_ai'] = true;
        $contextExtras['system_ai_capability'] = self::KEY;
        $contextExtras['stage'] = (string) ($contextExtras['stage'] ?? 'writing');

        try {
            $result = $this->hookExecutor->execute(
                $prompt,
                $variables,
                $contextExtras,
                $previousOutputs,
            );
        } catch (PromptRunException $e) {
            throw $e;
        }

        return is_array($result) ? $result : ['output' => $result];
    }
}
