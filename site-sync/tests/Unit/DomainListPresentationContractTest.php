<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DomainListPresentationContractTest extends TestCase
{
    public function test_legacy_website_type_labels(): void
    {
        self::assertSame('Manufacturer', DomainListPresentation::websiteTypeLabel('production'));
        self::assertSame('Manufacturer', DomainListPresentation::websiteTypeLabel('manufacturer'));
        self::assertSame('Ecommerce', DomainListPresentation::websiteTypeLabel('e-commerce'));
        self::assertSame('Ecommerce', DomainListPresentation::websiteTypeLabel('ecommerce'));
        self::assertSame('News', DomainListPresentation::websiteTypeLabel('news'));
        self::assertSame('Manufacturer', DomainListPresentation::websiteTypeFormOptions()['production']);
        self::assertSame('Ecommerce', DomainListPresentation::websiteTypeFormOptions()['e-commerce']);
        self::assertArrayNotHasKey('manufacturer', DomainListPresentation::websiteTypeFormOptions());
    }

    public function test_domain_table_columns_drop_tone_cta_and_add_ops_columns(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(DomainResource::class))->getFileName());

        self::assertStringNotContainsString("TextColumn::make('domain_tone')", $src);
        self::assertStringNotContainsString("TextColumn::make('domain_cta')", $src);
        self::assertStringContainsString("TextColumn::make('seo_platform')", $src);
        self::assertStringContainsString("TextColumn::make('seo_domain_type')", $src);
        self::assertStringContainsString("ViewColumn::make('bridge_version')", $src);
        self::assertStringContainsString("TextColumn::make('sync_status')", $src);
        self::assertStringContainsString("TextColumn::make('last_sync')", $src);
        self::assertStringContainsString("ViewColumn::make('is_main')", $src);
        self::assertStringContainsString('DomainListPresentation::websiteTypeLabel', $src);
        self::assertStringContainsString('ActionGroup::make', $src);
        self::assertStringContainsString('recordUrl', $src);
        self::assertStringContainsString("getUrl('general'", $src);
        self::assertStringNotContainsString("getUrl('edit'", $src);
        self::assertStringContainsString("Action::make('overview')", $src);
        self::assertStringContainsString("Action::make('set_as_main')", $src);
        self::assertStringContainsString('DeleteAction::make()', $src);
    }
}
