<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookHttpStatus;
use PHPUnit\Framework\TestCase;

final class PromptHookHttpStatusTest extends TestCase
{
    public function test_maps_error_codes_to_http_status(): void
    {
        self::assertSame(404, PromptHookHttpStatus::for(PromptHookErrorCode::HookNotFound));
        self::assertSame(404, PromptHookHttpStatus::for(PromptHookErrorCode::HookArticleNotFound));
        self::assertSame(403, PromptHookHttpStatus::for(PromptHookErrorCode::HookArticleForbidden));
        self::assertSame(422, PromptHookHttpStatus::for(PromptHookErrorCode::HookPromptNotConfigured));
        self::assertSame(422, PromptHookHttpStatus::for(PromptHookErrorCode::HookPromptMismatch));
        self::assertSame(422, PromptHookHttpStatus::for(PromptHookErrorCode::HookInputInvalid));
        self::assertSame(422, PromptHookHttpStatus::for(PromptHookErrorCode::HookModelUnsupported));
        self::assertSame(422, PromptHookHttpStatus::for(PromptHookErrorCode::HookOutputInvalid));
        self::assertSame(502, PromptHookHttpStatus::for(PromptHookErrorCode::HookExecutionFailed));
        self::assertSame(500, PromptHookHttpStatus::for(PromptHookErrorCode::HookManifestInvalid));
    }
}
