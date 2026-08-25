<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live Google URL Inspection API client.
 * Endpoint: POST https://searchconsole.googleapis.com/v1/urlInspection/index:inspect
 * Scope: webmasters.readonly (already requested by GoogleSearchConsoleOAuthService).
 */
final class GscUrlInspectionClient
{
    public const ENDPOINT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    /** @var callable|null */
    private $transport;

    /**
     * @param  callable(string $accessToken, string $inspectionUrl, string $propertyUri): array<string, mixed>|null  $transport
     */
    public function __construct(
        private readonly GscUrlInspectionBindingResolver $bindings = new GscUrlInspectionBindingResolver,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    public function inspect(string $inspectionUrl, string $propertyUri, SeoGscMasterConnection $connection): GscUrlInspectionResult
    {
        $url = trim($inspectionUrl);
        $property = trim($propertyUri);
        if ($url === '' || $property === '') {
            throw GscUrlInspectionApiException::missingBinding('inspectionUrl and siteUrl are required.');
        }

        $token = $this->bindings->resolveAccessToken($connection);
        $payload = $this->callApi($token, $url, $property);

        return $this->normalize($url, $property, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function callApi(string $accessToken, string $inspectionUrl, string $propertyUri): array
    {
        if ($this->transport !== null) {
            /** @var array<string, mixed> $custom */
            $custom = ($this->transport)($accessToken, $inspectionUrl, $propertyUri);

            return $custom;
        }

        $attempts = 0;
        $lastException = null;

        while ($attempts < GscUrlInspectionPolicy::MAX_TRANSIENT_ATTEMPTS) {
            $attempts++;
            try {
                $response = Http::withToken($accessToken)
                    ->timeout(GscUrlInspectionPolicy::HTTP_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->asJson()
                    ->post(self::ENDPOINT, [
                        'inspectionUrl' => $inspectionUrl,
                        'siteUrl' => $propertyUri,
                        'languageCode' => 'en-US',
                    ]);

                $status = $response->status();
                if ($response->successful()) {
                    $json = $response->json();

                    return is_array($json) ? $json : [];
                }

                $message = $this->sanitizeMessage(
                    (string) ($response->json('error.message') ?? $response->body())
                );

                $ex = GscUrlInspectionApiException::http($message !== '' ? $message : 'GSC URL Inspection failed.', $status);
                if (! $ex->transient || $attempts >= GscUrlInspectionPolicy::MAX_TRANSIENT_ATTEMPTS) {
                    throw $ex;
                }
                $lastException = $ex;
                usleep(200_000 * $attempts);
            } catch (GscUrlInspectionApiException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastException = GscUrlInspectionApiException::transient(
                    $this->sanitizeMessage($e->getMessage()) ?: 'Network error calling GSC URL Inspection.',
                    0,
                );
                if ($attempts >= GscUrlInspectionPolicy::MAX_TRANSIENT_ATTEMPTS) {
                    throw $lastException;
                }
                usleep(200_000 * $attempts);
            }
        }

        throw $lastException ?? GscUrlInspectionApiException::transient('GSC URL Inspection failed.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalize(string $inspectionUrl, string $propertyUri, array $payload): GscUrlInspectionResult
    {
        $result = is_array($payload['inspectionResult'] ?? null) ? $payload['inspectionResult'] : $payload;
        $index = is_array($result['indexStatusResult'] ?? null) ? $result['indexStatusResult'] : [];

        $sitemaps = [];
        foreach ((array) ($index['sitemap'] ?? []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $sitemaps[] = trim($item);
            }
        }

        $referring = [];
        foreach ((array) ($index['referringUrls'] ?? []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $referring[] = trim($item);
            }
        }

        $sanitizedIndex = array_filter([
            'verdict' => $index['verdict'] ?? null,
            'coverageState' => $index['coverageState'] ?? null,
            'indexingState' => $index['indexingState'] ?? null,
            'pageFetchState' => $index['pageFetchState'] ?? null,
            'robotsTxtState' => $index['robotsTxtState'] ?? null,
            'lastCrawlTime' => $index['lastCrawlTime'] ?? null,
            'googleCanonical' => $index['googleCanonical'] ?? null,
            'userCanonical' => $index['userCanonical'] ?? null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        return new GscUrlInspectionResult(
            inspectionUrl: $inspectionUrl,
            propertyUri: $propertyUri,
            verdict: isset($index['verdict']) ? (string) $index['verdict'] : null,
            coverageState: isset($index['coverageState']) ? (string) $index['coverageState'] : null,
            indexingState: isset($index['indexingState']) ? (string) $index['indexingState'] : null,
            pageFetchState: isset($index['pageFetchState']) ? (string) $index['pageFetchState'] : null,
            robotsTxtState: isset($index['robotsTxtState']) ? (string) $index['robotsTxtState'] : null,
            lastCrawlTime: isset($index['lastCrawlTime']) ? (string) $index['lastCrawlTime'] : null,
            googleCanonical: isset($index['googleCanonical']) ? (string) $index['googleCanonical'] : null,
            userCanonical: isset($index['userCanonical']) ? (string) $index['userCanonical'] : null,
            sitemaps: $sitemaps,
            referringUrls: $referring,
            rawIndexStatus: $sanitizedIndex,
        );
    }

    private function sanitizeMessage(string $message): string
    {
        $clean = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [redacted]', $message) ?? $message;

        return mb_substr(trim($clean), 0, 240);
    }
}
