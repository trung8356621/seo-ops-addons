<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptPack;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

final class PromptPortableIdentity
{
    public function ensure(SeoPrompt $prompt): string
    {
        $settings = is_array($prompt->settings) ? $prompt->settings : [];
        $fromSettings = trim((string) ($settings['portable_uuid'] ?? ''));
        if ($this->isUuid($fromSettings)) {
            $this->persistColumn($prompt, $fromSettings);

            return $fromSettings;
        }

        $fromColumn = trim((string) ($prompt->getAttribute('portable_uuid') ?? ''));
        if ($this->isUuid($fromColumn)) {
            $settings['portable_uuid'] = $fromColumn;
            $prompt->settings = $settings;
            $prompt->save();

            return $fromColumn;
        }

        $uuid = (string) Str::uuid();
        $settings['portable_uuid'] = $uuid;
        $prompt->settings = $settings;
        $this->persistColumn($prompt, $uuid);
        $prompt->save();

        return $uuid;
    }

    public function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    private function persistColumn(SeoPrompt $prompt, string $uuid): void
    {
        try {
            $connection = $prompt->getConnectionName();
            if (Schema::connection($connection)->hasColumn($prompt->getTable(), 'portable_uuid')) {
                $prompt->setAttribute('portable_uuid', $uuid);
            }
        } catch (\Throwable) {
            // sqlite unit tests may omit the column.
        }
    }
}
