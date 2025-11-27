<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BookExtractionService
{
    public function extractFromProductUrl(string $productUrl): array
    {
        $response = Http::withToken(config('notion.openai_api_key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('notion.openai_model'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->userPrompt($productUrl),
                    ],
                ],
            ]);

        $response->throw();

        $content = $response->json('choices.0.message.content');

        return $this->normalisePayload($content);
    }

    private function systemPrompt(): string
    {
        return 'You are a data extractor that reads a book product URL and returns JSON with '
            . 'strict keys: name (string), author (string), price (number), image (url string).'
            . 'Respond in Japanese if the page is Japanese, otherwise English. Do not invent data.';
    }

    private function userPrompt(string $productUrl): string
    {
        return sprintf(
            "Book product URL: %s\n\n"
            . "Return a JSON object exactly with keys: name, author, price, image.",
            $productUrl
        );
    }

    private function normalisePayload(string $content): array
    {
        $decoded = json_decode($content, true) ?: [];

        return [
            'name' => Str::of($decoded['name'] ?? '')->trim()->value(),
            'author' => Str::of($decoded['author'] ?? '')->trim()->value(),
            'price' => is_numeric($decoded['price'] ?? null) ? (float) $decoded['price'] : null,
            'image' => $decoded['image'] ?? null,
        ];
    }
}
