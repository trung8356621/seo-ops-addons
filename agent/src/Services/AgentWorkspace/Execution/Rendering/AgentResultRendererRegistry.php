<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;

/**
 * Picks the first matching renderer. Renderers must not query business services.
 */
final class AgentResultRendererRegistry
{
    /** @var list<AgentResultRenderer> */
    private array $renderers;

    /**
     * @param  list<AgentResultRenderer>|null  $renderers
     */
    public function __construct(?array $renderers = null)
    {
        $this->renderers = $renderers ?? [
            new AgentErrorRenderer,
            new ContentProjectResultRenderer,
            new KeywordResultRenderer,
            new SerpResultRenderer,
            new SeoAuditResultRenderer,
            new GenericAgentResultRenderer,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function render(AgentExecutionResult $result): array
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($result)) {
                return $renderer->render($result);
            }
        }

        return (new GenericAgentResultRenderer)->render($result);
    }
}
