<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\System;

use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Capability\SystemCapabilityHandler;
use RuntimeException;

/** One routed AI request for all Website Share target platforms. */
final class WebsiteShareGenerateCapabilityHandler implements SystemCapabilityHandler
{
    public const KEY = 'seeding.website_share.generate';

    public function handle(array $input, array $context = []): array
    {
        $textPort = $context['system_ai_text_port'] ?? null;
        if (! $textPort instanceof AiTextExecutionPort) {
            throw new RuntimeException('System AI text port is required for Website Share generation.');
        }

        $socials = array_values(array_unique(array_filter(array_map(
            static fn (mixed $social): string => trim((string) $social),
            is_array($input['socials'] ?? null) ? $input['socials'] : [],
        ))));
        if ($socials === []) {
            throw new RuntimeException('Website Share generation requires target socials.');
        }

        $title = trim((string) ($input['title'] ?? ''));
        $url = trim((string) ($input['article_url'] ?? $input['url'] ?? ''));
        $prompt = implode("\n", [
            'Generate exactly one social share text for each requested platform.',
            'Return JSON only with this exact shape: {"outputs":[{"social":"facebook","content":"..."}]}.' ,
            'Include every requested platform exactly once, no unknown or extra platforms, and never leave content empty.',
            'Tailor tone naturally: Facebook may be descriptive, Threads concise and conversational, Pinterest discovery-friendly.',
            'Requested platforms: '.implode(', ', $socials),
            'Article title: '.$title,
            'Article URL: '.$url,
        ]);

        $generated = $textPort->generate(
            $prompt,
            self::KEY,
            [
                'count' => count($socials),
                'quantity' => count($socials),
                'socials' => $socials,
                'structured_output' => true,
                'max_output' => min(4096, max(512, count($socials) * 700)),
            ],
        );

        $raw = trim((string) ($generated['text'] ?? ''));
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $fenced = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;
            $decoded = json_decode(trim($fenced), true);
        }
        if (! is_array($decoded) || ! is_array($decoded['outputs'] ?? null)) {
            throw new RuntimeException('Website Share AI output is not valid JSON.');
        }

        return [
            'outputs' => $decoded['outputs'],
            'raw_output' => $raw,
            'provider' => $generated['provider'] ?? null,
            'model' => $generated['model'] ?? null,
            'physical_route' => $generated['physical_route'] ?? null,
            'usage' => $generated['usage'] ?? null,
            'trace' => $generated['trace'] ?? null,
            'path' => 'system_ai_text_port',
            'system_ai_capability' => self::KEY,
        ];
    }
}
