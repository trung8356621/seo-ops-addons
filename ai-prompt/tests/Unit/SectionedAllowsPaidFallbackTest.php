<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Sectioned shape may use paid fallback when Free only is OFF.
 */
final class SectionedAllowsPaidFallbackTest extends TestCase
{
    public function test_paid_candidate_is_not_rejected_by_sectioned_tracked_call(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeTrackedProviderCall::class,
            ))->getFileName(),
        );
        $this->assertStringNotContainsString('SECTIONED_FREE_NON_FREE_MODEL_SELECTED', $src);
        $this->assertStringContainsString('Paid fallback is allowed', $src);
        $this->assertStringNotContainsString("router_policy' => 'free_only'", $src);
    }
}
