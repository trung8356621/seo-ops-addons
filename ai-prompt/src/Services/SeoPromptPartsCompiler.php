<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptPart;
use Omnichannel\Addons\AiPrompt\Support\CompiledPromptInstructions;
use Omnichannel\Addons\Content\Support\Utf8Sanitizer;

/**
 * SSOT Prompt Parts compiler: SeoPrompt markdown → structured system/user blocks.
 *
 * Does not own hook templates, output contracts, or provider message mapping.
 */
final class SeoPromptPartsCompiler
{
    /** @var list<string> */
    private const SYSTEM_ROLES = [
        'global_constraints',
        'role',
        'context',
        'constraints',
        'formatting',
    ];

    /** @var list<string> */
    private const USER_ROLES = [
        'task',
        'sub_task',
    ];

    private const ROLE_HEADINGS = [
        'role' => 'Vai trò',
        'context' => 'Bối cảnh',
        'task' => 'Nhiệm vụ',
        'sub_task' => 'Nhiệm vụ phụ thuộc',
        'formatting' => 'Định dạng đầu ra',
        'constraints' => 'Ràng buộc',
        'global_constraints' => 'Ràng buộc tổng (Global)',
    ];

    /**
     * @param  array<string, string>  $variables
     */
    public function compile(SeoPrompt $prompt, array $variables = []): CompiledPromptInstructions
    {
        $systemBlocks = [];
        $userBlocks = [];
        $ordered = [];

        foreach ($prompt->resolvedParts() as $part) {
            if (! $part instanceof SeoPromptPart) {
                continue;
            }
            $block = $this->formatPartBlock($part, $variables);
            if ($block === '') {
                continue;
            }
            $ordered[] = $block;
            $role = strtolower(trim((string) $part->role));
            if (in_array($role, self::USER_ROLES, true)) {
                $userBlocks[] = $block;
            } else {
                // SYSTEM_ROLES + unknown → system bucket (parser default role is context).
                $systemBlocks[] = $block;
            }
        }

        if ($ordered === []) {
            throw new PromptRunException('Prompt không có nội dung thành phần nào.');
        }

        return new CompiledPromptInstructions(
            systemBlocks: $systemBlocks,
            userBlocks: $userBlocks,
            orderedBlocks: $ordered,
        );
    }

    /**
     * @param  array<string, string>  $variables
     */
    public function compileChainStepInstructions(
        SeoPrompt $prompt,
        SeoPromptPart $stepPart,
        array $variables = [],
    ): CompiledPromptInstructions {
        $compiled = $this->compile($prompt, $variables);
        $stepBlock = $this->formatPartBlock($stepPart, $variables);
        if ($stepBlock === '') {
            throw new PromptRunException('Bước chuỗi prompt không có nội dung.');
        }

        // System side = all non-task/sub_task blocks (includes global_constraints).
        $systemOnly = [];
        foreach ($prompt->resolvedParts() as $part) {
            if (! $part instanceof SeoPromptPart) {
                continue;
            }
            $role = strtolower(trim((string) $part->role));
            if (in_array($role, self::USER_ROLES, true)) {
                continue;
            }
            $block = $this->formatPartBlock($part, $variables);
            if ($block !== '') {
                $systemOnly[] = $block;
            }
        }

        return new CompiledPromptInstructions(
            systemBlocks: $systemOnly,
            userBlocks: [$stepBlock],
            orderedBlocks: $compiled->orderedBlocks,
        );
    }

    /**
     * @param  array<string, string>  $variables
     */
    public function compileChainStep(SeoPrompt $prompt, SeoPromptPart $stepPart, array $variables = []): string
    {
        $chain = $this->compileChainStepInstructions($prompt, $stepPart, $variables);
        $stepBlock = $chain->userBlocks[0] ?? '';

        return Utf8Sanitizer::string($chain->toChainFlatString($stepBlock));
    }

    /**
     * @param  array<string, string>  $variables
     */
    public function formatPartBlock(SeoPromptPart $part, array $variables): string
    {
        // Lossless: global_constraints is formatted like other roles (never dropped).
        $content = trim($this->substituteVariables(Utf8Sanitizer::string((string) $part->content), $variables));
        if ($content === '') {
            return '';
        }

        $role = (string) $part->role;
        $heading = self::ROLE_HEADINGS[$role] ?? ucfirst($role !== '' ? $role : 'context');
        if (in_array($role, self::USER_ROLES, true) && filled($part->name)) {
            $heading .= ': '.Utf8Sanitizer::string((string) $part->name);
        }

        $lines = ["## {$heading}", $content];

        $meta = is_array($part->meta) ? $part->meta : [];
        $rules = trim(Utf8Sanitizer::string((string) ($meta['rules'] ?? '')));
        if ($rules !== '') {
            $lines[] = '';
            $lines[] = 'Quy tắc:';
            $lines[] = $this->substituteVariables($rules, $variables);
        }

        if ($role === 'sub_task') {
            $specific = trim(Utf8Sanitizer::string((string) ($meta['specific_constraints'] ?? '')));
            if ($specific !== '') {
                $lines[] = '';
                $lines[] = 'Ràng buộc riêng (sub-prompt):';
                $lines[] = $this->substituteVariables($specific, $variables);
            }
        }

        return Utf8Sanitizer::string(implode("\n", $lines));
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function substituteVariables(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                $key = $matches[1];

                return array_key_exists($key, $variables)
                    ? (string) $variables[$key]
                    : $matches[0];
            },
            $text,
        );
    }
}
