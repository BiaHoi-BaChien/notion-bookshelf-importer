<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class NotionService
{
    public function findPageIdByUniqueId(int $uniqueId): ?string
    {
        $dataSourceId = config('notion.data_source_id');

        if (! $dataSourceId) {
            throw new InvalidArgumentException('NOTION_DATA_SOURCE_ID must be configured to query by unique ID.');
        }

        $payload = [
            'filter' => [
                'property' => 'ID',
                'unique_id' => [
                    'equals' => [
                        'number' => $uniqueId,
                    ],
                ],
            ],
        ];

        $endpoint = $this->endpoint("data_sources/{$dataSourceId}/query");

        $response = Http::withHeaders($this->headers())
            ->post($endpoint, $payload);

        $this->logNotionHttp('POST', $endpoint, $payload, $response);

        if ($response->failed()) {
            Log::error('Failed to query Notion data source', [
                'unique_id' => $uniqueId,
                'body' => $response->json(),
            ]);

            $response->throw();
        }

        $results = $response->json('results', []);

        if (config('app.debug')) {
            Log::debug('Notion query results', [
                'unique_id' => $uniqueId,
                'results' => $results,
            ]);
        }

        if (count($results) === 0) {
            return null;
        }

        return $results[0]['id'] ?? null;
    }

    public function updatePageProperties(string $pageId, array $properties): void
    {
        $payload = [
            'properties' => $this->buildProperties($properties),
        ];

        $endpoint = $this->endpoint("pages/{$pageId}");

        $response = Http::withHeaders($this->headers())
            ->patch($endpoint, $payload);

        $this->logNotionHttp('PATCH', $endpoint, $payload, $response);

        if ($response->failed()) {
            Log::error('Failed to update Notion page', [
                'page_id' => $pageId,
                'body' => $response->json(),
            ]);

            $response->throw();
        }
    }

    private function buildProperties(array $properties): array
    {
        $mapping = config('notion.property_mapping');
        $payload = [];

        if ($mapping === [] || $mapping === null) {
            Log::error('Notion property mapping is empty. Set NOTION_PROPERTY_MAPPING to map extracted fields.');

            throw new InvalidArgumentException('NOTION_PROPERTY_MAPPING must be configured to build page properties.');
        }

        $builder = [
            'title' => fn ($value) => ['title' => [[
                'text' => ['content' => $value],
            ]]],
            'select' => fn ($value) => ['select' => ['name' => $value]],
            'date' => fn ($value) => ['date' => ['start' => $value]],
            'number' => fn ($value) => ['number' => $value],
            'image' => fn ($value) => ['files' => [[
                'name' => basename((string) $value) ?: 'image',
                'type' => 'external',
                'external' => ['url' => $value],
            ]]],
        ];

        foreach ($mapping as $key => $config) {
            $propertyName = Arr::get($config, 'name');
            $type = Arr::get($config, 'type');

            if (! $propertyName || ! $type || ! Arr::exists($properties, $key)) {
                continue;
            }

            $value = $properties[$key];
            if ($value === null || $value === '') {
                continue;
            }

            if (isset($builder[$type])) {
                $payload[$propertyName] = $builder[$type]($value);
            }
        }

        if ($payload === []) {
            Log::warning('No Notion properties were built from the extracted data and mapping.', [
                'extracted_keys' => array_keys($properties),
                'mapping_keys' => array_keys($mapping),
            ]);

            throw new InvalidArgumentException('No Notion properties could be built from the provided mapping.');
        }

        return $payload;
    }

    private function endpoint(string $path): string
    {
        $baseUrl = rtrim((string) config('notion.base_url'), '/');

        if ($baseUrl === '') {
            throw new InvalidArgumentException('NOTION_BASE_URL must be configured.');
        }

        $parsed = parse_url($baseUrl) ?: [];
        $scheme = $parsed['scheme'] ?? null;
        $host = $parsed['host'] ?? null;
        $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';
        $pathInBase = trim($parsed['path'] ?? '', '/');

        if ($scheme === null || $host === null) {
            throw new InvalidArgumentException('NOTION_BASE_URL must include a valid scheme and host.');
        }

        $normalizedBase = sprintf('%s://%s%s', $scheme, $host, $port);
        if ($pathInBase !== '') {
            $normalizedBase .= '/' . $pathInBase;
        }

        return $normalizedBase . '/' . ltrim($path, '/');
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . config('notion.api_key'),
            'Notion-Version' => config('notion.version'),
            'Content-Type' => 'application/json',
        ];
    }

    private function logNotionHttp(string $method, string $endpoint, array $payload, Response $response): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug('Notion API request', [
            'method' => $method,
            'url' => $endpoint,
            'payload' => $payload,
        ]);

        Log::debug('Notion API response', [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);
    }
}
