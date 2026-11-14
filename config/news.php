<?php
// TASK 4 — News & Media configuration. NEW FILE. Nothing secret here.
return [
    // Disk for uploaded media. 'local' is PRIVATE (not web-served); files are streamed through authorised routes only.
    // NOTE: on ephemeral hosts (e.g. Railway) point this at a persistent/volume or S3-compatible disk.
    'disk' => env('NEWS_MEDIA_DISK', 'local'),
    'directory' => 'news-media',

    'max_image_kb' => (int) env('NEWS_MAX_IMAGE_KB', 5120),          // 5 MB
    'max_video_kb' => (int) env('NEWS_MAX_VIDEO_KB', 51200),         // 50 MB
    'max_image_pixels' => 6000,                                       // longest side
    // SVG is deliberately NOT allowed (scriptable). GIF is excluded (animated payloads, size).
    'image_mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
    'video_mimes' => ['video/mp4' => 'mp4', 'video/webm' => 'webm'],

    // Only these hosts can be embedded. The embed URL is REBUILT from provider + id; arbitrary iframe HTML is never stored or rendered.
    'embed_providers' => ['youtube', 'vimeo'],

    'signed_url_minutes' => 30,
    'per_page' => 12,
];
