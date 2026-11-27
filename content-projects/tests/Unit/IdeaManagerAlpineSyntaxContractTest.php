<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

/**
 * Guard against Alpine/JS SyntaxError that blanks the whole Idea Manager UI.
 */
final class IdeaManagerAlpineSyntaxContractTest extends TestCase
{
    public function test_no_unparenthesized_nullish_or_mix_in_script(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        // Illegal: a ?? b || c  (SyntaxError in browsers) — killed ideaManagerRoot + warehouse.
        self::assertDoesNotMatchRegularExpression(
            '/\?\?[^;\n]*\|\|/',
            $this->scriptBody($blade),
            'Nullish coalescing mixed with || without safe parentheses breaks Alpine init',
        );
        self::assertStringContainsString('function ideaManagerRoot', $blade);
        self::assertStringContainsString('function ideaManagerDistribution', $blade);
        self::assertStringContainsString('preview_timeout', $blade);
        self::assertStringContainsString('canConfirmNow', $blade);
        self::assertStringContainsString('displayItems', $blade);
        self::assertStringContainsString('_reqSeq', $blade);
        self::assertStringContainsString('wire:ignore', $blade);
        self::assertStringContainsString('data-im-distribution-foot', $blade);
    }

    public function test_semantic_and_exact_warnings_never_disable_confirm(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/IdeaManager/IdeaManagerDistributionService.php'
        );
        self::assertStringContainsString('Semantic/exact warnings NEVER block Confirm', $src);
        self::assertStringContainsString('TopicGroupRetrievalClient', $src);
        self::assertStringContainsString("'can_confirm' => count(\$rows) > 0", $src);
        self::assertStringNotContainsString('duplicates_block_confirm', $src);
        self::assertStringContainsString('semantic_similarity', $src);
        self::assertStringContainsString('exact_destination_workspace', $src);
    }

    private function scriptBody(string $blade): string
    {
        $start = strpos($blade, '<script>');
        $end = strrpos($blade, '</script>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($blade, $start, $end - $start);
    }
}
