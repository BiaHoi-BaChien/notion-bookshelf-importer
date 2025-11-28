<?php

namespace App\Http\Controllers;

use App\Services\BookExtractionService;
use App\Services\NotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
            'headers' => Arr::except($request->headers->all(), ['x-webhook-key']),
            'payload' => $request->all(),
            'webhook_key_present' => $request->hasHeader('X-Webhook-Key'),
        ]);

        $payload = $this->normalizePayload($request);

        $this->logDebug('Webhook payload validated', $payload);

        $pageId = $this->resolvePageId($payload['ID']);

        if (! $pageId) {
            return response()->json([
                'status' => 'ng',
                'message' => 'Notion page could not be resolved from provided ID.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (array_key_exists('情報', $payload)) {
            $extracted = $this->extractFromInformation($payload['情報']);
        } else {
            $extracted = $this->bookExtractionService->extractFromProductUrl($payload['商品URL']);
        }

        $this->logDebug('Book data extracted', $extracted);

        if (! $this->bookExtractionService->extractionIsComplete($extracted)) {
            if ($this->bookExtractionService->extractionHasAllButPrice($extracted)) {
                Log::warning('Book price could not be extracted; proceeding without price.', [
                    'page_id' => $pageId,
                    'product_url' => $payload['商品URL'],
                ]);
            } else {
                Log::warning('Book extraction incomplete', [
                    'page_id' => $pageId,
                    'product_url' => $payload['商品URL'],
                ]);

                return response()->json([
                    'status' => 'ng',
                    'message' => 'Book information could not be fully extracted.',
                    'data' => $extracted,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $this->notionService->updatePageProperties(
            $pageId,
            $extracted
        );

        $this->logDebug('Notion page updated', ['page_id' => $pageId]);

        return response()->json([
            'status' => 'ok',
            'data' => $extracted,
        ], Response::HTTP_OK, [], JSON_PRESERVE_ZERO_FRACTION);
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

    private function normalizePayload(Request $request): array
    {
        if ($request->has('情報')) {
            $request->validate([
                'ID' => ['required', 'string'],
                '情報' => ['required'],
            ]);

            return [
                'ID' => $request->input('ID'),
                '情報' => $this->validateInformationPayload($request->input('情報')),
            ];
        }

        if ($request->has(['ID', '商品URL'])) {
            return $request->validate([
                'ID' => ['required', 'string'],
                '商品URL' => ['required', 'url'],
            ]);
        }

        if ($request->has('data')) {
            $request->validate([
                'data' => ['required', 'array'],
                'data.id' => ['required', 'string'],
                'data.properties' => ['required', 'array'],
                'data.properties.商品URL.url' => ['required', 'url'],
            ]);

            return [
                'ID' => $request->input('data.id'),
                '商品URL' => $request->input('data.properties.商品URL.url'),
            ];
        }

        return $request->validate([
            'ID' => ['required', 'string'],
            '商品URL' => ['required', 'url'],
        ]);
    }

    private function validateInformationPayload(mixed $information): array
    {
        if (is_string($information)) {
            $decoded = json_decode($information, true);

            if (! is_array($decoded)) {
                throw ValidationException::withMessages([
                    '情報' => '情報は有効なJSON形式で提供してください。',
                ]);
            }

            $information = $decoded;
        }

        if (! is_array($information)) {
            throw ValidationException::withMessages([
                '情報' => '情報は配列で提供してください。',
            ]);
        }

        $validator = validator([
            '情報' => $information,
        ], [
            '情報' => ['required', 'array'],
            '情報.title' => ['nullable', 'string'],
            '情報.author' => ['nullable', 'string'],
            '情報.kindle_price' => ['nullable', 'string'],
            '情報.image_url' => ['nullable', 'url'],
        ]);

        return $validator->validate()['情報'];
    }

    private function resolvePageId(string $providedId): ?string
    {
        if ($this->looksLikePageId($providedId)) {
            $this->logDebug('Notion page provided', ['page_id' => $providedId]);

            return $providedId;
        }

        $uniqueId = $this->parseUniqueId($providedId);

        if ($uniqueId === null) {
            Log::warning('Provided ID could not be parsed as a Notion Unique ID or page_id.', [
                'provided_id' => $providedId,
            ]);

            return null;
        }

        $pageId = isset($uniqueId['prefix'])
            ? $this->notionService->findPageIdByUniqueId($uniqueId['number'], $uniqueId['prefix'])
            : $this->notionService->findPageIdByUniqueId($uniqueId['number']);

        if ($pageId) {
            $this->logDebug('Notion page resolved', ['unique_id' => $providedId, 'page_id' => $pageId]);
        } else {
            Log::warning('Notion page not found for provided Unique ID.', [
                'provided_id' => $providedId,
                'parsed_unique_id' => $uniqueId,
            ]);
        }

        return $pageId;
    }

    private function parseUniqueId(string $id): ?array
    {
        if (ctype_digit($id)) {
            return ['number' => (int) $id];
        }

        if (preg_match('/^(?<prefix>[A-Za-z]+)-(?<number>\d+)$/', $id, $matches)) {
            return [
                'prefix' => $matches['prefix'],
                'number' => (int) $matches['number'],
            ];
        }

        return null;
    }

    private function looksLikePageId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F]{32}$/', $id)
            || (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id);
    }

    private function extractFromInformation(array $information): array
    {
        $price = $this->parsePriceText(Arr::get($information, 'kindle_price'));

        if ($price === null) {
            return [
                'title' => Arr::get($information, 'title'),
                'price' => null,
            ];
        }

        return [
            'name' => Arr::get($information, 'title'),
            'author' => Arr::get($information, 'author'),
            'price' => $price,
            'image' => Arr::get($information, 'image_url'),
        ];
    }

    private function parsePriceText(?string $priceText): ?float
    {
        if ($priceText === null) {
            return null;
        }

        $numeric = preg_replace('/[^\d.,]/', '', $priceText);

        if ($numeric === null || $numeric === '') {
            return null;
        }

        $normalised = Str::of($numeric)->replace(',', '')->value();

        return is_numeric($normalised) ? (float) $normalised : null;
    }

    private function logDebug(string $message, array $context = []): void
    {
        if (config('app.debug')) {
            Log::debug($message, $context);
        }
    }

}
