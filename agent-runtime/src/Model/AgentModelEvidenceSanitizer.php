<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

use Omnichannel\Addons\AgentRuntime\Response\AgentPublicPayloadSanitizer;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;

final class AgentModelEvidenceSanitizer
{
    /** @return array<string, mixed> */
    public function sanitize(RetrievalBundle $bundle): array
    {
        $payload = $bundle->toArray();
        foreach ($payload['sources'] as &$source) {
            if (! is_array($source) || ! is_array($source['data'] ?? null)) {
                continue;
            }

            $source['data'] = (new AgentPublicPayloadSanitizer())->sanitize($this->withoutInternalNavigation($source['data']));
            if (($source['name'] ?? null) === 'site') {
                $source['data'] = $this->withoutImportantPageUrls($source['data']);
            }
            if (($source['name'] ?? null) === 'gsc'
                && is_array($source['data']['latest_available'] ?? null)) {
                unset($source['data']['latest_available']['href']);
            }
        }
        unset($source);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function withoutInternalNavigation(array $node): array
    {
        unset($node['ui_href'], $node['detail_href']);
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->withoutInternalNavigation($value);
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutImportantPageUrls(array $data): array
    {
        $items = $data['important_pages']['items'] ?? null;
        if (! is_array($items)) {
            return $data;
        }

        foreach ($items as $index => $item) {
            if (is_array($item)) {
                unset($item['url']);
                $data['important_pages']['items'][$index] = $item;
            }
        }

        return $data;
    }
}
