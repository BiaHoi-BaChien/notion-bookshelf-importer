<?php

namespace Tests\Unit;

use App\Http\Controllers\NotionWebhookController;
use App\Services\BookExtractionService;
use App\Services\NotionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

class NotionWebhookControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    public function test_updates_provided_page_id(): void
    {
        config([
            'notion.webhook_key' => 'secret',
            'app.debug' => false,
        ]);

        $bookExtractionService = \Mockery::mock(BookExtractionService::class);
        $notionService = \Mockery::mock(NotionService::class);

        $bookExtractionService->shouldReceive('extractFromProductUrl')
            ->once()
            ->with('https://example.com/product')
            ->andReturn(['title' => 'Example Book']);

        $bookExtractionService->shouldReceive('extractionIsComplete')
            ->once()
            ->with(['title' => 'Example Book'])
            ->andReturnTrue();

        $notionService->shouldReceive('findPageIdByUniqueId')->never();
        $notionService->shouldReceive('updatePageProperties')
            ->once()
            ->with('123e4567-e89b-12d3-a456-426614174000', ['title' => 'Example Book']);

        $controller = new NotionWebhookController($bookExtractionService, $notionService);

        $request = Request::create('/', 'POST', [
            'ID' => '123e4567-e89b-12d3-a456-426614174000',
            '商品URL' => 'https://example.com/product',
        ]);

        $request->headers->set('X-Webhook-Key', 'secret');

        $response = $controller($request);

        $this->assertSame(SymfonyResponse::HTTP_OK, $response->getStatusCode());
        $this->assertSame([
            'status' => 'ok',
            'data' => ['title' => 'Example Book'],
        ], $response->getData(true));
    }

    public function test_resolves_page_id_from_unique_id(): void
    {
        config([
            'notion.webhook_key' => 'secret',
            'app.debug' => false,
        ]);

        $bookExtractionService = \Mockery::mock(BookExtractionService::class);
        $notionService = \Mockery::mock(NotionService::class);

        $bookExtractionService->shouldReceive('extractFromProductUrl')
            ->once()
            ->with('https://example.com/product')
            ->andReturn(['title' => 'Example Book']);

        $bookExtractionService->shouldReceive('extractionIsComplete')
            ->once()
            ->with(['title' => 'Example Book'])
            ->andReturnTrue();

        $notionService->shouldReceive('findPageIdByUniqueId')
            ->once()
            ->with(39)
            ->andReturn('resolved-page-id');

        $notionService->shouldReceive('updatePageProperties')
            ->once()
            ->with('resolved-page-id', ['title' => 'Example Book']);

        $controller = new NotionWebhookController($bookExtractionService, $notionService);

        $request = Request::create('/', 'POST', [
            'ID' => '39',
            '商品URL' => 'https://example.com/product',
        ]);

        $request->headers->set('X-Webhook-Key', 'secret');

        $response = $controller($request);

        $this->assertSame(SymfonyResponse::HTTP_OK, $response->getStatusCode());
        $this->assertSame([
            'status' => 'ok',
            'data' => ['title' => 'Example Book'],
        ], $response->getData(true));
    }

    public function test_resolves_page_id_from_prefixed_unique_id(): void
    {
        config([
            'notion.webhook_key' => 'secret',
            'app.debug' => false,
        ]);

        $bookExtractionService = \Mockery::mock(BookExtractionService::class);
        $notionService = \Mockery::mock(NotionService::class);

        $bookExtractionService->shouldReceive('extractFromProductUrl')
            ->once()
            ->with('https://example.com/product')
            ->andReturn(['title' => 'Example Book']);

        $bookExtractionService->shouldReceive('extractionIsComplete')
            ->once()
            ->with(['title' => 'Example Book'])
            ->andReturnTrue();

        $notionService->shouldReceive('findPageIdByUniqueId')
            ->once()
            ->with(39, 'BOOK')
            ->andReturn('resolved-page-id');

        $notionService->shouldReceive('updatePageProperties')
            ->once()
            ->with('resolved-page-id', ['title' => 'Example Book']);

        $controller = new NotionWebhookController($bookExtractionService, $notionService);

        $request = Request::create('/', 'POST', [
            'ID' => 'BOOK-39',
            '商品URL' => 'https://example.com/product',
        ]);

        $request->headers->set('X-Webhook-Key', 'secret');

        $response = $controller($request);

        $this->assertSame(SymfonyResponse::HTTP_OK, $response->getStatusCode());
        $this->assertSame([
            'status' => 'ok',
            'data' => ['title' => 'Example Book'],
        ], $response->getData(true));
    }

    public function test_handles_automation_payload_using_page_id(): void
    {
        config([
            'notion.webhook_key' => 'secret',
            'app.debug' => false,
        ]);

        $bookExtractionService = \Mockery::mock(BookExtractionService::class);
        $notionService = \Mockery::mock(NotionService::class);

        $bookExtractionService->shouldReceive('extractFromProductUrl')
            ->once()
            ->with('https://example.test/book')
            ->andReturn(['title' => 'Example Book']);

        $bookExtractionService->shouldReceive('extractionIsComplete')
            ->once()
            ->with(['title' => 'Example Book'])
            ->andReturnTrue();

        $notionService->shouldReceive('findPageIdByUniqueId')->never();
        $notionService->shouldReceive('updatePageProperties')
            ->once()
            ->with('123e4567e89b12d3a456426614174000', ['title' => 'Example Book']);

        $controller = new NotionWebhookController($bookExtractionService, $notionService);

        $request = Request::create('/', 'POST', [
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
        ]);

        $request->headers->set('X-Webhook-Key', 'secret');

        $response = $controller($request);

        $this->assertSame(SymfonyResponse::HTTP_OK, $response->getStatusCode());
        $this->assertSame([
            'status' => 'ok',
            'data' => ['title' => 'Example Book'],
        ], $response->getData(true));
    }

    public function test_returns_ng_when_page_id_cannot_be_resolved(): void
    {
        config([
            'notion.webhook_key' => 'secret',
            'app.debug' => false,
        ]);

        $bookExtractionService = \Mockery::mock(BookExtractionService::class);
        $notionService = \Mockery::mock(NotionService::class);

        $bookExtractionService->shouldReceive('extractFromProductUrl')->never();
        $bookExtractionService->shouldReceive('extractionIsComplete')->never();
        $notionService->shouldReceive('findPageIdByUniqueId')
            ->once()
            ->with(39, 'BOOK')
            ->andReturnNull();

        $controller = new NotionWebhookController($bookExtractionService, $notionService);

        $request = Request::create('/', 'POST', [
            'ID' => 'BOOK-39',
            '商品URL' => 'https://example.com/product',
        ]);

        $request->headers->set('X-Webhook-Key', 'secret');

        $response = $controller($request);

        $this->assertSame(SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame([
            'status' => 'ng',
            'message' => 'Notion page could not be resolved from provided ID.',
        ], $response->getData(true));
    }
}
