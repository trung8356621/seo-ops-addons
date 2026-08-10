<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\InlineMarkBoundaryWhitespace;
use Omnichannel\Addons\Content\Services\ArticleEditorHtmlSanitizeService;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemOutcome;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingQueueRemoteReconcileService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * WP HTML whitespace + publish completion contracts (no live WP).
 */
final class WordpressPublishWhitespaceAndCompletionContractTest extends TestCase
{
    public function test_inline_spaces_preserved_for_strong_em_anchor(): void
    {
        $repair = new InlineMarkBoundaryWhitespace;
        $sanitize = new ArticleEditorHtmlSanitizeService;

        $cases = [
            'pháº£i <strong>thÃ´ng minh</strong> trong cÃ¡ch lá»±a chá»n',
            'lÃ½ do <strong>phong cÃ¡ch Athleisure</strong> ra Ä‘á»i',
            'nÆ¡i <em>sá»± thoáº£i mÃ¡i</em> káº¿t há»£p cÃ¹ng váº» ngoÃ i',
            'xem <a href="/x">bÃ i liÃªn quan</a> táº¡i Ä‘Ã¢y',
        ];

        foreach ($cases as $plainish) {
            $html = '<p>'.$plainish.'</p>';
            $out = $sanitize->prepareHtmlForWordPressSync($html);
            $plain = trim(html_entity_decode(strip_tags($out), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $expected = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            self::assertSame($expected, $plain, $html);
            self::assertMatchesRegularExpression('/\s<(?:strong|em|a)\b/u', $out);
            self::assertMatchesRegularExpression('/<\/(?:strong|em|a)>\s/u', $out);
        }
    }

    public function test_repair_unglues_word_mark_boundaries_without_punct_space(): void
    {
        $repair = new InlineMarkBoundaryWhitespace;
        self::assertSame(
            'pháº£i <strong>thÃ´ng minh</strong> trong',
            $repair->repair('pháº£i<strong>thÃ´ng minh</strong>trong'),
        );
        self::assertSame(
            '<strong>Tá»« khÃ³a</strong>, vÃ­ dá»¥',
            $repair->repair('<strong>Tá»« khÃ³a</strong>, vÃ­ dá»¥'),
        );
    }

    public function test_adjacent_inline_tags_preserve_boundaries(): void
    {
        $sanitize = new ArticleEditorHtmlSanitizeService;
        $html = '<p>alpha <strong>one</strong> <em>two</em> beta</p>';
        $out = $sanitize->prepareHtmlForWordPressSync($html);
        self::assertSame(
            'alpha one two beta',
            trim(preg_replace('/\s+/u', ' ', strip_tags($out)) ?? ''),
        );
    }

    public function test_block_level_whitespace_may_normalize(): void
    {
        $sanitize = new ArticleEditorHtmlSanitizeService;
        $html = "<p>one</p>\n\n<p>two</p>";
        $out = $sanitize->prepareHtmlForWordPressSync($html);
        self::assertStringContainsString('<p>', $out);
        self::assertStringContainsString('one', $out);
        self::assertStringContainsString('two', $out);
    }

    public function test_dispatch_success_outcome_is_not_published_constant(): void
    {
        self::assertNotSame(PublishDueItemOutcome::PUBLISHED, PublishDueItemOutcome::AWAITING_DELIVERY);
        $runner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectPublishingQueueRunner.php',
        );
        self::assertStringContainsString("PublishDueItemOutcome::AWAITING_DELIVERY =>", $runner);
        self::assertStringContainsString("\$stats['dispatched']++", $runner);
        self::assertStringContainsString("\$stats['published_confirmed']++", $runner);
        // Must not increment published on AWAITING_DELIVERY.
        self::assertDoesNotMatchRegularExpression(
            '/AWAITING_DELIVERY =>[\s\S]{0,200}\$stats\[\'published\'\]\+\+/',
            $runner,
        );
        self::assertDoesNotMatchRegularExpression(
            '/AWAITING_DELIVERY =>[\s\S]{0,280}rememberPublisherProcessed/',
            $runner,
        );
    }

    public function test_confirm_delivery_accepts_queued_for_delivery(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressArticleSyncService.php',
        );
        self::assertStringContainsString('QueuedForDelivery', $src);
        self::assertStringContainsString('confirmContentProjectPublishDelivery', $src);
        self::assertStringContainsString('markPublished', $src);
    }

    public function test_seed_rule_maps_task_id_and_token(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php',
        );
        self::assertStringContainsString("'task_id' => '{{ payload.task_id }}'", $src);
        self::assertStringContainsString("'publish_attempt_token' => '{{ payload.publish_attempt_token }}'", $src);
    }

    public function test_hook_confirms_after_wp_success(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php',
        );
        self::assertStringContainsString('confirmContentProjectPublishDelivery', $src);
        self::assertStringContainsString('publishing.wp_sync_token_mismatch_continue', $src);
        self::assertStringContainsString('reconciledTokenMismatch', $src);
    }

    public function test_reconcile_service_classifies_explicitly(): void
    {
        $ref = new ReflectionClass(PublishingQueueRemoteReconcileService::class);
        self::assertTrue($ref->hasConstant('CLASS_REMOTE_PUBLISHED_MATCHING'));
        self::assertTrue($ref->hasConstant('CLASS_REMOTE_MISSING'));
        self::assertTrue($ref->hasConstant('CLASS_REMOTE_AMBIGUOUS'));
        self::assertTrue($ref->hasConstant('CLASS_LOCAL_ALREADY_PUBLISHED'));

        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/publishing/src/Console/ReconcilePublishingQueueTasksCommand.php',
        );
        self::assertStringContainsString('seo:publishing:reconcile-tasks', $cmd);
        self::assertStringContainsString('438,441,442,453,454,455,456,457,458,459,461,462,463,464', $cmd);
        self::assertStringContainsString('--resync-content', $cmd);
        self::assertStringContainsString('ReconcilePublishingQueueRemoteTasksCommand', $cmd);
        self::assertStringContainsString('ContentProjectCommandBus', $cmd);
    }

    public function test_article_9632_style_fixture_not_joined(): void
    {
        $repair = new InlineMarkBoundaryWhitespace;
        $glued = '<p>pháº£i<strong>thÃ´ng minh</strong>trong cÃ¡ch lá»±a chá»n lÃ½ do<strong>phong cÃ¡ch Athleisure</strong>ra Ä‘á»i</p>';
        $fixed = $repair->repair($glued);
        self::assertStringContainsString('pháº£i <strong>thÃ´ng minh</strong> trong', $fixed);
        self::assertStringContainsString('lÃ½ do <strong>phong cÃ¡ch Athleisure</strong> ra Ä‘á»i', $fixed);
        self::assertStringNotContainsString('pháº£i<strong>', $fixed);
        self::assertStringNotContainsString('</strong>trong', $fixed);
    }

    public function test_prepare_html_for_wordpress_calls_boundary_repair(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorHtmlSanitizeService.php',
        );
        self::assertStringContainsString('InlineMarkBoundaryWhitespace', $src);
        self::assertStringContainsString('->repair(', $src);
    }

    public function test_cli_metrics_use_confirmed_not_dispatch(): void
    {
        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/publishing/src/Console/PublishScheduledArticlesCommand.php',
        );
        self::assertStringContainsString('published_confirmed_count=', $cmd);
        self::assertStringContainsString('dispatched_count=', $cmd);
        self::assertStringContainsString('claimed_count=', $cmd);
    }
}
