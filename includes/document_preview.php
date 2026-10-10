<?php

function officePreviewMimeType(array $attachment): ?string
{
    $extension = strtolower(pathinfo((string) ($attachment['original_name'] ?? ''), PATHINFO_EXTENSION));
    $storedMimeType = strtolower((string) ($attachment['mime_type'] ?? ''));
    $previewTypes = [
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-zip',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip',
        ],
    ];
    $canonicalTypes = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    if (!isset($previewTypes[$extension]) || !in_array($storedMimeType, $previewTypes[$extension], true)) {
        return null;
    }

    return $canonicalTypes[$extension];
}
