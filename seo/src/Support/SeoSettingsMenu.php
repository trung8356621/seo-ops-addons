<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use App\Core\Settings\CoreSettingsBootstrap;
use App\Core\Settings\SettingsSectionRegistry;

/**
 * Settings navigation — driven by Core SettingsSectionRegistry (canonical Admin URLs).
 * SEO sidebar reuses the same menu; multiple nav entry points, one implementation.
 */
final class SeoSettingsMenu
{
    /**
     * @return list<array{id: string, label: string, icon: string, url: string}>
     */
    public static function items(): array
    {
        try {
            /** @var SettingsSectionRegistry $registry */
            $registry = app(SettingsSectionRegistry::class);
            app(CoreSettingsBootstrap::class)->seed($registry);

            $items = $registry->menuItems();
            if ($items !== []) {
                return $items;
            }
        } catch (\Throwable) {
            // fall through to static fallback
        }

        return self::fallbackItems();
    }

    /**
     * @return list<array{id: string, label: string, items: list<array{id: string, label: string, icon: string, url: string}>}>
     */
    public static function groups(): array
    {
        $buckets = [];
        foreach (self::items() as $item) {
            $buckets[self::groupKey((string) $item['id'])][] = $item;
        }

        $out = [];
        foreach (['general', 'ai', 'seo', 'automation', 'data'] as $key) {
            if (($buckets[$key] ?? []) === []) {
                continue;
            }

            $out[] = [
                'id' => $key,
                'label' => __('seo-content-ai::filament.settings_nav.'.$key),
                'items' => $buckets[$key],
            ];
        }

        return $out;
    }

    public static function groupKey(string $sectionId): string
    {
        return match ($sectionId) {
            'ai-center', 'semantic-routing', 'api' => 'ai',
            'keyword-sources', 'editor', 'scoring' => 'seo',
            'workflows' => 'automation',
            'import-export', 'seeding-service' => 'data',
            'general', 'performance' => 'general',
            default => 'general',
        };
    }

    /**
     * @return list<array{id: string, label: string, icon: string, url: string}>
     */
    private static function fallbackItems(): array
    {
        return [
            [
                'id' => 'general',
                'label' => 'seo-content-ai::filament.settings_general.nav',
                'icon' => 'heroicon-o-cog-6-tooth',
                'url' => url('/admin/settings/general'),
            ],
            [
                'id' => 'performance',
                'label' => 'performance.nav',
                'icon' => 'heroicon-o-bolt',
                'url' => url('/admin/settings/performance'),
            ],
            [
                'id' => 'ai-center',
                'label' => 'AI Center',
                'icon' => 'heroicon-o-cpu-chip',
                'url' => url('/admin/settings/ai-center'),
            ],
            [
                'id' => 'semantic-routing',
                'label' => 'Semantic Routing',
                'icon' => 'heroicon-o-queue-list',
                'url' => url('/admin/settings/semantic-routing'),
            ],
            [
                'id' => 'api',
                'label' => 'API Connections',
                'icon' => 'heroicon-o-link',
                'url' => url('/admin/settings/api'),
            ],
        ];
    }
}
