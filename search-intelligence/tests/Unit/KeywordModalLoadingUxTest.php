<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class KeywordModalLoadingUxTest extends TestCase
{
    public function test_shared_modal_loading_placeholder_exists(): void
    {
        $path = LegacyAddonPath::resolve('resources/views/components/modal-loading-placeholder.blade.php');
        self::assertFileExists($path);

        $blade = (string) file_get_contents($path);
        self::assertStringContainsString('modal-loading-placeholder', $blade);
        self::assertStringContainsString('animate-pulse', $blade);
        self::assertStringContainsString('modal_loading_data', $blade);
    }

    public function test_mcp_group_modal_opens_shell_before_prepare(): void
    {
        $modal = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/mcp-group-modal.blade.php',
        ));
        $menu = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/cluster-row-actions-menu.blade.php',
        ));
        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));
        $page = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');

        self::assertStringNotContainsString('@if ($this->mcpGroupModalOpen)', $modal);
        self::assertStringContainsString('mcp-group-modal-open', $modal);
        self::assertStringContainsString('openShell', $modal);
        self::assertStringContainsString('prepareMcpGroupModal', $modal);
        self::assertStringContainsString('modal-loading-placeholder', $modal);
        self::assertStringContainsString('showLoading()', $modal);
        self::assertStringContainsString('opening', $modal);
        self::assertStringContainsString('retryMcpGroupModal', $modal);

        self::assertStringContainsString("\$dispatch('mcp-group-modal-open'", $menu);
        self::assertStringNotContainsString('wire:click="openMcpGroupModal', $menu);
        self::assertStringContainsString("\$dispatch('mcp-group-modal-open'", $index);

        self::assertStringContainsString('mcpGroupModalPhase', $page);
        self::assertStringContainsString('function prepareMcpGroupModal', $page);
        self::assertStringContainsString("mcpGroupModalPhase = 'loading'", $page);
        self::assertStringContainsString("mcpGroupModalPhase = 'ready'", $page);
        self::assertStringContainsString("mcpGroupModalPhase = 'error'", $page);
    }

    public function test_move_cluster_modal_opens_before_options_load(): void
    {
        $modal = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-move-cluster-modal.blade.php',
        ));
        $item = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-item.blade.php',
        ));
        $trait = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/InteractsWithKeywordItemActions.php');
        $list = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php');

        self::assertStringContainsString('modal-loading-placeholder', $modal);
        self::assertStringContainsString('clientLoading', $modal);
        self::assertStringContainsString('retryMoveClusterModal', $modal);
        self::assertStringContainsString('resetMoveClusterModal', $modal);

        self::assertStringContainsString("\$dispatch('open-modal', { id: 'keyword-move-cluster-modal' })", $item);
        self::assertStringContainsString('prepareMoveClusterModal', $item);
        self::assertStringNotContainsString('wire:click="openMoveClusterModal', $item);

        self::assertStringContainsString('moveClusterModalPhase', $trait);
        self::assertStringContainsString('function prepareMoveClusterModal', $trait);
        self::assertStringContainsString("moveClusterModalPhase = 'loading'", $trait);

        self::assertStringContainsString("\$dispatch('open-modal', { id: 'keyword-move-cluster-modal' })", $list);
        self::assertStringContainsString('prepareMoveClusterModal', $list);
    }

    public function test_project_cursor_rule_documents_modal_loading_ux(): void
    {
        $rule = dirname(__DIR__, 4).'/omnichannel-client/.cursor/rules/modal-loading-feedback.mdc';
        if (! is_file($rule)) {
            $rule = 'D:/work/omnichannel-client/.cursor/rules/modal-loading-feedback.mdc';
        }
        self::assertFileExists($rule);

        $body = (string) file_get_contents($rule);
        self::assertStringContainsString('MODAL / DIALOG LOADING UX RULE', $body);
        self::assertStringContainsString('open the modal shell immediately', $body);
        self::assertStringContainsString('Never flash stale data', $body);
        self::assertStringContainsString('modal-loading-placeholder', $body);
    }
}
