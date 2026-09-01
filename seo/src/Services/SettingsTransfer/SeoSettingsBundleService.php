<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateCatalog;
use Omnichannel\Addons\AiPrompt\Support\ConfigurationPackageType;
use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Omnichannel\Addons\Seo\Services\SeoDateTimeSettingsService;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Services\SeoScoringSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationImportAuditor;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationImportPlan;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationJsonGuard;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationPackageLimits;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Facades\DB;

final class SeoSettingsBundleService
{
    public function __construct(
        private readonly ConfigurationJsonGuard $guard = new ConfigurationJsonGuard(),
        private readonly ConfigurationImportAuditor $auditor = new ConfigurationImportAuditor(),
    ) {}

    public function registry(): SettingsTransferRegistry
    {
        return new SettingsTransferRegistry($this->sections());
    }

    /**
     * @param  list<string>  $sectionKeys
     * @return array<string, mixed>
     */
    public function export(int $userId, array $sectionKeys, bool $includePrompts = false, bool $includeTemplates = false): array
    {
        $this->assertManager();
        $settings = [];
        $excluded = [
            'recommendations' => 'Operator best-practice docs live in Global Help topics (not stored settings).',
            'workflow_task_ids' => 'Task/workflow numeric IDs are installation-local.',
        ];
        foreach ($this->registry()->all() as $section) {
            if ($sectionKeys !== [] && ! in_array($section->key(), $sectionKeys, true)) {
                continue;
            }
            $settings[$section->key()] = $section->export($userId);
        }

        $packageType = $includePrompts || $includeTemplates
            ? ConfigurationPackageType::SeoConfigurationBundle
            : ConfigurationPackageType::SeoSettings;

        $payload = [
            'package_type' => $packageType->value,
            'schema_version' => '1.0',
            'meta' => [
                'app' => 'seo-ops',
                'exported_at' => gmdate('c'),
                'kind' => 'configuration_export',
            ],
            'scope' => ['type' => 'workspace'],
            'settings' => $settings,
            '_excluded' => $excluded,
        ];
        if ($includePrompts) {
            $payload['prompts'] = app(PromptPackService::class)->export($userId);
        }
        if ($includeTemplates) {
            $payload['provider_templates'] = $this->exportTemplates($userId);
        }

        return $payload;
    }

    /**
     * @param  list<string>  $selected
     */
    public function plan(array $data, int $userId, string $mode, array $selected = []): ConfigurationImportPlan
    {
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $sections = [];
        $warnings = [];
        foreach ($this->registry()->all() as $section) {
            $key = $section->key();
            if (! array_key_exists($key, $settings)) {
                continue;
            }
            if ($selected !== [] && ! in_array($key, $selected, true)) {
                continue;
            }
            $incoming = is_array($settings[$key]) ? $settings[$key] : [];
            $diff = $section->diff($userId, $incoming);
            $diff['key'] = $key;
            $diff['selected'] = true;
            $sections[] = $diff;
            foreach ($diff['warnings'] as $warning) {
                $warnings[] = $warning;
            }
        }
        foreach (array_keys($settings) as $unknown) {
            if ($this->registry()->get($unknown) === null) {
                $warnings[] = 'Unknown settings module ignored: '.$unknown;
            }
        }

        $prompts = [];
        if (isset($data['prompts'])) {
            $pack = is_array($data['prompts']) ? $data['prompts'] : [];
            if (! isset($pack['prompts']) && array_is_list($pack)) {
                $pack = ['schema_version' => '1.0', 'prompts' => $pack];
            }
            $promptPlan = app(PromptPackService::class)->plan($pack, $userId, 'update');
            $prompts = $promptPlan->prompts;
            $warnings = array_merge($warnings, $promptPlan->warnings);
        }

        return new ConfigurationImportPlan(
            type: ConfigurationPackageType::tryFrom((string) ($data['package_type'] ?? '')) ?? ConfigurationPackageType::SeoSettings,
            schemaVersion: (string) ($data['schema_version'] ?? '1.0'),
            mode: $mode === 'replace' ? 'replace' : 'merge',
            sections: $sections,
            prompts: $prompts,
            warnings: $warnings,
            payload: $data,
        );
    }

    public function parseAndPlan(string $rawJson, int $userId, string $mode, array $selected = []): ConfigurationImportPlan
    {
        $data = $this->guard->decode($rawJson, ConfigurationPackageLimits::fullBundle());
        $type = (string) ($data['package_type'] ?? '');
        if (! in_array($type, [
            ConfigurationPackageType::SeoSettings->value,
            ConfigurationPackageType::SeoConfigurationBundle->value,
        ], true)) {
            throw ConfigurationPackageException::rejected('unexpected package_type for SEO settings.');
        }
        if (trim((string) ($data['schema_version'] ?? '')) !== '1.0') {
            throw ConfigurationPackageException::unsupportedVersion((string) ($data['schema_version'] ?? ''), '1.0');
        }

        return $this->plan($data, $userId, $mode, $selected);
    }

