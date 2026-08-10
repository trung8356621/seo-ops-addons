<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;

/**
 * Presentation-only MCP capability markdown for Operation Center (developer/MCP reference).
 * Source of truth: CanonicalCapabilityRegistry — never hard-codes tool lists.
 * Global catalog — not bound to a Domain General page or site_feature manifest.
 */
final class McpCapabilityMarkdownPresenter
{
    public const FILTER_ALL = 'all';

    public const FILTER_READ = 'read';

    public const FILTER_WRITE = 'write';

    public const FILTER_CONFIRMATION = 'confirmation';

    public const FILTER_SITE_SYNC = 'site_sync';

    public const FILTER_CONTENT_PROJECT = 'content_project';

    public const FILTER_INTERNAL = 'internal';

    /** @var list<string> */
    public const FILTERS = [
        self::FILTER_ALL,
        self::FILTER_READ,
        self::FILTER_WRITE,
        self::FILTER_CONFIRMATION,
        self::FILTER_SITE_SYNC,
        self::FILTER_CONTENT_PROJECT,
        self::FILTER_INTERNAL,
    ];

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'handler',
        'input_schema',
        'password',
        'secret',
        'token',
        'api_key',
        'credentials',
        'authorization',
    ];

    public function __construct(
        private readonly CanonicalCapabilityRegistry $registry,
        private readonly ContentProjectMcpToolCatalog $catalog,
    ) {}

    /**
     * @return array{
     *     title: string,
     *     filter: string,
     *     filters: list<array{key: string, label: string}>,
     *     items: list<array<string, mixed>>,
     *     internal_items: list<array<string, mixed>>,
     *     markdown: string,
     *     include_internal: bool,
     *     count: int
     * }
     */
    public function present(bool $includeInternal = false, string $filter = self::FILTER_ALL): array
    {
        $mcpNames = $this->mcpToolNameSet();

        return $this->presentFromDefinitions(
            $this->registry->all(),
            $mcpNames,
            includeInternal: $includeInternal,
            filter: $filter,
        );
    }

    /**
     * Testable entry — accepts raw capability definitions.
     *
     * @param  list<array<string, mixed>>  $capabilities
     * @param  array<string, true>  $mcpToolNames
     * @return array{
     *     title: string,
     *     filter: string,
     *     filters: list<array{key: string, label: string}>,
     *     items: list<array<string, mixed>>,
     *     internal_items: list<array<string, mixed>>,
     *     markdown: string,
     *     include_internal: bool,
     *     count: int
     * }
     */
    public function presentFromDefinitions(
        array $capabilities,
        array $mcpToolNames,
        bool $includeInternal = false,
        string $filter = self::FILTER_ALL,
    ): array {
        $filter = in_array($filter, self::FILTERS, true) ? $filter : self::FILTER_ALL;

        $public = [];
        $internal = [];

        foreach ($capabilities as $cap) {
            if (! is_array($cap)) {
                continue;
            }

            $row = $this->normalize($cap, $mcpToolNames);
            if ($row === null) {
                continue;
            }

            if ($row['internal']) {
                $internal[] = $row;
            } else {
                $public[] = $row;
            }
        }

        $visibleInternal = $includeInternal ? $this->applyFilter($internal, $filter) : [];
        $visiblePublic = $filter === self::FILTER_INTERNAL
            ? []
            : $this->applyFilter($public, $filter);

        if ($filter === self::FILTER_INTERNAL) {
            $visiblePublic = [];
            $visibleInternal = $includeInternal ? $this->applyFilter($internal, self::FILTER_ALL) : [];
        }

        $markdownItems = $visiblePublic;
        if ($includeInternal && $visibleInternal !== []) {
            $markdownItems = array_merge($visiblePublic, $visibleInternal);
        }

        return [
            'title' => 'MCP Capabilities',
            'filter' => $filter,
            'filters' => $this->filterOptions($includeInternal),
            'items' => $visiblePublic,
            'internal_items' => $visibleInternal,
            'markdown' => $this->toMarkdown($markdownItems, $includeInternal && $visibleInternal !== []),
            'include_internal' => $includeInternal,
            'count' => count($visiblePublic) + count($visibleInternal),
        ];
    }

    /**
     * @param  array<string, mixed>  $cap
     * @param  array<string, true>  $mcpToolNames
     * @return array<string, mixed>|null
     */
    private function normalize(array $cap, array $mcpToolNames): ?array
    {
        $name = trim((string) ($cap['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $readOnly = (bool) ($cap['read_only'] ?? ((string) ($cap['risk_level'] ?? '') === 'read'));
        $type = $readOnly ? 'Read' : 'Write';
        $confirmationRequired = (bool) ($cap['confirmation_requirement'] ?? false);
        $confirmationModes = array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) ($cap['confirmation_modes'] ?? ($confirmationRequired ? ['confirm'] : [])),
        ), static fn (string $v): bool => $v !== ''));

        $enabled = (bool) ($cap['enabled'] ?? true);
        $unhealthy = (bool) ($cap['unhealthy'] ?? false);
        $internal = (bool) ($cap['internal'] ?? ((string) ($cap['visibility'] ?? 'public') === 'internal'));

        if (array_key_exists('agent_exposed', $cap)) {
            $agentExposed = (bool) $cap['agent_exposed'];
        } elseif (! $readOnly && $this->registry->get($name) !== null) {
            $agentExposed = $this->isAgentExposedName($name);
        } else {
            $agentExposed = true;
        }

        $mcpOverride = $cap['mcp_exposed'] ?? null;
        $mcpExposed = is_bool($mcpOverride)
            ? $mcpOverride
            : isset($mcpToolNames[$name]) || isset($mcpToolNames[$this->mcpAlias($name)]);

        $status = [];
        $status[] = $enabled ? 'enabled' : 'disabled';
        if ($unhealthy) {
            $status[] = 'unhealthy';
        }
        if ($internal) {
            $status[] = 'internal-only';
        }
        // Disabled caps must not advertise agent/MCP exposure.
        if ($enabled && $agentExposed) {
            $status[] = 'exposed-to-agent';
        }
        if ($enabled && $mcpExposed) {
            $status[] = 'exposed-to-mcp';
        }

        $confirmationNote = $cap['confirmation_note'] ?? null;
        if (! is_string($confirmationNote) || $confirmationNote === '') {
            $confirmationNote = $confirmationRequired || $confirmationModes !== [] ? 'Có' : 'Không';
        }

        $scopes = array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) ($cap['scopes'] ?? [(string) ($cap['required_permission'] ?? '')]),
        ), static fn (string $v): bool => $v !== ''));

        $inputSummary = array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) ($cap['input_summary'] ?? array_keys(is_array($cap['input_schema'] ?? null) ? $cap['input_schema'] : [])),
        ), static fn (string $v): bool => $v !== '' && ! self::isSensitiveKey($v)));

        $outputSummary = array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) ($cap['output_summary'] ?? []),
        ), static fn (string $v): bool => $v !== ''));

        $examples = array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) ($cap['examples'] ?? []),
        ), static fn (string $v): bool => $v !== ''));

        return [
            'name' => $name,
            'title' => (string) ($cap['label'] ?? $name),
            'description' => (string) ($cap['presentation_description'] ?? $cap['description'] ?? $name),
            'type' => $type,
            'capability_kind' => (string) ($cap['capability_kind'] ?? 'system_action'),
            'action_domain' => (string) ($cap['action_domain'] ?? $cap['category'] ?? 'general'),
            'required_context' => array_values(array_filter(array_map(
                static fn (mixed $v): string => trim((string) $v),
                (array) ($cap['required_context'] ?? []),
            ), static fn (string $v): bool => $v !== '')),
            'side_effect_level' => (string) ($cap['side_effect_level'] ?? ($readOnly ? 'none' : 'write')),
            'scopes' => $scopes,
            'input_summary' => $inputSummary,
            'output_summary' => $outputSummary,
            'confirmation' => $confirmationRequired || $confirmationModes !== [],
            'confirmation_policy' => $confirmationNote,
            'confirmation_modes' => $confirmationModes,
            'examples' => $examples,
            'status' => $status,
            'category' => (string) ($cap['category'] ?? 'general'),
            'internal' => $internal,
            'enabled' => $enabled,
            'unhealthy' => $unhealthy,
            'exposed_to_agent' => $agentExposed,
            'exposed_to_mcp' => $mcpExposed,
        ];
    }

    private function isAgentExposedName(string $name): bool
    {
        try {
            return $this->registry->isAgentWriteExposed($name);
        } catch (\Throwable) {
            return true;
        }
    }

    private function mcpAlias(string $name): string
    {
        return $name === 'content_project.rerun' ? 'content_project.rerun_items' : $name;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $needle) {
            if ($lower === $needle || str_contains($lower, $needle)) {
                // confirmation_token is a field name users need to know exists — keep unless exact secret.
                if ($needle === 'token' && $lower === 'confirmation_token') {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyFilter(array $items, string $filter): array
    {
        return array_values(array_filter($items, static function (array $row) use ($filter): bool {
            return match ($filter) {
                self::FILTER_READ => ($row['type'] ?? '') === 'Read',
                self::FILTER_WRITE => ($row['type'] ?? '') === 'Write',
                self::FILTER_CONFIRMATION => (bool) ($row['confirmation'] ?? false),
                self::FILTER_SITE_SYNC => ($row['category'] ?? '') === 'site_sync' || str_starts_with((string) ($row['name'] ?? ''), 'site.'),
                self::FILTER_CONTENT_PROJECT => ($row['category'] ?? '') === 'content_project' || str_starts_with((string) ($row['name'] ?? ''), 'content_project.'),
                self::FILTER_INTERNAL => (bool) ($row['internal'] ?? false),
                default => true,
            };
        }));
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function filterOptions(bool $includeInternal): array
    {
        $options = [
            ['key' => self::FILTER_ALL, 'label' => 'All'],
            ['key' => self::FILTER_READ, 'label' => 'Read'],
            ['key' => self::FILTER_WRITE, 'label' => 'Write'],
            ['key' => self::FILTER_CONFIRMATION, 'label' => 'Requires confirmation'],
            ['key' => self::FILTER_SITE_SYNC, 'label' => 'Site Sync'],
            ['key' => self::FILTER_CONTENT_PROJECT, 'label' => 'Content Project'],
        ];

        if ($includeInternal) {
            $options[] = ['key' => self::FILTER_INTERNAL, 'label' => 'Internal'];
        }

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function toMarkdown(array $items, bool $hasInternalSection): string
    {
        $lines = [
            '# MCP Capabilities',
            '',
            '_Global system_action catalog from CanonicalCapabilityRegistry. Not site_feature flags. Not bound to any Domain General page._',
            '',
        ];

        $public = [];
        $internal = [];
        foreach ($items as $item) {
            if (($item['internal'] ?? false) === true) {
                $internal[] = $item;
            } else {
                $public[] = $item;
            }
        }

        foreach ($public as $item) {
            $this->appendCapabilityMarkdown($lines, $item);
        }

        if ($hasInternalSection && $internal !== []) {
            $lines[] = '## Internal capabilities';
            $lines[] = '';
            foreach ($internal as $item) {
                $this->appendCapabilityMarkdown($lines, $item);
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $item
     */
    private function appendCapabilityMarkdown(array &$lines, array $item): void
    {
        $name = (string) ($item['name'] ?? '');
        $lines[] = '### '.$name;
        $lines[] = '';
        $lines[] = 'Kind: '.(string) ($item['capability_kind'] ?? 'system_action');
        $lines[] = 'Domain: '.(string) ($item['action_domain'] ?? $item['category'] ?? 'general');
        $requiredContext = (array) ($item['required_context'] ?? []);
        $lines[] = 'Required context: '.($requiredContext === []
            ? '—'
            : implode(', ', array_map(static fn (mixed $c): string => (string) $c, $requiredContext)));
        $scopes = (array) ($item['scopes'] ?? []);
        $scopeText = $scopes === [] ? '—' : implode(', ', array_map(
            static fn (mixed $s): string => '`'.(string) $s.'`',
            $scopes,
        ));
        $lines[] = 'Required scopes: '.$scopeText;
        $lines[] = 'Confirmation: '.(string) ($item['confirmation_policy'] ?? 'Không');
        $lines[] = 'Side effects: '.(string) ($item['side_effect_level'] ?? ($item['type'] ?? 'Write'));
        $lines[] = 'Type: '.(string) ($item['type'] ?? 'Write');
        $lines[] = 'Description: '.(string) ($item['description'] ?? '');
        $exposed = [];
        if (($item['exposed_to_agent'] ?? false) === true) {
            $exposed[] = 'agent';
        }
        if (($item['exposed_to_mcp'] ?? false) === true) {
            $exposed[] = 'mcp';
        }
        $lines[] = 'Exposed: '.($exposed === [] ? '—' : implode(', ', $exposed));
        $status = (array) ($item['status'] ?? []);
        if ($status !== []) {
            $lines[] = 'Status: '.implode(', ', array_map(static fn (mixed $s): string => (string) $s, $status));
        }
        $lines[] = '';
        $lines[] = '#### Input schema';
        $lines[] = '';
        $inputs = (array) ($item['input_summary'] ?? []);
        if ($inputs === []) {
            $lines[] = '- _(none)_';
        } else {
            foreach ($inputs as $input) {
                $lines[] = '- `'.(string) $input.'`';
            }
        }
        $lines[] = '';
        $lines[] = '#### Output schema';
        $lines[] = '';
        $outputs = (array) ($item['output_summary'] ?? []);
        if ($outputs === []) {
            $lines[] = '- _(n/a)_';
        } else {
            foreach ($outputs as $output) {
                $lines[] = '- '.(string) $output;
            }
        }
        $examples = (array) ($item['examples'] ?? []);
        if ($examples !== []) {
            $lines[] = '';
            $lines[] = '#### Examples';
            $lines[] = '';
            foreach ($examples as $example) {
                $lines[] = '`'.(string) $example.'`';
                $lines[] = '';
            }
        } else {
            $lines[] = '';
        }
        $lines[] = '---';
        $lines[] = '';
    }

    /**
     * @return array<string, true>
     */
    private function mcpToolNameSet(): array
    {
        $set = [];
        foreach ($this->catalog->listTools() as $tool) {
            $name = trim((string) ($tool['name'] ?? ''));
            if ($name !== '') {
                $set[$name] = true;
            }
        }

        return $set;
    }
}
