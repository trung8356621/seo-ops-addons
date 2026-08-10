<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Support;

enum PromptHookFailureCode: string
{
    case DefinitionNotFound = 'DEFINITION_NOT_FOUND';
    case VersionNotFound = 'VERSION_NOT_FOUND';
    case HookDisabled = 'HOOK_DISABLED';
    case ExperimentalNotAllowed = 'EXPERIMENTAL_NOT_ALLOWED';
    case InvalidInput = 'INVALID_INPUT';
    case InputTooLarge = 'INPUT_TOO_LARGE';
    case TemplateRenderFailed = 'TEMPLATE_RENDER_FAILED';
    case UnsupportedProviderCapability = 'UNSUPPORTED_PROVIDER_CAPABILITY';
    case ProviderFailed = 'PROVIDER_FAILED';
    case ProviderTimeout = 'PROVIDER_TIMEOUT';
    case ProviderRefused = 'PROVIDER_REFUSED';
    case OutputTruncated = 'OUTPUT_TRUNCATED';
    case InvalidOutput = 'INVALID_OUTPUT';
    case MissingRequiredSection = 'MISSING_REQUIRED_SECTION';
    case DuplicateOutputSection = 'DUPLICATE_OUTPUT_SECTION';
    case MismatchedSectionMarker = 'MISMATCHED_SECTION_MARKER';
    case UnknownSectionMarker = 'UNKNOWN_SECTION_MARKER';
    case TextOutsideDeclaredSections = 'TEXT_OUTSIDE_DECLARED_SECTIONS';
    case InvalidSectionOutput = 'INVALID_SECTION_OUTPUT';
    case BudgetExceeded = 'BUDGET_EXCEEDED';
    case LegacyParityMismatch = 'LEGACY_PARITY_MISMATCH';
    case DefinitionInvalid = 'DEFINITION_INVALID';
    case EloquentRejected = 'ELOQUENT_REJECTED';
}
