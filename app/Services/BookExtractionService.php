<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
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

        $structuredData = $this->extractFromStructuredData($xpath);

        $name = $this->extractText($xpath, '//*[@id="productTitle"] | //*[@id="ebooksProductTitle"]');
        $author = $this->extractAuthors($xpath);
        $price = $this->extractPrice($xpath);
        $image = $this->extractImageUrl($xpath);

        $name ??= $structuredData['name'];
        $author ??= $structuredData['author'];
        $price ??= $structuredData['price'];
        $image ??= $structuredData['image'];

        libxml_clear_errors();

        return [
            'name' => $name,
            'author' => $author,
            'price' => $price,
            'image' => $image,
        ];
    }

    private function extractFromStructuredData(DOMXPath $xpath): array
    {
        $result = [
            'name' => null,
            'author' => null,
            'price' => null,
            'image' => null,
        ];

        $nodes = $xpath->query('//script[@type="application/ld+json"]');

        if (! $nodes) {
            return $result;
        }

        foreach ($nodes as $node) {
            $json = $this->normaliseText($node->textContent ?? '');

            if ($json === '') {
                continue;
            }

            $decoded = json_decode($json, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->normaliseStructuredData($decoded) as $entry) {
                $this->fillStructuredDataResult($result, $entry);

                if ($this->extractionIsComplete($result)) {
                    break 2;
                }
            }
        }

        return $result;
    }

    private function normaliseStructuredData(array $data): array
    {
        if (array_is_list($data)) {
            return $data;
        }

        if (array_key_exists('@graph', $data) && is_array($data['@graph'])) {
            return $data['@graph'];
        }

        return [$data];
    }

    private function fillStructuredDataResult(array &$result, array $entry): void
    {
        $name = $this->normaliseText($entry['name'] ?? '');

        if ($result['name'] === null && $name !== '') {
            $result['name'] = $name;
        }

        if ($result['author'] === null && array_key_exists('author', $entry)) {
            $result['author'] = $this->normaliseStructuredAuthors($entry['author']);
        }

        if ($result['price'] === null && array_key_exists('offers', $entry)) {
            $result['price'] = $this->extractStructuredPrice($entry['offers']);
        }

        if ($result['image'] === null && array_key_exists('image', $entry)) {
            $result['image'] = $this->extractStructuredImage($entry['image']);
        }
    }

    private function normaliseStructuredAuthors(mixed $author): ?string
    {
        if (is_string($author)) {
            $normalised = $this->normaliseText($author);

            return $normalised !== '' ? $normalised : null;
        }

        if (! is_array($author)) {
            return null;
        }

        $authors = [];

        foreach ($author as $entry) {
            if (is_array($entry) && array_key_exists('name', $entry)) {
                $authors[] = $this->normaliseText($entry['name']);
            }
        }

        $authors = array_values(array_filter(array_unique($authors)));

        return $authors !== [] ? implode(', ', $authors) : null;
    }

    private function extractStructuredPrice(mixed $offers): ?float
    {
        $price = null;

        if (is_array($offers) && array_is_list($offers) && $offers !== []) {
            $price = $offers[0]['price'] ?? null;
        }

        if (is_array($offers) && ! array_is_list($offers)) {
            $price = $offers['price'] ?? ($offers['priceSpecification']['price'] ?? null);
        }

        if ($price === null) {
            return null;
        }

        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            return $this->parsePriceText($price);
        }

        return null;
    }

    private function extractStructuredImage(mixed $image): ?string
    {
        if (is_string($image)) {
            $normalised = $this->normaliseText($image);

            return $normalised !== '' ? $normalised : null;
        }

        if (is_array($image) && $image !== []) {
            $first = reset($image);

            if (is_string($first)) {
                $normalised = $this->normaliseText($first);

                return $normalised !== '' ? $normalised : null;
            }
        }

        return null;
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

            if ($text !== '' && ! $this->textIsFollowLink($text)) {
                $authors[] = $text;
            }
        }

        $authors = array_values(array_unique($authors));

        return $authors !== [] ? implode(', ', $authors) : null;
    }

    private function textIsFollowLink(string $text): bool
    {
        $normalised = Str::of($text)->replace(',', '')->trim();

        return $normalised->is('フォロー') || $normalised->is('Follow');
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
            . ' | //*[@id="corePrice_feature_div"]//*[contains(@class,"a-offscreen")]'
            . ' | //*[@id="corePrice_feature_div"]//span[@data-a-color="price"]'
            . ' | //*[@id="snsPriceMessage"]//*[contains(@class,"a-offscreen")]'
            . ' | //*[@id="tmmSwatches"]//span[contains(@class,"a-color-price")]'
            . ' | //meta[@itemprop="price"]/@content'
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
                $html = $response->body() ?? '';

                $this->logFetchSuccess($productUrl, $response, $html);

                if ($html === '') {
                    Log::warning('Amazon product HTML was empty after a successful response', [
                        'product_url' => $productUrl,
                        'status' => $response->status(),
                    ]);
                }

                return $html;
            }

            Log::warning('Amazon product HTML fetch failed', [
                'product_url' => $productUrl,
                'status' => $response->status(),
                'reason' => $response->reason(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Amazon product HTML fetch threw an exception', [
                'product_url' => $productUrl,
                'error' => $exception->getMessage(),
            ]);
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

    public function extractionHasAllButPrice(array $extracted): bool
    {
        $requiredKeys = ['name', 'author', 'image'];

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

        if (! array_key_exists('price', $extracted)) {
            return false;
        }

        $price = $extracted['price'];

        return $price === null || (is_string($price) && trim($price) === '');
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

    private function logFetchSuccess(string $productUrl, Response $response, string $html): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug('Amazon product HTML fetch succeeded', [
            'product_url' => $productUrl,
            'status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'html_length' => strlen($html),
            'html_excerpt' => Str::of($html)
                ->replaceMatches('/\s+/', ' ')
                ->substr(0, 200)
                ->value(),
        ]);
    }
}
