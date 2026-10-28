<?php

namespace MityDigital\CargoAustraliaPost\Services\AustraliaPost;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MityDigital\CargoAustraliaPost\Exceptions\AustraliaPostApiException;
use Throwable;

class AustraliaPostApiConnector
{
    public function __construct(
        #[\SensitiveParameter]
        protected string $apiKey
    ) {}

    protected function baseUrl(): string
    {
        return 'https://digitalapi.auspost.com.au/';
    }

    protected function configure(PendingRequest $request): PendingRequest
    {
        return $request->baseUrl($this->baseUrl())
            ->acceptJson()
            ->withHeaders(['auth-key' => $this->apiKey])
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(2, 200, fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && ($exception->response->serverError() || $exception->response->status() === 429)));
    }

    public function get(string $url, array $query = []): array
    {
        try {
            $response = $this->configure(Http::withOptions([]))->get($url, $query)->throw();
        } catch (RequestException|ConnectionException $exception) {
            Log::error('Australia Post API request failed', [
                'url' => $url,
                'query' => $query,
                'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'body' => $exception instanceof RequestException ? $exception->response->body() : null,
                'message' => $exception->getMessage(),
            ]);

            throw new AustraliaPostApiException(
                $exception instanceof ConnectionException
                    ? 'The Australia Post API could not be reached.'
                    : 'The Australia Post API returned an HTTP error ('.$exception->response->status().').',
                previous: $exception,
            );
        }

        return $this->responseData($response, $url, $query);
    }

    /**
     * @param  array<string, array{endpoint: string, query: array<string, mixed>}>  $calculations
     * @return array<string, array>
     */
    public function pool(array $calculations): array
    {
        if ($calculations === []) {
            return [];
        }

        $responses = Http::pool(function (Pool $pool) use ($calculations): void {
            foreach ($calculations as $key => $calculation) {
                $this->configure($pool->as($key))->get($calculation['endpoint'], $calculation['query']);
            }
        });

        $results = [];

        foreach ($responses as $key => $response) {
            if ($response instanceof RequestException) {
                $response = $response->response;
            }

            $calculation = $calculations[$key];

            if (! $response instanceof Response || ! $response->successful()) {
                Log::error('Australia Post API calculation failed', [
                    'url' => $calculation['endpoint'],
                    'query' => $calculation['query'],
                    'status' => $response instanceof Response ? $response->status() : null,
                    'body' => $response instanceof Response ? $response->body() : null,
                    'message' => $response instanceof Throwable ? $response->getMessage() : null,
                ]);

                continue;
            }

            try {
                $results[$key] = $this->responseData($response, $calculation['endpoint'], $calculation['query']);
            } catch (AustraliaPostApiException) {
                continue;
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $query */
    public function cacheKey(string $endpoint, array $query): string
    {
        ksort($query);

        return 'cargo-australia-post:calculation:'.hash('sha256', json_encode([
            'url' => $this->baseUrl().$endpoint,
            'account' => hash('sha256', $this->apiKey),
            'query' => $query,
        ], JSON_THROW_ON_ERROR));
    }

    protected function responseData(Response $response, string $url, array $query): array
    {
        $data = json_decode($response->body(), true, flags: JSON_BIGINT_AS_STRING);

        if (is_array($data) && ! array_key_exists('error', $data)) {
            return $data;
        }

        Log::error('Australia Post API returned an invalid response', [
            'url' => $url,
            'query' => $query,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new AustraliaPostApiException('The Australia Post API returned an invalid response.');
    }
}
