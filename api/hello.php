<?php
header('Content-Type: application/json; charset=utf-8');

$body_raw = file_get_contents('php://input');
$body = [];
if (!empty($body_raw)) {
    $json = json_decode($body_raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $body = $json;
    } elseif ($_SERVER['CONTENT_TYPE'] ?? '' === 'application/x-www-form-urlencoded') {
        parse_str($body_raw, $body);
    }
}

$query = $_GET;

echo json_encode([
    "message" => "Hello, world!",
    "status" => "success",
    "body" => $body,
    "query" => $query
]);
