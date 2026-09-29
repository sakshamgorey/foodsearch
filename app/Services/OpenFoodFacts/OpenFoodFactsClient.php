<?php

namespace App\Services\OpenFoodFacts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class OpenFoodFactsClient
{
    /**
     * GET /api/v2/search for one page.
     *
     * Deliberately does NOT retry internally: the queue already retries the
     * job with backoff. Retrying in both layers multiplies requests against a
     * 10 req/min limit (3 HTTP retries x 5 job tries = 15 calls for one page).
     *
     * @throws RateLimitedByRemote  on 429 (carries Retry-After)
     * @throws RemoteUnavailable    on 5xx / timeouts — transient, retry later
     * @throws InvalidResponse      on 4xx / malformed body — retrying won't help
     */
    public function searchPage(int $page, ?int $pageSize = null): SearchPage
    {
        $pageSize ??= config('foodfacts.page_size');

        $query = array_merge(config('foodfacts.filters', []), [
            'page' => $page,
            'page_size' => $pageSize,
            'fields' => implode(',', config('foodfacts.fields')),
            'sort_by' => config('foodfacts.sort_by'),
        ]);

        try {
            $response = $this->http()->get('/api/v2/search', $query);
        } catch (ConnectionException $e) {
            throw new RemoteUnavailable('Open Food Facts unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 429) {
            throw new RateLimitedByRemote((int) ($response->header('Retry-After') ?: 60));
        }

        if ($response->serverError()) {
            throw new RemoteUnavailable("Open Food Facts returned HTTP {$response->status()}");
        }

        if ($response->failed()) {
            throw new InvalidResponse("Open Food Facts returned HTTP {$response->status()}");
        }

        $json = $response->json();

        // OFF sometimes serves an HTML maintenance page with a 200.
        if (! is_array($json) || ! array_key_exists('products', $json) || ! is_array($json['products'])) {
            throw new RemoteUnavailable('Open Food Facts returned a non-JSON or unexpected body');
        }

        return new SearchPage(
            page: $page,
            pageSize: $pageSize,
            count: (int) ($json['count'] ?? 0),
            products: $json['products'],
        );
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(config('foodfacts.base_url'))
            ->withUserAgent(config('foodfacts.user_agent'))
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(config('foodfacts.timeout'));
    }
}
