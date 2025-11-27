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

    public function test_extracts_price_from_kindle_alc_price_row(): void
    {
        $html = <<<'HTML'
        <html>
            <body>
                <span id="productTitle">サンプル書籍タイトル</span>
                <table>
                    <tr id="Ebooks-desktop-KINDLE_ALC-prices-kindlePrice" class="celwidget kindle-price" aria-label="Kindle 価格: ￥814 (税込)">
                        <td>
                            <span class="a-offscreen">￥814</span>
                        </td>
                    </tr>
                </table>
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

        $this->assertSame(814.0, $extracted['price']);
    }

    public function test_ignores_follow_link_text_when_extracting_authors(): void
    {
        $html = <<<'HTML'
        <html>
            <body>
                <span id="productTitle">サンプル書籍タイトル</span>
                <div id="bylineInfo">
                    <span class="author notFaded"><a class="a-link-normal">今村翔吾</a></span>
                    <span class="author notFaded"><a class="a-link-normal">フォロー</a></span>
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

        $this->assertSame('今村翔吾', $extracted['author']);
    }

    public function test_extracts_price_from_whole_and_fraction_markup(): void
    {
        $html = <<<'HTML'
        <html>
            <body>
                <span id="productTitle">サンプル書籍タイトル</span>
                <div id="corePriceDisplay_desktop_feature_div">
                    <span class="a-price aok-align-center">
                        <span class="a-price-symbol">￥</span>
                        <span class="a-price-whole">1,980</span>
                        <span class="a-price-decimal">.</span>
                        <span class="a-price-fraction">00</span>
                    </span>
                </div>
            </body>
        </html>
        HTML;

        Http::fake([
            'https://example.test/product' => Http::response($html, 200),
        ]);

        $service = new BookExtractionService();

        $extracted = $service->extractFromProductUrl('https://example.test/product');

        $this->assertSame(1980.0, $extracted['price']);
    }

    public function test_falls_back_to_structured_data_when_dom_selectors_fail(): void
    {
        $html = <<<'HTML'
        <html>
            <head>
                <script type="application/ld+json">
                    {
                        "@context": "https://schema.org",
                        "@type": "Book",
                        "name": "構造化データから取得したタイトル",
                        "author": [
                            {"@type": "Person", "name": "構造化 著者"}
                        ],
                        "offers": {
                            "@type": "Offer",
                            "price": "1,500"
                        },
                        "image": "https://example.test/structured-cover.jpg"
                    }
                </script>
            </head>
            <body>
                <div id="placeholder">This page intentionally hides normal product markup.</div>
            </body>
        </html>
        HTML;

        Http::fake([
            'https://example.test/product-with-structured-data' => Http::response($html, 200),
        ]);

        $service = new BookExtractionService();

        $extracted = $service->extractFromProductUrl('https://example.test/product-with-structured-data');

        $this->assertSame('構造化データから取得したタイトル', $extracted['name']);
        $this->assertSame('構造化 著者', $extracted['author']);
        $this->assertSame(1500.0, $extracted['price']);
        $this->assertSame('https://example.test/structured-cover.jpg', $extracted['image']);
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

    public function test_extraction_has_all_but_price_detects_missing_price_only(): void
    {
        $service = new BookExtractionService();

        $this->assertTrue($service->extractionHasAllButPrice([
            'name' => 'タイトル',
            'author' => '著者',
            'price' => null,
            'image' => 'https://example.test/cover.jpg',
        ]));

        $this->assertFalse($service->extractionHasAllButPrice([
            'name' => null,
            'author' => '著者',
            'price' => null,
            'image' => 'https://example.test/cover.jpg',
        ]));
    }

    public function test_returns_empty_extraction_when_bot_block_html_is_detected(): void
    {
        $html = <<<'HTML'
        <html>
            <head>
                <title>Robot Check</title>
            </head>
            <body>
                <form action="/errors/validateCaptcha">
                    <p>To discuss automated access to Amazon data please contact api-services-support@amazon.com.</p>
                    <input type="text" name="captcha" />
                </form>
            </body>
        </html>
        HTML;

        Http::fake([
            'https://example.test/product-blocked' => Http::response($html, 200),
        ]);

        $service = new BookExtractionService();

        $extracted = $service->extractFromProductUrl('https://example.test/product-blocked');

        $this->assertSame([
            'name' => null,
            'author' => null,
            'price' => null,
            'image' => null,
        ], $extracted);
    }
}
