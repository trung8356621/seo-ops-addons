<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageDependencyValidator;
use PHPUnit\Framework\TestCase;

final class PackageDependencyValidatorTest extends TestCase
{
    public function test_empty_dependencies_ok(): void
    {
        $v = new PackageDependencyValidator;
        $r = $v->validate([], []);
        self::assertTrue($r['ok']);
    }

    public function test_missing_and_digest_mismatch(): void
    {
        $v = new PackageDependencyValidator;
        $digest = str_repeat('11', 32);
        $published = [
            ['package_key' => 'facebook.group.post', 'version' => '1.0.0', 'content_digest' => $digest, 'status' => 'published'],
        ];

        $missing = $v->validate([
            ['package_key' => 'facebook.group.comment', 'version' => '1.0.0', 'content_digest' => $digest],
        ], $published);
        self::assertFalse($missing['ok']);
        self::assertContains('dependency_missing:0', $missing['errors']);

        $mismatch = $v->validate([
            ['package_key' => 'facebook.group.post', 'version' => '1.0.0', 'content_digest' => str_repeat('22', 32)],
        ], $published);
        self::assertFalse($mismatch['ok']);
        self::assertContains('dependency_digest_mismatch:0', $mismatch['errors']);

        $ok = $v->validate([
            ['package_key' => 'facebook.group.post', 'version' => '1.0.0', 'content_digest' => $digest],
        ], $published);
        self::assertTrue($ok['ok']);
    }
}
