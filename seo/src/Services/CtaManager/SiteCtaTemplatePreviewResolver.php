<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\CtaManager;

use App\Models\Site;
use Omnichannel\Addons\Seo\Services\DomainCtaEditorService;
use Omnichannel\Addons\Seo\Support\CtaContactUsability;
use Omnichannel\Addons\Seo\Support\CtaLinkFormatter;

/**
 * Preview CTA copy with dynamic contact tokens as HTML anchors (or plain address text).
 * Templates remain token-based; this resolver never writes URLs into stored content.
 */
final class SiteCtaTemplatePreviewResolver
{
    public function __construct(
        private readonly DomainCtaEditorService $contacts,
        private readonly SiteCtaTokenHtmlRenderer $renderer = new SiteCtaTokenHtmlRenderer,
    ) {}

    /**
     * @param  list<array<string, mixed>>|null  $contactItems
     * @return array{preview: string, html: string, unresolved: list<string>, unsupported: list<string>}
     */
    public function preview(Site|int $site, string $content, ?array $contactItems = null): array
    {
        $items = $contactItems ?? $this->contacts->forSite($site);
        $byType = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! CtaContactUsability::isUsable($item)) {
                continue;
            }
            $type = mb_strtolower(trim((string) ($item['type'] ?? '')));
            if ($type === '') {
                continue;
            }
            $value = trim((string) ($item['value'] ?? $item['label'] ?? ''));
            if ($value === '') {
                continue;
            }
            $plain = ! empty($item['plain_text']) || CtaLinkFormatter::isPlainTextType($type);
            $href = trim((string) ($item['href'] ?? ''));
            if (! $plain && $href === '') {
                $href = CtaLinkFormatter::format($this->renderer->baseType($type), $value);
            }
            $byType[$type] = [
                'value' => $value,
                'href' => $href,
                'plain_text' => $plain,
            ];
            // Also expose base aliases when only phone_1 / email_1 exist.
            $base = $this->renderer->baseType($type);
            if ($base !== $type && ! isset($byType[$base])) {
                $byType[$base] = $byType[$type];
            }
        }

        if (! isset($byType['website'])) {
            $siteModel = $site instanceof Site ? $site : Site::query()->find((int) $site);
            $domain = trim((string) ($siteModel?->domain ?? ''));
            if ($domain !== '') {
                $scheme = ($siteModel?->ssl ?? true) === false ? 'http://' : 'https://';
                $websiteUrl = preg_match('#^https?://#i', $domain) === 1
                    ? $domain
                    : $scheme.ltrim($domain, '/').'/';
                if ($this->renderer->isSafeUrl($websiteUrl)) {
                    $byType['website'] = [
                        'value' => $websiteUrl,
                        'href' => $websiteUrl,
                        'plain_text' => false,
                    ];
                }
            }
        }

        $rendered = $this->renderer->render($content, $byType);

        return [
            'preview' => $rendered['html'],
            'html' => $rendered['html'],
            'unresolved' => $rendered['unresolved'],
            'unsupported' => $rendered['unsupported'],
        ];
    }
}
