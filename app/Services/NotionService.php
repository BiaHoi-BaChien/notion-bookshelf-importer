<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class NotionService
{
    public function updatePageProperties(string $pageId, array $properties): void
    {
        $payload = [
            'properties' => $this->buildProperties($properties),
        ];

        $response = Http::withHeaders($this->headers())
            ->patch($this->endpoint("items/{$pageId}"), $payload);

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

        return $payload;
    }

    private function endpoint(string $path): string
    {
        $baseUrl = rtrim((string) config('notion.base_url'), '/');
        $dataSourceId = (string) config('notion.data_source_id');

        if ($baseUrl === '' || $dataSourceId === '') {
            throw new InvalidArgumentException('NOTION_BASE_URL and NOTION_DATA_SOURCE_ID must be configured.');
        }

        return $baseUrl . "/data_sources/{$dataSourceId}/" . ltrim($path, '/');
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . config('notion.api_key'),
            'Notion-Version' => config('notion.version'),
            'Content-Type' => 'application/json',
        ];
    }
}
