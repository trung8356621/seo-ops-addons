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
    protected static ?string $slug = 'settings/semantic-routing';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'agent-runtime::semantic-routing';

    public string $level = 'global';

    public string $module = 'keywords';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, string> */
    public array $exampleText = [];

    public string $lexicalText = '';

    /** @var array{present: bool, revision: int|null, authoritative: false} */
    public array $persistedCompatibility = ['present' => false, 'revision' => null, 'authoritative' => false];

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
            $decoded = json_decode($this->lexicalText, true);
            if (! is_array($decoded)) {
                Notification::make()->title('Lexical Hints JSON is invalid.')->danger()->send();
                return;
            }
            $document['lexical_hints'] = $decoded;
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

    public function getTitle(): string
    {
        return 'Semantic Routing';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user instanceof \App\Models\User
            && in_array((string) $user->role, [\App\Models\User::ROLE_OWNER, \App\Models\User::ROLE_ADMIN], true)
        ) {
            return true;
        }

        return class_exists(SeoAccessControl::class) && SeoAccessControl::canAccessManagerFeatures();
    }

    /** @return list<string> */
    public function moduleOptions(): array
    {
        return SemanticOperationRegistry::moduleKeys();
    }

    private function loadRows(SemanticRoutingConfig $config): void
    {
        $this->persistedCompatibility = $config->persistedCompatibilityStatus();
        $groups = $this->level === 'global'
            ? $config->globalGroups()
            : $config->moduleGroups($this->module);
        $this->rows = $groups;
        $this->exampleText = [];
        foreach ($groups as $group) {
            $this->exampleText[(string) $group['id']] = implode("\n", $group['examples']);
        }
        $this->lexicalText = json_encode($config->document()['lexical_hints'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';
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
