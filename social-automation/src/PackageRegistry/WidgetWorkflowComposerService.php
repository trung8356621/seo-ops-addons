<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Omnichannel\Addons\SocialAutomation\Domain\WidgetFormSchema;
use Omnichannel\Addons\SocialAutomation\Domain\WidgetInputComposer;

/**
 * Filament-facing helpers to compose/publish Widget & Workflow packages
 * using existing validators/composers (no parallel stack).
 */
final class WidgetWorkflowComposerService
{
    public function __construct(
        private readonly PackageRegistryService $registry,
        private readonly WidgetFormSchema $forms = new WidgetFormSchema,
        private readonly WidgetInputComposer $inputs = new WidgetInputComposer,
        private readonly ScriptRegistryUiService $ui = new ScriptRegistryUiService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, preview?: array<string, mixed>, errors?: list<string>, outcome?: string}
     */
    public function publishWidget(array $input, ?int $userId = null): array
    {
        $result = $this->registry->publish($input, [
            'user_id' => $userId,
            'sync_source' => 'seo_ops_ui',
        ]);

        return [
            'ok' => $result->outcome === PackagePublishResult::OUTCOME_CREATED
                || $result->outcome === PackagePublishResult::OUTCOME_IDEMPOTENT,
            'outcome' => $result->outcome,
            'errors' => $result->errors,
            'release' => $result->release,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, preview?: array<string, mixed>, errors?: list<string>, outcome?: string}
     */
    public function publishWorkflow(array $input, ?int $userId = null): array
    {
        return $this->publishWidget($input, $userId);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $manual
     * @return array<string, mixed>
     */
    public function previewWidgetInput(array $manifest, string $mode, array $manual = []): array
    {
        return $this->inputs->compose($manifest, $mode, $manual);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{ok: bool, errors: list<string>, fields: list<array<string, mixed>>, script_ref: ?array<string, string>}
     */
    public function parseForm(array $manifest): array
    {
        return $this->forms->parse($manifest);
    }

    /**
     * @return list<array{package_key: string, version: string, content_digest: string, name: string}>
     */
    public function scriptOptions(): array
    {
        $out = [];
        foreach ($this->ui->listScriptsForUi() as $script) {
            $out[] = [
                'package_key' => (string) $script['package_key'],
                'version' => (string) $script['version'],
                'content_digest' => (string) $script['content_digest'],
                'name' => (string) $script['name'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{package_key: string, version: string|null, name: string, is_enabled: bool}>
     */
    public function widgetOptions(): array
    {
        $out = [];
        foreach ($this->ui->listWidgetsForUi() as $widget) {
            $out[] = [
                'package_key' => (string) $widget['package_key'],
                'version' => $widget['version'] !== null ? (string) $widget['version'] : null,
                'name' => (string) $widget['name'],
                'is_enabled' => (bool) $widget['is_enabled'],
            ];
        }

        return $out;
    }
}
