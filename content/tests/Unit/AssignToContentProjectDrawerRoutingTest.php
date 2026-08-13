<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

/**
 * Regression: canonical drawer preserves domain mode semantics after consolidation.
 */
final class AssignToContentProjectDrawerRoutingTest extends TestCase
{
    public function test_seo_audit_option_wires_ignore_monthly_capacity_into_article_submit(): void
    {
        $source = $this->drawerSource();

        self::assertStringContainsString(
            "\$this->ignoreMonthlyCapacity = (bool) (\$options['ignore_monthly_capacity'] ?? false);",
            $source,
        );
        self::assertStringContainsString(
            "'ignore_monthly_capacity' => \$this->ignoreMonthlyCapacity,",
            $source,
        );

        $auditBlade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/articles-optimal.blade.php'
        );
        self::assertStringContainsString("'ignore_monthly_capacity' => true", $auditBlade);
        self::assertStringContainsString('ignore_monthly_capacity: true', $auditBlade);
    }

    public function test_article_title_and_keyword_overrides_reach_form_data_payload(): void
    {
        $source = $this->drawerSource();

        self::assertStringContainsString("'keyword' => \$this->keywordOverride !== '' ? \$this->keywordOverride : null,", $source);
        self::assertStringContainsString("'title' => \$this->titleOverride !== '' ? \$this->titleOverride : null,", $source);
        self::assertStringContainsString('ArticleResource::assignArticlesFromFormData', $source);

        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php'
        );
        self::assertStringContainsString("is_string(\$data['title'] ?? null) ? \$data['title'] : null", $resource);
        self::assertStringContainsString("(bool) (\$data['ignore_monthly_capacity'] ?? false)", $resource);
    }

    public function test_keyword_multi_site_uses_independent_project_id_by_site_fields(): void
    {
        $source = $this->drawerSource();

        self::assertStringContainsString('projectIdBySite', $source);
        self::assertStringContainsString("\$assignData['project_id_'.\$siteId] = (int) (\$this->projectIdBySite[\$siteId] ?? 0);", $source);
        self::assertStringContainsString('KeywordResource::executeAssignKeywordsToContentProjects', $source);

        $keywordResource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-intelligence/src/Filament/Resources/KeywordResource.php'
        );
        self::assertStringContainsString("\$data['project_id_'.\$siteId]", $keywordResource);
        self::assertStringContainsString('foreach ($siteIds as $siteId)', $keywordResource);
    }

    public function test_pending_link_routes_through_article_pending_internal_link_service(): void
    {
        $source = $this->drawerSource();

        self::assertStringContainsString('AssignToContentProjectContract::MODE_PENDING_LINK => $this->submitPendingLink()', $source);
        self::assertStringContainsString('ArticlePendingInternalLinkService::class', $source);
        self::assertStringContainsString('->assignFromEditor(', $source);

        $pendingMethod = $this->methodSource('submitPendingLink');
        self::assertStringContainsString('assignFromEditor', $pendingMethod);
        self::assertStringNotContainsString('assignArticlesFromFormData', $pendingMethod);
        self::assertStringNotContainsString('executeAssignKeywordsToContentProjects', $pendingMethod);
    }

    public function test_vocabulary_items_route_through_phrase_assignment(): void
    {
        $source = $this->drawerSource();
        self::assertStringContainsString(
            'AssignToContentProjectContract::MODE_VOCABULARY_ITEMS => $this->submitVocabularyItems()',
            $source,
        );
        self::assertStringContainsString('KeywordProjectAssignmentService::class', $source);
        self::assertStringContainsString('->assignPhrases(', $source);
    }

    public function test_quick_create_uses_article_resource_quick_create_helper(): void
    {
        $source = $this->drawerSource();

        self::assertStringContainsString('ArticleResource::quickCreateContentProject', $source);
        self::assertStringContainsString('public function quickCreate(?int $writerId = null): void', $source);
        self::assertStringNotContainsString('open-article-assign-content-project-modal', $source);
    }

    public function test_reset_form_state_clears_cross_mode_fields(): void
    {
        $drawer = new AssignToContentProjectDrawer();
        $drawer->mode = AssignToContentProjectContract::MODE_ARTICLE;
        $drawer->source = 'seo_audit';
        $drawer->articleIds = [11, 22];
        $drawer->keywordIds = [33];
        $drawer->siteIds = [1, 2];
        $drawer->projectId = 99;
        $drawer->projectIdBySite = [1 => 10, 2 => 20];
        $drawer->type = 'improve';
        $drawer->rewriteNotes = 'notes';
        $drawer->focusKeyword = 'focus';
        $drawer->keywordOverride = 'kw';
        $drawer->titleOverride = 'title';
        $drawer->needsFocusKeyword = true;
        $drawer->ignoreMonthlyCapacity = true;
        $drawer->showArticleFields = true;
        $drawer->errorMessage = 'boom';
        $drawer->anchorPhrase = 'phrase';
        $drawer->items = [['keyword' => 'x', 'title' => 'x', 'source' => 'vocabulary', 'source_article_id' => 1]];

        $reset = new ReflectionMethod(AssignToContentProjectDrawer::class, 'resetFormState');
        $reset->setAccessible(true);
        $reset->invoke($drawer);

        self::assertSame(AssignToContentProjectContract::MODE_ARTICLE, $drawer->mode);
        self::assertSame('', $drawer->source);
        self::assertSame([], $drawer->articleIds);
        self::assertSame([], $drawer->keywordIds);
        self::assertSame([], $drawer->siteIds);
        self::assertNull($drawer->projectId);
        self::assertSame([], $drawer->projectIdBySite);
        self::assertSame(SeoProjectTask::TYPE_REWRITE, $drawer->type);
        self::assertSame('', $drawer->rewriteNotes);
        self::assertSame('', $drawer->focusKeyword);
        self::assertSame('', $drawer->keywordOverride);
        self::assertSame('', $drawer->titleOverride);
        self::assertFalse($drawer->needsFocusKeyword);
        self::assertFalse($drawer->ignoreMonthlyCapacity);
        self::assertFalse($drawer->showArticleFields);
        self::assertNull($drawer->errorMessage);
        self::assertNull($drawer->anchorPhrase);
        self::assertSame([], $drawer->items);
    }

    public function test_prepare_normalizes_payload_via_contract_modes(): void
    {
        $payload = AssignToContentProjectContract::normalizePayload([
            'mode' => 'pending_link',
            'source' => 'link_edit_bubble',
            'article_ids' => [5],
            'anchor_phrase' => '  hello  ',
            'options' => ['ignore_monthly_capacity' => true],
        ]);

        self::assertSame(AssignToContentProjectContract::MODE_PENDING_LINK, $payload['mode']);
        self::assertSame('link_edit_bubble', $payload['source']);
        self::assertSame([5], $payload['article_ids']);
        self::assertSame('hello', $payload['anchor_phrase']);
        self::assertTrue((bool) ($payload['options']['ignore_monthly_capacity'] ?? false));
    }

    public function test_source_is_not_used_for_backend_routing_in_drawer(): void
    {
        $source = $this->drawerSource();

        self::assertStringNotContainsString("\$this->source ===", $source);
        self::assertStringNotContainsString('source === \'seo_audit\'', $source);
        self::assertStringNotContainsString('source === "seo_audit"', $source);
        self::assertStringContainsString('match ($this->mode)', $source);
    }

    public function test_prepare_tracks_request_id_against_stale_completions(): void
    {
        $method = $this->methodSource('prepare');
        self::assertStringContainsString('_request_id', $method);
        self::assertStringContainsString('prepareRequestId', $method);
        self::assertStringContainsString('if ($requestId !== $this->prepareRequestId)', $method);
    }

    public function test_load_articles_uses_record_route_binding_query(): void
    {
        $method = $this->methodSource('loadArticles');
        self::assertStringContainsString('getRecordRouteBindingEloquentQuery()', $method);
        self::assertStringNotContainsString('getEloquentQuery()', $method);
    }

    private function drawerSource(): string
    {
        $path = (new ReflectionClass(AssignToContentProjectDrawer::class))->getFileName();
        self::assertNotFalse($path);

        return (string) file_get_contents($path);
    }

    private function methodSource(string $method): string
    {
        $ref = new ReflectionMethod(AssignToContentProjectDrawer::class, $method);
        $file = (string) $ref->getFileName();
        $lines = file($file);
        self::assertNotFalse($lines);

        $start = $ref->getStartLine() - 1;
        $end = $ref->getEndLine();

        return implode('', array_slice($lines, $start, $end - $start));
    }
}
