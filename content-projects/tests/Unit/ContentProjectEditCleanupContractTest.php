<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\UpdateContentProjectItemHandler;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemContentLengthDefaults;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemHeaderLabel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ContentProjectEditCleanupContractTest extends TestCase
{
    public function test_normalize_post_type_maps_article_to_post(): void
    {
        self::assertSame(SeoProjectTask::POST_TYPE_POST, SeoProjectTask::normalizePostType('article'));
        self::assertSame(SeoProjectTask::POST_TYPE_POST, SeoProjectTask::normalizePostType('post'));
        self::assertSame(SeoProjectTask::POST_TYPE_POST, SeoProjectTask::normalizePostType(''));
        self::assertSame(SeoProjectTask::POST_TYPE_POST, SeoProjectTask::normalizePostType(null));
        self::assertSame(SeoProjectTask::POST_TYPE_PRODUCT, SeoProjectTask::normalizePostType('product'));
        self::assertSame('post', SeoProjectTask::POST_TYPE_POST);
        self::assertSame('article', SeoProjectTask::POST_TYPE_ARTICLE);
    }

    public function test_planner_editable_post_type_persists_canonical_post(): void
    {
        self::assertSame(
            SeoProjectTask::POST_TYPE_POST,
            UpdateContentProjectItemHandler::normalizeEditablePlannerPostType('article'),
        );
        self::assertSame(
            SeoProjectTask::POST_TYPE_POST,
            UpdateContentProjectItemHandler::normalizeEditablePlannerPostType('post'),
        );
    }

    public function test_rewrite_header_includes_keyword(): void
    {
        $label = ContentProjectItemHeaderLabel::fromState([
            'type' => SeoProjectTask::TYPE_REWRITE,
            'keyword' => 'Phối đồ với quần jean ôm',
            'source_content' => '15+ Phối đồ với quần jean ôm giúp chị em tôn dáng nhất',
            'title' => 'stale title should not win',
        ]);

        self::assertSame(
            '[Rewrite] (Phối đồ với quần jean ôm) 15+ Phối đồ với quần jean ôm giúp chị em tôn dáng nhất',
            $label,
        );
    }

    public function test_create_header_includes_keyword_and_post_label(): void
    {
        $label = ContentProjectItemHeaderLabel::fromState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'post_type' => 'article',
            'keyword' => 'balo quà tặng',
            'title' => 'Top 10 balo quà tặng',
        ]);

        self::assertSame('[Post] (balo quà tặng) Top 10 balo quà tặng', $label);
    }

    public function test_product_header_and_empty_title(): void
    {
        self::assertSame(
            '[Product] (túi xách)',
            ContentProjectItemHeaderLabel::fromState([
                'type' => SeoProjectTask::TYPE_CREATE,
                'post_type' => SeoProjectTask::POST_TYPE_PRODUCT,
                'keyword' => 'túi xách',
                'title' => '',
            ]),
        );
    }

    public function test_rewrite_title_field_hidden_create_title_visible(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString("normalizeType(\$get('type')) === SeoProjectTask::TYPE_CREATE", $src);
        self::assertStringContainsString('ContentProjectItemHeaderLabel::fromState', $src);
        self::assertStringContainsString('content_length_target_words', $src);
        self::assertStringContainsString('ContentProjectItemContentLengthDefaults', $src);
        self::assertStringContainsString('title_of_article_to_rewrite', $src);
    }

    public function test_content_length_defaults_and_custom_preserved(): void
    {
        self::assertSame(2000, ContentProjectItemContentLengthDefaults::forPostType('post'));
        self::assertSame(2000, ContentProjectItemContentLengthDefaults::forPostType('article'));
        self::assertSame(1000, ContentProjectItemContentLengthDefaults::forPostType('product'));
        self::assertTrue(ContentProjectItemContentLengthDefaults::isDefaultValue(2000, 'post'));
        self::assertFalse(ContentProjectItemContentLengthDefaults::isDefaultValue(3000, 'post'));
        self::assertTrue(ContentProjectItemContentLengthDefaults::isDefaultValue(null, 'product'));
    }

    public function test_draft_items_ui_uses_canonical_post_value(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');
        self::assertStringContainsString("['value' => 'post'", $items);
        self::assertStringNotContainsString("['value' => 'article'", $items);
        self::assertStringContainsString("? 'product' : 'post'", $items);
    }

    public function test_advanced_form_no_longer_hosts_length_presets(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Forms\ContentProjectItemAdvancedForm::class,
            ))->getFileName(),
        );
        self::assertStringNotContainsString('content_length_override', $src);
        self::assertStringNotContainsString('content_length_target_words', $src);
    }
}
