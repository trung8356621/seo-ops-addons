<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\UsesScriptRegistryUi;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\WidgetWorkflowComposerService;

final class SocialAutomationWidgetsPage extends Page
{
    use Access;
    use UsesScriptRegistryUi;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $slug = 'widgets';

    protected static ?int $navigationSort = 11;

    protected static string $view = 'social-automation::filament.pages.widgets';

    public string $packageKey = '';

    public string $name = '';

    public string $version = '1.0.0';

    public string $scriptKey = '';

    public string $formFieldsJson = "[\n  {\"key\": \"text\", \"label\": \"Nội dung\", \"input_type\": \"textarea\", \"required\": true, \"script_variable\": \"text\", \"ai_policy\": \"eligible\"}\n]";

    public string $flash = '';

    public string $error = '';

    public string $previewJson = '';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.widgets.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.widgets.title');
    }

    /** @return list<array<string, mixed>> */
    public function widgets(): array
    {
        return $this->registryUi()->listWidgetsForUi();
    }

    /** @return list<array{package_key: string, version: string, content_digest: string, name: string}> */
    public function scriptOptions(): array
    {
        return app(WidgetWorkflowComposerService::class)->scriptOptions();
    }

    public function previewCompose(): void
    {
        $this->error = '';
        $this->flash = '';
        $manifest = $this->buildManifest();
        if ($manifest === null) {
            return;
        }
        $preview = app(WidgetWorkflowComposerService::class)->previewWidgetInput($manifest, 'manual', []);
        $this->previewJson = (string) json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (! ($preview['ok'] ?? false)) {
            $this->error = implode(', ', $preview['errors'] ?? ['preview_failed']);
        } else {
            $this->flash = 'Preview compose OK';
        }
    }

    public function publish(): void
    {
        $this->error = '';
        $this->flash = '';
        $payload = $this->buildPublishPayload();
        if ($payload === null) {
            return;
        }
        $userId = Auth::id();
        $result = app(WidgetWorkflowComposerService::class)->publishWidget(
            $payload,
            is_int($userId) ? $userId : null
        );
        if (! ($result['ok'] ?? false)) {
            $this->error = implode(', ', $result['errors'] ?? ['publish_failed']);

            return;
        }
        $this->flash = 'Published: '.($result['outcome'] ?? 'ok');
        Notification::make()->title('Widget published')->success()->send();
    }

    public function enableWidget(string $packageKey): void
    {
        $result = $this->registryUi()->setPackageEnabled($packageKey, true);
        if (! ($result['ok'] ?? false)) {
            $this->error = implode(', ', $result['errors'] ?? ['enable_failed']);

            return;
        }
        $this->flash = 'Enabled '.$packageKey;
    }

    public function disableWidget(string $packageKey): void
    {
        $result = $this->registryUi()->setPackageEnabled($packageKey, false);
        if (! ($result['ok'] ?? false)) {
            $this->error = implode(', ', $result['errors'] ?? ['disable_failed']);

            return;
        }
        $this->flash = 'Disabled '.$packageKey;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildManifest(): ?array
    {
        $fields = json_decode($this->formFieldsJson, true);
        if (! is_array($fields)) {
            $this->error = 'form_fields JSON không hợp lệ';

            return null;
        }
        $script = collect($this->scriptOptions())->firstWhere('package_key', $this->scriptKey);
        if (! is_array($script)) {
            $this->error = 'Chọn Script hợp lệ';

            return null;
        }

        return [
            'script_key' => $script['package_key'],
            'script_version' => $script['version'],
            'script_digest' => $script['content_digest'],
            'form_fields' => $fields,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPublishPayload(): ?array
    {
        $manifest = $this->buildManifest();
        if ($manifest === null) {
            return null;
        }
        $key = trim($this->packageKey);
        $name = trim($this->name);
        $version = trim($this->version);
        if ($key === '' || $name === '' || $version === '') {
            $this->error = 'package_key, name, version bắt buộc';

            return null;
        }

        return [
            'package_key' => $key,
            'kind' => 'widget',
            'name' => $name,
            'version' => $version,
            'schema_version' => 1,
            'manifest' => $manifest,
            'dependencies' => [[
                'package_key' => $manifest['script_key'],
                'version' => $manifest['script_version'],
                'content_digest' => $manifest['script_digest'],
            ]],
            'runtime_compatibility' => [],
        ];
    }
}
