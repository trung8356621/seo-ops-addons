<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Article Editor Internal Link: keep search/apply; phrase button → Idea Manager.
 * Pending Internal Link workflow removed.
 */
final class ArticleEditorIdeaManagerIntakeContractTest extends TestCase
{
    public function test_edit_article_exposes_idea_phrase_intake_and_search(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(EditArticle::class))->getFileName()
        );
        self::assertStringContainsString('function addPhraseToIdeaManager', $src);
        self::assertStringContainsString('IdeaManagerKeywordIntakeService', $src);
        self::assertStringContainsString('->addPhrases(', $src);
        self::assertStringContainsString("'outcome' => \$outcome", $src);
        self::assertStringContainsString('already_exists', $src);
        self::assertStringContainsString("'idea_refs'", $src);
        self::assertStringContainsString('function searchInternalLinkArticles', $src);
        self::assertStringContainsString('function searchInternalLinkArticlesSemantic', $src);
        self::assertStringContainsString('function resolveInternalLinkSearchUrl', $src);
        self::assertStringNotContainsString('ArticlePendingInternalLinkService', $src);
        self::assertStringNotContainsString('addPendingLink', $src);
    }

    public function test_link_bubble_uses_idea_manager_not_pending_link(): void
    {
        $bubble = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/LinkEditBubble.jsx'
        );
        self::assertStringContainsString("callEditArticleLivewire('addPhraseToIdeaManager'", $bubble);
        self::assertStringContainsString('handleAddPhraseToIdeaManager', $bubble);
        self::assertStringContainsString('seoOps:idea-manager:intake-ping', $bubble);
        self::assertStringContainsString("outcome === 'already_exists'", $bubble);
        self::assertStringContainsString("callEditArticleLivewire('searchInternalLinkArticles'", $bubble);
        self::assertStringContainsString("callEditArticleLivewire('searchInternalLinkArticlesSemantic'", $bubble);
        self::assertStringNotContainsString('MODE_PENDING_LINK', $bubble);
        self::assertStringNotContainsString('pending-internal-link-ready', $bubble);
        self::assertStringNotContainsString('openAssignToContentProject', $bubble);
    }

    public function test_article_editor_bridge_does_not_forward_pending_link_event(): void
    {
        $bridge = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx'
        );
        self::assertStringNotContainsString('pending-internal-link-ready', $bridge);
    }

    public function test_i18n_idea_manager_labels(): void
    {
        $i18n = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/i18n.js'
        );
        self::assertStringContainsString("link_bubble_assign_content_project: 'Add to Idea Warehouse'", $i18n);
        self::assertStringContainsString("link_bubble_assign_content_project: 'Thêm vào Kho Idea'", $i18n);
        self::assertStringNotContainsString("link_bubble_assign_content_project: 'Add to Draft'", $i18n);
        self::assertStringNotContainsString("link_bubble_assign_content_project: 'Thêm vào Draft'", $i18n);
    }

    public function test_wordpress_sync_does_not_resolve_pending_links(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressArticleSyncService.php'
        );
        self::assertStringNotContainsString('resolveForMainArticle', $src);
        self::assertStringNotContainsString('ArticlePendingInternalLinkService', $src);
    }

    public function test_article_editor_does_not_mount_assign_draft_drawer(): void
    {
        $provider = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/Providers/SeoPanelProvider.php'
        );
        self::assertStringContainsString("seo/articles/*/edit", $provider);
        self::assertStringContainsString("@livewire('assign-to-content-project-drawer')", str_replace("\\'", "'", $provider));

        $editArticle = (string) file_get_contents(
            (string) (new ReflectionClass(EditArticle::class))->getFileName()
        );
        self::assertStringNotContainsString('assign-to-content-project-drawer', $editArticle);
        self::assertStringNotContainsString('AssignToContentProjectDrawer', $editArticle);
        self::assertStringNotContainsString('assign-content-project:open', $editArticle);
    }

    public function test_link_bubble_does_not_dispatch_assign_drawer_open(): void
    {
        $bubble = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/LinkEditBubble.jsx'
        );
        self::assertStringNotContainsString('assign-content-project:open', $bubble);
        self::assertStringNotContainsString('openAssignToContentProject', $bubble);
        self::assertStringContainsString("callEditArticleLivewire('addPhraseToIdeaManager'", $bubble);
    }
}
