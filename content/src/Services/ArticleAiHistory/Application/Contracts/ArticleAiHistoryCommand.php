<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Contracts;

/**
 * Marker contract cho Application command DTO của Article AI History.
 * Thin — dùng để Agent tái sử dụng sau, KHÔNG đăng ký trên MCP/ContentProjectCapabilityRegistry.
 */
interface ArticleAiHistoryCommand
{
    public function name(): string;
}
