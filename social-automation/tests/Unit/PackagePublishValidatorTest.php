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

    public function test_script_kind_accepted(): void
    {
        $result = $this->validator->validate([
            'package_key' => 'facebook.group.post.script',
            'kind' => 'script',
            'name' => 'Post Script',
            'version' => '1.0.0',
            'schema_version' => 1,
            'manifest' => [
                'script_id' => 'facebook.group.post',
                'variables' => [
                    ['key' => 'text', 'type' => 'text'],
                ],
                'steps' => [
                    ['id' => 's1', 'action' => 'CLICK_TARGET'],
                ],
                'video_demo' => [
                    'artifact_ref' => 'case/run/recording.webm',
                    'mime' => 'video/webm',
                    'sha256' => str_repeat('ab', 32),
                    'test_run_id' => '11111111-1111-1111-1111-111111111111',
                    'script_version' => '1.0.0',
                    'reviewed' => true,
                    'audio' => false,
                    'desktop_capture' => false,
                ],
            ],
            'dependencies' => [],
        ]);
        self::assertTrue($result['ok'], json_encode($result['errors'] ?? []));
        self::assertSame('script', $result['data']['kind']);
    }

    public function test_script_without_video_demo_rejected(): void
    {
        $result = $this->validator->validate([
            'package_key' => 'facebook.group.post.script',
            'kind' => 'script',
            'name' => 'Post Script',
            'version' => '1.0.0',
            'schema_version' => 1,
            'manifest' => [
                'script_id' => 'facebook.group.post',
                'variables' => [
                    ['key' => 'text', 'type' => 'text'],
                ],
                'steps' => [
                    ['id' => 's1', 'action' => 'CLICK_TARGET'],
                ],
            ],
            'dependencies' => [],
        ]);
        self::assertFalse($result['ok']);
        self::assertContains('script_video_demo_required', $result['errors']);
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

    public function test_workflow_composition_accepts_sequential_widget_steps(): void
    {
        $digest = str_repeat('ab', 32);
        $result = $this->validator->validate([
            'package_key' => 'facebook.group.post_then_comment',
            'kind' => 'workflow',
            'name' => 'Post then comment',
            'version' => '1.0.0',
            'schema_version' => 1,
            'manifest' => [
                'steps' => [
                    ['widget_key' => 'facebook.group.post', 'input' => ['text' => 'hi']],
                    ['widget_key' => 'facebook.group.comment', 'output_bindings' => ['url' => 'facebook.group.post.post_url']],
                ],
            ],
            'dependencies' => [
                ['package_key' => 'facebook.group.post', 'version' => '1.0.0', 'content_digest' => $digest],
                ['package_key' => 'facebook.group.comment', 'version' => '1.0.0', 'content_digest' => $digest],
            ],
        ]);

        self::assertTrue($result['ok']);
    }

    public function test_workflow_rejects_account_switch_and_missing_steps(): void
    {
        $badSwitch = $this->validator->validate([
            'package_key' => 'facebook.flow',
            'kind' => 'workflow',
            'name' => 'Bad',
            'version' => '1.0.0',
            'schema_version' => 1,
            'manifest' => [
                'steps' => [
                    ['widget_key' => 'facebook.group.post', 'account_switch' => true],
                ],
            ],
            'dependencies' => [
                ['package_key' => 'facebook.group.post', 'version' => '1.0.0', 'content_digest' => str_repeat('cd', 32)],
            ],
        ]);
        self::assertFalse($badSwitch['ok']);
        self::assertTrue(
            (bool) array_filter(
                $badSwitch['errors'],
                static fn (string $e): bool => str_contains($e, 'forbidden_field')
            )
        );

        $noSteps = $this->validator->validate([
            'package_key' => 'facebook.flow2',
            'kind' => 'workflow',
            'name' => 'Empty',
            'version' => '1.0.0',
            'schema_version' => 1,
            'manifest' => ['steps' => []],
            'dependencies' => [],
        ]);
        self::assertFalse($noSteps['ok']);
        self::assertContains('workflow_steps_required', $noSteps['errors']);
    }

    public function test_invalid_dependency_shape_rejected(): void
    {
        $result = $this->validator->validate($this->basePayload([
            'dependencies' => [['package_key' => 'x']],
        ]));
        self::assertFalse($result['ok']);
        self::assertTrue(
            (bool) array_filter(
                $result['errors'],
                static fn (string $e): bool => str_starts_with($e, 'dependency_invalid:')
            )
        );
    }
}
