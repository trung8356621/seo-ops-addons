<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationImportAuditor;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationImportPlan;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationJsonGuard;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationPackageLimits;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\TaskPackService;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateCatalog;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateParser;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateStore;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Support\ConfigurationPackageType;
use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Omnichannel\Addons\Seo\Services\SeoAnalyticsScopeSettingsService;
use Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService;
use Omnichannel\Addons\Seo\Services\SeoDateTimeSettingsService;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Services\SeoScoringSettingsService;
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
    public function export(
        int $userId,
        array $sectionKeys,
        bool $includePrompts = false,
        bool $includeTemplates = false,
        bool $includeTasks = false,
    ): array {
        $this->assertManager();
        $settings = [];
        $excluded = [
            'recommendations' => 'Operator best-practice docs live in Global Help topics (not stored settings).',
            'secrets' => 'API keys, tokens, and credentials are never exported.',
            'runtime_data' => 'Articles, execution history, test results, and runtime logs are excluded.',
        ];

        foreach ($this->registry()->all() as $section) {
            if ($sectionKeys !== [] && ! in_array($section->key(), $sectionKeys, true)) {
                continue;
            }
            $settings[$section->key()] = $section->export($userId);
        }

        $isFull = $includePrompts || $includeTemplates || $includeTasks;
        $packageType = $isFull
            ? ConfigurationPackageType::SeoConfigurationBundle
            : ConfigurationPackageType::SeoSettings;

        $payload = [
            'package_type' => $packageType->value,
            'schema_version' => '1.0',
            'meta' => [
                'app' => 'seo-ops',
                'exported_at' => gmdate('c'),
                'kind' => $isFull ? 'configuration_backup' : 'configuration_export',
            ],
            'scope' => ['type' => 'workspace'],
            'settings' => $settings,
            '_excluded' => $excluded,
        ];

        if ($includePrompts || $isFull) {
            $payload['prompts'] = app(PromptPackService::class)->export($userId, [], includeInactive: true);
        }
        if ($includeTasks || $isFull) {
            $payload['tasks'] = app(TaskPackService::class)->export($userId, [], includeInactive: true);
        }
        if ($includeTemplates || $isFull) {
            $payload['provider_templates'] = $this->exportTemplates($userId);
        }

        $this->assertNoSecrets($payload);

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

        $tasks = [];
        if (isset($data['tasks'])) {
            $tasksRaw = is_array($data['tasks']) ? $data['tasks'] : [];
            $tasks = app(TaskPackService::class)->plan($tasksRaw, $userId, $mode, $warnings);
        }

        $connections = [];
        $aiSettings = is_array($settings['ai'] ?? null) ? $settings['ai'] : [];
        if (isset($aiSettings['connections']) && is_array($aiSettings['connections'])) {
            $connections = $aiSettings['connections'];
        }

        $providerTemplates = is_array($data['provider_templates'] ?? null) ? $data['provider_templates'] : [];

        return new ConfigurationImportPlan(
            type: ConfigurationPackageType::tryFrom((string) ($data['package_type'] ?? '')) ?? ConfigurationPackageType::SeoSettings,
            schemaVersion: (string) ($data['schema_version'] ?? '1.0'),
            mode: $mode === 'replace' ? 'replace' : 'merge',
            sections: $sections,
            prompts: $prompts,
            warnings: $warnings,
            payload: $data,
            tasks: $tasks,
            connections: $connections,
            providerTemplates: $providerTemplates,
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
            // STEP 1: Provider templates
            $this->applyProviderTemplates($plan->payload['provider_templates'] ?? [], $userId, $changed);

            // STEP 2: Connection skeletons (before AI routing to ensure connections exist)
            $aiPayload = $this->extractSectionPayload($plan, 'ai');
            if ($aiPayload !== null && isset($aiPayload['connections']) && is_array($aiPayload['connections'])) {
                $aiSection = $this->registry()->get('ai');
                if ($aiSection instanceof AiCenterSettingsSection) {
                    $aiSection->restoreConnections($userId, $aiPayload['connections']);
                }
            }

            // STEP 3: Prompts (must be imported before tasks and workflow bindings)
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

            // STEP 4: Tasks / Workflows (resolve node prompt references to local IDs)
            if ($plan->tasks !== []) {
                $changed += app(TaskPackService::class)->apply($plan->tasks, $userId);
            }

            // STEP 5: Workflow bindings (resolve prompt and task bindings)
            $workflowPayload = $this->extractSectionPayload($plan, 'workflows');
            if ($workflowPayload !== null) {
                $workflowSection = $this->registry()->get('workflows');
                if ($workflowSection !== null) {
                    $workflowSection->apply($userId, $workflowPayload, $plan->mode);
                    $changed++;
                }
            }

            // STEP 6: AI Center routing & preferences
            if ($aiPayload !== null) {
                $aiSection = $this->registry()->get('ai');
                if ($aiSection !== null) {
                    $aiSection->apply($userId, $aiPayload, $plan->mode);
                    $changed++;
                }
            }

            // STEP 7: Remaining independent settings sections
            foreach ($plan->sections as $sectionPlan) {
                $key = (string) ($sectionPlan['key'] ?? '');
                if (in_array($key, ['workflows', 'ai'], true)) {
                    continue; // already applied in dependency order
                }
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

    public function assertNoSecrets(array $payload): void
    {
        $forbiddenKeys = [
            'api_key',
            'token',
            'access_token',
            'refresh_token',
            'password',
            'secret',
            'client_secret',
            'authorization',
        ];
        $this->walkAssertNoSecrets($payload, $forbiddenKeys);
    }

    private function walkAssertNoSecrets(mixed $node, array $forbiddenKeys): void
    {
        if (! is_array($node)) {
            if (is_string($node)) {
                $trimmed = trim($node);
                if (preg_match('/^(sk-|sk-or-|sk-ant-|AIza)[A-Za-z0-9_\-]{8,}$/', $trimmed) === 1
                    || preg_match('/^Bearer\s+\S{8,}/i', $trimmed) === 1) {
                    throw ConfigurationPackageException::rejected('Security violation: secret token pattern detected in configuration export.');
                }
            }

            return;
        }

        foreach ($node as $k => $v) {
            if (is_string($k)) {
                $lower = strtolower($k);
                if (in_array($lower, $forbiddenKeys, true)) {
                    if (is_string($v) && trim($v) !== '') {
                        throw ConfigurationPackageException::rejected('Security violation: forbidden key "'.$k.'" with value found in configuration export.');
                    }
                }
            }
            $this->walkAssertNoSecrets($v, $forbiddenKeys);
        }
    }

    private function applyProviderTemplates(mixed $templates, int $userId, int &$changed): void
    {
        if (! is_array($templates) || $templates === []) {
            return;
        }
        $catalog = new AiProviderTemplateCatalog();
        $builtins = array_keys($catalog->builtins());
        $parser = new AiProviderTemplateParser();
        $store = new AiProviderTemplateStore();

        foreach ($templates as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $providerKey = strtolower(trim((string) ($raw['provider']['key'] ?? '')));
            if ($providerKey === '') {
                continue;
            }
            if (in_array($providerKey, $builtins, true)) {
                continue;
            }
            try {
                $encoded = json_encode($raw, JSON_THROW_ON_ERROR);
                $normalized = $parser->parse($encoded);
                $store->persist($userId, $normalized, false);
                $changed++;
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractSectionPayload(ConfigurationImportPlan $plan, string $sectionKey): ?array
    {
        foreach ($plan->sections as $sec) {
            if (($sec['key'] ?? '') === $sectionKey) {
                return is_array($sec['payload'] ?? null) ? $sec['payload'] : [];
            }
        }
        $settings = is_array($plan->payload['settings'] ?? null) ? $plan->payload['settings'] : [];
        if (isset($settings[$sectionKey]) && is_array($settings[$sectionKey])) {
            return $settings[$sectionKey];
        }

        return null;
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
        $contentLanguage = app(SeoContentLanguageSettingsService::class);
        $analyticsScope = app(SeoAnalyticsScopeSettingsService::class);

        return [
            new ArrayOptionSection(
                'general',
                [
                    SeoOverviewSettingsService::KEY_OUTLINE_SKIP_WORDS,
                    SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS,
                    SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB,
                    SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS,
                    SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE,
                    SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS,
                ],
                fn (): array => array_merge(
                    $overview->getSettings(),
                    $contentLanguage->getSettings(),
                    $analyticsScope->getSettings(),
                ),
                function (array $data) use ($overview, $contentLanguage, $analyticsScope): void {
                    $overview->saveSettings($data);
                    if (array_key_exists(SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE, $data)) {
                        $contentLanguage->save($data);
                    }
                    if (array_key_exists(SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS, $data)) {
                        $analyticsScope->save($data);
                    }
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
                unset($config['api_key'], $config['token'], $config['password'], $config['secret']);
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
