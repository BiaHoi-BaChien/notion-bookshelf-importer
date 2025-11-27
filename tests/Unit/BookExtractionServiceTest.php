<?php

namespace Tests\Unit;

use App\Services\BookExtractionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookExtractionServiceTest extends TestCase
{
    public function test_user_prompt_includes_url_and_html_snippet(): void
    {
        config([
            'notion.openai_api_key' => 'test-key',
            'notion.openai_model' => 'gpt-test',
        ]);

        $service = new BookExtractionService();
        $capturedPrompt = null;

        Http::fake([
            'https://example.com/book' => Http::response(str_repeat('ABC', 4000), 200),
            'https://api.openai.com/*' => function ($request) use (&$capturedPrompt) {
                $capturedPrompt = data_get($request->data(), 'messages.1.content');

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'name' => 'Example Book',
                                    'author' => 'Someone',
                                    'price' => 12.3,
                                    'image' => 'https://example.com/image.jpg',
                                ]),
                            ],
                        ],
                    ],
                ]);
            },
        ]);

        $service->extractFromProductUrl('https://example.com/book');

        $this->assertNotNull($capturedPrompt);
        $this->assertStringContainsString('Book product URL: https://example.com/book', $capturedPrompt);
        $this->assertStringContainsString('Product page HTML (truncated if long):', $capturedPrompt);
        $this->assertStringContainsString('ABC', $capturedPrompt);
        $this->assertStringContainsString('Return a JSON object exactly with keys: name, author, price, image.', $capturedPrompt);
    }

    public function test_user_prompt_mentions_missing_html_on_fetch_failure(): void
    {
        config([
            'notion.openai_api_key' => 'test-key',
            'notion.openai_model' => 'gpt-test',
        ]);

        $service = new BookExtractionService();
        $capturedPrompt = null;

        Http::fake([
            'https://example.com/unreachable' => Http::response('Unavailable', 500),
            'https://api.openai.com/*' => function ($request) use (&$capturedPrompt) {
                $capturedPrompt = data_get($request->data(), 'messages.1.content');

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'name' => null,
                                    'author' => null,
                                    'price' => null,
                                    'image' => null,
                                ]),
                            ],
                        ],
                    ],
                ]);
            },
        ]);

        $service->extractFromProductUrl('https://example.com/unreachable');

        $this->assertNotNull($capturedPrompt);
        $this->assertStringContainsString('No HTML could be fetched from the product page.', $capturedPrompt);
    }
}
