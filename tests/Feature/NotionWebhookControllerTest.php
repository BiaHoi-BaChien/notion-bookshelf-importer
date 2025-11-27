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
            'ID' => 'BOOK-39',
            '商品URL' => 'https://example.test/book',
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
        $bookExtractionService->shouldReceive('extractFromProductUrl')
            ->once()
            ->with('https://example.test/book')
            ->andReturn([
                'name' => 'Example Book',
                'author' => 'Author Name',
                'price' => 1200,
                'image' => null,
            ]);
        $bookExtractionService->shouldReceive('extractionIsComplete')
            ->once()
            ->with([
                'name' => 'Example Book',
                'author' => 'Author Name',
                'price' => 1200,
                'image' => null,
            ])
            ->andReturnFalse();

        $notionService = Mockery::mock(NotionService::class);
        $notionService->shouldReceive('findPageIdByUniqueId')->never();
        $notionService->shouldReceive('updatePageProperties')->never();

        $this->app->instance(BookExtractionService::class, $bookExtractionService);
        $this->app->instance(NotionService::class, $notionService);

        Log::spy();

        $response = $this->postJson(route('webhook.notion.books'), [
            'ID' => '0f9d48b3a5e24f8b9d2c4d1b2e3f4567',
            '商品URL' => 'https://example.test/book',
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
}
