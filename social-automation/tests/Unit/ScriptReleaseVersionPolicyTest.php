<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptReleaseVersionPolicy;
use PHPUnit\Framework\TestCase;

final class ScriptReleaseVersionPolicyTest extends TestCase
{
    public function test_integer_releases_are_monotonic(): void
    {
        $policy = new ScriptReleaseVersionPolicy;
        self::assertSame([], $policy->validateNext(null, '1'));
        self::assertSame([], $policy->validateNext('1', '2'));
        self::assertSame(['script_version_sequence_conflict'], $policy->validateNext('1', '3'));
    }

    public function test_legacy_x_dot_zero_dot_zero_maps_to_its_generation_only(): void
    {
        $policy = new ScriptReleaseVersionPolicy;
        self::assertSame([], $policy->validateNext('1.0.0', '2'));
        self::assertSame(['script_version_sequence_conflict'], $policy->validateNext('1.0.0', '1'));
        self::assertSame(
            ['script_version_compatibility_unsupported'],
            $policy->validateNext('1.2.3', '2'),
        );
    }

    public function test_unchanged_canonical_content_cannot_create_another_release(): void
    {
        $policy = new ScriptReleaseVersionPolicy;
        self::assertSame(['script_content_unchanged'], $policy->validateCanonicalChange('abc', 'ABC'));
        self::assertSame([], $policy->validateCanonicalChange('abc', 'def'));
    }
}
