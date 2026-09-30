<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools;

use App\Models\Site;

final class SeoToolExecutor
{
    public function __construct(
        private readonly SeoToolRegistry $registry,
        private readonly SeoToolInputValidator $validator
    ) {
    }

    /**
     * Execute a registered tool by its capability key.
     * Follows strict fail-closed validation order:
     * 1. Tool exists
     * 2. Tool exposed / enabled
     * 3. Caller scopes
     * 4. Required context
     * 5. Site / tenant access
     * 6. Input schema validation
     * 7. Confirmation policy
     * 8. Execute handler
     *
     * @param string $toolKey
     * @param SeoToolContext $context
     * @param array<string, mixed> $input
     * @param bool $confirmed
     * @return SeoToolExecutionResult
     */
    public function execute(
        string $toolKey,
        SeoToolContext $context,
        array $input = [],
        bool $confirmed = false
    ): SeoToolExecutionResult {
        // Step 1: Tool exists
        if (!$this->registry->has($toolKey)) {
            return SeoToolExecutionResult::failure(
                errorCode: 'tool_not_found',
                errorMessage: "Tool '{$toolKey}' was not found in the registry.",
                meta: ['tool' => $toolKey],
                httpStatus: 404
            );
        }

        $definition = $this->registry->getDefinition($toolKey);
        $handler = $this->registry->getHandler($toolKey);

        // Step 2: Tool exposed / enabled
        if (!$definition || !$handler || !$definition->isExposed()) {
            return SeoToolExecutionResult::failure(
                errorCode: 'tool_not_exposed',
                errorMessage: "Tool '{$toolKey}' is currently disabled or unavailable.",
                meta: ['tool' => $toolKey],
                httpStatus: 404
            );
        }

        // Step 3: Caller scopes
        if (!$context->satisfiesAllScopes($definition->scopes)) {
            return SeoToolExecutionResult::failure(
                errorCode: 'scope_denied',
                errorMessage: "Caller lacks required scopes: " . implode(', ', $definition->scopes),
                meta: [
                    'tool' => $toolKey,
                    'required_scopes' => $definition->scopes,
                    'caller_scopes' => $context->scopes,
                ],
                httpStatus: 403
            );
        }

        // Step 4: Required context
        $missingContext = $definition->getMissingContext($context);
        if (!empty($missingContext)) {
            return SeoToolExecutionResult::failure(
                errorCode: 'missing_context',
                errorMessage: "Missing required context: " . implode(', ', $missingContext),
                meta: [
                    'tool' => $toolKey,
                    'missing_context' => $missingContext,
                ],
                httpStatus: 422
            );
        }

        // Step 5: Site / tenant access
        if (in_array(SeoToolDefinition::CONTEXT_SITE_REF, $definition->requiredContext, true)) {
            $siteId = $context->resolvedSiteId;
            if ($siteId === null || $siteId <= 0) {
                return SeoToolExecutionResult::failure(
                    errorCode: 'missing_context',
                    errorMessage: 'A valid site context is required.',
                    meta: ['tool' => $toolKey],
                    httpStatus: 403
                );
            }

            if (class_exists(Site::class) && \Illuminate\Support\Facades\Schema::hasTable('sites')) {
                $site = Site::query()->find($siteId);
                if ($site === null) {
                    return SeoToolExecutionResult::failure(
                        errorCode: 'site_not_found',
                        errorMessage: "Site '{$siteId}' was not found.",
                        meta: ['tool' => $toolKey, 'site_id' => $siteId],
                        httpStatus: 404
                    );
                }
            }
        }

        // Step 6: Input schema validation
        $schemaErrors = $this->validator->validate($definition->inputSchema, $input);
        if (!empty($schemaErrors)) {
            return SeoToolExecutionResult::failure(
                errorCode: 'validation_failed',
                errorMessage: implode('; ', $schemaErrors),
                meta: [
                    'tool' => $toolKey,
                    'schema_errors' => $schemaErrors,
                ],
                httpStatus: 422
            );
        }

        // Step 7: Confirmation policy
        if ($definition->isConfirmationRequired() && !$confirmed) {
            return SeoToolExecutionResult::failure(
                errorCode: 'confirmation_required',
                errorMessage: "Tool '{$toolKey}' modifies state and requires explicit confirmation before execution.",
                meta: [
                    'tool' => $toolKey,
                    'confirmation_policy' => $definition->confirmationPolicy,
                    'kind' => $definition->kind,
                ],
                httpStatus: 422
            );
        }

        // Step 8: Execute handler
        return $handler->execute($context, $input, $confirmed);
    }
}
