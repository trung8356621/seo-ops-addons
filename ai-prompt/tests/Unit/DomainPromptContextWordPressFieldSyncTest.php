<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\DomainPromptContextWordPressFieldSyncService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\AiPrompt\Services\WordPressFieldSyncAccessGate;
use Omnichannel\Addons\SiteSync\Services\Profile\WordPressSiteProfileReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DomainPromptContextWordPressFieldSyncTest extends TestCase
{
    public function test_service_wires_concrete_dependencies_without_container_bindings(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DomainPromptContextWordPressFieldSyncService::class))->getFileName(),
        );

        self::assertStringContainsString(WordPressSiteProfileReader::class, $source);
        self::assertStringContainsString('SiteDomainPromptContextService $contextService', $source);
        self::assertStringContainsString('WordPressFieldSyncAccessGate $accessGate', $source);
        self::assertStringContainsString('WordPress chưa có Tiêu đề trang web.', $source);
        self::assertStringContainsString('WordPress chưa có Dòng mô tả.', $source);
        self::assertStringContainsString('Không thể đọc thông tin website từ WordPress.', $source);
        self::assertStringContainsString('patchForSite', $source);
        self::assertStringContainsString('clampCompanyShortIdentity', $source);
    }

    public function test_ai_prompt_provider_registers_profile_reader_binding(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/AiPromptServiceProvider.php',
        );

        self::assertStringContainsString(WordPressSiteProfileReader::class, $source);
        self::assertStringContainsString('WordPressSiteProfileSource::class', $source);
    }
}
