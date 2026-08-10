<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use App\Support\RuntimeLogger;

final class PromptPostProcessing
{
    public const GRID_SIZE_MIN = 2;

    public const GRID_SIZE_MAX = 6;

    public const GRID_SIZE_DEFAULT = 3;

    public const SNAPSHOT_VARIABLE_KEY = 'quick_split';

    /**
     * @return array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows: int,
     *     split_columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }
     */
    public static function defaults(): array
    {
        $grid = self::GRID_SIZE_DEFAULT;

        return [
            'split_enabled' => false,
            'split_grid_size' => $grid,
            'split_rows' => $grid,
            'split_columns' => $grid,
            'expected_panels' => $grid * $grid,
            'resize_enabled' => false,
            'resize_width' => null,
            'resize_height' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows: int,
     *     split_columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }
     */
    public static function fromPromptSettings(?array $settings): array
    {
        $raw = is_array($settings['post_processing'] ?? null)
            ? $settings['post_processing']
            : [];

        return self::normalize($raw);
    }

    public static function fromPrompt(SeoPrompt $prompt): array
    {
        $settings = is_array($prompt->settings) ? $prompt->settings : [];

        return self::fromPromptSettings($settings);
    }

    /**
     * Prefer immutable run snapshot over live prompt settings.
     *
     * @param  array<string, mixed>  $variables
     * @return array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows: int,
     *     split_columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }
     */
    public static function resolveFromVariablesOrPrompt(array $variables, SeoPrompt $prompt): array
    {
        $fromSnapshot = self::fromVariablesSnapshot($variables);
        if ($fromSnapshot !== null) {
            return $fromSnapshot;
        }

        return self::fromPrompt($prompt);
    }

    /**
     * @return array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows: int,
     *     split_columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }
     */
    public static function resolveFromMediaOrPrompt(SeoMedia $media, SeoPrompt $prompt): array
    {
        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];

        return self::resolveFromVariablesOrPrompt($variables, $prompt);
    }

    /**
     * @param  array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows: int,
     *     split_columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }  $config
     */
    public static function isActive(array $config): bool
    {
        if ($config['split_enabled']) {
            return true;
        }

        return $config['resize_enabled']
            && ($config['resize_width'] !== null || $config['resize_height'] !== null);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows: int,
     *     split_columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }
     */
    public static function normalize(array $raw, ?int $promptId = null): array
    {
        $defaults = self::defaults();
        $grid = self::resolveGridSize($raw, $promptId);

        return [
            'split_enabled' => filter_var($raw['split_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'split_grid_size' => $grid,
            'split_rows' => $grid,
            'split_columns' => $grid,
            'expected_panels' => $grid * $grid,
            'resize_enabled' => filter_var($raw['resize_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'resize_width' => self::positiveIntOrNull($raw['resize_width'] ?? null),
            'resize_height' => self::positiveIntOrNull($raw['resize_height'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    public static function mergeIntoSettings(?array $settings, ?array $postProcessing, ?int $promptId = null): array
    {
        $settings = is_array($settings) ? $settings : [];

        $settings['post_processing'] = self::normalize(
            is_array($postProcessing) ? $postProcessing : [],
            $promptId,
        );

        return $settings;
    }

    /**
     * @param  array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows: int,
     *     split_columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }  $config
     * @return array{
     *     enabled: bool,
     *     grid_size: int,
     *     rows: int,
     *     columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }
     */
    public static function toSnapshot(array $config): array
    {
        $grid = (int) $config['split_grid_size'];

        return [
            'enabled' => (bool) $config['split_enabled'],
            'grid_size' => $grid,
            'rows' => $grid,
            'columns' => $grid,
            'expected_panels' => $grid * $grid,
            'resize_enabled' => (bool) $config['resize_enabled'],
            'resize_width' => $config['resize_width'],
            'resize_height' => $config['resize_height'],
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows: int,
     *     split_columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }  $config
     * @return array<string, mixed>
     */
    public static function attachSnapshotToVariables(array $variables, array $config): array
    {
        $variables[self::SNAPSHOT_VARIABLE_KEY] = self::toSnapshot($config);

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows: int,
     *     split_columns: int,
     *     expected_panels: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }|null
     */
    public static function fromVariablesSnapshot(array $variables): ?array
    {
        $raw = $variables[self::SNAPSHOT_VARIABLE_KEY] ?? null;

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($raw)) {
            return null;
        }

        return self::normalize([
            'split_enabled' => $raw['enabled'] ?? $raw['split_enabled'] ?? false,
            'split_grid_size' => $raw['grid_size'] ?? $raw['split_grid_size'] ?? null,
            'split_rows' => $raw['rows'] ?? $raw['split_rows'] ?? null,
            'split_columns' => $raw['columns'] ?? $raw['split_columns'] ?? null,
            'resize_enabled' => $raw['resize_enabled'] ?? false,
            'resize_width' => $raw['resize_width'] ?? null,
            'resize_height' => $raw['resize_height'] ?? null,
        ]);
    }

    public static function clampGridSize(int $value): int
    {
        return max(self::GRID_SIZE_MIN, min(self::GRID_SIZE_MAX, $value));
    }

    public static function isValidGridSize(mixed $value): bool
    {
        if (is_bool($value) || $value === null || $value === '' || is_array($value)) {
            return false;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || ! preg_match('/^-?\d+$/', $value)) {
                return false;
            }
        } elseif (is_float($value)) {
            if (floor($value) !== $value) {
                return false;
            }
        } elseif (! is_int($value)) {
            return false;
        }

        $int = (int) $value;

        return $int >= self::GRID_SIZE_MIN && $int <= self::GRID_SIZE_MAX;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function resolveGridSize(array $raw, ?int $promptId = null): int
    {
        if (array_key_exists('split_grid_size', $raw) && is_numeric($raw['split_grid_size'])) {
            return self::clampGridSize((int) $raw['split_grid_size']);
        }

        $hasRows = array_key_exists('split_rows', $raw) && is_numeric($raw['split_rows']);
        $hasCols = array_key_exists('split_columns', $raw) && is_numeric($raw['split_columns']);

        if ($hasRows && $hasCols) {
            $rows = (int) $raw['split_rows'];
            $cols = (int) $raw['split_columns'];

            if ($rows === $cols && $rows > 0) {
                return self::clampGridSize($rows);
            }

            $preferred = $rows > 0 ? $rows : $cols;
            if ($preferred <= 0) {
                $preferred = self::GRID_SIZE_DEFAULT;
            }

            self::warnLegacyRectangular($promptId, $rows, $cols, $preferred);

            return self::clampGridSize($preferred);
        }

        if ($hasRows && (int) $raw['split_rows'] > 0) {
            return self::clampGridSize((int) $raw['split_rows']);
        }

        if ($hasCols && (int) $raw['split_columns'] > 0) {
            return self::clampGridSize((int) $raw['split_columns']);
        }

        return self::GRID_SIZE_DEFAULT;
    }

    private static function warnLegacyRectangular(?int $promptId, int $rows, int $cols, int $normalized): void
    {
        $context = [
            'prompt_id' => $promptId,
            'legacy_rows' => $rows,
            'legacy_columns' => $cols,
            'normalized_grid_size' => $normalized,
        ];

        try {
            if (function_exists('app') && app()->bound('log')) {
                RuntimeLogger::warning('seo.prompt.quick_split.legacy_rectangular', $context);
            }
        } catch (\Throwable) {
            // Unit tests / non-HTTP contexts: skip.
        }
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
