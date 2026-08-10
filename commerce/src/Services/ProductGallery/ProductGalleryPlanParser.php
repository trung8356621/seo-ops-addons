<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryPlan;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryShotDefinition;

final class ProductGalleryPlanParser
{
    /**
     * @param  list<string>|null  $supportedAspectRatios
     */
    public function __construct(
        private readonly ?array $supportedAspectRatios = null,
        private readonly int $maxShots = 9,
    ) {}

    public static function fromConfig(): self
    {
        try {
            $ratios = config('seo-content-ai.product_gallery.parent_child.supported_aspect_ratios', ['1:1']);
            $max = (int) config('seo-content-ai.product_gallery.parent_child.max_shots', 9);
        } catch (\Throwable) {
            $ratios = ['1:1', '4:3', '3:4', '16:9', '9:16'];
            $max = 9;
        }

        return new self(
            supportedAspectRatios: is_array($ratios) ? array_values(array_map('strval', $ratios)) : ['1:1'],
            maxShots: max(1, $max),
        );
    }

    /**
     * @return array{ok: bool, plan: ?ProductGalleryPlan, errors: list<string>}
     */
    public function parse(string $rawOutput, int $requestedImageCount): array
    {
        $requestedImageCount = max(1, min($this->maxShots, $requestedImageCount));
        $trimmed = trim($rawOutput);
        $errors = [];

        if ($trimmed === '') {
            return ['ok' => false, 'plan' => null, 'errors' => ['empty_output']];
        }

        if ($this->looksLikeMarkdownOutsideJson($trimmed)) {
            $errors[] = 'markdown_outside_json';
        }

        $json = $this->extractJsonObject($trimmed);
        if ($json === null) {
            $errors[] = 'invalid_json';

            return ['ok' => false, 'plan' => null, 'errors' => $errors];
        }

        $shotsRaw = $json['shots'] ?? null;
        if (! is_array($shotsRaw) || $shotsRaw === []) {
            return ['ok' => false, 'plan' => null, 'errors' => array_merge($errors, ['shots_missing'])];
        }

        if (count($shotsRaw) > $requestedImageCount) {
            $errors[] = 'count_exceeds_requested';
        }
        if (count($shotsRaw) > $this->maxShots) {
            $errors[] = 'count_exceeds_max_shots';
        }

        $shots = [];
        $slots = [];
        $keys = [];
        foreach ($shotsRaw as $index => $row) {
            if (! is_array($row)) {
                $errors[] = 'shot_not_object_'.$index;

                continue;
            }
            $shot = ProductGalleryShotDefinition::fromArray($row);
            $shotErrors = $this->validateShot($shot, $slots, $keys);
            foreach ($shotErrors as $code) {
                $errors[] = $code;
            }
            $slots[$shot->slot] = true;
            $keys[$shot->shotKey] = true;
            $shots[] = $shot;
        }

        if ($shots !== [] && min(array_map(static fn (ProductGalleryShotDefinition $s): int => $s->slot, $shots)) !== 1) {
            $errors[] = 'slot_must_start_at_1';
        }

        if ($errors !== []) {
            return ['ok' => false, 'plan' => null, 'errors' => array_values(array_unique($errors))];
        }

        return [
            'ok' => true,
            'plan' => new ProductGalleryPlan($shots, $requestedImageCount),
            'errors' => [],
        ];
    }

    /**
     * @param  array<int, true>  $slots
     * @param  array<string, true>  $keys
     * @return list<string>
     */
    private function validateShot(ProductGalleryShotDefinition $shot, array $slots, array $keys): array
    {
        $errors = [];
        if ($shot->slot < 1) {
            $errors[] = 'slot_invalid';
        }
        if (isset($slots[$shot->slot])) {
            $errors[] = 'duplicate_slot';
        }
        if ($shot->shotKey === '') {
            $errors[] = 'shot_key_missing';
        }
        if ($shot->shotKey !== '' && isset($keys[$shot->shotKey])) {
            $errors[] = 'duplicate_shot_key';
        }
        if ($shot->label === '') {
            $errors[] = 'label_missing';
        }
        if (! in_array($shot->priority, [
            ProductGalleryShotDefinition::PRIORITY_REQUIRED,
            ProductGalleryShotDefinition::PRIORITY_OPTIONAL,
        ], true)) {
            $errors[] = 'priority_invalid';
        }
        $supported = $this->supportedAspectRatios ?? ['1:1'];
        if (! in_array($shot->aspectRatio, $supported, true)) {
            $errors[] = 'aspect_ratio_unsupported';
        }
        if ($shot->instruction === '') {
            $errors[] = 'instruction_missing';
        }
        if ($this->instructionRequestsForbiddenLayout($shot->instruction)) {
            $errors[] = 'instruction_collage_or_grid_forbidden';
        }
        if ($this->instructionRequestsTextInImage($shot->instruction)) {
            $errors[] = 'instruction_text_in_image_forbidden';
        }

        return $errors;
    }

    private function instructionRequestsForbiddenLayout(string $instruction): bool
    {
        $lower = mb_strtolower($instruction);

        return str_contains($lower, 'collage')
            || str_contains($lower, 'grid')
            || str_contains($lower, 'multi-panel')
            || str_contains($lower, 'multipanel')
            || str_contains($lower, 'sprite sheet')
            || str_contains($lower, 'contact sheet');
    }

    private function instructionRequestsTextInImage(string $instruction): bool
    {
        $lower = mb_strtolower($instruction);

        return str_contains($lower, 'add text')
            || str_contains($lower, 'overlay text')
            || str_contains($lower, 'watermark text')
            || str_contains($lower, 'label on image')
            || preg_match('/\b(text|typo|lettering|caption)\b.*\b(in|on|inside)\b.*\b(image|photo|picture)\b/u', $lower) === 1;
    }

    private function looksLikeMarkdownOutsideJson(string $raw): bool
    {
        if (str_starts_with($raw, '```')) {
            return true;
        }

        $withoutFence = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $raw) ?? $raw;
        $withoutFence = trim($withoutFence);
        if ($withoutFence !== '' && ($withoutFence[0] ?? '') !== '{' && str_contains($raw, '```')) {
            return true;
        }

        // Prose before/after JSON object.
        if (preg_match('/^[^{\s]/u', $raw) === 1 && str_contains($raw, '{')) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJsonObject(string $raw): ?array
    {
        $candidate = trim($raw);
        if (str_starts_with($candidate, '```')) {
            $candidate = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $candidate) ?? $candidate;
            $candidate = trim($candidate);
        }

        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $candidate, $m) === 1) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
