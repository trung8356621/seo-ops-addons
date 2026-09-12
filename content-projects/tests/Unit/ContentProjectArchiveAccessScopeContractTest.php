<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchiveAccessScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectArchiveAccessScopeContractTest extends TestCase
{
    public function test_vault_list_uses_access_scope_not_unconditional_null_site(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchive::class))->getFileName());

        self::assertStringContainsString('ContentProjectArchiveAccessScope', $source);
        self::assertStringContainsString('constrainQuery', $source);
        self::assertStringContainsString('constrainQueryToSiteFilter', $source);
        self::assertStringNotContainsString("orWhereNull('site_id')", $source);
        self::assertStringNotContainsString("orWhere('site_id', 0)", $source);
    }

    public function test_preview_filters_rows_via_access_scope(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchivePreview::class))->getFileName());

        self::assertStringContainsString('ContentProjectArchiveAccessScope', $source);
        self::assertStringContainsString('filterPresenterRows', $source);
        self::assertStringContainsString('userCanAccessArchive', $source);
    }

    public function test_access_scope_documents_item_based_multi_domain_rule(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchiveAccessScope::class))->getFileName());

        self::assertStringContainsString('Never use an unconditional null site_id OR', $source);
        self::assertStringContainsString('JSON_EXTRACT(cai.article_snapshot', $source);
        self::assertStringContainsString('filterPresenterRows', $source);
    }
}
