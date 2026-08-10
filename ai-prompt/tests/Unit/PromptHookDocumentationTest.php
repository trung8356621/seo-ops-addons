<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Tests\Support\ProjectRoot;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookManifestLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookRegistry;
use PHPUnit\Framework\TestCase;

final class PromptHookDocumentationTest extends TestCase
{
    private function registry(): PromptHookRegistry
    {
        $registry = new PromptHookRegistry(
            new PromptHookManifestLoader(PromptHookManifestLoader::defaultDirectory(), true),
        );
        $registry->clearCache();

        return $registry;
    }

    private function repoRoot(): string
    {
        return ProjectRoot::path();
    }

    public function test_each_manifest_has_unique_documentation_file(): void
    {
        $seenPaths = [];
        $root = $this->repoRoot();

        foreach ($this->registry()->all() as $definition) {
            $docPath = $definition->documentationPath();
            self::assertNotNull($docPath, "Hook [{$definition->key}] missing documentation.path");

            $absolute = $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $docPath);
            self::assertFileExists($absolute, "Doc missing for [{$definition->key}]: {$docPath}");

            self::assertArrayNotHasKey(
                $docPath,
                $seenPaths,
                "Documentation path shared by [{$seenPaths[$docPath]}] and [{$definition->key}]",
            );
            $seenPaths[$docPath] = $definition->key;

            $contents = (string) file_get_contents($absolute);
            self::assertMatchesRegularExpression(
                '/^---\s*\n(?:.*\n)*?hook_key:\s*'.preg_quote($definition->key, '/').'\s*\n/s',
                $contents,
                "Doc front matter hook_key mismatch for [{$definition->key}]",
            );
            self::assertMatchesRegularExpression(
                '/^---\s*\n(?:.*\n)*?version:\s*'.$definition->version.'\s*\n/s',
                $contents,
                "Doc front matter version mismatch for [{$definition->key}]",
            );
            self::assertStringContainsString($definition->key, $contents);
        }

        self::assertCount(2, $seenPaths);
    }

    public function test_manifest_locale_keys_exist_in_lang_files(): void
    {
        $langRoot = LegacyAddonPath::resolve('lang');
        $locales = ['vi', 'en'];

        foreach ($this->registry()->all() as $definition) {
            $keys = [
                $definition->labelKey,
                $definition->descriptionKey,
            ];
            $templateKey = is_array($definition->template)
                ? trim((string) ($definition->template['template_key'] ?? ''))
                : '';
            if ($templateKey !== '') {
                $keys[] = $templateKey;
            }
            foreach ($definition->settings as $schema) {
                if (is_array($schema) && isset($schema['label_key'])) {
                    $keys[] = (string) $schema['label_key'];
                }
            }

            foreach ($locales as $locale) {
                $file = $langRoot.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'prompt_hooks.php';
                self::assertFileExists($file);
                /** @var array<string, mixed> $translations */
                $translations = require $file;

                foreach ($keys as $fullKey) {
                    self::assertStringStartsWith('prompt_hooks.', $fullKey);
                    $relative = substr($fullKey, strlen('prompt_hooks.'));
                    self::assertTrue(
                        $this->arrayHasDotPath($translations, $relative),
                        "Missing locale [{$locale}] key [{$fullKey}]",
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function arrayHasDotPath(array $data, string $path): bool
    {
        $cursor = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }

        return true;
    }
}
