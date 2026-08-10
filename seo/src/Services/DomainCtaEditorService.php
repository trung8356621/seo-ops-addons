<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Support\CtaContactUsability;
use Omnichannel\Addons\Seo\Support\CtaLinkFormatter;
use Omnichannel\Addons\Seo\Support\CtaQuickTemplates;
use App\Models\Site;

final class DomainCtaEditorService
{
    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly SeoDomainCtaGlobalSettingsService $globalSettings,
    ) {}

    /**
     * Usable contact rows only — unresolved placeholders / blanks are omitted from UI lists.
     *
     * @return list<array{
     *     type: string,
     *     value: string,
     *     label: string,
     *     href: string,
     *     plain_text: bool,
     *     can_insert: bool,
     *     usable: bool,
     *     is_blank: bool
     * }>
     */
    public function forSite(Site|int|null $site): array
    {
        if ($site === null) {
            return [];
        }

        $site = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($site === null) {
            return [];
        }

        $items = [];

        foreach ($this->promptContext->getForSite($site)['cta'] ?? [] as $row) {
            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));
            if ($type === '') {
                continue;
            }

            if ($value === '' || CtaContactUsability::isUnresolvedPlaceholder($value)) {
                continue;
            }

            $plainText = CtaLinkFormatter::isPlainTextType($type);
            $href = $plainText ? '' : CtaLinkFormatter::format($type, $value);

            $item = [
                'type' => $type,
                'value' => $value,
                'label' => $value,
                'href' => $href,
                'plain_text' => $plainText,
                'can_insert' => true,
                'usable' => true,
                'is_blank' => false,
            ];

            if (! CtaContactUsability::isUsable($item)) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array<string, array{default_index: int, templates: list<string>}>
     */
    public function quickTemplates(): array
    {
        return $this->globalSettings->getCtaQuickTemplates();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array{default_index: int, templates: list<string>}>
     */
    public function saveQuickTemplates(array $payload): array
    {
        return $this->globalSettings->saveCtaQuickTemplates($payload);
    }

    /**
     * @param  array{type?: string, value?: string, label?: string}  $item
     */
    public function resolveQuickTemplate(string $template, array $item): string
    {
        return CtaQuickTemplates::resolve($template, $item);
    }
}
