<?php
require_once 'common/api-helper.php';

// Set headers and allow GET and POST
setApiHeaders([HttpMethod::GET, HttpMethod::POST]);
validateMethod([HttpMethod::GET, HttpMethod::POST]);

$method = $_SERVER['REQUEST_METHOD'];

try {
    $body = getJsonBody();
    $query = $_GET;
    
    sendSuccessResponse([
        'message' => 'Hello, world!',
        'method' => $method,
        'body' => $body,
        'query' => $query
    ], 'Hello API Response');
    
} catch (Exception $e) {
    sendErrorResponse('Server error: ' . $e->getMessage(), 500);
}
?>
