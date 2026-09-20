<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Throwable;

/**
 * Projects the resolved item policy onto the prompt variables of a TaskTestContext.
 *
 * Canonical prompt variables (tone, article_length) are overwritten only when the
 * item actually decided something; `_item_*` mirrors stay for debugging and for the
 * run-item input snapshot.
 */
final class ContentProjectItemGenerationPolicyApplier
{
    public const VAR_TONE = 'tone';

    public const VAR_ARTICLE_LENGTH = 'article_length';

    public const VAR_PROTECT_TITLE = '_protect_article_title';

    public function __construct(
        private readonly ContentProjectItemGenerationPolicyResolver $resolver,
    ) {}

    public static function withPromptSettings(SeoPromptSettingsService $promptSettings): self
    {
        return new self(ContentProjectItemGenerationPolicyResolver::withPromptSettings($promptSettings));
    }

    public function apply(TaskTestContext $context, SeoProjectTask $task): TaskTestContext
    {
        $policy = $this->resolver->resolve($task);
        $variables = $this->stampVariables($context->variables, $policy);

        $this->persistResolvedTone($task, $policy);

        return $context->withVariables($variables);
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public function stampVariables(array $variables, ContentProjectItemGenerationPolicy $policy): array
    {
        if ($policy->tone !== null && $policy->tone !== '') {
            $variables[self::VAR_TONE] = $policy->tone;
            $variables['_item_tone'] = $policy->tone;
        }

        $variables['_item_tone_mode'] = $policy->toneIsAutomaticVariety ? 'automatic_variety' : 'override';

        if ($policy->contentLengthMode !== null) {
            $variables['_item_content_length_mode'] = $policy->contentLengthMode->value;
        }

        if ($policy->contentLengthTargetWords !== null) {
            $target = (string) $policy->contentLengthTargetWords;
            $variables[self::VAR_ARTICLE_LENGTH] = $target;
            $variables['_item_content_length_target_words'] = $target;
        }

        if ($policy->generationMode !== null) {
            $variables['_item_generation_mode'] = $policy->generationMode->value;
        }

        if ($policy->modelOverrideId !== null) {
            $variables['_item_model_override_id'] = (string) $policy->modelOverrideId;
            $variables['_item_model_override_mode'] = ($policy->modelOverrideMode ?? ItemModelOverrideMode::Preferred)->value;
        }

        if ($policy->protectTitle) {
            $variables[self::VAR_PROTECT_TITLE] = '1';

            $protection = $policy->effectiveTitleProtection();
            if ($protection !== null) {
                $variables['_item_title_protection'] = $protection->value;
            }

            if ($protection?->isHumanOwned() === true) {
                $variables['_item_title_is_user_defined'] = '1';
            }
        }

        return $variables;
    }

    private function persistResolvedTone(SeoProjectTask $task, ContentProjectItemGenerationPolicy $policy): void
    {
        if (! $policy->shouldPersistResolvedTone() || ! $task->exists) {
            return;
        }

        try {
            if (! Schema::connection($task->getConnectionName())->hasColumn($task->getTable(), 'resolved_tone')) {
                return;
            }

            $task->forceFill(['resolved_tone' => $policy->tone])->saveQuietly();
        } catch (Throwable) {
            // Sticky tone is an optimisation — never fail generation because of it.
        }
    }
}
