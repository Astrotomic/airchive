<?php

return [
    'default' => env('CONVERSATION_IMPORT_DRIVER', 'chatgpt_json'),
    'max_upload_kilobytes' => (int) env('IMPORT_MAX_UPLOAD_KILOBYTES', 2 * 1024 * 1024),
    'temporary_upload_minutes' => (int) env('IMPORT_TEMPORARY_UPLOAD_MINUTES', 60),
];
