<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceDeepLink;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Filament\Pages\AgentWorkspaceRedirect;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentDeepLinkTest extends TestCase
{
    public function test_missing_site_message_constant(): void
    {
        self::assertStringContainsString('chọn website', AgentWorkspaceDeepLink::MISSING_SITE_MESSAGE);
    }

    public function test_filter_params_only_keeps_non_empty_strings_via_source(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceDeepLink::class))->getFileName(),
        );

        self::assertStringContainsString('project_ref', $source);
        self::assertStringContainsString('conversation', $source);
        self::assertStringContainsString('skill', $source);
        self::assertStringContainsString('template', $source);
        self::assertStringContainsString('tryUrl', $source);
        self::assertStringContainsString('forCurrentRequest', $source);
        self::assertStringContainsString('resolveConnectionHash', $source);
        self::assertStringNotContainsString('SeoDatabaseConnection::query()->first', $source);
        self::assertStringNotContainsString('orderByRaw', $source);
    }

    public function test_infers_project_ref_from_content_projects_path_pattern(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceDeepLink::class))->getFileName(),
        );

        self::assertStringContainsString('content-projects/', $source);
        self::assertStringContainsString('ContentProjectPublicRef::project', $source);
        self::assertStringContainsString('globalContentProjectId', $source);
    }

    public function test_project_public_ref_helper_exists(): void
    {
        $ref = ContentProjectPublicRef::project(1);
        self::assertStringStartsWith('cpj_', $ref);
    }

    public function test_admin_redirect_uses_deep_link_try_url(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceRedirect::class))->getFileName(),
        );

        self::assertStringContainsString('AgentWorkspaceDeepLink::tryUrl', $source);
        self::assertStringContainsString('MISSING_SITE_MESSAGE', $source);
        self::assertStringNotContainsString('url(\'/seo\')', $source);
    }
}
