<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\AiPromptServiceProvider;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use ReflectionClass;
use Tests\TestCase;

final class PromptHookDefinitionLoaderContainerTest extends TestCase
{
    public function test_ai_prompt_provider_owns_loader_factory_binding(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AiPromptServiceProvider::class))->getFileName(),
        );
        self::assertStringContainsString('registerPromptHookRuntimeBindings', $source);
        self::assertStringContainsString('PromptHookDefinitionLoader::defaultV01Directory()', $source);
        self::assertStringContainsString('PromptHookDefinitionLoader::defaultPhase1Directory()', $source);

        $this->app->register(AiPromptServiceProvider::class);

        self::assertTrue($this->app->bound(PromptHookDefinitionLoader::class));
        $loader = $this->app->make(PromptHookDefinitionLoader::class);
        self::assertInstanceOf(PromptHookDefinitionLoader::class, $loader);
        self::assertNotSame([], $loader->loadAll());
        self::assertInstanceOf(
            PromptHookRuntimeRegistry::class,
            $this->app->make(PromptHookRuntimeRegistry::class),
        );
        self::assertInstanceOf(
            PromptHookEditorCatalog::class,
            $this->app->make(PromptHookEditorCatalog::class),
        );
    }
}
