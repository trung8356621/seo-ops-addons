<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Carbon\Carbon;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Enums\ArticleIndexHealthStatus;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexCheckUrlBuilder;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthNotificationPublisher;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthPolicy;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthRecorder;
use Omnichannel\Addons\Content\Filament\Pages\ArticleIndexHealth;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditCheckIndexUrl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArticleIndexHealthPolicyTest extends TestCase
{
    private ArticleIndexHealthPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ArticleIndexHealthPolicy;
    }

    public function test_first_indexed_stays_indexed(): void
    {
        $effective = $this->policy->deriveEffective(
            ArticleIndexCheckStatus::Indexed,
            null,
        );
        self::assertSame(ArticleIndexHealthStatus::Indexed, $effective);
    }

    public function test_first_not_indexed_is_not_dropped(): void
    {
        $effective = $this->policy->deriveEffective(
            ArticleIndexCheckStatus::NotIndexed,
            null,
        );
        self::assertSame(ArticleIndexHealthStatus::NotIndexed, $effective);
    }

    public function test_previous_indexed_then_not_indexed_is_dropped(): void
    {
        $effective = $this->policy->deriveEffective(
            ArticleIndexCheckStatus::NotIndexed,
            ArticleIndexHealthStatus::Indexed,
        );
        self::assertSame(ArticleIndexHealthStatus::Dropped, $effective);
    }

    public function test_unsure_is_unknown(): void
    {
        $effective = $this->policy->deriveEffective(
            ArticleIndexCheckStatus::Unknown,
            ArticleIndexHealthStatus::Indexed,
        );
        self::assertSame(ArticleIndexHealthStatus::Unknown, $effective);
    }

    public function test_monthly_due_uses_add_months(): void
    {
        $checked = Carbon::parse('2026-01-31 10:00:00');
        $next = $this->policy->nextCheckDueAt($checked);
        self::assertNotNull($next);
        self::assertSame('2026-02-28', $next->format('Y-m-d'));

        self::assertFalse($this->policy->isDue($checked, Carbon::parse('2026-02-27 10:00:00')));
        self::assertTrue($this->policy->isDue($checked, Carbon::parse('2026-02-28 10:00:00')));
        self::assertTrue($this->policy->isDue(null, Carbon::now()));
    }

    public function test_dropped_remains_needs_review_even_if_just_checked(): void
    {
        self::assertTrue($this->policy->needsReview(
            ArticleIndexHealthStatus::Dropped,
            Carbon::now(),
            Carbon::now(),
        ));
    }

    public function test_indexed_recent_is_not_needs_review(): void
    {
        self::assertFalse($this->policy->needsReview(
            ArticleIndexHealthStatus::Indexed,
            Carbon::now()->subDays(2),
            Carbon::now(),
        ));
    }

    public function test_site_query_builder_encodes_url(): void
    {
        $url = (new ArticleIndexCheckUrlBuilder)->forCanonicalUrl('https://example.com/article/');
        self::assertSame(
            'https://www.google.com/search?q='.rawurlencode('site:https://example.com/article/'),
            $url,
        );
        self::assertSame($url, SeoAuditCheckIndexUrl::forCanonicalUrl('https://example.com/article/'));
    }

    public function test_no_google_http_client_in_recorder_or_builder(): void
    {
        foreach ([
            ArticleIndexHealthRecorder::class,
            ArticleIndexCheckUrlBuilder::class,
            ArticleIndexHealthPolicy::class,
        ] as $class) {
            $src = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            self::assertStringNotContainsString('Http::', $src);
            self::assertStringNotContainsString('file_get_contents(\'https://www.google', $src);
            self::assertStringNotContainsString('curl_', $src);
            self::assertStringNotContainsString('Guzzle', $src);
        }
    }

    public function test_dropped_notification_dedup_and_event_code(): void
    {
        self::assertSame(
            'article-index-health:42:dropped',
            (new ArticleIndexHealthNotificationPublisher)->droppedDedupKey(42),
        );
        self::assertSame('article.index_dropped', OperationalNotificationEventCode::ArticleIndexDropped->value);
        self::assertSame('article', OperationalNotificationEventCode::ArticleIndexDropped->module());
    }

    public function test_ui_page_and_nav_contract(): void
    {
        $page = (string) file_get_contents((string) (new ReflectionClass(ArticleIndexHealth::class))->getFileName());
        self::assertStringContainsString('articles/index-health', $page);
        self::assertStringContainsString('markIndexed', $page);
        self::assertStringContainsString('ArticleIndexHealthRecorder', $page);

        $blade = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/article-index-health.blade.php'
        );
        self::assertStringContainsString('index_health.check_index', $blade);
        self::assertStringContainsString('markIndexed', $blade);
        self::assertStringContainsString('target="_blank"', $blade);
        self::assertStringNotContainsString('Maximum articles this month', $blade);
    }

    public function test_agent_record_requires_explicit_status(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/agent/src/Automation/Registry/ActionCatalogBootstrap.php'
        );
        self::assertStringContainsString("'article.index_health.record'", $src);
        self::assertStringContainsString("'article.index_health.list_due'", $src);
        self::assertStringContainsString("'article.index_health.inspect_gsc'", $src);
        self::assertStringContainsString("'article.index_health.inspect_due_gsc'", $src);
        self::assertStringContainsString("'status' => ['type' => 'string', 'required' => true", $src);
        self::assertStringContainsString('never invents Google results', $src);
    }
}
