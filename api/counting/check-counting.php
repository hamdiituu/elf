<?php
require_once '../../api/common/api-helper.php';
require_once '../../config/config.php';

// Handle multiple methods with different handlers
handleMethod([
    // POST method - Check if counting exists and is active
    'POST' => function() {
        // Get sayim_no from request body (required)
        $sayim_no = getRequiredParam('sayim_no', true);
        
        $db = getDB();
        
        // Check if active sayim exists with this sayim_no
        $stmt = $db->prepare("SELECT id, sayim_no, aktif, created_at FROM sayimlar WHERE sayim_no = ? AND aktif = 1 LIMIT 1");
        $stmt->execute([$sayim_no]);
        $sayim = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sayim) {
            sendSuccessResponse([
                'id' => (int)$sayim['id'],
                'sayim_no' => $sayim['sayim_no'],
                'aktif' => (bool)$sayim['aktif'],
                'created_at' => $sayim['created_at']
            ], 'Aktif sayım bulundu');
        } else {
            // Check if sayim exists but is inactive
            $stmt = $db->prepare("SELECT id, sayim_no, aktif FROM sayimlar WHERE sayim_no = ? LIMIT 1");
            $stmt->execute([$sayim_no]);
            $inactive_sayim = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inactive_sayim) {
                sendJsonResponse([
                    'success' => false,
                    'message' => 'Sayım bulundu ancak aktif değil',
                    'data' => [
                        'id' => (int)$inactive_sayim['id'],
                        'sayim_no' => $inactive_sayim['sayim_no'],
                        'aktif' => (bool)$inactive_sayim['aktif']
                    ]
                ], 200);
            } else {
                sendErrorResponse('Sayım bulunamadı', 404);
            }
        }
    },
    
    // GET method - Get counting info by sayim_no query parameter
    'GET' => function() {
        // Get sayim_no from query parameter (required)
        $sayim_no = getRequiredParam('sayim_no', false);
        
        $db = getDB();
        
        // Get sayim info
        $stmt = $db->prepare("SELECT id, sayim_no, aktif, created_at, updated_at FROM sayimlar WHERE sayim_no = ? LIMIT 1");
        $stmt->execute([$sayim_no]);
        $sayim = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sayim) {
            sendSuccessResponse([
                'id' => (int)$sayim['id'],
                'sayim_no' => $sayim['sayim_no'],
                'aktif' => (bool)$sayim['aktif'],
                'created_at' => $sayim['created_at'],
                'updated_at' => $sayim['updated_at']
            ], 'Sayım bilgisi');
        } else {
            sendErrorResponse('Sayım bulunamadı', 404);
        }
    }
]);
