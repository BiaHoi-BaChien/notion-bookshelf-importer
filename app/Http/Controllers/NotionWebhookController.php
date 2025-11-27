<?php

namespace App\Http\Controllers;

use App\Services\BookExtractionService;
use App\Services\NotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class NotionWebhookController extends Controller
{
    public function __construct(
        private BookExtractionService $bookExtractionService,
        private NotionService $notionService
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->assertAuthorized($request);

        $this->logDebug('Webhook request received', [
            'headers' => $request->headers->except(['x-webhook-key']),
            'payload' => $request->all(),
            'webhook_key_present' => $request->hasHeader('X-Webhook-Key'),
        ]);

        $payload = $request->validate([
            'id' => ['required', 'integer'],
            'product_url' => ['required', 'url'],
        ]);

        $this->logDebug('Webhook payload validated', $payload);

        $pageId = $this->notionService->findPageIdByUniqueId((int) $payload['id']);

        if (! $pageId) {
            abort(Response::HTTP_NOT_FOUND, "Notion page not found for ID {$payload['id']}.");
        }

        $this->logDebug('Notion page resolved', ['unique_id' => $payload['id'], 'page_id' => $pageId]);

        $extracted = $this->bookExtractionService->extractFromProductUrl($payload['product_url']);

        $this->logDebug('Book data extracted', $extracted);

        $this->notionService->updatePageProperties(
            $pageId,
            $extracted
        );

        $this->logDebug('Notion page updated', ['page_id' => $pageId]);

        return response()->json([
            'status' => 'ok',
            'data' => $extracted,
        ]);
    }

    private function assertAuthorized(Request $request): void
    {
        $expected = config('notion.webhook_key');
        $provided = $request->header('X-Webhook-Key');

        if (! $expected || ! hash_equals($expected, (string) $provided)) {
            Log::warning('Webhook authorization failed');
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }

        $this->logDebug('Webhook authorized');
    }

    private function logDebug(string $message, array $context = []): void
    {
        if (config('app.debug')) {
            Log::debug($message, $context);
        }
    }

}
