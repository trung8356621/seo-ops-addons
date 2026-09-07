<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Detect + safely quarantine malformed catalog rows (e.g. literal "a"/"b"/"c").
 * Never DELETE WHERE name IN (...) blindly — requires provenance checks.
 */
final class MalformedAiModelRepairService
{
    /**
     * Provider model ids that are too short / not plausible catalog identifiers.
     */
    public static function isMalformedProviderModelId(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return true;
        }
        // Single Latin letter leftovers from fixtures / bad parsers — not real OpenRouter ids.
        if (preg_match('/^[a-z]$/i', $raw) === 1) {
            return true;
        }
        // Extremely short opaque tokens without vendor/slash and without digits.
        if (mb_strlen($raw) <= 2 && ! str_contains($raw, '/') && ! preg_match('/\d/', $raw)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<array{id: int, connection_id: int, raw: string, display: string, action: string}>
     */
    public function auditAndRepair(bool $apply = false): array
    {
        $out = [];
        $models = SeoAiModel::query()->orderBy('id')->get();
        foreach ($models as $model) {
            $raw = (string) $model->raw_model_name;
            $display = (string) $model->display_name;
            if (! self::isMalformedProviderModelId($raw) && ! self::isMalformedProviderModelId($display)) {
                continue;
            }
            // Prefer raw as SSOT; if display is short but raw is valid, repair display only.
            $malformedRaw = self::isMalformedProviderModelId($raw);
            $action = $malformedRaw ? 'quarantine' : 'fix_display';
            $out[] = [
                'id' => (int) $model->id,
                'connection_id' => (int) $model->api_connection_id,
                'raw' => $raw,
                'display' => $display,
                'action' => $action,
            ];
            if (! $apply) {
                continue;
            }
            if ($action === 'fix_display') {
                $model->display_name = $raw;
                $model->save();
                continue;
            }
            $this->quarantine($model);
        }

        return $out;
    }

    private function quarantine(SeoAiModel $model): void
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $areas = is_array($caps[AiModelPriorityService::AREAS_KEY] ?? null)
            ? $caps[AiModelPriorityService::AREAS_KEY]
            : [];
        foreach (AiModelArea::uiCases() as $area) {
            $current = is_array($areas[$area->value] ?? null) ? $areas[$area->value] : [];
            $areas[$area->value] = array_merge($current, [
                'enabled' => false,
                'source' => 'malformed_quarantine',
            ]);
        }
        $caps[AiModelPriorityService::AREAS_KEY] = $areas;
        $caps['malformed_quarantine'] = [
            'at' => now()->toIso8601String(),
            'raw' => (string) $model->raw_model_name,
        ];
        $model->capabilities = $caps;
        $model->status = SeoAiModel::STATUS_INACTIVE;
        if (\Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'is_hidden')) {
            $model->is_hidden = true;
        }
        $model->save();

        // Drop routing targets that point at this model key.
        try {
            DB::connection($model->getConnectionName() ?: config('database.core_connection', 'mysql'))
                ->table('ai_routing_targets')
                ->where('seo_ai_model_id', $model->id)
                ->orWhere('model_key', (string) $model->raw_model_name)
                ->delete();
        } catch (\Throwable) {
        }
    }
}
