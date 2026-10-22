<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\UsesScriptRegistryUi;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\WidgetWorkflowComposerService;

final class SocialAutomationWorkflowsPage extends Page
{
    use Access;
    use UsesScriptRegistryUi;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $slug = 'workflows';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'social-automation::filament.pages.workflows';

    public string $packageKey = '';

    public string $name = '';

    public string $version = '1.0.0';

    public string $stepsJson = "[\n  {\"widget_key\": \"example.widget\", \"input\": {}},\n  {\"widget_key\": \"example.comment\", \"output_bindings\": {\"url\": \"example.widget.post_url\"}}\n]";

    public string $flash = '';

    public string $error = '';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.workflows.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.workflows.title');
    }

    /** @return list<array<string, mixed>> */
    public function workflows(): array
    {
        return $this->registryUi()->listWorkflowsForUi();
    }

    /** @return list<array{package_key: string, version: string|null, name: string, is_enabled: bool}> */
    public function widgetOptions(): array
    {
        return app(WidgetWorkflowComposerService::class)->widgetOptions();
    }

    public function validateSteps(): void
    {
        $payload = $this->buildPayload();
        if ($payload === null) {
            return;
        }
        $validated = app(PackagePublishValidator::class)->validate($payload);
        if (! ($validated['ok'] ?? false)) {
            $this->error = implode(', ', $validated['errors'] ?? ['invalid']);
            $this->flash = '';

            return;
        }
        $this->error = '';
        $this->flash = 'Workflow payload hợp lệ';
    }

    public function publish(): void
    {
        $payload = $this->buildPayload();
        if ($payload === null) {
            return;
        }
        $userId = Auth::id();
        $result = app(WidgetWorkflowComposerService::class)->publishWorkflow(
            $payload,
            is_int($userId) ? $userId : null
        );
        if (! ($result['ok'] ?? false)) {
            $this->error = implode(', ', $result['errors'] ?? ['publish_failed']);
            $this->flash = '';

            return;
        }
        $this->error = '';
        $this->flash = 'Published: '.($result['outcome'] ?? 'ok');
        Notification::make()->title('Workflow published')->success()->send();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPayload(): ?array
    {
        $this->error = '';
        $steps = json_decode($this->stepsJson, true);
        if (! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            $this->error = 'steps JSON phải là mảng Widget tuần tự';

            return null;
        }
        $key = trim($this->packageKey);
        $name = trim($this->name);
        $version = trim($this->version);
        if ($key === '' || $name === '' || $version === '') {
            $this->error = 'package_key, name, version bắt buộc';

            return null;
        }

        $deps = [];
        $options = collect($this->widgetOptions())->keyBy('package_key');
        foreach ($steps as $i => $step) {
            if (! is_array($step)) {
                $this->error = 'step invalid:'.$i;

                return null;
            }
            $widgetKey = trim((string) ($step['widget_key'] ?? $step['package_key'] ?? ''));
            $widget = $options->get($widgetKey);
            if (! is_array($widget) || empty($widget['version'])) {
                $this->error = 'widget_missing_or_unpublished:'.$widgetKey;

                return null;
            }
            if (! ($widget['is_enabled'] ?? true)) {
                $this->error = 'widget_disabled:'.$widgetKey;

                return null;
            }
            $release = app(\Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService::class)
                ->findRelease($widgetKey, (string) $widget['version']);
            if ($release === null) {
                $this->error = 'widget_release_missing:'.$widgetKey;

                return null;
            }
            $deps[] = [
                'package_key' => $widgetKey,
                'version' => (string) $widget['version'],
                'content_digest' => (string) $release['content_digest'],
            ];
        }

        return [
            'package_key' => $key,
            'kind' => 'workflow',
            'name' => $name,
            'version' => $version,
            'schema_version' => 1,
            'manifest' => ['steps' => $steps],
            'dependencies' => $deps,
            'runtime_compatibility' => [],
        ];
    }
}
