<?php

namespace Tests\Feature;

use App\Services\BookExtractionService;
use App\Services\NotionService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class NotionWebhookControllerTest extends TestCase
{
    public function test_returns_ng_when_book_data_is_incomplete(): void
    {
        config(['notion.webhook_key' => 'secret']);

        $bookExtractionService = Mockery::mock(BookExtractionService::class);
        $bookExtractionService->shouldReceive('extractFromProductUrl')
            ->once()
            ->with('https://example.test/book')
            ->andReturn([
                'name' => null,
                'author' => null,
                'price' => null,
                'image' => null,
            ]);
        $bookExtractionService->shouldReceive('extractionIsComplete')
            ->once()
            ->andReturnFalse();

        $notionService = Mockery::mock(NotionService::class);
        $notionService->shouldReceive('findPageIdByUniqueId')->never();
        $notionService->shouldReceive('updatePageProperties')->never();

        $this->app->instance(BookExtractionService::class, $bookExtractionService);
        $this->app->instance(NotionService::class, $notionService);

        Log::spy();

        $response = $this->postJson(route('webhook.notion.books'), [
            'source' => [
                'type' => 'automation',
            ],
            'data' => [
                'object' => 'page',
                'id' => '123e4567e89b12d3a456426614174000',
                'properties' => [
                    'ID' => [
                        'id' => 'iaMa',
                        'type' => 'unique_id',
                        'unique_id' => [
                            'prefix' => 'BOOK',
                            'number' => 39,
                        ],
                    ],
                    '商品URL' => [
                        'id' => '%3B%5DDE',
                        'type' => 'url',
                        'url' => 'https://example.test/book',
                    ],
                ],
            ],
        ], [
            'X-Webhook-Key' => 'secret',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJson([
                'status' => 'ng',
                'message' => 'Book information could not be fully extracted.',
            ]);

        Log::shouldHaveReceived('warning')
            ->once();
    }
}
