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
        $dom->loadHTML('<?xml encoding="UTF-8">' . $productHtml);
        $xpath = new DOMXPath($dom);

        $name = $this->extractText($xpath, '//*[@id="productTitle"] | //*[@id="ebooksProductTitle"]');
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
        $nodes = $xpath->query(
            '//a[contains(@class,"contributorNameID")]'
            . ' | //span[contains(@class,"author")]//a'
            . ' | //*[@id="bylineInfo"]//a[contains(@class,"a-link-normal")]'
        );

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
            '//*[@id="Ebooks-desktop-KINDLE_ALC-prices-kindlePrice"]//*[contains(@class,"a-offscreen")]'
            . ' | '
            . '//*[@id="kindle-price"]//*[contains(@class,"a-offscreen")]'
            . ' | //*[@id="kindle-store-price"]//*[contains(@class,"a-offscreen")]'
            . ' | //*[@id="kindle-price-inside-buybox"]//*[contains(@class,"a-offscreen")]'
            . ' | //*[@id="priceInsideBuyBox_feature_div"]//*[contains(@class,"a-offscreen")]'
            . ' | //span[contains(@class,"a-price")]/span[contains(@class,"a-offscreen")]'
        );

        if ($priceText === null) {
            $priceText = $this->extractWholeAndFractionPrice($xpath);
        }

        if ($priceText === null) {
            return null;
        }

        return $this->parsePriceText($priceText);
    }

    private function extractWholeAndFractionPrice(DOMXPath $xpath): ?string
    {
        $whole = $this->extractText($xpath, '//span[contains(@class,"a-price-whole")]');

        if ($whole === null) {
            return null;
        }

        $fraction = $this->extractText($xpath, '//span[contains(@class,"a-price-fraction")]');

        return $fraction !== null ? $whole . '.' . $fraction : $whole;
    }

    private function parsePriceText(string $priceText): ?float
    {
        $numeric = preg_replace('/[^\d.,]/', '', $priceText);

        if ($numeric === null || $numeric === '') {
            return null;
        }

        $normalised = str_replace(',', '', $numeric);

        return is_numeric($normalised) ? (float) $normalised : null;
    }

    private function extractImageUrl(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//*[@id="imgTagWrapperId"]//img | //*[@id="ebooksImgBlkFront"] | //*[@id="imgBlkFront"]')->item(0);

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
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                    . '(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept-Language' => 'ja-JP,ja;q=0.9,en-US;q=0.8,en;q=0.7',
            ])->get($productUrl);

            if ($response->successful()) {
                return $response->body() ?? '';
            }
        } catch (\Throwable $exception) {
            // Swallow the exception to allow downstream handling based on missing HTML.
        }

        return '';
    }

    public function extractionIsComplete(array $extracted): bool
    {
        $requiredKeys = ['name', 'author', 'price', 'image'];

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $extracted)) {
                return false;
            }

            $value = $extracted[$key];

            if ($value === null) {
                return false;
            }

            if (is_string($value) && trim($value) === '') {
                return false;
            }
        }

        return true;
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
