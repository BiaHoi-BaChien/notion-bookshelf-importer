<?php

return [
    'api_key' => env('NOTION_API_KEY'),
    'version' => env('NOTION_VERSION', '2025-09-03'),
    'data_source_id' => env('NOTION_DATA_SOURCE_ID'),
    'base_url' => env('NOTION_BASE_URL', 'https://api.notion.com/v1'),
    'property_mapping' => json_decode(env('NOTION_PROPERTY_MAPPING', '{}'), true) ?? [],
    'openai_api_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    'webhook_key' => env('WEBHOOK_AUTH_KEY'),
];
