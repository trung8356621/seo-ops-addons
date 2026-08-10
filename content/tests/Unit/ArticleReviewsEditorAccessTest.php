<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArticleReviewsEditorAccessTest extends TestCase
{
    /**
     * Mirrors EditArticle::getEditorSettingsPayload() review flags.
     *
     * @return array{show_reviews_tab: bool, can_quick_create_reviews: bool, show_configure_reviews_link: bool}
     */
    private function resolveReviewEditorFlags(bool $isContentManager, bool $workflowConfigured): array
    {
        return [
            'show_reviews_tab' => true,
            'can_quick_create_reviews' => ! $isContentManager && $workflowConfigured,
            'show_configure_reviews_link' => ! $isContentManager && ! $workflowConfigured,
        ];
    }

    public function test_content_manager_cannot_quick_create_reviews(): void
    {
        $flags = $this->resolveReviewEditorFlags(isContentManager: true, workflowConfigured: true);

        $this->assertTrue($flags['show_reviews_tab']);
        $this->assertFalse($flags['can_quick_create_reviews']);
        $this->assertFalse($flags['show_configure_reviews_link']);
    }

    public function test_non_content_manager_can_quick_create_when_workflow_configured(): void
    {
        $flags = $this->resolveReviewEditorFlags(isContentManager: false, workflowConfigured: true);

        $this->assertTrue($flags['show_reviews_tab']);
        $this->assertTrue($flags['can_quick_create_reviews']);
        $this->assertFalse($flags['show_configure_reviews_link']);
    }

    public function test_non_content_manager_sees_configure_link_when_workflow_missing(): void
    {
        $flags = $this->resolveReviewEditorFlags(isContentManager: false, workflowConfigured: false);

        $this->assertTrue($flags['show_reviews_tab']);
        $this->assertFalse($flags['can_quick_create_reviews']);
        $this->assertTrue($flags['show_configure_reviews_link']);
    }
}
