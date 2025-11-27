<?php

namespace Tests\Unit;

use App\Services\BookExtractionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookExtractionServiceTest extends TestCase
{
    public function test_extracts_book_fields_from_product_html(): void
    {
        $html = <<<'HTML'
        <html>
            <body>
                <span id="productTitle">サンプル書籍タイトル</span>
                <div id="bylineInfo">
                    <span class="author notFaded"><a class="a-link-normal">著者一郎</a></span>
                    <span class="author notFaded"><a class="a-link-normal">著者二郎</a></span>
                </div>
                <div id="priceInsideBuyBox_feature_div">
                    <span class="a-offscreen">￥1,234</span>
                </div>
                <div id="imgTagWrapperId">
                    <img data-old-hires="https://example.test/cover.jpg" />
                </div>
            </body>
        </html>
        HTML;

        Http::fake([
            'https://example.test/product' => Http::response($html, 200),
        ]);

        $service = new BookExtractionService();

        $extracted = $service->extractFromProductUrl('https://example.test/product');

        $this->assertSame('サンプル書籍タイトル', $extracted['name']);
        $this->assertSame('著者一郎, 著者二郎', $extracted['author']);
        $this->assertSame(1234.0, $extracted['price']);
        $this->assertSame('https://example.test/cover.jpg', $extracted['image']);
        $this->assertTrue($service->extractionIsComplete($extracted));
    }

    public function test_extraction_is_complete_detects_missing_values(): void
    {
        $service = new BookExtractionService();

        $this->assertFalse($service->extractionIsComplete([
            'name' => 'タイトル',
            'author' => null,
            'price' => 1200.0,
            'image' => 'https://example.test/cover.jpg',
        ]));
    }
}
