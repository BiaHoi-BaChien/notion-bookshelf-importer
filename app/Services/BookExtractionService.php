<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookExtractionService
{
    private const HTML_SNIPPET_LIMIT = 8000;

    public function extractFromProductUrl(string $productUrl): array
    {
        $productHtml = $this->fetchProductHtml($productUrl);

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
                        'content' => $this->userPrompt($productUrl, $productHtml),
                    ],
                ],
            ]);

        if (config('app.debug')) {
            Log::debug('OpenAI chat completion response', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

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

    private function userPrompt(string $productUrl, string $productHtml): string
    {
        $htmlSection = $this->formatHtmlForPrompt($productHtml);

        return trim(sprintf(
            "Book product URL: %s\n\n"
            . "Product page HTML (truncated if long):\n%s\n\n"
            . "Return a JSON object exactly with keys: name, author, price, image.",
            $productUrl,
            $htmlSection
        ));
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

    private function fetchProductHtml(string $productUrl): string
    {
        try {
            $response = Http::get($productUrl);

            if ($response->successful()) {
                return $response->body() ?? '';
            }
        } catch (\Throwable $exception) {
            // Swallow the exception to allow downstream handling based on missing HTML.
        }

        return '';
    }

    private function formatHtmlForPrompt(string $productHtml): string
    {
        if ($productHtml === '') {
            return 'No HTML could be fetched from the product page.';
        }

        $squishedHtml = Str::of($productHtml)->squish()->value();
        $limitedHtml = Str::limit($squishedHtml, self::HTML_SNIPPET_LIMIT, '... [truncated]');

        if (Str::length($squishedHtml) > self::HTML_SNIPPET_LIMIT) {
            return $limitedHtml . sprintf(
                "\n\nNote: HTML content truncated to the first %d characters to fit the prompt size.",
                self::HTML_SNIPPET_LIMIT
            );
        }

        return $limitedHtml;
    }
}
