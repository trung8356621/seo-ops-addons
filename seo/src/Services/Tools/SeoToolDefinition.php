<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools;

use InvalidArgumentException;

/**
 * Single source of truth for an exposed SEO tool capability.
 */
final class SeoToolDefinition
{
    public const CONTEXT_SITE_REF = 'site_ref';
    public const CONTEXT_TENANT_REF = 'tenant_ref';
    public const CONTEXT_ACTOR_REF = 'actor_ref';
    private const SUPPORTED_CONTEXT = [self::CONTEXT_SITE_REF, self::CONTEXT_TENANT_REF, self::CONTEXT_ACTOR_REF];
    public const KIND_READ = 'read';

    public const KIND_WRITE = 'write';

    public const CONFIRMATION_NONE = 'none';

    public const CONFIRMATION_REQUIRED = 'required';

    public const WRITE_ALLOWED_NAMESPACES = [
        'draft',
        'topic',
    ];

    /** @var list<string> */
    public readonly array $scopes;

    /** @var list<string> */
    public readonly array $requiredContext;

    /**
     * @param list<string> $scopes
     * @param list<string> $requiredContext
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly string $module,
        public readonly string $kind,
        array $scopes,
        array $requiredContext,
        public readonly string $confirmationPolicy,
        public readonly array $inputSchema,
        public readonly bool $isExposed = true,
        public readonly bool $enabled = true
    ) {
        $cleanKey = trim($key);
        if ($cleanKey === '' || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $cleanKey) !== 1) {
            throw new InvalidArgumentException("Invalid tool key format: '{$key}'. Expected format like 'module.action'.");
        }

        if ($kind !== self::KIND_READ && $kind !== self::KIND_WRITE) {
            throw new InvalidArgumentException("Invalid tool kind: '{$kind}'. Allowed: 'read', 'write'.");
        }

        if ($kind === self::KIND_WRITE) {
            $namespace = explode('.', $cleanKey, 2)[0];
            if (!in_array($namespace, self::WRITE_ALLOWED_NAMESPACES, true)) {
                throw new InvalidArgumentException(
                    "Write tool '{$key}' belongs to forbidden namespace '{$namespace}'. Write tools are restricted to namespaces: " . implode(', ', self::WRITE_ALLOWED_NAMESPACES) . "."
                );
            }
        }

        if ($confirmationPolicy !== self::CONFIRMATION_NONE && $confirmationPolicy !== self::CONFIRMATION_REQUIRED) {
            throw new InvalidArgumentException("Invalid confirmation policy: '{$confirmationPolicy}'. Allowed: 'none', 'required'.");
        }

        if ($kind === self::KIND_WRITE && $confirmationPolicy !== self::CONFIRMATION_REQUIRED) {
            throw new InvalidArgumentException("Write tool '{$key}' must have confirmation policy 'required'.");
        }

        $cleanScopes = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '') {
                $cleanScopes[] = $scope;
            }
        }
        $this->scopes = array_values(array_unique($cleanScopes));

        $cleanContext = [];
        foreach ($requiredContext as $ctx) {
            $ctx = trim((string) $ctx);
            if ($ctx !== '') {
                $cleanContext[] = $ctx;
            }
        }
        $this->requiredContext = array_values(array_unique($cleanContext));
        foreach ($this->requiredContext as $contextKey) {
            if (!in_array($contextKey, self::SUPPORTED_CONTEXT, true)) {
                throw new InvalidArgumentException("Unsupported required context: '{$contextKey}'.");
            }
        }

        self::assertSafeSchema($inputSchema);
    }

    public function isRead(): bool
    {
        return $this->kind === self::KIND_READ;
    }

    public function isWrite(): bool
    {
        return $this->kind === self::KIND_WRITE;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isExposed(): bool
    {
        return $this->isExposed && $this->enabled;
    }

    public function isConfirmationRequired(): bool
    {
        return $this->confirmationPolicy === self::CONFIRMATION_REQUIRED;
    }

    /**
     * Check if the context provides all required context keys.
     *
     * @return list<string> List of missing context identifiers.
     */
    public function getMissingContext(SeoToolContext $context): array
    {
        $missing = [];

        foreach ($this->requiredContext as $req) {
            $matched = match ($req) {
                self::CONTEXT_SITE_REF => $context->resolvedSiteId !== null && $context->resolvedSiteId > 0,
                self::CONTEXT_TENANT_REF => $context->tenantRef !== null && trim($context->tenantRef) !== '',
                self::CONTEXT_ACTOR_REF => trim($context->actorRef) !== '',
                default => false,
            };

            if (!$matched) {
                $missing[] = $req;
            }
        }

        return $missing;
    }

    /**
     * Public representation suitable for discovery.
     * Contains NO PHP class names, internal URLs, or secrets.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'module' => $this->module,
            'kind' => $this->kind,
            'required_context' => $this->requiredContext,
            'confirmation_policy' => $this->confirmationPolicy,
            'input_schema' => $this->inputSchema,
        ];
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function assertSafeSchema(array $schema): void
    {
        $dangerousKeys = ['__proto__', 'constructor', 'prototype', '$where', 'eval', 'exec', 'system', 'closure', 'callback'];
        foreach ($schema as $key => $val) {
            if (in_array(strtolower((string) $key), $dangerousKeys, true)) {
                throw new InvalidArgumentException("Input schema contains unsafe metadata key: '{$key}'.");
            }
            if (is_array($val)) {
                self::assertSafeSchema($val);
            }
        }
    }
}

