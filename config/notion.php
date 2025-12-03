<?php

return [
    'api_key' => env('NOTION_API_KEY'),
    'version' => env('NOTION_VERSION', '2025-09-03'),
    'data_source_id' => env('NOTION_DATA_SOURCE_ID'),
    'base_url' => env('NOTION_BASE_URL', 'https://api.notion.com/v1'),
    'property_mapping' => json_decode(env('NOTION_PROPERTY_MAPPING', '{}'), true) ?? [],
    'webhook_key' => env('WEBHOOK_AUTH_KEY'),
    'download_webhook_image' => env('DOWNLOAD_WEBHOOK_IMAGE', false),
];
