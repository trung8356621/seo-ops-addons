<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationImportPlan;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationPackageLimits;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationPackageParser;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SeoSettingsBundleService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeoSettingsConfigurationTransfer extends Page
{
    use WithFileUploads;

    protected static ?string $slug = 'settings/configuration';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Import / Export';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-configuration-transfer';

    public string $intent = 'export';

    public string $focus = 'settings';

    public string $preset = 'settings';

    public string $mode = 'merge';

    public string $bulkPolicy = 'update';

    public string $importJson = '';

    /** @var TemporaryUploadedFile|null */
    public $importFile = null;

    /** @var array<string, mixed>|null */
    public ?array $importMeta = null;

    public bool $showAdvancedPaste = false;

    /** @var array<string, bool> */
    public array $exportSections = [];

    public bool $includePrompts = false;

    public bool $includeTemplates = false;

    /** @var list<int> */
    public array $selectedPromptIds = [];

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    protected $queryString = ['intent', 'focus'];

    public function mount(): void
    {
        foreach (app(SeoSettingsBundleService::class)->registry()->all() as $section) {
            $this->exportSections[$section->key()] = true;
        }
        if (! in_array($this->intent, ['export', 'import'], true)) {
            $this->intent = 'export';
        }
        if ($this->focus === 'prompts') {
            $this->preset = 'settings';
            $this->includePrompts = true;
            foreach (array_keys($this->exportSections) as $key) {
                $this->exportSections[$key] = false;
            }
        }
    }

    public function updatedImportFile(): void
    {
        $this->assertManager();
        $this->preview = null;
        $this->importMeta = null;
        if (! $this->importFile instanceof TemporaryUploadedFile) {
            return;
        }

        $maxKb = (int) ceil(ConfigurationPackageLimits::fullBundle()->maxBytes / 1024);
        $this->validate([
            'importFile' => 'required|file|mimes:json,txt|max:'.$maxKb,
        ]);

        $path = $this->importFile->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            Notification::make()->title(__('seo-content-ai::filament.settings_transfer.invalid_file'))->danger()->send();

            return;
        }

        $raw = (string) file_get_contents($path);
        $this->importJson = $raw;
        try {
            $parsed = app(ConfigurationPackageParser::class)->parse($raw);
            $data = $parsed['data'];
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $prompts = $data['prompts'] ?? [];
            $promptCount = 0;
            if (is_array($prompts)) {
                $list = is_array($prompts['prompts'] ?? null) ? $prompts['prompts'] : $prompts;
                $promptCount = array_is_list($list) ? count($list) : 0;
            }
            $this->importMeta = [
                'filename' => (string) $this->importFile->getClientOriginalName(),
                'package_type' => $parsed['type']->value,
                'schema_version' => $parsed['schema_version'],
                'size' => (int) $this->importFile->getSize(),
                'sections' => array_values(array_filter(array_map('strval', array_keys($settings)))),
                'prompts' => $promptCount,
            ];
        } catch (ConfigurationPackageException $exception) {
            $this->importJson = '';
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    public function downloadExport(SeoSettingsBundleService $bundle, PromptPackService $prompts): StreamedResponse
    {
        $this->assertManager();
        $userId = (int) auth()->id();
        $date = gmdate('Y-m-d');
        if ($this->focus === 'prompts') {
            $payload = $prompts->export($userId, $this->selectedPromptIds);
            $name = 'seo-ops-prompts-'.$date.'.json';
        } else {
            $keys = array_keys(array_filter($this->exportSections));
            $payload = $bundle->export(
                $userId,
                $keys,
                $this->includePrompts || $this->preset === 'full',
                $this->preset === 'full' || $this->includeTemplates,
            );
            $name = $this->preset === 'full'
                ? 'seo-ops-configuration-'.$date.'.json'
                : 'seo-ops-settings-'.$date.'.json';
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, $name, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function previewImport(SeoSettingsBundleService $bundle, PromptPackService $prompts): void
    {
        $this->assertManager();
        $this->preview = null;
        try {
            $plan = $this->buildPlan($bundle, $prompts);
            $this->preview = $plan->toArray();
        } catch (ConfigurationPackageException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    public function applyImport(SeoSettingsBundleService $bundle, PromptPackService $prompts): void
    {
        $this->assertManager();
        try {
            $plan = $this->buildPlan($bundle, $prompts);
            $userId = (int) auth()->id();
            $selected = array_values(array_filter(array_map(
                static fn (array $row): string => (string) ($row['key'] ?? ''),
                $plan->sections,
            )));
            if ($plan->type->value === 'prompt_pack' || $this->focus === 'prompts') {
                $count = $prompts->apply($plan, $userId);
            } else {
                $count = $bundle->apply($plan, $userId, $selected);
            }
            $this->preview = null;
            $this->importJson = '';
            $this->importFile = null;
            $this->importMeta = null;
            Notification::make()->title(__('seo-content-ai::filament.settings_transfer.imported', ['count' => $count]))->success()->send();
        } catch (ConfigurationPackageException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    /**
     * @return array<string, string>
     */
    public function sectionLabels(): array
    {
        return [
            'general' => __('seo-content-ai::filament.settings_transfer.section_general'),
            'date_time' => __('seo-content-ai::filament.settings_transfer.section_date_time'),
            'workflows' => __('seo-content-ai::filament.settings_transfer.section_workflows'),
            'ai' => __('seo-content-ai::filament.settings_transfer.section_ai'),
            'article_editor' => __('seo-content-ai::filament.settings_transfer.section_editor'),
            'keywords' => __('seo-content-ai::filament.settings_transfer.section_keywords'),
            'seo_scoring' => __('seo-content-ai::filament.settings_transfer.section_scoring'),
            'prompt_runtime' => __('seo-content-ai::filament.settings_transfer.section_prompt_runtime'),
        ];
    }

    public static function canAccess(): bool
    {
        if (request()->query('focus') === 'prompts') {
            return SeoAccessControl::canAccessPlannerFeatures();
        }

        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        unset($parameters);

        return false;
    }

    private function buildPlan(SeoSettingsBundleService $bundle, PromptPackService $prompts): ConfigurationImportPlan
    {
        $userId = (int) auth()->id();
        if ($this->focus === 'prompts') {
            return $prompts->parseAndPlan($this->importJson, $userId, $this->bulkPolicy);
        }

        return $bundle->parseAndPlan(
            $this->importJson,
            $userId,
            $this->mode,
            array_keys(array_filter($this->exportSections)),
        );
    }

    private function assertManager(): void
    {
        if ($this->focus === 'prompts') {
            if (! SeoAccessControl::canAccessPlannerFeatures()) {
                abort(403);
            }

            return;
        }
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            abort(403);
        }
    }
}
