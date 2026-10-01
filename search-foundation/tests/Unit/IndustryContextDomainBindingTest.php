<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IndustryContextDomainBindingTest extends TestCase
{
    public function test_domain_form_and_site_meta_pipeline_keep_industry_context_separate(): void
    {
        $resource = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/DomainResource.php');
        $persistence = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/DomainResource/Pages/Concerns/PersistsSeoDomainMetas.php');
        self::assertStringContainsString("Select::make('seo_industry_context_key')", (string) $resource);
        self::assertStringContainsString("->where('is_active', true)", (string) $resource);
        self::assertStringNotContainsString("Action::make('quick_generate')", (string) $resource);
        self::assertStringNotContainsString("Action::make('copy_prompt')", (string) $resource);
        self::assertStringContainsString("getMeta('seo_industry_context_key')", (string) $persistence);
        self::assertStringContainsString("['meta_key' => 'seo_industry_context_key']", (string) $persistence);
        self::assertStringContainsString("\$data['seo_domain_type'] ?? 'news'", (string) $persistence);
        self::assertStringContainsString("'production'", (string) file_get_contents(dirname(__DIR__, 2).'/src/Support/DomainListPresentation.php'));
    }
}
