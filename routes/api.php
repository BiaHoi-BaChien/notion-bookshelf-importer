<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NotionWebhookController;

Route::post('/webhook/notion/books', NotionWebhookController::class)
    ->name('webhook.notion.books');
