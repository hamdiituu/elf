<?php
/**
 * Node.js Code Executor
 * Executes JavaScript/Node.js code via Node.js HTTP server
 */

/**
 * Start Node.js server if not running
 * @return bool True if server is running or started successfully
 */
function ensureNodeJsServer() {
    $node_path = trim(shell_exec('which node 2>/dev/null'));
    if (empty($node_path)) {
        return false;
    }
    
    $server_script = __DIR__ . '/../nodejs-server.js';
    $pid_file = __DIR__ . '/../nodejs-server.pid';
    $port = 3001;
    
    // Check if server is already running
    if (file_exists($pid_file)) {
        $pid = trim(file_get_contents($pid_file));
        if ($pid && function_exists('posix_kill') && posix_kill(intval($pid), 0)) {
            // Check if port is listening
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if ($connection) {
                fclose($connection);
                return true;
            }
        }
        // PID file exists but process is dead - remove it
        @unlink($pid_file);
    }
    
    // Start server
    $command = sprintf(
        'cd %s && nohup %s %s > /dev/null 2>&1 &',
        escapeshellarg(__DIR__ . '/..'),
        escapeshellarg($node_path),
        escapeshellarg($server_script)
    );
    
    exec($command);
    
    // Wait a bit for server to start
    for ($i = 0; $i < 10; $i++) {
        usleep(200000); // 200ms
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($connection) {
            fclose($connection);
            return true;
        }
    }
    
    return false;
}

/**
 * Execute JavaScript/Node.js code
 * @param string $code JavaScript code to execute
 * @param array $context Context variables (request, method, headers, response)
 * @return array Result array with 'success', 'data', 'message', 'error'
 */
function executeNodeCode($code, $context = []) {
    // Ensure Node.js server is running
    if (!ensureNodeJsServer()) {
        return [
            'success' => false,
            'message' => 'Node.js server is not available. Please ensure Node.js is installed.',
            'error' => 'Server not running'
        ];
    }
    
    $port = 3001;
    $url = "http://127.0.0.1:{$port}/";
    
    // Prepare request data
    $requestData = [
        'code' => $code,
        'context' => [
            'request' => $context['request'] ?? [],
            'method' => $context['method'] ?? 'POST',
            'headers' => $context['headers'] ?? [],
            'response' => $context['response'] ?? ['success' => false, 'data' => null, 'message' => '', 'error' => null]
        ]
    ];
    
    // Make HTTP request
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'message' => 'Failed to connect to Node.js server: ' . $error,
            'error' => $error
        ];
    }
    
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'message' => 'Node.js server returned error code: ' . $httpCode,
            'error' => 'HTTP ' . $httpCode
        ];
    }
    
    $result = json_decode($response, true);
    if (!$result || !is_array($result)) {
        return [
            'success' => false,
            'message' => 'Invalid response from Node.js server',
            'error' => 'Invalid JSON'
        ];
    }
    
    return $result;
}
