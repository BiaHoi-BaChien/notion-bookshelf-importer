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
            ->with('BOOK-39', ['title' => 'Example Book']);

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
}
