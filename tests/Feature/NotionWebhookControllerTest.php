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
        $bookExtractionService->shouldReceive('extractFromProductUrl')->never();
        $bookExtractionService->shouldReceive('extractionIsComplete')
            ->once()
            ->with([
                'name' => null,
                'author' => null,
                'price' => null,
                'image' => null,
            ])
            ->andReturnFalse();
        $bookExtractionService->shouldReceive('extractionHasAllButPrice')
            ->once()
            ->andReturnFalse();

        $notionService = Mockery::mock(NotionService::class);
        $notionService->shouldReceive('findPageIdByUniqueId')->never();
        $notionService->shouldReceive('updatePageProperties')->never();

        $this->app->instance(BookExtractionService::class, $bookExtractionService);
        $this->app->instance(NotionService::class, $notionService);

        Log::spy();

        $response = $this->postJson(route('webhook.notion.books'), [
            'ID' => 'BOOK-39',
            '情報' => [
                'title' => null,
                'author' => null,
                'kindle_price' => null,
                'image_url' => null,
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

    public function test_returns_ng_with_extracted_data_when_image_is_missing(): void
    {
        config(['notion.webhook_key' => 'secret']);

        $bookExtractionService = Mockery::mock(BookExtractionService::class);
        $bookExtractionService->shouldReceive('extractFromProductUrl')->never();
        $bookExtractionService->shouldReceive('extractionIsComplete')
            ->once()
            ->with([
                'name' => 'Example Book',
                'author' => 'Author Name',
                'price' => 1200,
                'image' => null,
            ])
            ->andReturnFalse();
        $bookExtractionService->shouldReceive('extractionHasAllButPrice')
            ->once()
            ->andReturnFalse();

        $notionService = Mockery::mock(NotionService::class);
        $notionService->shouldReceive('findPageIdByUniqueId')->never();
        $notionService->shouldReceive('updatePageProperties')->never();

        $this->app->instance(BookExtractionService::class, $bookExtractionService);
        $this->app->instance(NotionService::class, $notionService);

        Log::spy();

        $response = $this->postJson(route('webhook.notion.books'), [
            'ID' => '0f9d48b3a5e24f8b9d2c4d1b2e3f4567',
            '情報' => [
                'title' => 'Example Book',
                'author' => 'Author Name',
                'kindle_price' => '1200',
                'image_url' => null,
            ],
        ], [
            'X-Webhook-Key' => 'secret',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJson([
                'status' => 'ng',
                'message' => 'Book information could not be fully extracted.',
                'data' => [
                    'name' => 'Example Book',
                    'author' => 'Author Name',
                    'price' => 1200,
                    'image' => null,
                ],
            ]);

        Log::shouldHaveReceived('warning')
            ->once();
    }

    public function test_returns_ok_when_price_is_missing(): void
    {
        config(['notion.webhook_key' => 'secret']);

        $bookExtractionService = Mockery::mock(BookExtractionService::class);
        $bookExtractionService->shouldReceive('extractFromProductUrl')->never();
        $bookExtractionService->shouldReceive('extractionIsComplete')
            ->once()
            ->with([
                'name' => 'Example Book',
                'author' => 'Author Name',
                'price' => null,
                'image' => 'https://example.test/image.jpg',
            ])
            ->andReturnFalse();
        $bookExtractionService->shouldReceive('extractionHasAllButPrice')
            ->once()
            ->with([
                'name' => 'Example Book',
                'author' => 'Author Name',
                'price' => null,
                'image' => 'https://example.test/image.jpg',
            ])
            ->andReturnTrue();

        $notionService = Mockery::mock(NotionService::class);
        $notionService->shouldReceive('findPageIdByUniqueId')
            ->once()
            ->with(39, 'BOOK')
            ->andReturn('resolved-page-id');
        $notionService->shouldReceive('updatePageProperties')
            ->once()
            ->with('resolved-page-id', [
                'name' => 'Example Book',
                'author' => 'Author Name',
                'price' => null,
                'image' => 'https://example.test/image.jpg',
            ]);

        $this->app->instance(BookExtractionService::class, $bookExtractionService);
        $this->app->instance(NotionService::class, $notionService);

        Log::spy();

        $response = $this->postJson(route('webhook.notion.books'), [
            'ID' => 'BOOK-39',
            '情報' => [
                'title' => 'Example Book',
                'author' => 'Author Name',
                'kindle_price' => null,
                'image_url' => 'https://example.test/image.jpg',
            ],
        ], [
            'X-Webhook-Key' => 'secret',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'data' => [
                    'name' => 'Example Book',
                    'author' => 'Author Name',
                    'price' => null,
                    'image' => 'https://example.test/image.jpg',
                ],
            ]);

        Log::shouldHaveReceived('warning')
            ->once();
    }
}
