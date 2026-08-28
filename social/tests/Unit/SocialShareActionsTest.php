<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Tests\Unit;

use Omnichannel\Addons\Social\Enums\SocialPlatform;
use Omnichannel\Addons\Social\Services\SocialShareUrlResolver;
use Omnichannel\Addons\Social\Support\SocialShareActionsPresenter;
use PHPUnit\Framework\TestCase;

final class SocialShareActionsTest extends TestCase
{
    public function test_share_adapters_use_stable_intent_urls(): void
    {
        $resolver = new SocialShareUrlResolver;
        $article = 'https://example.test/may-balo';
        $title = 'May balo';

        $fb = $resolver->shareIntent(SocialPlatform::Facebook, $article, $title);
        self::assertNotNull($fb);
        self::assertStringContainsString('facebook.com/sharer', $fb);
        self::assertStringContainsString(rawurlencode($article), $fb);

        $li = $resolver->shareIntent(SocialPlatform::LinkedIn, $article, $title);
        self::assertNotNull($li);
        self::assertStringContainsString('linkedin.com/sharing', $li);

        $x = $resolver->shareIntent(SocialPlatform::X, $article, $title);
        self::assertNotNull($x);
        self::assertStringContainsString('twitter.com/intent/tweet', $x);
        self::assertStringContainsString(rawurlencode($title), $x);
    }

    public function test_presenter_always_renders_facebook_linkedin_x_and_copy(): void
    {
        $presenter = new SocialShareActionsPresenter(new SocialShareUrlResolver);
        $presented = $presenter->present('https://example.test/may-balo', 'May balo', true);

        self::assertTrue($presented['can_share']);
        $keys = array_column($presented['actions'], 'key');
        self::assertSame(['facebook', 'linkedin', 'x', 'copy'], $keys);

        foreach ($presented['actions'] as $action) {
            if ($action['mode'] === 'share_intent') {
                self::assertNotEmpty($action['href']);
                self::assertNull($action['copy_url']);
            }
            if ($action['mode'] === 'copy_link') {
                self::assertSame('https://example.test/may-balo', $action['copy_url']);
                self::assertNull($action['href']);
            }
        }
    }

    public function test_presenter_does_not_depend_on_social_profiles(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Support/SocialShareActionsPresenter.php'
        );
        self::assertStringNotContainsString('SocialProfile', $source);
        self::assertStringNotContainsString('setup_needed', $source);
        self::assertStringNotContainsString('profile_url', $source);
        self::assertStringNotContainsString('siteId', $source);
    }

    public function test_presenter_hides_when_no_publish_url(): void
    {
        $presenter = new SocialShareActionsPresenter(new SocialShareUrlResolver);

        self::assertFalse($presenter->present('', 'Title')['can_share']);
        self::assertFalse($presenter->present('/relative-path', 'Title')['can_share']);
        self::assertFalse($presenter->present('ftp://example.test/a', 'Title')['can_share']);
        self::assertSame([], $presenter->present('', 'Title')['actions']);
    }

    public function test_blade_component_is_stateless_url_utility(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/components/social-share-actions.blade.php'
        );
        self::assertStringContainsString("'url'", $blade);
        self::assertStringContainsString("'title'", $blade);
        self::assertStringNotContainsString('siteId', $blade);
        self::assertStringNotContainsString('SocialProfilesPage', $blade);
        self::assertStringNotContainsString('not_configured', $blade);
        self::assertStringNotContainsString('setup', $blade);
        self::assertStringContainsString('noopener noreferrer', $blade);
        self::assertStringContainsString('copy_link', $blade);
    }

    public function test_social_profile_model_remains_for_future_automation(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Models/SocialProfile.php'
        );
        self::assertStringContainsString("protected \$table = 'seo_social_profiles'", $source);
        self::assertStringContainsString('function scopeForSite', $source);
        self::assertStringNotContainsString('browser_profile_id', $source);
    }
}
