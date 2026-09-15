<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemErrorSource;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemExecutionState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemReviewState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGeneratorDoneClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemState;
use PHPUnit\Framework\TestCase;

final class ContentProjectCompactSuccessEligibilityTest extends TestCase
{
    private ContentProjectGeneratorDoneClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ContentProjectGeneratorDoneClassifier;
    }

    public function test_seo_audit_planner_title_keyword_only_is_not_generator_done(): void
    {
        self::assertFalse($this->classifier->isGeneratorDone($this->state(
            lifecycle: ContentProjectLifecyclePhase::Draft,
            generation: ContentProjectItemGenerationState::Pending,
        ), false));
    }

    public function test_false_success_completed_without_body_is_not_generator_done(): void
    {
        self::assertFalse($this->classifier->isGeneratorDone($this->state(
            lifecycle: ContentProjectLifecyclePhase::Review,
            generation: ContentProjectItemGenerationState::Completed,
            review: ContentProjectItemReviewState::Draft,
        ), false));
    }

    public function test_completed_with_body_is_generator_done(): void
    {
        self::assertTrue($this->classifier->isGeneratorDone($this->state(
            lifecycle: ContentProjectLifecyclePhase::Review,
            generation: ContentProjectItemGenerationState::Completed,
            review: ContentProjectItemReviewState::Draft,
        ), true));
    }

    public function test_article_has_generated_content_false_for_null(): void
    {
        self::assertFalse($this->classifier->articleHasGeneratedContent(null));
    }

    private function state(
        ContentProjectLifecyclePhase $lifecycle,
        ContentProjectItemGenerationState $generation,
        ContentProjectItemReviewState $review = ContentProjectItemReviewState::None,
        ContentProjectItemPublishState $publish = ContentProjectItemPublishState::None,
        bool $hasPublished = false,
    ): ContentProjectItemState {
        return new ContentProjectItemState(
            lifecycleState: $lifecycle,
            generationState: $generation,
            reviewState: $review,
            publishState: $publish,
            executionState: ContentProjectItemExecutionState::Idle,
            archiveState: ContentProjectItemArchiveState::None,
            availableActions: [],
            blockingReason: null,
            currentError: null,
            currentErrorSource: ContentProjectItemErrorSource::None,
            hasPublishedRevision: $hasPublished,
        );
    }
}
