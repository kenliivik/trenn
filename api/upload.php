<?php
// Exercise photos: the app posts the image bytes (already shrunk on the device) and gets back where it is stored.
require __DIR__ . '/lib.php';
require_key();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply_error('post only', 405);
if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'image/')) reply_error('image only', 415);

$path = store_photo(file_get_contents('php://input', false, null, 0, 3_000_001));
$path ? reply(['path' => $path]) : reply_error('not an image', 400);
