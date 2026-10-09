<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Omnichannel\Addons\AgentRuntime\Routing\SemanticOperationRegistry;
use Omnichannel\Addons\AgentRuntime\Routing\SemanticRoutingConfig;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

class SemanticRoutingPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Semantic Routing';

    protected static ?string $slug = 'semantic-routing';

    protected static ?int $navigationSort = 13;

    protected static string $view = 'agent-runtime::semantic-routing';

    public string $level = 'global';

    public string $module = 'keywords';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, string> */
    public array $exampleText = [];

    public function mount(SemanticRoutingConfig $config): void
    {
        $this->loadRows($config);
    }

    public function updatedLevel(): void
    {
        $this->loadRows(new SemanticRoutingConfig());
    }

    public function updatedModule(): void
    {
        if ($this->level === 'module') {
            $this->loadRows(new SemanticRoutingConfig());
        }
    }

    public function save(SemanticRoutingConfig $config): void
    {
        $document = $config->document();
        $rows = $this->rowsFromForm();
        if ($this->level === 'global') {
            $document['global'] = $rows;
        } else {
            $document['modules'][$this->module] = $rows;
        }
        try {
            $config->save($document);
        } catch (Throwable $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }
        $this->loadRows(new SemanticRoutingConfig());
        Notification::make()->title('Đã lưu semantic routing.')->success()->send();
    }

    public static function canAccess(): bool
    {
        if (class_exists(SeoAccessControl::class) && ! SeoAccessControl::canAccessSeoPanel()) {
            return false;
        }
        $user = auth()->user();
        if ($user === null) {
            return false;
        }
        $role = $user->role ?? null;
        if ($role === 'owner' || $role === 'admin') {
            return true;
        }

        return method_exists($user, 'hasRole') && ($user->hasRole('owner') || $user->hasRole('admin'));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** @return list<string> */
    public function moduleOptions(): array
    {
        return SemanticOperationRegistry::moduleKeys();
    }

    private function loadRows(SemanticRoutingConfig $config): void
    {
        $groups = $this->level === 'global'
            ? $config->globalGroups()
            : $config->moduleGroups($this->module);
        $this->rows = $groups;
        $this->exampleText = [];
        foreach ($groups as $group) {
            $this->exampleText[(string) $group['id']] = implode("\n", $group['examples']);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rowsFromForm(): array
    {
        $rows = [];
        foreach ($this->rows as $group) {
            if (! is_array($group)) {
                continue;
            }
            $id = (string) ($group['id'] ?? '');
            $lines = preg_split('/\r\n|\r|\n/', (string) ($this->exampleText[$id] ?? '')) ?: [];
            $group['examples'] = array_values(array_filter(array_map('trim', $lines)));
            $rows[] = $group;
        }

        return $rows;
    }
}
