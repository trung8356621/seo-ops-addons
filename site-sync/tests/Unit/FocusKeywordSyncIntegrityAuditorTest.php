<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Services\Audit\FocusKeywordSyncIntegrityAuditor;
use PHPUnit\Framework\TestCase;

final class FocusKeywordSyncIntegrityAuditorTest extends TestCase
{
    public function test_f_v3_has_focus_but_laravel_relation_missing_detected(): void
    {
        $auditor = new FocusKeywordSyncIntegrityAuditor();

        $result = $auditor->audit(
            wpFocusByWpId: [
                10 => ['balo học sinh'],
                20 => ['túi canvas'],
                30 => [],
            ],
            v3FocusByWpId: [
                10 => ['balo học sinh'],
                20 => ['túi canvas'],
            ],
            laravelProviderByWpId: [
                10 => true,
                20 => false,
                30 => false,
            ],
            laravelEffectiveByWpId: [
                10 => true,
                20 => false,
                30 => false,
            ],
            eligibleWpIds: [10, 20, 30],
            missingEffectiveWpIds: [20, 30],
        );

        self::assertSame(2, $result['stages']['wordpress_provider']);
        self::assertSame(2, $result['stages']['v3_payload']);
        self::assertSame(1, $result['stages']['laravel_provider_relation']);
        self::assertSame([20], $result['set_diffs']['v3_minus_laravel_provider']);
        self::assertSame([], $result['set_diffs']['wp_minus_v3']);
        self::assertSame(1, $result['classification'][FocusKeywordSyncIntegrityAuditor::CLASS_V3_TO_LARAVEL_LOSS]);
        self::assertSame(1, $result['classification'][FocusKeywordSyncIntegrityAuditor::CLASS_WP_TRULY_MISSING]);
        self::assertContains(20, $result['classification_wp_ids'][FocusKeywordSyncIntegrityAuditor::CLASS_V3_TO_LARAVEL_LOSS]);
        self::assertContains(30, $result['classification_wp_ids'][FocusKeywordSyncIntegrityAuditor::CLASS_WP_TRULY_MISSING]);
    }

    public function test_wp_to_v3_exporter_loss(): void
    {
        $auditor = new FocusKeywordSyncIntegrityAuditor();

        $result = $auditor->audit(
            wpFocusByWpId: [5 => ['valid phrase']],
            v3FocusByWpId: [],
            laravelProviderByWpId: [5 => false],
            laravelEffectiveByWpId: [5 => false],
            eligibleWpIds: [5],
            missingEffectiveWpIds: [5],
        );

        self::assertSame([5], $result['set_diffs']['wp_minus_v3']);
        self::assertSame(1, $result['classification'][FocusKeywordSyncIntegrityAuditor::CLASS_WP_TO_V3_LOSS]);
    }

    public function test_url_shaped_provider_keyword_rejected_not_counted_as_wp_focus(): void
    {
        $auditor = new FocusKeywordSyncIntegrityAuditor();

        $result = $auditor->audit(
            wpFocusByWpId: [7 => ['https://example.com/page']],
            v3FocusByWpId: [],
            laravelProviderByWpId: [7 => false],
            laravelEffectiveByWpId: [7 => false],
            eligibleWpIds: [7],
            missingEffectiveWpIds: [7],
        );

        self::assertSame(0, $result['stages']['wordpress_provider']);
        self::assertSame(1, $result['classification'][FocusKeywordSyncIntegrityAuditor::CLASS_WP_TRULY_MISSING]);
        self::assertNotEmpty($result['candidate_rejections']);
    }
}
