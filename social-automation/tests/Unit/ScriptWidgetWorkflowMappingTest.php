<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\WidgetInputComposer;
use Omnichannel\Addons\SocialAutomation\Domain\WorkflowJobComposer;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use PHPUnit\Framework\TestCase;

final class ScriptWidgetWorkflowMappingTest extends TestCase
{
    private PackagePublishValidator $validator;

    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PackagePublishValidator;
        $this->fixtures = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'contracts';
    }

    /** @return array<string, mixed> */
    private function load(string $name): array
    {
        $data = json_decode((string) file_get_contents($this->fixtures.DIRECTORY_SEPARATOR.$name), true);
        self::assertIsArray($data);

        return $data;
    }

    public function test_script_release_fixture_validates(): void
    {
        $fx = $this->load('12_script_publish_success.request.json');
        $result = $this->validator->validate($fx['body']);
        self::assertTrue($result['ok'], json_encode($result['errors'] ?? []));
        self::assertSame('script', $result['data']['kind']);
        self::assertArrayHasKey('video_demo', $result['data']['manifest']);
    }

    public function test_script_rejects_executable_top_level_script_field(): void
    {
        $fx = $this->load('12_script_publish_success.request.json');
        $body = $fx['body'];
        $body['manifest']['script'] = 'alert(1)';
        $result = $this->validator->validate($body);
        self::assertFalse($result['ok']);
        self::assertContains('manifest_forbidden_field:script', $result['errors']);
    }

    public function test_widget_form_over_script_requires_dependency(): void
    {
        $fx = $this->load('13_widget_form_over_script.request.json');
        $ok = $this->validator->validate($fx['body']);
        self::assertTrue($ok['ok'], json_encode($ok['errors'] ?? []));

        $missingDep = $fx['body'];
        $missingDep['dependencies'] = [];
        $bad = $this->validator->validate($missingDep);
        self::assertFalse($bad['ok']);
        self::assertContains('widget_script_dependency_required', $bad['errors']);
    }

    public function test_legacy_widget_steps_still_valid(): void
    {
        $fx = $this->load('01_package_publish_success.request.json');
        $result = $this->validator->validate($fx['body']);
        self::assertTrue($result['ok']);
        self::assertSame('widget', $result['data']['kind']);
    }

    public function test_manual_and_ai_auto_share_validated_payload_shape(): void
    {
        $fx = $this->load('13_widget_form_over_script.request.json');
        $manifest = $fx['body']['manifest'];
        $composer = new WidgetInputComposer;

        $manual = $composer->compose($manifest, 'manual', [
            'text' => 'Hello group',
            'group_url' => 'https://facebook.com/groups/123',
        ]);
        self::assertTrue($manual['ok'], json_encode($manual['errors']));

        $ai = $composer->compose(
            $manifest,
            'ai_auto',
            ['group_url' => 'https://facebook.com/groups/123'],
            ['text' => 'AI drafted post'],
        );
        self::assertTrue($ai['ok'], json_encode($ai['errors']));
        self::assertSame($manual['inputs']['group_url'], $ai['inputs']['group_url']);
        self::assertArrayHasKey('text', $ai['inputs']);
    }

    public function test_ai_auto_rejects_identity_field_invention(): void
    {
        $fx = $this->load('13_widget_form_over_script.request.json');
        $composer = new WidgetInputComposer;
        $bad = $composer->compose(
            $fx['body']['manifest'],
            'ai_auto',
            [],
            [
                'text' => 'AI text',
                'group_url' => 'https://facebook.com/groups/guessed',
            ],
        );
        self::assertFalse($bad['ok']);
        self::assertTrue(
            (bool) array_filter(
                $bad['errors'],
                static fn (string $e): bool => str_starts_with($e, 'ai_forbidden_field:') || str_starts_with($e, 'field_required:')
            )
        );
    }

    public function test_workflow_composer_maps_widget_steps(): void
    {
        $wf = $this->load('03_workflow_sequential_widgets.request.json');
        $composer = new WorkflowJobComposer;
        $release = [
            'package_key' => $wf['body']['package_key'],
            'kind' => 'workflow',
            'version' => $wf['body']['version'],
            'content_digest' => str_repeat('ef', 32),
            'manifest' => $wf['body']['manifest'],
            'dependencies' => $wf['body']['dependencies'],
        ];
        $built = $composer->compose(
            'workflow',
            ['campaign' => 'demo'],
            $release,
            ['facebook.group.post' => ['post_url' => 'https://example.com/p/1']],
        );
        self::assertTrue($built['ok'], json_encode($built['errors']));
        self::assertSame('https://example.com/p/1', $built['payload']['workflow']['steps'][1]['input']['url']);
    }

    public function test_script_kind_not_dispatchable_via_composer(): void
    {
        $composer = new WorkflowJobComposer;
        $result = $composer->compose('script', [], [
            'package_key' => 'facebook.group.post.script',
            'kind' => 'script',
            'version' => '1.0.0',
            'manifest' => ['script_id' => 'x'],
        ]);
        self::assertFalse($result['ok']);
        self::assertContains('script_not_dispatchable', $result['errors']);
    }
}
