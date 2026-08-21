<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;

/**
 * Gate for Product Gallery canary fixture UI / commands.
 */
final class ProductGalleryCanaryAccess
{
    public static function fixtureUiEnabled(): bool
    {
        try {
            if ((bool) config('seo-content-ai.product_gallery.canary.fixture_ui_enabled', false)) {
                return true;
            }
        } catch (\Throwable) {
        }

        try {
            if (app()->environment(['local', 'staging', 'testing'])) {
                return true;
            }
        } catch (\Throwable) {
        }

        return ProductGalleryParentChildFeature::enabled();
    }

    public static function allowsUi(?User $user = null): bool
    {
        if (! self::fixtureUiEnabled()) {
            return false;
        }

        $user ??= auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if (SeoAccessControl::canMutateContentProjects() || SeoAccessControl::canAccessManagerFeatures()) {
            return true;
        }

        return (string) ($user->role ?? '') === User::ROLE_OWNER;
    }

    public static function isCanaryArticle(SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');
        $flag = strtolower(trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'is_canary')?->meta_value ?? ''
        )));

        if (in_array($flag, ['1', 'true', 'yes'], true)) {
            return true;
        }

        $type = strtolower(trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'canary_type')?->meta_value ?? ''
        )));

        return $type === 'product_gallery';
    }
}
