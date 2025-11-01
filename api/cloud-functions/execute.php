<?php
/**
 * Cloud Functions Execution Endpoint
 * Executes cloud functions via REST API
 */

require_once __DIR__ . '/../common/api-helper.php';
require_once __DIR__ . '/../common/node-executor.php';
require_once __DIR__ . '/../../config/config.php';

ensureCors();

// Get function name from endpoint or request body
$function_name = $_GET['function'] ?? $_POST['function'] ?? null;

if (!$function_name) {
    // Try to get from URL path: /api/cloud-functions/execute.php?function=my-function
    $path_parts = explode('/', trim($_SERVER['REQUEST_URI'] ?? '', '/'));
    foreach ($path_parts as $part) {
        if ($part !== 'api' && $part !== 'cloud-functions' && $part !== 'execute.php') {
            $function_name = $part;
            break;
        }
    }
}

// Also check POST body for function name
if (!$function_name) {
    $body = getRequestBody();
    if (isset($body['function'])) {
        $function_name = $body['function'];
    }
}

if (!$function_name) {
    sendErrorResponse('Function name is required. Provide ?function=function-name or in request body.', 400);
}

// Get function from database
$db = getDB();
$stmt = $db->prepare("SELECT cf.*, cm.code as middleware_code, cm.name as middleware_name, cm.language as middleware_language FROM cloud_functions cf LEFT JOIN cloud_middlewares cm ON cf.middleware_id = cm.id WHERE cf.name = ? AND cf.enabled = 1 LIMIT 1");
$stmt->execute([$function_name]);
$function = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$function) {
    sendErrorResponse("Function '{$function_name}' not found or disabled.", 404);
}

// Check if middleware exists and is enabled
$has_middleware = !empty($function['middleware_id']) && !empty($function['middleware_code']);

// Check HTTP method
$current_method = getCurrentMethod();
if (strtoupper($function['http_method']) !== $current_method) {
    sendErrorResponse("Method not allowed. This function requires {$function['http_method']}, got {$current_method}.", 405);
}

// Set appropriate headers based on function's HTTP method
setApiHeaders([$function['http_method']]);

// Prepare execution environment
$dbContext = $db; // Make database available as $dbContext
$request = getRequestBody(); // Request data (empty array for GET)
$method = $current_method; // HTTP method
$headers = getAllHeaders(); // Request headers
$response = ['success' => false, 'data' => null, 'message' => '', 'error' => null];

// Execute middleware first if exists
if ($has_middleware) {
    try {
        $middleware_language = $function['middleware_language'] ?? 'php';
        
        set_time_limit(30);
        $middleware_response = $response; // Initialize middleware response
        
        // Execute middleware based on language
        if ($middleware_language === 'js' || $middleware_language === 'javascript') {
            // Execute JavaScript middleware
            $middleware_result = executeNodeCode(
                $function['middleware_code'],
                [
                    'dbContext' => $dbContext,
                    'request' => $request,
                    'method' => $method,
                    'headers' => $headers,
                    'response' => $middleware_response
                ]
            );
        } else {
            // Execute PHP middleware
            $executeMiddleware = function($code, $dbContext, $request, $method, $headers, &$response) {
                $db = $dbContext;
                eval($code);
                return $response;
            };
            $middleware_result = $executeMiddleware($function['middleware_code'], $dbContext, $request, $method, $headers, $middleware_response);
        }
        
        // If middleware fails, return early without executing function
        if (isset($middleware_result['success']) && !$middleware_result['success']) {
            sendJsonResponse([
                'success' => false,
                'message' => $middleware_result['message'] ?? $middleware_result['error'] ?? 'Middleware validation failed',
                'data' => $middleware_result['data'] ?? null,
                'middleware' => $function['middleware_name'] ?? null
            ], 200);
            exit;
        }
        
        // Update response with middleware result if it was successful
        if (isset($middleware_result['success']) && $middleware_result['success']) {
            $response = $middleware_result;
        }
    } catch (ParseError $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Syntax error in middleware code: ' . $e->getMessage(),
            'error_type' => 'ParseError',
            'middleware' => $function['middleware_name'] ?? null
        ], 200);
        exit;
    } catch (Throwable $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Error executing middleware: ' . $e->getMessage(),
            'error_type' => get_class($e),
            'middleware' => $function['middleware_name'] ?? null
        ], 200);
        exit;
    }
}

// Execute function code in isolated scope
try {
    $function_language = $function['language'] ?? 'php';
    
    // Execute with timeout
    set_time_limit(30); // Max 30 seconds execution time
    
    // Execute function based on language
    if ($function_language === 'js' || $function_language === 'javascript') {
        // Execute JavaScript function
        $result = executeNodeCode(
            $function['code'],
            [
                'dbContext' => $dbContext,
                'request' => $request,
                'method' => $method,
                'headers' => $headers,
                'response' => $response
            ]
        );
    } else {
        // Execute PHP function
        $executeFunction = function($code, $dbContext, $request, $method, $headers, &$response) {
            // These variables will be available in the function code
            // $dbContext is the database connection (PDO)
            // $db is also available as alias for $dbContext
            $db = $dbContext;
            
            // Execute the code
            eval($code);
            
            return $response;
        };
        $result = $executeFunction($function['code'], $dbContext, $request, $method, $headers, $response);
    }
    
    // Always return success response (200), never 500
    // If function sets success=false, we still return 200 with success=false in response body
    if (isset($result['success']) && $result['success']) {
        sendSuccessResponse($result['data'] ?? null, $result['message'] ?? 'Function executed successfully');
    } else {
        // Return 200 with success=false instead of 500
        sendJsonResponse([
            'success' => false,
            'message' => $result['error'] ?? $result['message'] ?? 'Function execution failed',
            'data' => $result['data'] ?? null
        ], 200);
    }
    
} catch (ParseError $e) {
    // Return 200 with error message instead of 500
    sendJsonResponse([
        'success' => false,
        'message' => 'Syntax error in function code: ' . $e->getMessage(),
        'error_type' => 'ParseError'
    ], 200);
} catch (Throwable $e) {
    // Return 200 with error message instead of 500
    sendJsonResponse([
        'success' => false,
        'message' => 'Error executing function: ' . $e->getMessage(),
        'error_type' => get_class($e)
    ], 200);
}
?>

