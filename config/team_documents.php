<?php
// LOCATION: config/team_documents.php
//
// Document attachments on MarvFlow team requests / replies (PDF, DOCX, XLSX, TXT, MD).
// Images are NOT handled here: they keep their existing path (Cloudinary + team_requests.attachment_url /
// team_request_messages.attachment_url, 5 MB limit), unchanged.

return [

    // Private disk (config/filesystems.php). Never a public/URL-served disk: files are only ever streamed through the
    // authenticated download routes after a per-request authorisation check.
    'disk' => env('TEAM_DOCUMENTS_DISK', 'team_documents'),

    // Configurable size limit for documents, in kilobytes. Default 10 MB.
    // NOTE: PHP's own upload_max_filesize / post_max_size (and any proxy limit) must be at least this large.
    'max_kb' => (int) env('TEAM_DOCUMENT_MAX_KB', 10240),

    // Whitelist: extension => canonical MIME type that is STORED and SERVED (never the client-supplied one).
    // Legacy binary Office formats (.doc / .xls) are deliberately NOT supported: they can carry macros and cannot be
    // content-verified reliably. Macro-enabled OOXML (.docm / .xlsm) is rejected too.
    'types' => [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt'  => 'text/plain',
        'md'   => 'text/markdown',
    ],

    // Content-sniffed MIME types (finfo) accepted for each extension.
    'detected_mimes' => [
        'pdf'  => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'],
        'txt'  => ['text/plain'],
        'md'   => ['text/plain', 'text/markdown', 'text/x-markdown'],
    ],

    // OOXML (DOCX/XLSX are ZIP containers) safety limits against zip bombs.
    'ooxml' => [
        'max_entries'            => 5000,
        'max_uncompressed_bytes' => 150 * 1024 * 1024,
    ],
];
