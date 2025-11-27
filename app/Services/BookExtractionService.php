<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookExtractionService
{
    public function extractFromProductUrl(string $productUrl): array
    {
        $productHtml = $this->fetchProductHtml($productUrl);

        if ($productHtml === '') {
            return $this->emptyExtraction();
        }

        $extracted = $this->extractFromHtml($productHtml);

        $this->logExtraction($productUrl, $extracted);

        return $extracted;
    }

    private function extractFromHtml(string $productHtml): array
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML($productHtml);
        $xpath = new DOMXPath($dom);

        $name = $this->extractText($xpath, '//*[@id="productTitle"]');
        $author = $this->extractAuthors($xpath);
        $price = $this->extractPrice($xpath);
        $image = $this->extractImageUrl($xpath);

        libxml_clear_errors();

        return [
            'name' => $name,
            'author' => $author,
            'price' => $price,
            'image' => $image,
        ];
    }

    private function extractAuthors(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//a[contains(@class,"contributorNameID")] | //span[contains(@class,"author")]//a');

        if (! $nodes) {
            return null;
        }

        $authors = [];

        foreach ($nodes as $node) {
            $text = $this->normaliseText($node->textContent ?? '');

            if ($text !== '') {
                $authors[] = $text;
            }
        }

        $authors = array_values(array_unique($authors));

        return $authors !== [] ? implode(', ', $authors) : null;
    }

    private function extractPrice(DOMXPath $xpath): ?float
    {
        $priceText = $this->extractText(
            $xpath,
            '//*[@id="kindle-price"]//*[contains(@class,"a-offscreen")]
            | //*[@id="kindle-store-price"]//*[contains(@class,"a-offscreen")]
            | //*[@id="kindle-price-inside-buybox"]//*[contains(@class,"a-offscreen")]'
        );

        if ($priceText === null) {
            return null;
        }

        $numeric = preg_replace('/[^\d.,]/', '', $priceText);

        if ($numeric === null || $numeric === '') {
            return null;
        }

        $normalised = str_replace(',', '', $numeric);

        return is_numeric($normalised) ? (float) $normalised : null;
    }

    private function extractImageUrl(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//*[@id="imgTagWrapperId"]//img')->item(0);

        if (! $node) {
            return null;
        }

        $primary = $node->getAttribute('data-old-hires');
        $fallback = $node->getAttribute('src');

        $url = $primary !== '' ? $primary : $fallback;

        return $url !== '' ? $url : null;
    }

    private function extractText(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)->item(0);

        if (! $node) {
            return null;
        }

        $text = $this->normaliseText($node->textContent ?? '');

        return $text !== '' ? $text : null;
    }

    private function normaliseText(string $value): string
    {
        return Str::of($value)->replaceMatches('/\s+/', ' ')->trim()->value();
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

    private function emptyExtraction(): array
    {
        return [
            'name' => null,
            'author' => null,
            'price' => null,
            'image' => null,
        ];
    }

    private function logExtraction(string $productUrl, array $extracted): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug('Amazon product extraction complete', [
            'product_url' => $productUrl,
            'name' => $extracted['name'] ?? null,
            'author' => $extracted['author'] ?? null,
            'price' => $extracted['price'] ?? null,
            'image' => $extracted['image'] ?? null,
        ]);
    }
}
