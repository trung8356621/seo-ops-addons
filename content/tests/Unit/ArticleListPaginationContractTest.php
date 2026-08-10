<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

final class ArticleListPaginationContractTest extends TestCase
{
    public function test_article_table_default_per_page_is_30_without_all_option(): void
    {
        $resource = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php';
        $source = (string) file_get_contents($resource);

        self::assertStringContainsString('->defaultPaginationPageOption(30)', $source);
        self::assertStringContainsString('->paginationPageOptions([10, 30, 50, 100])', $source);
        self::assertDoesNotMatchRegularExpression(
            "/paginationPageOptions\\(\\[[^\\]]*['\"]all['\"]/",
            $source,
        );
    }

    public function test_list_articles_uses_get_per_page_not_session(): void
    {
        $page = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/ListArticles.php';
        $source = (string) file_get_contents($page);

        self::assertStringContainsString("DEFAULT_RECORDS_PER_PAGE = 30", $source);
        self::assertStringContainsString("'as' => 'perPage'", $source);
        self::assertStringContainsString('function updatedTableRecordsPerPage', $source);
        self::assertStringContainsString('function getDefaultTableRecordsPerPageSelectOption', $source);
        self::assertStringContainsString('session()->forget($this->getTablePerPageSessionKey())', $source);
        self::assertStringContainsString("request()->query('perPage')", $source);
        self::assertStringNotContainsString('session()->put([', $source);
    }
}
