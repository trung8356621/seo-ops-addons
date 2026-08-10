<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Http\Controllers\PromptHookExecuteController;
use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookExecutionResult;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookExecutionService;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Illuminate\Http\JsonResponse;
use Mockery;
use PHPUnit\Framework\TestCase;

final class PromptHookExecuteControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_success_response_shape_without_persist_side_effects(): void
    {
        $execution = Mockery::mock(PromptHookExecutionService::class);
        $execution->shouldReceive('execute')
            ->once()
            ->with('article.title_suggestion', 123, ['keyword' => 'kw', 'old_title' => null])
            ->andReturn(new PromptHookExecutionResult(
                hook: 'article.title_suggestion',
                output: [
                    'format' => 'text',
                    'raw' => 'Title',
                    'value' => 'Title',
                ],
                promptResultId: 99,
            ));

        $controller = new PromptHookExecuteController($execution);
        $response = $controller->executeHook(
            'article.title_suggestion',
            123,
            ['keyword' => 'kw', 'old_title' => null],
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);
        self::assertTrue($payload['success']);
        self::assertSame('article.title_suggestion', $payload['data']['hook']);
        self::assertSame('Title', $payload['data']['output']['value']);
        self::assertSame('text', $payload['data']['output']['format']);
        // API không trả prompt_result_id / stack / connection secrets
        self::assertArrayNotHasKey('prompt_result_id', $payload['data']);
        self::assertArrayNotHasKey('connection', $payload);
    }

    public function test_maps_prompt_not_configured(): void
    {
        $execution = Mockery::mock(PromptHookExecutionService::class);
        $execution->shouldReceive('execute')
            ->once()
            ->andThrow(new PromptHookException(
                PromptHookErrorCode::HookPromptNotConfigured,
                'No prompt configured.',
            ));

        $controller = new PromptHookExecuteController($execution);
        $response = $controller->executeHook('article.title_suggestion', 1, []);

        self::assertSame(422, $response->getStatusCode());
        $payload = $response->getData(true);
        self::assertFalse($payload['success']);
        self::assertSame('HOOK_PROMPT_NOT_CONFIGURED', $payload['error']);
        self::assertSame('No prompt configured.', $payload['message']);
    }

    public function test_maps_article_forbidden(): void
    {
        $execution = Mockery::mock(PromptHookExecutionService::class);
        $execution->shouldReceive('execute')
            ->once()
            ->andThrow(new PromptHookException(
                PromptHookErrorCode::HookArticleForbidden,
                'Forbidden.',
            ));

        $controller = new PromptHookExecuteController($execution);
        $response = $controller->executeHook('article.meta_description_suggestion', 9, []);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('HOOK_ARTICLE_FORBIDDEN', $response->getData(true)['error']);
    }

    public function test_empty_hook_key_is_not_found(): void
    {
        $execution = Mockery::mock(PromptHookExecutionService::class);
        $execution->shouldNotReceive('execute');

        $controller = new PromptHookExecuteController($execution);
        $response = $controller->executeHook('  ', 1, []);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('HOOK_NOT_FOUND', $response->getData(true)['error']);
    }
}
