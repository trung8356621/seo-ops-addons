<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\Media\Services\GeminiMediaGenerationService;
use Omnichannel\Addons\AiPrompt\Services\PromptMediaStorageService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Tests\TestCase;

final class PromptMediaStorageServiceTest extends TestCase
{
    public function test_prompt_media_storage_resolves_as_singleton(): void
    {
        $fromJob = app(PromptMediaStorageService::class);
        $fromRunner = app(PromptRunnerService::class);
        $fromGemini = app(GeminiMediaGenerationService::class);

        $this->assertSame($fromJob, app(PromptMediaStorageService::class));

        $runnerStorage = (new \ReflectionClass($fromRunner))
            ->getProperty('promptMediaStorage');
        $runnerStorage->setAccessible(true);

        $geminiStorage = (new \ReflectionClass($fromGemini))
            ->getProperty('promptMediaStorage');
        $geminiStorage->setAccessible(true);

        $this->assertSame($fromJob, $runnerStorage->getValue($fromRunner));
        $this->assertSame($fromJob, $geminiStorage->getValue($fromGemini));
    }
}
