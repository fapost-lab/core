<?php

declare(strict_types=1);

/**
 * Media domain configuration.
 *
 * Tenant-overrideable: read via TenantInterface::getConfig('media.*'). Falls back to
 * the values defined here when the tenant has no override. Provider limits are surfaced
 * as upload warnings (informational, do not block) so authors get the right signal in
 * the picker before assigning a file to a node.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    | max_size_bytes — applied to the raw upload regardless of channel; rejects
    | the upload outright when exceeded (HTTP 422). Per-channel provider limits
    | below are warnings, not hard rejections.
    */
    'max_size_bytes'           => 100 * 1024 * 1024, // 100 MB

    /*
    |--------------------------------------------------------------------------
    | Mime type whitelist
    |--------------------------------------------------------------------------
    | Detected via finfo, never trusted from the upload extension alone. Anything
    | outside this list returns a 422 with the rejected mime in the response.
    */
    'allowed_mime_types'       => [
        // images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        // videos
        'video/mp4',
        'video/quicktime',
        'video/webm',
        // audio
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/x-wav',
        // documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Folder rules
    |--------------------------------------------------------------------------
    */
    'folder'                   => [
        'max_depth'      => 10,
        'name_max_chars' => 255,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed URL TTL (download + preview)
    |--------------------------------------------------------------------------
    */
    'download_url_ttl_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Channel provider limits
    |--------------------------------------------------------------------------
    | Surfaced as `provider_warnings` on upload responses so the picker can hint
    | "this file will not deliver via Telegram" before the author wires it into
    | a send_message node. Sizes are in bytes.
    |
    | Telegram bot API caps:
    |   photo upload: 10 MB
    |   audio:        50 MB
    |   video:        50 MB
    |   document:     50 MB
    |   voice:        50 MB
    |
    | Source: https://core.telegram.org/bots/api#sending-files
    */
    'channel_limits'           => [
        'telegram' => [
            'image'    => 10 * 1024 * 1024,
            'video'    => 50 * 1024 * 1024,
            'audio'    => 50 * 1024 * 1024,
            'document' => 50 * 1024 * 1024,
            'sticker'  => 512 * 1024,
            'other'    => 50 * 1024 * 1024,
        ],
    ],
];
