<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Support\MediaAltOwnership;
use Omnichannel\Addons\WordPress\Services\WordPressAttachmentAltPendingService;
use Omnichannel\Addons\WordPress\Services\WordPressAttachmentMetaUpdateService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

final class MediaAltOwnershipContractTest extends TestCase
{
    public function test_ownership_mapping_and_wp_mutation_roles(): void
    {
        self::assertSame(
            MediaAltOwnership::OWNER_ARTICLE_HTML,
            MediaAltOwnership::altOwnerForRole(MediaAltOwnership::ROLE_ARTICLE_CONTENT),
        );
        self::assertSame(
            MediaAltOwnership::WP_ALT_SYNC_FORBIDDEN,
            MediaAltOwnership::wpAltSyncPolicyForRole(MediaAltOwnership::ROLE_ARTICLE_CONTENT),
        );
        self::assertFalse(MediaAltOwnership::isWpAltMutationRole(MediaAltOwnership::ROLE_ARTICLE_CONTENT));

        self::assertSame(
            MediaAltOwnership::OWNER_WORDPRESS,
            MediaAltOwnership::altOwnerForRole(MediaAltOwnership::ROLE_FEATURED_IMAGE),
        );
        self::assertTrue(MediaAltOwnership::isWpAltMutationRole(MediaAltOwnership::ROLE_FEATURED_IMAGE));
        self::assertTrue(MediaAltOwnership::isWpAltMutationRole(MediaAltOwnership::ROLE_PRODUCT_GALLERY));
    }

    public function test_content_context_never_resolves_wp_mutation_role_alone(): void
    {
        self::assertNull(MediaAltOwnership::resolveWpMutationRoleFromFlags(true, false, false));
        self::assertSame(
            MediaAltOwnership::ROLE_FEATURED_IMAGE,
            MediaAltOwnership::resolveWpMutationRoleFromFlags(true, true, false),
        );
        self::assertSame(
            MediaAltOwnership::ROLE_PRODUCT_GALLERY,
            MediaAltOwnership::resolveWpMutationRoleFromFlags(false, false, true),
        );
    }

    public function test_meta_update_service_requires_allowed_media_role(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressAttachmentMetaUpdateService::class, 'normalizeItems'),
        );

        self::assertStringContainsString('isWpAltMutationRole', $source);
        self::assertStringContainsString('requireMediaRole', $source);
        self::assertStringContainsString('ROLE_ARTICLE_CONTENT', $source);
        self::assertStringContainsString('fill_only_if_empty', $source);
    }

    public function test_pending_service_rejects_article_content_and_applies_after_sync_only(): void
    {
        $pending = (string) file_get_contents(
            (new ReflectionClass(WordPressAttachmentAltPendingService::class))->getFileName(),
        );
        self::assertStringContainsString('META_KEY = \'wp_attachment_alt_pending\'', $pending);
        self::assertStringContainsString('isWpAltMutationRole', $pending);
        self::assertStringContainsString('applyAfterSuccessfulSync', $pending);
        self::assertStringContainsString('fill_only_if_empty', $pending);

        $sync = (string) file_get_contents(
            (new ReflectionClass(WordPressArticleSyncService::class))->getFileName(),
        );
        self::assertStringContainsString('applyAfterSuccessfulSync', $sync);
        self::assertStringContainsString('WordPressAttachmentAltPendingService', $sync);
        // Must run only after featured media push success gate.
        self::assertStringContainsString('featured_media_sync_failed', $sync);
        $failPos = strpos($sync, 'featured_media_sync_failed');
        $applyPos = strpos($sync, 'applyAfterSuccessfulSync');
        self::assertNotFalse($failPos);
        self::assertNotFalse($applyPos);
        self::assertGreaterThan($failPos, $applyPos);
    }

    public function test_image_assistant_js_forbids_article_content_wp_alt_mutation(): void
    {
        $utils = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/articleImagesUtils.js',
        );
        self::assertStringContainsString('wpMetaQueue: []', $utils);
        self::assertStringContainsString('buildStagedWpAltItem', $utils);
        self::assertStringContainsString('Never queues WordPress attachment ALT', $utils);

        $ownership = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/mediaAltOwnership.js',
        );
        self::assertStringContainsString('MEDIA_ROLE_ARTICLE_CONTENT', $ownership);
        self::assertStringContainsString('buildStagedWpAltItem', $ownership);
        self::assertStringContainsString('isWordPressManagedAltUi', $ownership);

        $hook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorImageSlugRename.js',
        );
        self::assertStringContainsString('dispatchWordPressAttachmentAltStage', $hook);
        self::assertStringContainsString('sync_wordpress: false', $hook);
        self::assertStringContainsString('buildStagedWpAltItem', $hook);

        $tab = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/components/ArticleImagesTab.jsx',
        );
        self::assertStringContainsString('isWordPressManagedAltUi', $tab);
        self::assertStringContainsString('image_alt_managed_by_wordpress', $tab);
        self::assertStringContainsString('WP Media', $tab);
    }

    public function test_inventory_persists_contextual_provenance_fields(): void
    {
        $inventory = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/unifiedArticleImagesInventory.js',
        );
        self::assertStringContainsString('attachAltOwnershipProvenance', $inventory);
        self::assertStringContainsString('media_role', $inventory);
        self::assertStringContainsString('alt_owner', $inventory);
        self::assertStringContainsString('missing_alt_kind', $inventory);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) file_get_contents((string) $method->getFileName());
        $lines = explode("\n", $file);
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();

        return implode("\n", array_slice($lines, $start, $end - $start));
    }
}
