<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordIntentClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordScoringService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordToContentProjectConverter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KeywordIntelligenceFoundationTest extends TestCase
{
    public function test_normalization_trims_punctuation_keeps_vietnamese(): void
    {
        $svc = new KeywordNormalizationService;

        // Use Unicode escapes so FTP/source encoding cannot corrupt expectations.
        // dá»‹ch=d+á»‹, vá»¥=v+á»¥, tá»•ng=t+á»•+ng, thá»ƒ=th+á»ƒ
        $input = "  - D\u{1ECB}ch v\u{1EE5} SEO t\u{1ED5}ng th\u{1EC3}:  ";
        $expectedNormalized = "d\u{1ECB}ch v\u{1EE5} seo t\u{1ED5}ng th\u{1EC3}";
        $expectedDisplay = "D\u{1ECB}ch v\u{1EE5} SEO t\u{1ED5}ng th\u{1EC3}";

        self::assertSame($expectedNormalized, $svc->normalize($input));
        self::assertSame($expectedDisplay, $svc->displayKeyword($input));

        $display = $svc->displayKeyword($expectedNormalized);
        self::assertStringContainsString("\u{1ECB}", $display); // á»‹ in dá»‹ch
        self::assertStringContainsString("\u{1EC3}", $display); // á»ƒ in thá»ƒ
        self::assertNotSame('dich vu seo tong the', $svc->normalize($input));
    }

    public function test_near_duplicate_does_not_merge_different_entities(): void
    {
        $svc = new KeywordNormalizationService;
        self::assertFalse($svc->isNearDuplicate(
            $svc->normalize("seo l\u{00E0} g\u{00EC}"),
            $svc->normalize("d\u{1ECB}ch v\u{1EE5} seo"),
        ));
    }

    public function test_intent_classifier_local_commercial(): void
    {
        $classifier = new KeywordIntentClassifier;
        // ASCII markers already in classifier lists â€” no UTF-8 source dependency.
        $result = $classifier->classify('dich vu seo tphcm', 'dich vu seo tphcm');
        self::assertContains($result['primary'], [
            KeywordSearchIntent::Local,
            KeywordSearchIntent::Mixed,
            KeywordSearchIntent::Commercial,
        ]);
        self::assertGreaterThan(0.5, $result['confidence']);
        self::assertSame('rule', $result['source']);
    }

    public function test_scoring_returns_factors_without_external_metrics(): void
    {
        $scoring = new KeywordScoringService;
        $result = $scoring->score([
            'relevance' => 80,
            'business_value' => 70,
            'intent' => KeywordSearchIntent::Commercial->value,
            'has_existing_coverage' => false,
        ]);

        self::assertArrayHasKey('priority_score', $result);
        self::assertArrayHasKey('score_factors', $result);
        self::assertNotEmpty($result['score_factors']);
        self::assertLessThan(0.8, $result['confidence']);
    }

    public function test_public_ref_roundtrip_rejects_numeric(): void
    {
        $ref = KeywordIntelligencePublicRef::workspace(42);
        self::assertSame(42, KeywordIntelligencePublicRef::decodeWorkspace($ref));
        self::assertSame(42, KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($ref));

        $this->expectException(\InvalidArgumentException::class);
        KeywordIntelligencePublicRef::resolveWorkspaceIdStrict('42');
    }

    public function test_converter_uses_command_bus_create_project(): void
    {
        $path = ProjectRoot::addonsPath().'/search-intelligence/src/Services/KeywordIntelligence/KeywordToContentProjectConverter.php';
        if (! is_file($path)) {
            self::markTestSkipped('Converter missing');
        }

        $source = (string) file_get_contents($path);
        self::assertStringContainsString('CreateContentProjectCommand', $source);
        self::assertStringContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('gallery_description', $source);
    }

    public function test_architecture_handlers_folder_avoids_wordpress_builtin_if_present(): void
    {
        $dir = ProjectRoot::addonsPath().'/search-intelligence/src/Services/KeywordIntelligence/Application/Handlers';
        if (! is_dir($dir)) {
            self::markTestSkipped('Handlers not present yet');
        }

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('Extension\\Builtin\\Wordpress', $source, basename($file));
            self::assertStringNotContainsString('WordPressContentPublisher', $source, basename($file));
        }
    }

    /**
     * @return array<string, class-string<ContentProjectCommand>>
     */
    public static function commandClassesProvider(): array
    {
        $ns = 'Omnichannel\\Addons\\SearchIntelligence\\Services\\KeywordIntelligence\\Application\\Commands\\';

        return [
            'create_workspace' => [$ns.'CreateKeywordWorkspaceCommand', 'keyword_intelligence.create_workspace'],
            'import_keywords' => [$ns.'ImportKeywordsCommand', 'keyword_intelligence.import_keywords'],
            'analyze_workspace' => [$ns.'AnalyzeKeywordWorkspaceCommand', 'keyword_intelligence.analyze_workspace'],
            'analyze_keywords' => [$ns.'AnalyzeSelectedKeywordsCommand', 'keyword_intelligence.analyze_keywords'],
            'cancel_analysis' => [$ns.'CancelKeywordAnalysisCommand', 'keyword_intelligence.cancel_analysis'],
            'approve_keywords' => [$ns.'ApproveKeywordsCommand', 'keyword_intelligence.approve_keywords'],
            'exclude_keywords' => [$ns.'ExcludeKeywordsCommand', 'keyword_intelligence.exclude_keywords'],
            'update_keyword' => [$ns.'UpdateKeywordClassificationCommand', 'keyword_intelligence.update_keyword'],
            'approve_clusters' => [$ns.'ApproveKeywordClustersCommand', 'keyword_intelligence.approve_clusters'],
            'merge_clusters' => [$ns.'MergeKeywordClustersCommand', 'keyword_intelligence.merge_clusters'],
            'split_cluster' => [$ns.'SplitKeywordClusterCommand', 'keyword_intelligence.split_cluster'],
            'move_keywords' => [$ns.'MoveKeywordsToClusterCommand', 'keyword_intelligence.move_keywords'],
            'review_cannibalization' => [$ns.'ReviewCannibalizationIssueCommand', 'keyword_intelligence.review_cannibalization'],
            'build_topical_map' => [$ns.'BuildTopicalMapCommand', 'keyword_intelligence.build_topical_map'],
            'preview_convert' => [$ns.'PreviewContentProjectFromClustersCommand', 'keyword_intelligence.preview_convert'],
            'convert_to_content_project' => [$ns.'CreateContentProjectFromKeywordClustersCommand', 'keyword_intelligence.convert_to_content_project'],
            'archive_workspace' => [$ns.'ArchiveKeywordWorkspaceCommand', 'keyword_intelligence.archive_workspace'],
        ];
    }

    public function test_all_keyword_intelligence_commands_implement_content_project_command_contract(): void
    {
        foreach (self::commandClassesProvider() as $name => [$class, $expectedName]) {
            self::assertTrue(class_exists($class), "{$name}: {$class} must exist.");
            self::assertContains(ContentProjectCommand::class, class_implements($class) ?: [], "{$name} must implement ContentProjectCommand.");

            $ref = new ReflectionClass($class);
            $ctor = $ref->getConstructor();
            self::assertNotNull($ctor, "{$name} must declare a constructor.");

            // name() is a pure capability string â€” instantiate via reflection is unnecessary;
            // assert the method exists and returns the expected literal from source.
            $source = (string) file_get_contents((string) $ref->getFileName());
            self::assertStringContainsString("'{$expectedName}'", $source, "{$name} must declare name() => '{$expectedName}'.");
        }
    }

    public function test_all_keyword_intelligence_handlers_implement_command_handler_contract(): void
    {
        $dir = ProjectRoot::addonsPath().'/search-intelligence/src/Services/KeywordIntelligence/Application/Handlers';
        self::assertDirectoryExists($dir);

        $files = glob($dir.'/*.php') ?: [];
        self::assertNotEmpty($files, 'Expected Keyword Intelligence Handlers directory to contain handler files.');

        foreach ($files as $file) {
            $basename = basename($file, '.php');
            if ($basename === 'AbstractKeywordIntelligenceHandler') {
                continue;
            }

            $class = 'Omnichannel\\Addons\\SearchIntelligence\\Services\\KeywordIntelligence\\Application\\Handlers\\'.$basename;
            self::assertTrue(class_exists($class), "{$basename} must be autoloadable.");
            self::assertContains(
                ContentProjectCommandHandler::class,
                class_implements($class) ?: [],
                "{$basename} must implement ContentProjectCommandHandler.",
            );
        }
    }

    public function test_converter_dispatches_through_content_project_command_bus_with_create_content_project_command(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(KeywordToContentProjectConverter::class))->getFileName(),
        );

        self::assertStringContainsString('CreateContentProjectCommand', $source);
        self::assertStringContainsString('ContentProjectCommandBus', $source);
        self::assertStringContainsString('$bus->dispatch(', $source);
        // No gallery_description leakage into keyword-driven task rows.
        self::assertStringNotContainsString('gallery_description', $source);
    }

    public function test_registrar_wires_every_keyword_intelligence_command_to_a_handler(): void
    {
        $registrarPath = ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php';
        self::assertFileExists($registrarPath);

        $source = (string) file_get_contents($registrarPath);

        foreach (self::commandClassesProvider() as $name => [$class]) {
            $short = substr((string) strrchr($class, '\\'), 1);
            self::assertStringContainsString($short.'::class', $source, "Registrar must map {$name} ({$short}).");
        }
    }

    public function test_keyword_intelligence_capabilities_are_registered_in_capability_registry(): void
    {
        $registryPath = ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Capabilities/ContentProjectCapabilityRegistry.php';
        self::assertFileExists($registryPath);

        $source = (string) file_get_contents($registryPath);

        foreach (self::commandClassesProvider() as [, $expectedName]) {
            self::assertStringContainsString("'{$expectedName}'", $source, "Capability registry must expose {$expectedName}.");
        }
    }
}
