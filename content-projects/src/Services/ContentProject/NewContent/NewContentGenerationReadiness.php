<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

/**
 * Read-only readiness snapshot for Create new content with AI.
 *
 * @phpstan-type GateShape array{ready: bool, reason: ?string}
 * @phpstan-type LanguageGate array{ready: bool, value: ?string, reason: ?string}
 * @phpstan-type PromptGate array{ready: bool, hook: string, prompt_id: ?int, prompt_name: ?string, reason: ?string}
 * @phpstan-type ProfileGate array{value: ?string, label: ?string}
 * @phpstan-type GenerationGate array{active: bool, status: ?string, run_id: ?int, reason: ?string}
 * @phpstan-type PermissionGate array{ready: bool, reason: ?string}
 */
final class NewContentGenerationReadiness
{
    public const HOOK_KEY = 'keyword.discovery.structured';

    /**
     * @param  GateShape  $draft
     * @param  LanguageGate  $language
     * @param  PromptGate  $prompt
     * @param  ProfileGate  $profile
     * @param  GenerationGate  $generation
     * @param  PermissionGate  $permission
     * @param  list<string>  $blockReasons
     */
    public function __construct(
        public readonly bool $ready,
        public readonly bool $quantityEnabled,
        public readonly bool $generateEnabled,
        public readonly array $draft,
        public readonly array $language,
        public readonly array $prompt,
        public readonly array $profile,
        public readonly array $generation,
        public readonly array $permission,
        public readonly array $blockReasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'quantity_enabled' => $this->quantityEnabled,
            'generate_enabled' => $this->generateEnabled,
            'draft' => $this->draft,
            'language' => $this->language,
            'prompt' => $this->prompt,
            'profile' => $this->profile,
            'generation' => $this->generation,
            'permission' => $this->permission,
            'block_reasons' => $this->blockReasons,
        ];
    }
}
