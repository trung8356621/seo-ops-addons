<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use PHPUnit\Framework\TestCase;

final class GeminiProviderUnavailableErrorPolicyTest extends TestCase
{
    public function test_temporary_nvidia_503_is_not_hard_unavailable(): void
    {
        self::assertFalse(GeminiModelVersionPolicy::isProviderUnavailableError(
            'content safety screening is temporarily unavailable',
        ));
        self::assertFalse(GeminiModelVersionPolicy::isProviderUnavailableError(
            'Service temporarily unavailable. Try again later.',
        ));
    }

    public function test_hard_model_retirement_still_marks_unavailable(): void
    {
        self::assertTrue(GeminiModelVersionPolicy::isProviderUnavailableError(
            'This model is no longer available',
        ));
        self::assertTrue(GeminiModelVersionPolicy::isProviderUnavailableError(
            'The model has been shutdown',
        ));
        self::assertTrue(GeminiModelVersionPolicy::isProviderUnavailableError(
            'model not found',
        ));
    }
}
