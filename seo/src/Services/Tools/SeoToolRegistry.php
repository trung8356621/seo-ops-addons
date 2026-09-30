<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools;

use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Tools\Handlers\ContentProjectDraftIntakeToolHandler;
use Omnichannel\Addons\Seo\Services\Tools\Handlers\SeoAuditListToolHandler;

final class SeoToolRegistry
{
    /** @var array<string, SeoToolDefinition> */
    private array $definitions = [];

    /** @var array<string, SeoToolHandlerInterface> */
    private array $handlers = [];

    /**
     * Register a tool definition and its handler.
     */
    public function register(SeoToolDefinition $definition, SeoToolHandlerInterface $handler): void
    {
        $key = $definition->key;
        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException("Tool '{$key}' is already registered.");
        }

        if ($handler->getToolKey() !== $key) {
            throw new InvalidArgumentException(
                "Handler tool key '{$handler->getToolKey()}' does not match definition key '{$key}'."
            );
        }

        $this->definitions[$key] = $definition;
        $this->handlers[$key] = $handler;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function getDefinition(string $key): ?SeoToolDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function getHandler(string $key): ?SeoToolHandlerInterface
    {
        return $this->handlers[$key] ?? null;
    }

    /**
     * @return array<string, SeoToolDefinition>
     */
    public function allDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Filter definitions visible and authorized for the given execution context.
     * Public tool discovery must NOT leak PHP classes, internal URLs, or unexposed tools.
     *
     * @param SeoToolContext $context
     * @return list<array<string, mixed>>
     */
    public function listForContext(SeoToolContext $context): array
    {
        $visible = [];

        foreach ($this->definitions as $def) {
            if (!$def->isExposed()) {
                continue;
            }

            if (!$context->satisfiesAllScopes($def->scopes)) {
                continue;
            }

            $tool = $def->toPublicArray();
            $missing = $def->getMissingContext($context);
            $tool['availability'] = empty($missing)
                ? ['available' => true]
                : ['available' => false, 'reason' => in_array(SeoToolDefinition::CONTEXT_SITE_REF, $missing, true) ? 'missing_site_context' : 'missing_context'];
            $visible[] = $tool;
        }

        return $visible;
    }

    /**
     * Factory to build default registry with canonical SEO and ContentProject tools.
     */
    public static function buildDefault(
        SeoAuditListToolHandler $seoAuditHandler,
        ContentProjectDraftIntakeToolHandler $draftIntakeHandler
    ): self {
        $registry = new self();

        // 1. seo_audit.list
        $registry->register(
            new SeoToolDefinition(
                key: SeoAuditListToolHandler::TOOL_KEY,
                name: 'List SEO Audit Articles',
                description: 'List articles with SEO audit scoring and optimization recommendations for a site.',
                module: 'seo_audit',
                kind: SeoToolDefinition::KIND_READ,
                scopes: ['seo:read'],
                requiredContext: [SeoToolDefinition::CONTEXT_SITE_REF],
                confirmationPolicy: SeoToolDefinition::CONFIRMATION_NONE,
                isExposed: true,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'post_type' => [
                            'type' => 'string',
                            'description' => 'Optional post type filter (post, article, page, etc.).',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 100,
                            'description' => 'Maximum items to return (1-100). Default is 50.',
                        ],
                        'rules' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of specific rule keys to filter by.',
                        ],
                        'low_score' => [
                            'type' => 'boolean',
                            'description' => 'Filter only articles with low SEO score.',
                        ],
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ]
            ),
            $seoAuditHandler
        );

        // 2. content_project.draft_intake
        $registry->register(
            new SeoToolDefinition(
                key: ContentProjectDraftIntakeToolHandler::TOOL_KEY,
                name: 'Intake Items into Planning Draft',
                description: 'Intake new or rewrite content items into the shared planning draft for a site.',
                module: 'content_projects',
                kind: SeoToolDefinition::KIND_WRITE,
                scopes: ['content-projects:draft:write'],
                requiredContext: [SeoToolDefinition::CONTEXT_SITE_REF],
                confirmationPolicy: SeoToolDefinition::CONFIRMATION_REQUIRED,
                isExposed: true,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'description' => 'List of content items to intake into planning draft (1-100 items).',
                            'minItems' => 1,
                            'maxItems' => 100,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'keyword' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'type' => ['type' => 'string', 'enum' => ['new', 'rewrite']],
                                    'source' => ['type' => 'string'],
                                    'keyword_id' => ['type' => 'integer'],
                                    'keyword_ref' => ['type' => 'string'],
                                    'article_id' => ['type' => 'integer'],
                                    'article_ref' => ['type' => 'string'],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['items'],
                    'additionalProperties' => false,
                ]
            ),
            $draftIntakeHandler
        );

        return $registry;
    }
}

