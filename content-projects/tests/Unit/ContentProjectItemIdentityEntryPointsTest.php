<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Regression lock: every write/generation entry point shares ContentProjectItemIdentity
 * and outline hook no longer marks post_title absolutely required.
 */
final class ContentProjectItemIdentityEntryPointsTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_entry_points_reference_canonical_identity(): void
    {
        $files = [
            'Support/SeoProjectTaskSyncDataNormalizer.php',
            'Services/TaskTestInputResolver.php',
            'Services/CreateArticlesFromTaskService.php',
            'Services/ContentProject/Application/Handlers/AddContentProjectItemsHandler.php',
            'Services/ContentProject/Application/Handlers/UpdateContentProjectItemHandler.php',
            'Services/ContentProject/ContentProjectStepSourceValidator.php',
            'Services/ContentProject/Application/Support/ContentProjectRerunEligibilityGuard.php',
            'Filament/Resources/SeoProjectResource.php',
        ];

        foreach ($files as $rel) {
            $path = $this->resolveLegacyOrMovedAddonPath($rel);
            self::assertFileExists($path, $rel);
            $src = (string) file_get_contents($path);
            self::assertStringContainsString(
                'ContentProjectItemIdentity',
                $src,
                $rel.' must use ContentProjectItemIdentity',
            );
            self::assertStringNotContainsString(
                "\$row['title'] ?? \$row['keyword']",
                $src,
                $rel.' must not cross-fill title from keyword',
            );
        }
    }

    public function test_add_items_handler_does_not_cross_fill_keyword_from_title(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/AddContentProjectItemsHandler.php';
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString("\$row['keyword'] ?? \$row['title']", $src);
        self::assertStringNotContainsString("\$row['title'] ?? \$row['keyword']", $src);
    }

    public function test_outline_hook_schema_uses_require_any_of_not_absolute_post_title(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $indexed = $loader->indexed();
        self::assertArrayHasKey('article.outline.generate@0.1.0', $indexed);
        $def = $indexed['article.outline.generate@0.1.0'];

        self::assertFalse((bool) ($def->inputSchema->fields['post_title']['required'] ?? true));
        self::assertFalse((bool) ($def->inputSchema->fields['keyword']['required'] ?? true));
        self::assertSame(
            [['post_title', 'keyword']],
            $def->metadata['require_any_of'] ?? null,
        );
    }

    public function test_step_source_validator_outline_uses_topic_identity(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectStepSourceValidator.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("'topic_identity'", $src);
        self::assertStringNotContainsString(
            "ArticleOutlineGenerate => ['title', 'keyword']",
            $src,
        );
    }

    public function test_explicit_binding_enforces_require_any_of(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Runtime/PromptHookExplicitBindingExecutor.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('PromptHookRequireAnyOf::assertSatisfied', $src);
        self::assertStringContainsString('enrichTopicInput', $src);
        self::assertStringContainsString('seedEmptyPostTitleFromSubject', $src);
        self::assertStringContainsString('expandCompileAliasMirrors', $src);
        self::assertStringContainsString("array_key_exists('topic', \$fields)", $src);
        self::assertStringContainsString("unset(\$input['topic'])", $src);
    }
}
