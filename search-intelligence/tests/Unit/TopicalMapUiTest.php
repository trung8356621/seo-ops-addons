<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AttachClusterToTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateTopicCommand;
use PHPUnit\Framework\TestCase;

/**
 * UI must dispatch CommandBus capabilities — never update models directly.
 */
final class TopicalMapUiTest extends TestCase
{
    public function test_map_commands_are_bus_named(): void
    {
        self::assertSame('keyword_intelligence.build_topical_map', (new BuildTopicalMapCommand('kww_1'))->name());
        self::assertSame('keyword_intelligence.approve_topical_map', (new ApproveTopicalMapCommand('kww_1', 'tmv_1'))->name());
        self::assertSame('keyword_intelligence.create_topic', (new CreateTopicCommand('kww_1', ['name' => 'X']))->name());
        self::assertSame(
            'keyword_intelligence.attach_cluster',
            (new AttachClusterToTopicCommand('kww_1', 'kwt_1', 'kwc_1'))->name(),
        );
    }

    public function test_build_command_supports_modes_without_auto_approve_flag(): void
    {
        $cmd = new BuildTopicalMapCommand(
            workspaceRef: 'kww_1',
            mode: 'conservative',
            includeReviewedClusters: false,
            preserveManualTopics: true,
        );
        self::assertSame('conservative', $cmd->mode);
        self::assertFalse($cmd->includeReviewedClusters);
        self::assertTrue($cmd->preserveManualTopics);
    }
}
