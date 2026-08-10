<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use PHPUnit\Framework\TestCase;

final class GeminiModelVersionPolicyTest extends TestCase
{
    public function test_major_version_parsing(): void
    {
        self::assertSame(2, GeminiModelVersionPolicy::majorVersion('gemini-2.5-flash-image'));
        self::assertSame(3, GeminiModelVersionPolicy::majorVersion('gemini-3.1-flash-image-preview'));
        self::assertNull(GeminiModelVersionPolicy::majorVersion('imagen-4.0-generate-001'));
    }

    public function test_legacy_version_disabled_but_record_kept(): void
    {
        $decision = GeminiModelVersionPolicy::routingDecision('gemini-2.0-flash');

        self::assertSame(GeminiModelVersionPolicy::ROUTING_DISABLED, $decision['routing_status']);
        self::assertSame(GeminiModelVersionPolicy::REASON_LEGACY_VERSION, $decision['disabled_reason']);
        self::assertFalse(GeminiModelVersionPolicy::isEligibleForAutoRouting('gemini-2.5-pro'));
        self::assertTrue(GeminiModelVersionPolicy::isEligibleForAutoRouting('gemini-3-flash-preview'));
        self::assertTrue(GeminiModelVersionPolicy::isEligibleForAutoRouting('imagen-4.0-generate-001'));
    }

    public function test_prefer_stable_before_preview(): void
    {
        $ordered = GeminiModelVersionPolicy::preferStableFirst([
            'gemini-3-flash-preview',
            'gemini-3.1-flash-lite',
            'gemini-3.5-flash-preview',
        ]);

        self::assertSame('gemini-3.1-flash-lite', $ordered[0]);
    }

    public function test_mark_unavailable_capability(): void
    {
        $caps = GeminiModelVersionPolicy::markCapabilitiesUnavailable([], 'model is no longer available');
        $decision = GeminiModelVersionPolicy::routingDecision('gemini-3-flash-preview', $caps);

        self::assertSame(GeminiModelVersionPolicy::ROUTING_DISABLED, $decision['routing_status']);
        self::assertSame(GeminiModelVersionPolicy::REASON_PROVIDER_UNAVAILABLE, $decision['disabled_reason']);
    }
}