    /**
     * @param  list<string>  $selected
     * @param  array<int, string>  $promptOverrides
     */
    public function apply(ConfigurationImportPlan $plan, int $userId, array $selected = [], array $promptOverrides = []): int
    {
        $this->assertManager();
        $changed = 0;
        DB::transaction(function () use ($plan, $userId, $selected, $promptOverrides, &$changed): void {
            foreach ($plan->sections as $sectionPlan) {
                $key = (string) ($sectionPlan['key'] ?? '');
                if ($selected !== [] && ! in_array($key, $selected, true)) {
                    continue;
                }
                $section = $this->registry()->get($key);
                if ($section === null) {
                    continue;
                }
                $payload = is_array($sectionPlan['payload'] ?? null) ? $sectionPlan['payload'] : [];
                $section->apply($userId, $payload, $plan->mode);
                $changed += (int) ($sectionPlan['changed'] ?? 0);
            }
            if ($plan->prompts !== []) {
                $promptPlan = new ConfigurationImportPlan(
                    type: ConfigurationPackageType::PromptPack,
                    schemaVersion: $plan->schemaVersion,
                    mode: 'update',
                    sections: [],
                    prompts: $plan->prompts,
                    warnings: [],
                    payload: [],
                );
                $changed += app(PromptPackService::class)->apply($promptPlan, $userId, $promptOverrides);
            }
        });

        $this->auditor->record(
            $plan->type,
            $plan->schemaVersion,
            true,
            count($plan->sections),
            count($plan->prompts),
            $changed,
        );

        return $changed;
    }

    /**
     * @return list<PortableSettingsSection>
     */
    private function sections(): array
    {
        $overview = app(SeoOverviewSettingsService::class);
        $editor = app(ArticleEditorHistoryService::class);
        $datetime = app(SeoDateTimeSettingsService::class);
        $keywords = app(SeoKeywordSettingsService::class);
        $scoring = app(SeoScoringSettingsService::class);
        $promptRuntime = app(SeoPromptSettingsService::class);

        return [
            new ArrayOptionSection(
                'general',
                [
                    SeoOverviewSettingsService::KEY_OUTLINE_SKIP_WORDS,
                    SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS,
                    SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB,
                ],
                fn (): array => $overview->getSettings(),
                function (array $data) use ($overview): void {
                    $overview->saveSettings($data);
                },
            ),
            new ArrayOptionSection(
                'date_time',
                [SeoDateTimeSettingsService::KEY_TIMEZONE, SeoDateTimeSettingsService::KEY_PRESET],
                fn (): array => $datetime->getSettings(),
                function (array $data) use ($datetime): void {
                    $datetime->save($data);
                },
            ),
            new WorkflowBindingsSection(),
            new AiCenterSettingsSection(),
            new ArrayOptionSection(
                'article_editor',
                ['history_step', 'autosave_interval_seconds', 'wiki_trust_domains', SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS],
                function () use ($editor, $overview): array {
                    return array_merge($editor->getSettings(), [
                        SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $overview->getSettings()[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS],
                    ]);
                },
                function (array $data) use ($editor, $overview): void {
                    $editor->saveSettings($data);
                    if (array_key_exists(SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS, $data)) {
                        $overview->saveSettings([
                            SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $data[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS],
                        ]);
                    }
                },
            ),
            new ArrayOptionSection(
                'keywords',
                [SeoKeywordSettingsService::KEY_CTA_BLACKLIST],
                fn (): array => $keywords->getSettings(),
                function (array $data) use ($keywords): void {
                    $keywords->saveSettings($data);
                },
            ),
            new ArrayOptionSection(
                'seo_scoring',
                ['rules'],
                fn (): array => ['rules' => $scoring->getRuleOverrides()],
                function (array $data) use ($scoring): void {
                    $scoring->saveRuleOverrides(is_array($data['rules'] ?? null) ? $data['rules'] : []);
                },
            ),
            new ArrayOptionSection(
                'prompt_runtime',
                [
                    SeoPromptSettingsService::KEY_TONE_TEXT,
                    SeoPromptSettingsService::KEY_TONE_OF_VOICE,
                    SeoPromptSettingsService::KEY_ARTICLE_LENGTH_PRODUCT,
                    SeoPromptSettingsService::KEY_ARTICLE_LENGTH_DEFAULT,
                    SeoPromptSettingsService::KEY_KEYWORD_DENSITY_PRODUCT,
                    SeoPromptSettingsService::KEY_KEYWORD_DENSITY_DEFAULT,
                    SeoPromptSettingsService::KEY_DEFAULT_PROMPT_LANGUAGE,
                    SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MIN,
                    SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_RANGE,
                    SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MAX,
                    SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS,
                    SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS,
                ],
                fn (): array => $promptRuntime->getSettings(),
                function (array $data) use ($promptRuntime): void {
                    $promptRuntime->saveSettings(array_merge($promptRuntime->getSettings(), $data));
                },
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportTemplates(int $userId): array
    {
        $out = [];
        $catalog = new AiProviderTemplateCatalog();
        foreach ($catalog->builtins() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row['package_type'] = ConfigurationPackageType::AiProviderTemplate->value;
            $out[] = $row;
        }
        try {
            foreach (\Omnichannel\Addons\AiPrompt\Models\AiProviderTemplate::query()->where('user_id', $userId)->get() as $stored) {
                $config = is_array($stored->config) ? $stored->config : [];
                unset($config['api_key'], $config['token'], $config['password']);
                $config['package_type'] = ConfigurationPackageType::AiProviderTemplate->value;
                $config['credential'] = ['configured' => false, 'exported' => false];
                $out[] = $config;
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    private function assertManager(): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            throw ConfigurationPackageException::rejected('Not authorized to import or export settings.');
        }
    }
}
