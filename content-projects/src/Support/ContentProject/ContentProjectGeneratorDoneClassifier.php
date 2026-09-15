<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;

/**
 * Strict classifier: item is generator_done only when generation completed with valid content.
 *
 * Project Planner / SEO Audit / audit-ready / title+keyword-only are NOT generator_done.
 */
final class ContentProjectGeneratorDoneClassifier
{
    public function isGeneratorDone(ContentProjectItemState $state, bool $hasGeneratedContent): bool
    {
        if (! $hasGeneratedContent) {
            return false;
        }

        if ($state->archiveState !== ContentProjectItemArchiveState::None) {
            return false;
        }

        if (in_array($state->lifecycleState, [
            ContentProjectLifecyclePhase::Draft,
            ContentProjectLifecyclePhase::Generating,
            ContentProjectLifecyclePhase::Failed,
            ContentProjectLifecyclePhase::Archived,
        ], true)) {
            return false;
        }

        if (in_array($state->generationState, [
            ContentProjectItemGenerationState::Pending,
            ContentProjectItemGenerationState::Writing,
            ContentProjectItemGenerationState::Processing,
            ContentProjectItemGenerationState::Failed,
            ContentProjectItemGenerationState::Cancelled,
            ContentProjectItemGenerationState::Idle,
        ], true)) {
            // Published revision with body while a later rerun is mid-flight is still
            // "generated content exists", but not safely movable as terminal generator_done.
            if (! $state->hasPublishedRevision) {
                return false;
            }
        }

        return match ($state->lifecycleState) {
            ContentProjectLifecyclePhase::Review,
            ContentProjectLifecyclePhase::Approved,
            ContentProjectLifecyclePhase::WaitingPublish,
            ContentProjectLifecyclePhase::Published => true,
            default => $state->generationState === ContentProjectItemGenerationState::Completed,
        };
    }

    public function articleHasGeneratedContent(?SeoArticle $article): bool
    {
        if (! $article instanceof SeoArticle) {
            return false;
        }

        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return true;
        }

        if ($article->offsetExists('content')) {
            return trim((string) ($article->getAttribute('content') ?? '')) !== '';
        }

        return false;
    }
}
