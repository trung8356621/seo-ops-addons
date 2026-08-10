<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Support\CtaQuickTemplates;
use App\Models\WpOption;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;

final class SeoDomainCtaGlobalSettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $inMemorySettings = null;

    public const OPTION_KEY = 'seo_domain_cta_global_settings';

    public const KEY_DEFAULT_CTA_INTRO = 'default_cta_intro';

    public const KEY_GLOBAL_CTA = 'global_cta';

    public const KEY_CTA_QUICK_TEMPLATES = 'cta_quick_templates';

    /**
     * @return array{
     *     default_cta_intro: string,
     *     global_cta: list<array{type: string, value: string}>,
     *     cta_quick_templates: array<string, array{default_index: int, templates: list<string>}>
     * }
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->inMemorySettings;
        }

        $data = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($data)) {
            return $this->defaultSettings();
        }

        $intro = trim((string) ($data[self::KEY_DEFAULT_CTA_INTRO] ?? ''));

        $settings = [
            self::KEY_DEFAULT_CTA_INTRO => $intro !== ''
                ? $intro
                : SiteDomainPromptContextService::DEFAULT_CTA_INTRO,
            self::KEY_GLOBAL_CTA => $this->normalizeGlobalCtaRows(
                is_array($data[self::KEY_GLOBAL_CTA] ?? null) ? $data[self::KEY_GLOBAL_CTA] : [],
            ),
            self::KEY_CTA_QUICK_TEMPLATES => CtaQuickTemplates::normalize(
                is_array($data[self::KEY_CTA_QUICK_TEMPLATES] ?? null)
                    ? $data[self::KEY_CTA_QUICK_TEMPLATES]
                    : [],
            ),
        ];

        $this->inMemorySettings = $settings;

        return $settings;
    }

    /**
     * @return array<string, array{default_index: int, templates: list<string>}>
     */
    public function getCtaQuickTemplates(): array
    {
        return $this->getSettings()[self::KEY_CTA_QUICK_TEMPLATES];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array{default_index: int, templates: list<string>}>
     */
    public function saveCtaQuickTemplates(array $payload): array
    {
        $normalized = CtaQuickTemplates::normalize($payload);
        $current = $this->getSettings();

        WpOption::set(self::OPTION_KEY, [
            self::KEY_DEFAULT_CTA_INTRO => $current[self::KEY_DEFAULT_CTA_INTRO],
            self::KEY_GLOBAL_CTA => $current[self::KEY_GLOBAL_CTA],
            self::KEY_CTA_QUICK_TEMPLATES => $normalized,
        ]);

        $this->inMemorySettings = null;

        return $normalized;
    }

    public function getDefaultCtaIntro(): string
    {
        return $this->getSettings()[self::KEY_DEFAULT_CTA_INTRO];
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    public function getGlobalCta(): array
    {
        return $this->getSettings()[self::KEY_GLOBAL_CTA];
    }

    public function globalCtaValue(string $type): string
    {
        $type = mb_strtolower(trim($type));

        foreach ($this->getGlobalCta() as $row) {
            if (mb_strtolower(trim((string) ($row['type'] ?? ''))) !== $type) {
                continue;
            }

            return trim((string) ($row['value'] ?? ''));
        }

        return '';
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $rows
     */
    public function saveGlobalCta(array $rows): void
    {
        $current = $this->getSettings();

        WpOption::set(self::OPTION_KEY, [
            self::KEY_DEFAULT_CTA_INTRO => $current[self::KEY_DEFAULT_CTA_INTRO],
            self::KEY_GLOBAL_CTA => $this->normalizeGlobalCtaRows($rows),
            self::KEY_CTA_QUICK_TEMPLATES => $current[self::KEY_CTA_QUICK_TEMPLATES],
        ]);

        $this->inMemorySettings = null;
    }

    /**
     * @param  array{
     *     default_cta_intro?: string,
     *     global_cta?: list<array{type?: string, value?: string}>,
     *     cta_quick_templates?: array<string, mixed>
     * }  $payload
     */
    public function saveSettings(array $payload): void
    {
        $current = $this->getSettings();
        $intro = array_key_exists(self::KEY_DEFAULT_CTA_INTRO, $payload)
            ? trim((string) ($payload[self::KEY_DEFAULT_CTA_INTRO] ?? ''))
            : $current[self::KEY_DEFAULT_CTA_INTRO];

        WpOption::set(self::OPTION_KEY, [
            self::KEY_DEFAULT_CTA_INTRO => $intro,
            self::KEY_GLOBAL_CTA => array_key_exists(self::KEY_GLOBAL_CTA, $payload)
                ? $this->normalizeGlobalCtaRows(
                    is_array($payload[self::KEY_GLOBAL_CTA] ?? null) ? $payload[self::KEY_GLOBAL_CTA] : [],
                )
                : $current[self::KEY_GLOBAL_CTA],
            self::KEY_CTA_QUICK_TEMPLATES => array_key_exists(self::KEY_CTA_QUICK_TEMPLATES, $payload)
                ? CtaQuickTemplates::normalize(
                    is_array($payload[self::KEY_CTA_QUICK_TEMPLATES] ?? null)
                        ? $payload[self::KEY_CTA_QUICK_TEMPLATES]
                        : [],
                )
                : $current[self::KEY_CTA_QUICK_TEMPLATES],
        ]);

        $this->inMemorySettings = null;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{type: string, value: string}>
     */
    public function normalizeGlobalCtaRows(array $rows): array
    {
        $allowed = array_keys(SiteDomainPromptContextService::globalCtaFormTypeOptions());
        $normalized = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));

            if ($type === '' || $value === '' || ! in_array($type, $allowed, true)) {
                continue;
            }

            if (isset($seen[$type])) {
                continue;
            }

            $seen[$type] = true;
            $normalized[] = ['type' => $type, 'value' => $value];
        }

        return $normalized;
    }

    /**
     * @return array{
     *     default_cta_intro: string,
     *     global_cta: list<array{type: string, value: string}>,
     *     cta_quick_templates: array<string, array{default_index: int, templates: list<string>}>
     * }
     */
    private function defaultSettings(): array
    {
        return [
            self::KEY_DEFAULT_CTA_INTRO => SiteDomainPromptContextService::DEFAULT_CTA_INTRO,
            self::KEY_GLOBAL_CTA => [],
            self::KEY_CTA_QUICK_TEMPLATES => CtaQuickTemplates::normalize([]),
        ];
    }
}
