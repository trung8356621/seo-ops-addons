<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageDigest;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use PHPUnit\Framework\TestCase;

final class PackagePublishValidatorTest extends TestCase
{
    private PackagePublishValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PackagePublishValidator;
    }

    /** @return array<string, mixed> */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'package_key' => 'facebook.group.post',
            'kind' => 'widget',
            'name' => 'Facebook Group Post',
            'version' => '1.0.0',
            'schema_version' => 1,
            'manifest' => [
                'steps' => [
                    ['action' => 'open_composer'],
                    ['action' => 'submit'],
                ],
            ],
            'dependencies' => [],
            'runtime_compatibility' => ['runner_min' => '1.0.0'],
        ], $overrides);
    }

    public function test_valid_payload_computes_stable_digest(): void
    {
        $a = $this->validator->validate($this->basePayload());
        $b = $this->validator->validate($this->basePayload());

        self::assertTrue($a['ok']);
        self::assertTrue($b['ok']);
        self::assertSame($a['data']['content_digest'], $b['data']['content_digest']);
        self::assertTrue(PackageDigest::isValidHex($a['data']['content_digest']));
    }

    public function test_provided_matching_digest_accepted(): void
    {
        $first = $this->validator->validate($this->basePayload());
        self::assertTrue($first['ok']);

        $second = $this->validator->validate($this->basePayload([
            'content_digest' => $first['data']['content_digest'],
        ]));

        self::assertTrue($second['ok']);
        self::assertSame($first['data']['content_digest'], $second['data']['content_digest']);
    }

    public function test_provided_mismatched_digest_rejected(): void
    {
        $result = $this->validator->validate($this->basePayload([
            'content_digest' => str_repeat('ab', 32),
        ]));

        self::assertFalse($result['ok']);
        self::assertContains('content_digest_mismatch', $result['errors']);
    }

    public function test_forbidden_executable_manifest_fields_rejected(): void
    {
        $result = $this->validator->validate($this->basePayload([
            'manifest' => ['script' => 'alert(1)'],
        ]));

        self::assertFalse($result['ok']);
        self::assertTrue(
            (bool) array_filter(
                $result['errors'],
                static fn (string $e): bool => str_starts_with($e, 'manifest_forbidden_field:')
            )
        );
    }

    public function test_invalid_kind_rejected(): void
    {
        $result = $this->validator->validate($this->basePayload(['kind' => 'scenario']));

        self::assertFalse($result['ok']);
        self::assertContains('kind_invalid', $result['errors']);
    }

    public function test_digest_changes_when_manifest_changes(): void
    {
        $a = $this->validator->validate($this->basePayload());
        $b = $this->validator->validate($this->basePayload([
            'manifest' => ['steps' => [['action' => 'different']]],
        ]));

        self::assertTrue($a['ok']);
        self::assertTrue($b['ok']);
        self::assertNotSame($a['data']['content_digest'], $b['data']['content_digest']);
    }
}
