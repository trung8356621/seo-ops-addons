<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

/**
 * Regression: Add-to-Draft drawer routes all modes through PlanningDraftIntakeService.
 */
final class AssignToContentProjectDrawerRoutingTest extends TestCase
{
    public function test_seo_audit_opens_canonical_drawer_with_capacity_flag_compat(): void
    {
        $auditBlade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/articles-optimal.blade.php'
        );
        self::assertStringContainsString('ignore_monthly_capacity: true', $auditBlade);
        self::assertStringContainsString('openAssignDrawer', $auditBlade);
        self::assertStringContainsString('AssignToContentProjectContract::OPEN_EVENT', $auditBlade);
    }

    public function test_article_submit_uses_planning_draft_intake(): void
    {
        $source = $this->drawerSource();

        self::assertStringContainsString('PlanningDraftIntakeService::class', $source);
        self::assertStringContainsString('->addArticles(', $source);
        self::assertStringContainsString('shouldAutoSubmitAfterPrepare', $source);
        self::assertStringNotContainsString('ArticleResource::assignArticlesFromFormData', $source);
        self::assertStringNotContainsString("'title' => \$this->titleOverride", $source);
    }

    public function test_keyword_submit_uses_intake_without_project_id_by_site(): void
    {
        $source = $this->drawerSource();

        self::assertStringContainsString('->addKeywords(', $source);
        self::assertStringNotContainsString(
            "\$assignData['project_id_'.\$siteId] = (int) (\$this->projectIdBySite[\$siteId] ?? 0);",
            $source,
        );
        self::assertStringNotContainsString('KeywordResource::executeAssignKeywordsToContentProjects', $source);
    }

    public function test_pending_link_routes_through_intake_service(): void
    {
        $source = $this->drawerSource();

        self::assertStringContainsString('AssignToContentProjectContract::MODE_PENDING_LINK => $this->submitPendingLink()', $source);
        self::assertStringContainsString('->addPendingLink(', $source);

        $pendingMethod = $this->methodSource('submitPendingLink');
        self::assertStringContainsString('addPendingLink', $pendingMethod);
        self::assertStringNotContainsString('assignArticlesFromFormData', $pendingMethod);
        self::assertStringNotContainsString('executeAssignKeywordsToContentProjects', $pendingMethod);
    }

    public function test_vocabulary_items_route_through_intake_phrases(): void
    {
        $source = $this->drawerSource();
        self::assertStringContainsString(
            'AssignToContentProjectContract::MODE_VOCABULARY_ITEMS => $this->submitVocabularyItems()',
            $source,
        );
        self::assertStringContainsString('->addVocabularyPhrases(', $source);
        self::assertStringNotContainsString('KeywordProjectAssignmentService::class', $source);
    }

    public function test_quick_create_is_disabled_noop(): void
    {
        $source = $this->drawerSource();

        self::assertStringContainsString('public function quickCreate(?int $writerId = null): void', $source);
        self::assertStringNotContainsString('ArticleResource::quickCreateContentProject', $source);
    }

    public function test_mode_match_covers_all_contract_modes(): void
    {
        $source = $this->drawerSource();
        self::assertStringContainsString('AssignToContentProjectContract::MODE_KEYWORD => $this->submitKeyword()', $source);
        self::assertStringContainsString('AssignToContentProjectContract::MODE_PENDING_LINK => $this->submitPendingLink()', $source);
        self::assertStringContainsString('AssignToContentProjectContract::MODE_VOCABULARY_ITEMS => $this->submitVocabularyItems()', $source);
        self::assertStringContainsString('default => $this->submitArticle()', $source);
    }

    public function test_pending_link_payload_normalize(): void
    {
        $payload = AssignToContentProjectContract::normalizePayload([
            'mode' => AssignToContentProjectContract::MODE_PENDING_LINK,
            'source' => 'link_edit_bubble',
            'article_ids' => [7],
            'anchor_phrase' => '  hello  ',
        ]);

        self::assertSame(AssignToContentProjectContract::MODE_PENDING_LINK, $payload['mode']);
        self::assertSame([7], $payload['article_ids']);
        self::assertSame('hello', $payload['anchor_phrase']);
    }

    public function test_prepare_request_id_is_public(): void
    {
        $drawer = new AssignToContentProjectDrawer();
        $drawer->mode = AssignToContentProjectContract::MODE_ARTICLE;
        self::assertSame(0, $drawer->prepareRequestId);
    }

    public function test_reset_form_state_clears_keyword_flags(): void
    {
        $reset = new ReflectionMethod(AssignToContentProjectDrawer::class, 'resetFormState');
        $reset->setAccessible(true);
        $drawer = new AssignToContentProjectDrawer();
        $drawer->needsFocusKeyword = true;
        $drawer->focusKeyword = 'x';
        $reset->invoke($drawer);
        self::assertSame(AssignToContentProjectContract::MODE_ARTICLE, $drawer->mode);
        self::assertFalse($drawer->needsFocusKeyword);
        self::assertSame('', $drawer->focusKeyword);
    }

    private function drawerSource(): string
    {
        return (string) file_get_contents(
            (string) (new ReflectionClass(AssignToContentProjectDrawer::class))->getFileName()
        );
    }

    private function methodSource(string $method): string
    {
        $ref = new ReflectionMethod(AssignToContentProjectDrawer::class, $method);
        $file = (string) $ref->getFileName();
        $start = (int) $ref->getStartLine();
        $end = (int) $ref->getEndLine();
        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
