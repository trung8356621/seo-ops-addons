<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentChatTemplate;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Templates\BuiltinChatTemplateCatalog;
use Throwable;

final class AgentChatTemplateRegistry
{
    /** @var list<AgentChatTemplate>|null */
    private ?array $templates = null;

    /**
     * @param  list<array<string, mixed>>|null  $definitions
     */
    public function __construct(
        private readonly ?array $definitions = null,
        private readonly ?AgentPackRegistry $packs = null,
    ) {}

    public function invalidate(): void
    {
        $this->templates = null;
    }
    /**
     * @return list<AgentChatTemplate>
     */
    public function all(): array
    {
        return $this->boot();
    }

    public function get(string $key): ?AgentChatTemplate
    {
        foreach ($this->boot() as $template) {
            if ($template->key === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return list<AgentChatTemplate>
     */
    public function featured(): array
    {
        return array_values(array_filter(
            $this->boot(),
            static fn (AgentChatTemplate $t): bool => $t->isFeatured,
        ));
    }

    /**
     * @return list<AgentChatTemplate>
     */
    private function boot(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $raw = $this->definitions ?? BuiltinChatTemplateCatalog::definitions();
        if ($this->definitions === null && $this->packs !== null) {
            try {
                $raw = array_merge($raw, $this->packs->enabledTemplateDefinitions());
            } catch (Throwable) {
                // isolate
            }
        }
        $templates = [];
        $seen = [];
        foreach ($raw as $row) {
            $tpl = AgentChatTemplate::fromArray($row);
            if ($tpl->key === '' || isset($seen[$tpl->key])) {
                continue;
            }
            $seen[$tpl->key] = true;
            $templates[] = $tpl;
        }

        usort(
            $templates,
            static fn (AgentChatTemplate $a, AgentChatTemplate $b): int => $a->sortOrder <=> $b->sortOrder,
        );

        $this->templates = $templates;

        return $templates;
    }
}
