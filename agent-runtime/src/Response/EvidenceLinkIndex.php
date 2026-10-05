<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;

final class EvidenceLinkIndex
{
    /** @var array<string, true> */
    private array $hrefs = [];

    /** @var array<string, true> */
    private array $internalOrigins = [];

    public static function fromBundle(RetrievalBundle $bundle): self
    {
        $index = new self();
        foreach ($bundle->sources as $source) {
            if ($source->status === 'ok') {
                $index->walk($source->data);
            }
        }

        $appUrl = function_exists('config') ? config('app.url') : null;
        if (is_string($appUrl)) {
            $index->addInternalOrigin($appUrl);
        }

        return $index;
    }

    public function assertMarkdown(string $markdown): void
    {
        if (preg_match_all('/\[[^\]\n]+\]\(([^\s)]+)\)/', $markdown, $matches) === false) {
            throw new AgentResponseRejected('Markdown links could not be validated.');
        }

        foreach ($matches[1] ?? [] as $href) {
            $href = html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5);
            $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true)) {
                throw new AgentResponseRejected('Markdown link URI scheme is not allowed.');
            }
            if ($this->isInternal($href) && ! isset($this->hrefs[$href])) {
                throw new AgentResponseRejected('Internal Markdown link is not present in retrieval evidence.');
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function walk(array $node): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'ui_href' && is_string($value) && $this->isHttpUrl($value)) {
                $this->hrefs[$value] = true;
                $this->addInternalOrigin($value);
                continue;
            }
            if (is_array($value)) {
                $this->walk($value);
            }
        }
    }

    private function isInternal(string $href): bool
    {
        $origin = $this->origin($href);

        return $origin !== null && isset($this->internalOrigins[$origin]);
    }

    private function addInternalOrigin(string $url): void
    {
        $origin = $this->origin($url);
        if ($origin !== null) {
            $this->internalOrigins[$origin] = true;
        }
    }

    private function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function origin(string $url): ?string
    {
        if (! $this->isHttpUrl($url)) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }
}
