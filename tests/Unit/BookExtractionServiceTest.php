<?php

namespace Tests\Unit;

use App\Services\BookExtractionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookExtractionServiceTest extends TestCase
{
    public function test_extracts_book_fields_from_amazon_html(): void
    {
        $html = <<<'HTML'
<html>
    <body>
        <span id="productTitle">  Example Book Title  </span>
        <div class="author">
            <a class="contributorNameID">Author One</a>
            <a class="contributorNameID">Author Two</a>
        </div>
        <div id="kindle-price">
            <span class="a-offscreen">￥1,234</span>
        </div>
        <div id="imgTagWrapperId">
            <img data-old-hires="https://example.com/large.jpg" src="https://example.com/small.jpg" />
        </div>
    </body>
</html>
HTML;

        Http::fake([
            'https://example.com/book' => Http::response($html, 200),
        ]);

        $service = new BookExtractionService();
        $extracted = $service->extractFromProductUrl('https://example.com/book');

        $this->assertSame('Example Book Title', $extracted['name']);
        $this->assertSame('Author One, Author Two', $extracted['author']);
        $this->assertSame(1234.0, $extracted['price']);
        $this->assertSame('https://example.com/large.jpg', $extracted['image']);
    }

    public function test_returns_null_fields_when_html_unavailable(): void
    {
        Http::fake([
            'https://example.com/unreachable' => Http::response('Unavailable', 500),
        ]);

        $service = new BookExtractionService();
        $extracted = $service->extractFromProductUrl('https://example.com/unreachable');

        $this->assertNull($extracted['name']);
        $this->assertNull($extracted['author']);
        $this->assertNull($extracted['price']);
        $this->assertNull($extracted['image']);
    }
}
