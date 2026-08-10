<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;

final class SeoLinkMapNetworkStatusPresenter
{
    /**
     * @return array{
     *     tone: string,
     *     label: string,
     *     badge_class: string,
     *     card_border_class: string,
     *     show_green_dot: bool,
     *     is_broken_network: bool,
     *     is_restricted: bool,
     * }
     */
    public static function present(?int $httpStatus, SeoLinkMapStatus $status): array
    {
        if ($status === SeoLinkMapStatus::Broken && $httpStatus === null) {
            return self::brokenPresentation(
                label: __('seo-content-ai::filament.keyword.link_network_timeout'),
            );
        }

        if ($httpStatus === 404 || $httpStatus === 410) {
            return self::brokenPresentation(
                label: __('seo-content-ai::filament.keyword.link_network_dead', ['code' => $httpStatus]),
            );
        }

        if ($httpStatus !== null && $httpStatus >= 400) {
            if (in_array($httpStatus, [403, 405], true)) {
                return self::restrictedPresentation($httpStatus);
            }

            return self::brokenPresentation(
                label: __('seo-content-ai::filament.keyword.link_network_dead', ['code' => $httpStatus]),
            );
        }

        if ($status === SeoLinkMapStatus::Broken) {
            return self::brokenPresentation(
                label: __('seo-content-ai::filament.keyword.link_network_dead', ['code' => $httpStatus ?? 404]),
            );
        }

        if ($status === SeoLinkMapStatus::NeedsAudit) {
            if ($httpStatus === 403 || $httpStatus === 405) {
                return self::restrictedPresentation($httpStatus);
            }

            return self::restrictedPresentation($httpStatus ?? 403);
        }

        if ($httpStatus !== null && ($httpStatus === 301 || $httpStatus === 302)) {
            return self::successPresentation(
                label: __('seo-content-ai::filament.keyword.link_network_redirect', ['code' => $httpStatus]),
            );
        }

        if ($status === SeoLinkMapStatus::Active) {
            $code = $httpStatus ?? 200;

            return self::successPresentation(
                label: __('seo-content-ai::filament.keyword.link_network_active', ['code' => $code]),
            );
        }

        return [
            'tone' => 'pending',
            'label' => __('seo-content-ai::filament.keyword.link_network_pending'),
            'badge_class' => 'bg-gray-50 text-gray-600 dark:bg-white/10 dark:text-gray-300 px-2 py-0.5 rounded text-xs font-medium',
            'card_border_class' => 'border-gray-200 dark:border-white/10',
            'show_green_dot' => false,
            'is_broken_network' => false,
            'is_restricted' => false,
        ];
    }

    public static function isBrokenNetwork(?int $httpStatus, SeoLinkMapStatus $status): bool
    {
        if ($status === SeoLinkMapStatus::Broken) {
            return true;
        }

        return $httpStatus !== null && $httpStatus >= 400;
    }

    /**
     * @return array{
     *     tone: string,
     *     label: string,
     *     badge_class: string,
     *     card_border_class: string,
     *     show_green_dot: bool,
     *     is_broken_network: bool,
     *     is_restricted: bool,
     * }
     */
    private static function successPresentation(string $label): array
    {
        return [
            'tone' => 'success',
            'label' => $label,
            'badge_class' => 'text-emerald-700 dark:text-emerald-300 text-xs font-medium',
            'card_border_class' => 'border-gray-200 dark:border-white/10',
            'show_green_dot' => true,
            'is_broken_network' => false,
            'is_restricted' => false,
        ];
    }

    /**
     * @return array{
     *     tone: string,
     *     label: string,
     *     badge_class: string,
     *     card_border_class: string,
     *     show_green_dot: bool,
     *     is_broken_network: bool,
     *     is_restricted: bool,
     * }
     */
    private static function brokenPresentation(string $label): array
    {
        return [
            'tone' => 'broken',
            'label' => $label,
            'badge_class' => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400 px-2 py-0.5 rounded text-xs font-bold border border-red-200 dark:border-red-800/30',
            'card_border_class' => 'border-red-200 dark:border-red-900/50',
            'show_green_dot' => false,
            'is_broken_network' => true,
            'is_restricted' => false,
        ];
    }

    /**
     * @return array{
     *     tone: string,
     *     label: string,
     *     badge_class: string,
     *     card_border_class: string,
     *     show_green_dot: bool,
     *     is_broken_network: bool,
     *     is_restricted: bool,
     * }
     */
    private static function restrictedPresentation(int $httpStatus): array
    {
        return [
            'tone' => 'restricted',
            'label' => __('seo-content-ai::filament.keyword.link_network_restricted', ['code' => $httpStatus]),
            'badge_class' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300 px-2 py-0.5 rounded text-xs font-semibold border border-amber-200 dark:border-amber-700/30',
            'card_border_class' => 'border-amber-200 dark:border-amber-900/40',
            'show_green_dot' => false,
            'is_broken_network' => $httpStatus >= 400,
            'is_restricted' => true,
        ];
    }
}
