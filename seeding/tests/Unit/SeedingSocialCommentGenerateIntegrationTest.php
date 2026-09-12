<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Http\Controllers\SeedingCommentGenerateController;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Social\Ai\Exceptions\SocialAiException;
use Omnichannel\Addons\Social\Ai\Services\SocialAiExecutionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeedingSocialCommentGenerateIntegrationTest extends TestCase
{
    private function seedingAddonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_seeding_comment_generate_service_has_social_ai_delegation(): void
    {
        $serviceFile = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentGenerateService::class))->getFileName()
        );

        self::assertStringContainsString('SocialAiExecutionService', $serviceFile);
        self::assertStringContainsString('SeedingSocialContextResolver', $serviceFile);
        self::assertStringContainsString('generateFromPayload', $serviceFile);
        self::assertStringContainsString('public const HOOK_KEY = \'seeding.comment_generate\'', $serviceFile);
        self::assertStringContainsString('public function generate(', $serviceFile);
    }

    public function test_seeding_controller_supports_new_payload_contracts(): void
    {
        $controllerFile = (string) file_get_contents(
            $this->seedingAddonRoot().'/src/Http/Controllers/SeedingCommentGenerateController.php'
        );

        // Supports new payload contracts
        self::assertStringContainsString('source_type', $controllerFile);
        self::assertStringContainsString('content', $controllerFile);
        self::assertStringContainsString('url', $controllerFile);
        self::assertStringContainsString('social', $controllerFile);
        self::assertStringContainsString('quantity', $controllerFile);
        // Supports legacy backward-compatible keys
        self::assertStringContainsString('full_text', $controllerFile);
        self::assertStringContainsString('social_url', $controllerFile);
        self::assertStringContainsString('count', $controllerFile);
        // Error handling returns 422 with message
        self::assertStringContainsString('generateFromPayload', $controllerFile);
        self::assertStringContainsString('response()->json', $controllerFile);
        self::assertStringContainsString('422', $controllerFile);
    }

    public function test_append_link_concern_is_strictly_separated_from_ai_generation(): void
    {
        $seedGenerateJs = (string) file_get_contents(
            $this->seedingAddonRoot().'/resources/js/seeding/services/seedGenerate.js'
        );

        // Verify clean generation payload does not force link into comment prompt
        self::assertStringContainsString('source_type', $seedGenerateJs);
        self::assertStringContainsString('social', $seedGenerateJs);
        self::assertStringContainsString('quantity', $seedGenerateJs);

        // Verify append-link is handled at copy/render time, not by mutating prompt
        self::assertStringContainsString('linkPreviewCache', $seedGenerateJs);
        self::assertStringContainsString('comments.map', $seedGenerateJs);
    }

    public function test_link_pool_and_topic_progress_remain_isolated_from_generation(): void
    {
        $workspaceJs = (string) file_get_contents(
            $this->seedingAddonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );

        // Topic progress is only altered on report, not on generate
        self::assertStringContainsString('runGenerate', $workspaceJs);
        self::assertStringContainsString('persistNow', $workspaceJs);
        // Generation only updates seed_batches and seed_outputs
        self::assertStringContainsString('seed_batches: nextBatches', $workspaceJs);
        self::assertStringContainsString('seed_outputs: nextOutputs', $workspaceJs);
        // Link pool drawer is separate
        self::assertStringContainsString('data-drawer="link-pool"', $workspaceJs);
    }
}
