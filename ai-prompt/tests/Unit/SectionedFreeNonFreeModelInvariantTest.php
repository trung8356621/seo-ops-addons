<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @deprecated Invariant removed: sectioned shape no longer forces free-only models.
 * Kept as a pointer so old expectations are not reintroduced silently.
 */
final class SectionedFreeNonFreeModelInvariantTest extends TestCase
{
    public function test_non_free_invariant_removed_from_tracked_call(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/SectionedFree/SectionedFreeTrackedProviderCall.php',
        );
        $this->assertStringNotContainsString('SECTIONED_FREE_NON_FREE_MODEL_SELECTED', $src);
        $this->assertStringContainsString('Paid fallback is allowed', $src);
    }
}
