<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

final class ArticleEditorRuntimeModulesPhase6c2Test extends TestCase
{
    private function js(string $relative): string
    {
        return ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
    }

    public function test_links_panel_registered_on_runtime_module(): void
    {
        $mod = (string) file_get_contents($this->js('editor/modules/links/index.js'));
        self::assertStringContainsString("host: 'editor'", $mod);
        self::assertStringContainsString('LinksSidebarPanel', $mod);
        self::assertStringContainsString('portalRootKey: \'links\'', $mod);
        self::assertStringContainsString('bubble.link', $mod);
        self::assertStringContainsString('LinkEditBubble', $mod);
        self::assertFileExists($this->js('editor/modules/links/LinksSidebarPanel.jsx'));
    }

    public function test_faq_panel_registered_on_runtime_module(): void
    {
        $mod = (string) file_get_contents($this->js('editor/modules/faq/index.js'));
        self::assertStringContainsString("host: 'editor'", $mod);
        self::assertStringContainsString('FaqSidebarPanel', $mod);
        self::assertStringContainsString('navChip: false', $mod);
        self::assertStringContainsString('faq.toolbar.extract', $mod);
        self::assertFileExists($this->js('editor/modules/faq/FaqSidebarPanel.jsx'));
        self::assertFileExists($this->js('editor/modules/faq/faqExtractToolbarAction.js'));
    }

    public function test_cta_aliases_links_panel_with_own_module(): void
    {
        $mod = (string) file_get_contents($this->js('editor/modules/cta-contact/index.js'));
        self::assertStringContainsString("panelId: 'cta'", $mod);
        self::assertStringContainsString("aliasPanelId: 'links'", $mod);
        self::assertStringContainsString("portalRootKey: 'links'", $mod);
        self::assertStringContainsString('insert_contact_cta', $mod);
    }

    public function test_module_host_removed_after_ai_cutover(): void
    {
        self::assertFileDoesNotExist($this->js('components/ArticleEditorModuleHost.jsx'));
        $entry = (string) file_get_contents($this->js('article-editor.jsx'));
        self::assertStringNotContainsString('ArticleEditorModuleHost', $entry);
        $ai = (string) file_get_contents($this->js('editor/modules/ai/index.js'));
        self::assertStringContainsString('AiChatSidebarPanel', $ai);
    }

    public function test_suggested_link_and_cta_use_host_actions_not_primary_events(): void
    {
        $links = (string) file_get_contents($this->js('components/ArticleLinksSidebar.jsx'));
        self::assertStringContainsString('insertSuggestedLink', $links);
        self::assertStringContainsString('getEditorCommandHost', $links);

        $cta = (string) file_get_contents($this->js('components/CtaContactInsertList.jsx'));
        self::assertStringContainsString('insertCtaLink', $cta);
        self::assertStringContainsString('getEditorCommandHost', $cta);
        self::assertStringContainsString('preserveEditorContextBeforeSidebarAction', $cta);
    }

    public function test_faq_extract_uses_service_not_toolbar_event_loop(): void
    {
        $toolbar = (string) file_get_contents($this->js('components/BlockFormatToolbar.jsx'));
        self::assertStringContainsString('runFaqExtractFromToolbar', $toolbar);
        self::assertStringNotContainsString("extract-article-faqs-from-toolbar", $toolbar);

        $faqEditor = (string) file_get_contents($this->js('components/ArticleFaqEditor.jsx'));
        self::assertStringContainsString('runFaqExtractFromToolbar', $faqEditor);

        $extract = (string) file_get_contents($this->js('editor/modules/faq/faqExtractToolbarAction.js'));
        self::assertStringContainsString('extractFaqFromSelection', $extract);
        self::assertStringContainsString('applyExtractedFaqs', $extract);
    }

    public function test_faq_extract_rest_endpoint_exists(): void
    {
        $controller = ProjectRoot::addonsPath().'/content/src/Http/Controllers/ArticleEditorFaqSnapshotController.php';
        self::assertFileExists($controller);
        $body = (string) file_get_contents($controller);
        self::assertStringContainsString('function extract(', $body);
        self::assertStringContainsString('ArticleFaqManualExtractService', $body);

        $provider = LegacyAddonPath::resolve('Providers/SeoPanelProvider.php');
        $routes = (string) file_get_contents($provider);
        self::assertStringContainsString('faq-snapshot/extract', $routes);
    }

    public function test_link_bubble_uses_command_layer_for_unlink_and_apply(): void
    {
        $bubble = (string) file_get_contents($this->js('components/LinkEditBubble.jsx'));
        self::assertStringContainsString("executeEditorCommand('remove_link_keep_text'", $bubble);
        self::assertStringContainsString("executeEditorCommand(commandName", $bubble);
        self::assertStringContainsString('create_link', $bubble);
        self::assertStringContainsString('update_link', $bubble);
    }

    public function test_scoped_hooks_exist_without_giant_host_bag(): void
    {
        foreach ([
            'useEditorCommands.js',
            'useEditorInsertionContext.js',
            'useEditorLinks.js',
            'useEditorContacts.js',
            'useEditorFaq.js',
            'useEditorSession.js',
            'useEditorNotifications.js',
        ] as $file) {
            self::assertFileExists($this->js('editor/host/hooks/'.$file), $file);
        }
        $commands = (string) file_get_contents($this->js('editor/host/hooks/useEditorCommands.js'));
        self::assertStringContainsString('executeEditorCommand', $commands);
        self::assertStringNotContainsString('useEditorHostApi', $commands);
    }

    public function test_editor_hosted_modules_include_links_faq_cta(): void
    {
        $modules = (string) file_get_contents($this->js('utils/articleEditorModules.js'));
        self::assertStringContainsString("'links'", $modules);
        self::assertStringContainsString("'faq'", $modules);
        self::assertStringContainsString("'cta'", $modules);
        // Phase 6C.4: AI cutover â€” no EXTERNAL_HOSTED modules; ai-chat is EDITOR_HOSTED.
        self::assertMatchesRegularExpression('/EXTERNAL_HOSTED_MODULES\\s*=\\s*Object\\.freeze\\(\\s*\\[\\s*\\]\\s*\\)/', $modules);
        self::assertStringContainsString("'ai-chat'", $modules);
    }

    public function test_portal_host_maps_cta_to_links_panel(): void
    {
        $host = (string) file_get_contents($this->js('editor/host/EditorSidebarPortalHost.jsx'));
        self::assertStringContainsString("activePanelId === 'cta'", $host);
        self::assertStringContainsString('aliasPanelId', $host);
    }

    public function test_seo_article_editor_binds_module_actions(): void
    {
        $editor = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('insertSuggestedLink', $editor);
        self::assertStringContainsString('insertCtaLink', $editor);
        self::assertStringContainsString('applyExtractedFaqs', $editor);
        self::assertStringContainsString("links: document.getElementById('seo-article-links-root')", $editor);
        self::assertStringContainsString("faq: document.getElementById('seo-article-faq-root')", $editor);
        self::assertStringNotContainsString("lazy(() => import('../modules/LinksModule'))", $editor);
        self::assertStringNotContainsString("lazy(() => import('../modules/FaqModule'))", $editor);
    }

    public function test_client_extract_helper_exists(): void
    {
        $snap = (string) file_get_contents($this->js('utils/articleEditorFaqSnapshot.js'));
        self::assertStringContainsString('export async function extractFaqFromSelection', $snap);
        self::assertStringContainsString('/extract', $snap);
    }
}
