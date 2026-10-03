<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use InvalidArgumentException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Support\CommentSeedingOutputParser;
use PHPUnit\Framework\TestCase;

final class PromptOwnershipModelTest extends TestCase
{
    private function catalog(): PromptHookEditorCatalog
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();

        return new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader));
    }

    private function usePromptHookLocale(string $locale): void
    {
        $repoRoot = dirname((string) (new \ReflectionClass(PromptHookPresentationService::class))->getFileName(), 5);
        $loader = new ArrayLoader;
        $loader->addMessages(
            $locale,
            'prompt_hooks',
            require $repoRoot.'/seo-content-ai-compat/lang/'.$locale.'/prompt_hooks.php',
            'seo-content-ai',
        );

        app()->instance('translator', new Translator($loader, $locale));
    }

    public function test_settings_visible_hooks_include_title_meta_comment_not_gallery(): void
    {
        $keys = array_column($this->catalog()->settingsVisibleHooks(), 'hook_key');

        self::assertContains('article.title_suggestion', $keys);
        self::assertContains('article.meta_description_suggestion', $keys);
        self::assertContains('article.comment.generate', $keys);
        self::assertNotContains('product.gallery.generate', $keys);
        self::assertNotContains('product.gallery.plan', $keys);
        self::assertNotContains('product.gallery.parent.generate', $keys);
        self::assertNotContains('product.gallery.child.generate', $keys);
        self::assertNotContains('article.featured_image.generate', $keys);
        self::assertContains('article.content.translate', $keys);
        self::assertContains('article.faq.generate', $keys);
        self::assertContains('article.featured_snippet.generate', $keys);
        self::assertNotContains('article.outline.generate', $keys);
        self::assertContains('article.outline.structure.generate', $keys);
        self::assertContains('article.vocabulary.generate', $keys);
        self::assertContains('keyword.discovery.structured', $keys);
        self::assertContains('article.content.improve', $keys);
    }

    public function test_content_generate_is_settings_visible_rewrite_is_not(): void
    {
        $keys = array_column($this->catalog()->settingsVisibleHooks(), 'hook_key');

        self::assertContains('article.content.generate', $keys);
        self::assertNotContains('article.content.rewrite', $keys);
    }

    public function test_form_hook_key_encoding_roundtrip(): void
    {
        $hook = 'article.title_suggestion';
        $encoded = SeoCreateArticleSettingsService::encodeHookKeyForForm($hook);

        self::assertSame('article__title_suggestion', $encoded);
        self::assertSame($hook, SeoCreateArticleSettingsService::decodeHookKeyFromForm($encoded));
        self::assertStringNotContainsString('.', $encoded);
    }

    public function test_comment_presentation_has_friendly_guidance(): void
    {
        $service = new PromptHookPresentationService($this->catalog());
        $view = $service->forHook('article.comment.generate');

        self::assertNotNull($view);
        self::assertNotEmpty($view['default_instructions']);
        self::assertNotEmpty($view['output_format']);
        self::assertNotEmpty($view['inputs']);
        self::assertNotEmpty($view['sections']);
        self::assertSame('legacy_prompt_content', $view['content_mode']);
        self::assertTrue($view['uses_prompt_markdown']);
        self::assertSame('Post Title', $view['inputs'][0]['label']);
        self::assertStringNotContainsString('{{', $view['inputs'][0]['label']);
        self::assertStringNotContainsString('spec_version', json_encode($view, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('input_schema', json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function test_comment_variable_labels_follow_current_locale(): void
    {
        $service = new PromptHookPresentationService($this->catalog());
        $expected = [
            'vi' => [
                'post_title' => 'Tiêu đề bài viết',
                'comment_count' => 'Số bình luận cần tạo',
                'post_excerpt' => 'Nội dung tóm tắt',
                'post_type' => 'Loại bài viết',
                'site_short_description' => 'Mô tả ngắn của website',
            ],
            'en' => [
                'post_title' => 'Article title',
                'comment_count' => 'Number of comments to generate',
                'post_excerpt' => 'Content excerpt',
                'post_type' => 'Post type',
                'site_short_description' => 'Website short description',
            ],
        ];

        try {
            foreach ($expected as $locale => $labels) {
                $this->usePromptHookLocale($locale);
                $view = $service->forHook('article.comment.generate');
                self::assertNotNull($view);
                self::assertSame($labels, collect($view['inputs'])->pluck('label', 'key')->all());
            }
        } finally {
            app()->forgetInstance('translator');
        }
    }

    public function test_variable_label_fallback_keeps_declared_and_humanized_labels(): void
    {
        try {
            $this->usePromptHookLocale('en');
            $service = new PromptHookPresentationService($this->catalog());
            $method = new \ReflectionMethod($service, 'resolveVariableLabel');

            self::assertSame('Declared label', $method->invoke($service, 'unknown_variable', 'Declared label'));
            self::assertSame('Unknown Variable', $method->invoke($service, 'unknown_variable', ''));
        } finally {
            app()->forgetInstance('translator');
        }
    }

    public function test_all_exposed_hook_inputs_have_shared_vi_and_en_labels(): void
    {
        $service = new PromptHookPresentationService($this->catalog());
        $repoRoot = dirname((string) (new \ReflectionClass($service))->getFileName(), 5);
        $keys = [];
        foreach ($this->catalog()->settingsVisibleHooks() as $hook) {
            $view = $service->forHook($hook['hook_key']);
            foreach ($view['inputs'] ?? [] as $input) {
                $keys[] = $input['key'];
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        foreach (['vi', 'en'] as $locale) {
            $dictionary = require $repoRoot.'/seo-content-ai-compat/lang/'.$locale.'/prompt_hooks.php';
            $translated = array_keys($dictionary['variables'] ?? []);
            $missing = array_values(array_diff($keys, $translated));
            self::assertSame([], $missing, $locale.' missing shared variable labels: '.implode(', ', $missing));
        }
    }

    public function test_industry_context_variable_labels_match_shared_locales(): void
    {
        $repoRoot = dirname((string) (new \ReflectionClass(PromptHookPresentationService::class))->getFileName(), 5);
        $expected = [
            'vi' => [
                'context_name' => 'Tên ngữ cảnh ngành',
                'market' => 'Thị trường mục tiêu',
                'core_context' => 'Industry Core Context',
                'notes' => 'Ghi chú',
            ],
            'en' => [
                'context_name' => 'Context name',
                'market' => 'Target market',
                'core_context' => 'Core Industry Context',
                'notes' => 'Notes',
            ],
        ];

        foreach ($expected as $locale => $labels) {
            $dictionary = require $repoRoot.'/seo-content-ai-compat/lang/'.$locale.'/prompt_hooks.php';
            foreach ($labels as $key => $label) {
                self::assertSame($label, $dictionary['variables'][$key] ?? null, $locale.'.'.$key);
            }
        }
    }

    public function test_all_settings_visible_hooks_have_presentation_view_model(): void
    {
        $service = new PromptHookPresentationService($this->catalog());

        foreach ($this->catalog()->settingsVisibleHooks() as $hook) {
            $view = $service->forHook($hook['hook_key']);
            self::assertNotNull($view, $hook['hook_key']);
            self::assertNotSame('', trim((string) $view['description']), $hook['hook_key']);
            self::assertNotEmpty($view['sections'], $hook['hook_key']);
            self::assertContains($view['content_mode'], [
                PromptHookPresentationService::CONTENT_MODE_LEGACY_PROMPT,
                PromptHookPresentationService::CONTENT_MODE_INLINE,
            ], $hook['hook_key']);

            foreach ($view['inputs'] as $input) {
                self::assertNotSame('', trim($input['label']), $hook['hook_key'].'.'.$input['key']);
                self::assertStringNotContainsString('{{', $input['label']);
            }

            foreach ($view['sections'] as $section) {
                self::assertNotEmpty($section['items'], $hook['hook_key'].'.'.$section['key']);
            }

            $encoded = json_encode($view, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('spec_version', $encoded);
            self::assertStringNotContainsString('"retry"', $encoded);
            self::assertStringNotContainsString('side_effects', $encoded);
        }
    }

    public function test_inline_template_hooks_report_inline_content_mode(): void
    {
        $service = new PromptHookPresentationService($this->catalog());

        foreach ([
            'article.title_suggestion',
            'article.meta_description_suggestion',
            'article.faq.generate',
            'article.featured_snippet.generate',
        ] as $hookKey) {
            $view = $service->forHook($hookKey);
            self::assertNotNull($view);
            self::assertSame(PromptHookPresentationService::CONTENT_MODE_INLINE, $view['content_mode'], $hookKey);
            self::assertFalse($view['uses_prompt_markdown'], $hookKey);
            self::assertFalse(
                \Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookFormSchema::usesLegacyPromptTemplate($hookKey, '0.1.0'),
                $hookKey,
            );
        }
    }

    public function test_legacy_prompt_content_hooks_report_legacy_mode(): void
    {
        $service = new PromptHookPresentationService($this->catalog());

        foreach ([
            'article.outline.structure.generate',
            'article.content.translate',
            'article.comment.generate',
            'product.gallery.generate',
            'article.featured_image.generate',
            'keyword.discovery.structured',
        ] as $hookKey) {
            $view = $service->forHook($hookKey);
            self::assertNotNull($view);
            self::assertSame(PromptHookPresentationService::CONTENT_MODE_LEGACY_PROMPT, $view['content_mode'], $hookKey);
            self::assertTrue($view['uses_prompt_markdown'], $hookKey);
            self::assertTrue(
                \Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookFormSchema::usesLegacyPromptTemplate($hookKey, '0.1.0'),
                $hookKey,
            );
        }
    }

    public function test_empty_presentation_sections_are_omitted_from_view_model(): void
    {
        $service = new PromptHookPresentationService($this->catalog());
        $view = $service->forHook('article.meta_description_suggestion');
        self::assertNotNull($view);

        $keys = array_column($view['sections'], 'key');
        if (($view['notes'] ?? []) === []) {
            self::assertNotContains('notes', $keys);
        }
        self::assertContains('default_instructions', $keys);
        self::assertContains('output_format', $keys);
        self::assertContains('runtime_inputs', $keys);
    }

    public function test_format_sections_html_skips_empty_sections(): void
    {
        $service = new PromptHookPresentationService($this->catalog());
        $html = $service->formatSectionsHtml([
            'hook_key' => 'x',
            'label' => 'X',
            'description' => '',
            'content_mode' => PromptHookPresentationService::CONTENT_MODE_INLINE,
            'uses_prompt_markdown' => false,
            'sections' => [
                ['key' => 'default_instructions', 'label' => 'Guide', 'items' => ['Keep on topic']],
                ['key' => 'notes', 'label' => 'Notes', 'items' => []],
            ],
            'default_instructions_title' => 'Guide',
            'output_format_title' => 'Out',
            'input_data_title' => 'In',
            'notes_title' => 'Notes',
            'default_instructions' => ['Keep on topic'],
            'output_format' => [],
            'notes' => [],
            'inputs' => [],
        ]);

        self::assertStringContainsString('Keep on topic', $html);
        self::assertStringNotContainsString('>Notes<', $html);
    }

    public function test_comment_parser_accepts_three_valid_lines(): void
    {
        $parser = new CommentSeedingOutputParser;
        $comments = $parser->parse(
            "Nguyen Van A | a@example.com | Bai hay qua\n".
            "Tran B | b@example.com | Cho minh hoi them?\n".
            "Le C | c@example.com | Cam on tac gia"
        );

        self::assertCount(3, $comments);
        self::assertSame('Nguyen Van A', $comments[0]['name']);
        self::assertSame('a@example.com', $comments[0]['email']);
    }

    public function test_comment_parser_skips_explanation_and_keeps_valid_lines(): void
    {
        $comments = (new CommentSeedingOutputParser)->parse(
            "Day la 3 binh luan:\n".
            "A | a@example.com | x\n".
            "B | b@example.com | y\n".
            "C | c@example.com | z"
        );

        self::assertCount(3, $comments);
    }

    public function test_comment_parser_skips_bad_email_keeps_valid(): void
    {
        $comments = (new CommentSeedingOutputParser)->parse(
            "A | not-an-email | x\n".
            "B | b@example.com | y\n".
            "C | c@example.com | z"
        );

        self::assertCount(2, $comments);
        self::assertSame('B', $comments[0]['name']);
    }

    public function test_comment_parser_rejects_when_no_valid_lines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommentSeedingOutputParser)->parse("hello\nworld");
    }

    public function test_comment_parser_allows_pipe_in_content(): void
    {
        $parser = new CommentSeedingOutputParser;
        $comments = $parser->parse(
            "A | a@example.com | Phan A | Phan B\n".
            "B | b@example.com | ok\n".
            "C | c@example.com | ok2"
        );

        self::assertSame('Phan A | Phan B', $comments[0]['content']);
    }

    public function test_legacy_prompt_field_to_hook_map_covers_core_capabilities(): void
    {
        $map = SeoCreateArticleSettingsService::LEGACY_PROMPT_FIELD_TO_HOOK;

        self::assertSame('article.title_suggestion', $map['article_title_suggestion_prompt_id']);
        self::assertSame('article.meta_description_suggestion', $map['article_meta_description_suggestion_prompt_id']);
        self::assertSame('product.gallery.generate', $map['create_product_gallery_image_prompt_id']);
        self::assertSame('article.content.translate', $map['translate_article_prompt_id']);
    }
}
