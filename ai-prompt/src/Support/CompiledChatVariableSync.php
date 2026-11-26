<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Keeps `_compiled_system` / `_compiled_user` aligned with the final flat prompt
 * after post-compile mutations (image inject, FAQ contract, keyword batch shrink).
 *
 * Never invents business instructions — only reconciles stamp vs flat.
 */
final class CompiledChatVariableSync
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function align(array $variables, string $compiledFlat): array
    {
        $flat = trim($compiledFlat);
        if ($flat === '') {
            return $variables;
        }

        $system = trim((string) ($variables['_compiled_system'] ?? ''));
        $user = trim((string) ($variables['_compiled_user'] ?? ''));

        if ($system === '' && $user === '') {
            $variables['_compiled_system'] = '';
            $variables['_compiled_user'] = $flat;

            return $variables;
        }

        $joined = $system !== ''
            ? trim($system.($user !== '' ? "\n\n".$user : ''))
            : $user;

        if ($joined === $flat) {
            $variables['_compiled_system'] = $system;
            $variables['_compiled_user'] = $user !== '' ? $user : $flat;

            return $variables;
        }

        // Flat is SSOT for post-process appendages. Prefer preserving system role
        // when flat is system + mutated user suffix.
        if ($system !== '' && str_starts_with($flat, $system)) {
            $remainder = trim(substr($flat, strlen($system)));
            $variables['_compiled_system'] = $system;
            $variables['_compiled_user'] = $remainder !== '' ? $remainder : $flat;

            return $variables;
        }

        // Unsafe to split — keep lossless content on user (never drop flat).
        $variables['_compiled_system'] = '';
        $variables['_compiled_user'] = $flat;

        return $variables;
    }
}
