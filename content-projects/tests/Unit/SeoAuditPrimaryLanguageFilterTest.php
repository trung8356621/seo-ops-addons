<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditExistingContentSuggestionService;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithSeoAuditSuggestions;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class SeoAuditPrimaryLanguageFilterTest extends TestCase
{
    public function test_suggestion_service_applies_language_before_audit_filters(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(SeoAuditExistingContentSuggestionService::class))->getFileName(),
        );

        $this->assertStringContainsString('applyLanguageFilter', $src);
        $this->assertStringContainsString("where('articles.language'", $src);
        $this->assertStringContainsString('language_scope', $src);
        $this->assertStringContainsString('SitePrimaryLanguageService', $src);
        $this->assertStringContainsString('resolvePrimaryLanguage', $src);
        $this->assertStringContainsString('seedCandidate', $src);

        $applyPos = strpos($src, 'applyLanguageFilter($base');
        $auditPos = strpos($src, 'buildFilteredQuery');
        $this->assertNotFalse($applyPos);
        $this->assertNotFalse($auditPos);
        $this->assertLessThan($auditPos, $applyPos, 'Language filter must run before audit filters');
    }

    public function test_interacts_trait_includes_language_scope_in_filters_and_fill(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(InteractsWithSeoAuditSuggestions::class))->getFileName(),
        );

        $this->assertStringContainsString("public string \$suggestionLanguageScope = 'primary'", $src);
        $this->assertStringContainsString("'language_scope'", $src);
        $this->assertStringContainsString('updatedSuggestionLanguageScope', $src);
        $this->assertStringContainsString('primary_configured', $src);
        $this->assertStringContainsString('primary_language_label', $src);
        $this->assertStringContainsString('buildSuggestionFilters()', $src);

        $fillPos = strpos($src, 'function fillSuggestions');
        $this->assertNotFalse($fillPos);
        $fillChunk = substr($src, $fillPos, 800);
        $this->assertStringContainsString('buildSuggestionFilters()', $fillChunk);
    }

    public function test_planner_blade_has_language_select_without_primary_language_banner(): void
    {
        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-seo-audit-planner.blade.php',
        );
        $draft = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-draft-planner.blade.php',
        );

        $this->assertStringContainsString('wire:model.live="suggestionLanguageScope"', $view);
        $this->assertStringContainsString('suggestions_filter_language_all', $view);
        $this->assertStringNotContainsString('suggestions_primary_language_missing', $view);
        $this->assertStringNotContainsString('planner_site_language_undetected', $draft);
        $this->assertStringNotContainsString('data-planner-warning="primary-language"', $draft);
        $this->assertDoesNotMatchRegularExpression(
            '/<x-select[^>]*\s@disabled/',
            $view,
        );
    }

    public function test_keyword_intelligence_does_not_use_site_primary_language_service(): void
    {
        $root = ProjectRoot::addonsPath().'/search-intelligence/src/Services/KeywordIntelligence';
        $this->assertDirectoryExists($root);

        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'SitePrimaryLanguageService')) {
                $hits[] = $file->getPathname();
            }
        }

        $this->assertSame([], $hits, 'Keyword Intelligence must not globally filter by SitePrimaryLanguageService');
    }
}
