<?php

declare(strict_types=1);

// Router script for `php -S`: stores the request of an OTLP exporter in RECEIVER_OUT, after RECEIVER_SLEEP seconds.
$out = getenv('RECEIVER_OUT');
$sleep = (int) getenv('RECEIVER_SLEEP');
if ($sleep > 0) {
    sleep($sleep);
}

if (is_string($out) && $out !== '') {
    file_put_contents($out, json_encode([
        'path' => $_SERVER['REQUEST_URI'] ?? '',
        'contentType' => $_SERVER['CONTENT_TYPE'] ?? '',
        'body' => base64_encode((string) file_get_contents('php://input')),
    ], JSON_THROW_ON_ERROR));
}

// An empty ExportTraceServiceResponse: no bytes in protobuf, {} in JSON.
$contentType = is_string($_SERVER['CONTENT_TYPE'] ?? null) ? $_SERVER['CONTENT_TYPE'] : 'application/x-protobuf';
http_response_code(200);
header('Content-Type: ' . $contentType);
echo str_starts_with($contentType, 'application/json') ? '{}' : '';
