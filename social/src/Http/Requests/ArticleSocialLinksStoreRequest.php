<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ArticleSocialLinksStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'links' => ['required', 'array', 'min:1'],
            'links.*.url' => ['required', 'string', 'max:2048'],
            'links.*.external_ref' => ['nullable', 'string', 'max:191'],
            'links.*.recorded_at' => ['nullable', 'string', 'max:64'],
            'integration_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return list<array{url: string, external_ref?: string|null, recorded_at?: string|null}>
     */
    public function links(): array
    {
        $links = $this->input('links', []);
        if (! is_array($links)) {
            return [];
        }

        $payload = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $payload[] = [
                'url' => (string) ($link['url'] ?? ''),
                'external_ref' => isset($link['external_ref']) ? (string) $link['external_ref'] : null,
                'recorded_at' => isset($link['recorded_at']) ? (string) $link['recorded_at'] : null,
            ];
        }

        return $payload;
    }

    public function integrationKey(): ?string
    {
        $value = $this->input('integration_key');

        return is_string($value) ? $value : null;
    }
}
