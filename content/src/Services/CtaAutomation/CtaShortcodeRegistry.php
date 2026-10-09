<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use Omnichannel\Addons\Content\Services\ArticleCtaPlaceholderService;
use Omnichannel\Addons\Seo\Support\CtaLinkFormatter;
use App\Models\Site;

/**
 * Site-scoped CTA alias whitelist. The model never receives resolved destinations.
 */
final class CtaShortcodeRegistry
{
    /** @var list<string> */
    public const ALIASES = ['zalo', 'facebook', 'email', 'phone', 'address', 'website', 'products'];

    /** @var array<string, list<string>> */
    private const INTENT_ORDER = [
        'product_discovery' => ['products', 'website'],
        'service_discovery' => ['website', 'products'],
        'comparison' => ['products', 'website'],
        'consultation' => ['zalo', 'phone', 'email'],
        'conversion' => ['website', 'products', 'zalo'],
    ];

    public function __construct(
        private readonly ArticleCtaPlaceholderService $placeholders,
    ) {}

    /**
     * @return list<string>
     */
    public function enabledAliases(Site|int|null $site): array
    {
        if ($site === null) {
            return [];
        }
        $values = $this->placeholders->resolveValuesForSite($site);
        unset($values['_phone_pool'], $values['_email_pool']);
        $enabled = [];
        foreach (self::ALIASES as $alias) {
            if ($alias === 'website') {
                $siteModel = $site instanceof Site ? $site : Site::query()->find((int) $site);
                if ($siteModel !== null && trim((string) $siteModel->domain) !== '') {
                    $enabled[] = 'website';
                }
                continue;
            }
            if (trim((string) ($values[$alias] ?? '')) !== '') {
                $enabled[] = $alias;
            }
        }

        return $enabled;
    }

    /**
     * @param  list<string>  $enabled
     * @param  list<string>  $used
     */
    public function assign(string $intent, array $enabled, array $used): ?string
    {
        $order = self::INTENT_ORDER[$intent] ?? ['website'];
        foreach ($order as $alias) {
            if (in_array($alias, $enabled, true) && ! in_array($alias, $used, true)) {
                return $alias;
            }
        }
        foreach ($order as $alias) {
            if (in_array($alias, $enabled, true)) {
                return $alias;
            }
        }

        return null;
    }

    public function renderAlias(string $alias, Site|int|null $site): ?string
    {
        if (! in_array($alias, self::ALIASES, true) || $site === null) {
            return null;
        }
        $siteModel = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($siteModel === null) {
            return null;
        }
        $values = $this->placeholders->resolveValuesForSite($siteModel);
        unset($values['_phone_pool'], $values['_email_pool']);
        if ($alias === 'website') {
            $value = trim((string) $siteModel->domain);
        } else {
            $value = trim((string) ($values[$alias] ?? ''));
        }
        if ($value === '') {
            return null;
        }
        $href = CtaLinkFormatter::format($alias === 'products' ? 'website' : $alias, $value);
        if ($href === '' || preg_match('#^https?://#i', $href) !== 1 && ! str_starts_with($href, 'mailto:') && ! str_starts_with($href, 'tel:')) {
            if ($alias !== 'address') {
                return null;
            }
        }
        $label = htmlspecialchars($this->label($alias), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($alias === 'address' && $href === '') {
            return $label;
        }
        $safeHref = htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<a href="'.$safeHref.'">'.$label.'</a>';
    }

    private function label(string $alias): string
    {
        return match ($alias) {
            'zalo' => 'Zalo',
            'facebook' => 'Facebook',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address',
            'products' => 'products',
            default => 'website',
        };
    }
}
