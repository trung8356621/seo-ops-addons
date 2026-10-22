<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Artifacts\ArtifactAcceptPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AttemptFencePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\WorkflowOutputMapper;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use PHPUnit\Framework\TestCase;

final class ContractFixturesTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'contracts';
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $name): array
    {
        $path = $this->fixtures.DIRECTORY_SEPARATOR.$name;
        self::assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);

        return $data;
    }

    public function test_package_publish_success_fixture(): void
    {
        $fx = $this->load('01_package_publish_success.request.json');
        $result = (new PackagePublishValidator)->validate($fx['body']);
        self::assertTrue($result['ok']);
        $expected = $this->load('01_package_publish_success.response.json');
        self::assertSame('created', $expected['outcome']);
        self::assertTrue(isset($result['data']['content_digest']));
    }

    public function test_package_version_conflict_fixture_shape(): void
    {
        $fx = $this->load('02_package_version_conflict.response.json');
        self::assertSame('conflict', $fx['outcome']);
        self::assertContains('version_digest_conflict', $fx['errors']);
        self::assertSame(409, $fx['http_status']);
    }

    public function test_script_publish_fixture(): void
    {
        $fx = $this->load('12_script_publish_success.request.json');
        $result = (new PackagePublishValidator)->validate($fx['body']);
        self::assertTrue($result['ok'], json_encode($result['errors'] ?? []));
    }

    public function test_widget_form_fixture(): void
    {
        $fx = $this->load('13_widget_form_over_script.request.json');
        $result = (new PackagePublishValidator)->validate($fx['body']);
        self::assertTrue($result['ok'], json_encode($result['errors'] ?? []));
    }

    public function test_workflow_multi_widget_fixture(): void
    {
        $fx = $this->load('03_workflow_sequential_widgets.request.json');
        $result = (new PackagePublishValidator)->validate($fx['body']);
        self::assertTrue($result['ok']);

        $widgetKey = 'facebook.group.post';
        $priorOutputs = [
            $widgetKey => [
                'post_url' => 'https://example.com/p/1',
            ],
        ];

        $mapper = new WorkflowOutputMapper;
        $built = $mapper->buildRunnerPayload(
            $fx['body']['manifest']['steps'],
            ['campaign' => 'demo'],
            $priorOutputs
        );
        self::assertTrue($built['ok']);
        self::assertSame('https://example.com/p/1', $built['steps'][1]['input']['url']);
    }

    public function test_runner_register_heartbeat_fixtures(): void
    {
        $reg = $this->load('04_runner_register.request.json');
        self::assertNotEmpty($reg['body']['device_id']);
        $hb = $this->load('04_runner_heartbeat.request.json');
        self::assertArrayHasKey('availability', $hb['body']);
    }

    public function test_session_sync_fixtures(): void
    {
        $ok = $this->load('05_session_sync_multi_accounts.request.json');
        self::assertCount(2, $ok['body']['sessions'][0]['accounts']);
        self::assertTrue($ok['body']['sessions'][0]['accounts'][0]['verified']);

        $weak = $this->load('06_session_sync_missing_identity.request.json');
        self::assertFalse($weak['body']['sessions'][0]['accounts'][0]['verified']);
        self::assertArrayNotHasKey('platform_account_id', $weak['body']['sessions'][0]['accounts'][0]);
    }

    public function test_job_claim_success_failure_stale_duplicate_fixtures(): void
    {
        $claim = $this->load('07_job_claim.response.json');
        self::assertTrue($claim['ok']);
        self::assertNotEmpty($claim['attempt']['attempt_uuid']);

        $success = $this->load('08_job_success.request.json');
        self::assertSame('completed', $success['body']['status']);
        self::assertNotEmpty($success['body']['attempt_uuid']);

        $fail = $this->load('09_job_failure_screenshot.request.json');
        self::assertSame('failed', $fail['body']['status']);
        $shot = $fail['artifact'];
        $policy = new ArtifactAcceptPolicy;
        $check = $policy->validateScreenshotMetadata('failed', $shot['metadata']);
        self::assertTrue($check['ok']);

        $stale = $this->load('10_stale_result.response.json');
        self::assertContains(AttemptFencePolicy::ERR_STALE, $stale['errors']);
        self::assertSame(409, $stale['http_status']);

        $dup = $this->load('11_duplicate_request.response.json');
        self::assertTrue($dup['ok']);
        self::assertTrue($dup['idempotent']);
    }
}
