<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Manifest;

use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterRegistry;

/**
 * Compact Markdown README for AI discovery (high-signal, no payloads).
 */
final class McpManifestMarkdownPresenter
{
    public function present(McpRouterRegistry $registry, ?string $siteRef = null): string
    {
        $manifest = $registry->manifest();
        $lines = [
            '# SEO MCP',
            '',
        ];
        if ($siteRef !== null && $siteRef !== '') {
            $lines[] = 'Site: '.$siteRef;
            $lines[] = '';
        }
        $lines[] = 'Discover routers, then select only the parts needed for this turn.';
        $lines[] = 'Views control detail (`summary` / `standard` / `detail`); parts control which context areas load.';
        $lines[] = '';

        $routers = is_array($manifest['routers'] ?? null) ? $manifest['routers'] : [];
        foreach ($routers as $router) {
            if (! is_array($router)) {
                continue;
            }
            $key = (string) ($router['key'] ?? '');
            $title = (string) ($router['title'] ?? $key);
            $description = (string) ($router['description'] ?? '');
            $when = (string) ($router['when_to_use'] ?? '');

            $lines[] = '## '.$key.($title !== '' && $title !== $key ? ' — '.$title : '');
            if ($description !== '') {
                $lines[] = $description;
            }
            if ($when !== '') {
                $lines[] = 'When to use: '.$when;
            }
            $lines[] = '';
            $lines[] = 'Parts:';

            $parts = is_array($router['parts'] ?? null) ? $router['parts'] : [];
            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }
                $partKey = (string) ($part['key'] ?? '');
                $partDesc = (string) ($part['description'] ?? '');
                $partWhen = (string) ($part['when_to_use'] ?? '');
                $size = (string) ($part['size_hint'] ?? '');
                $line = '- '.$partKey;
                if ($partDesc !== '') {
                    $line .= ' — '.$partDesc;
                }
                if ($size !== '') {
                    $line .= ' ['.$size.']';
                }
                $lines[] = $line;
                if ($partWhen !== '') {
                    $lines[] = '  When to use: '.$partWhen;
                }
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines))."\n";
    }
}
