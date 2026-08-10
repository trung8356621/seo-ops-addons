<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Contract: picker SWR/tab stability, session document DI, CTA value vs sentence.
 */
final class ArticleEditorRegressionPickerSessionCtaTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_picker_switches_tab_before_network_and_keeps_cache(): void
    {
        $picker = $this->readAddon('resources/js/editor/host/SharedMediaPicker.jsx');

        self::assertStringContainsString('MEDIA_PICKER_CACHE_TTL_MS', $picker);
        self::assertStringContainsString('mediaPickerResultCache', $picker);
        self::assertStringContainsString('mediaPickerInFlight', $picker);
        self::assertStringContainsString('const switchTab = useCallback', $picker);
        self::assertStringContainsString('setTab(nextTab)', $picker);
        self::assertStringContainsString('becameOpen', $picker);
        self::assertStringContainsString('wasOpenRef', $picker);
        self::assertStringContainsString('sessionId', $picker);
        self::assertStringContainsString('skipCache', $picker);
        self::assertStringContainsString('data-media-picker-refresh="1"', $picker);
        // Selection patches must not re-init session / reset tab.
        self::assertStringContainsString('if (!becameOpen)', $picker);
        self::assertStringContainsString('becameOpen = nowOpen && !wasOpenRef.current', $picker);
        self::assertStringNotContainsString('cacheRef.current', $picker);
        self::assertStringNotContainsString('inFlightRef.current', $picker);
    }

    public function test_session_controller_injects_actions_and_bundle_apply(): void
    {
        $controller = $this->readAddon('Http/Controllers/ArticleEditorSessionController.php');

        self::assertStringContainsString('BusinessActionDispatcher $actions', $controller);
        self::assertStringContainsString('ArticleEditorBundleApplyService $bundleApply', $controller);
        self::assertStringContainsString("\$this->actions->dispatch(", $controller);
        self::assertStringContainsString("'article.content.update'", $controller);
        self::assertStringContainsString('$this->bundleApply->apply(', $controller);
        self::assertStringContainsString('private readonly BusinessActionDispatcher $actions', $controller);
        self::assertStringContainsString('private readonly ArticleEditorBundleApplyService $bundleApply', $controller);
    }

    public function test_cta_primary_is_contact_value_dropdown_is_sentence(): void
    {
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');
        $links = $this->readAddon('resources/js/components/ArticleLinksSidebar.jsx');
        $domain = $this->readAddon('resources/js/components/ArticleDomainWidgetsSidebar.jsx');
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');
        $registry = $this->readAddon('resources/js/utils/editorCommands/editorCommandRegistry.js');

        self::assertStringContainsString("data-cta-action=\"insert_contact_value\"", $cta);
        self::assertStringContainsString("onInsertQuickCta(item, itemKey, null, 'value')", $cta);
        self::assertStringContainsString("onInsertQuickCta(item, itemKey, null, 'sentence')", $cta);
        self::assertStringContainsString("onInsertQuickCta(item, itemKey, template, 'sentence')", $cta);
        self::assertStringNotContainsString("mode === 'value' ? 'sentence'", $cta);
        self::assertStringContainsString("const effectiveMode = mode === 'value' ? 'value' : 'sentence'", $cta);

        self::assertStringContainsString('mode = \'sentence\'', $links);
        self::assertStringContainsString('mode = \'sentence\'', $domain);
        self::assertStringContainsString('dispatchCtaInsert(', $links);
        self::assertStringContainsString('occurrence_index', $cta);

        self::assertStringContainsString('resolveInsertionAfterEnclosingBlock', $selection);
        self::assertStringContainsString("name === 'blockquote'", $selection);
        self::assertStringContainsString("name === 'bulletList'", $selection);
        self::assertStringContainsString('insertContentAt', $selection);

        self::assertStringContainsString("mut('insert_contact_value'", $registry);
        self::assertStringContainsString("mut('insert_contact_cta'", $registry);
    }
}
