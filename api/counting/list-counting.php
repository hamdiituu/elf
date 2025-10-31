<?php
require_once '../../api/common/api-helper.php';
require_once '../../config/config.php';

// Handle GET method
handleMethod([
    // GET method - List counting contents
    HttpMethod::GET => function() {
        // Get required parameter
        $sayim_no = getRequiredParam('sayim_no', false);
        
        // Get optional parameter
        $barkod = getOptionalParam('barkod', null, false);
        
        $db = getDB();
        
        // Check if sayım exists
        $stmt = $db->prepare("SELECT id FROM sayimlar WHERE sayim_no = ? LIMIT 1");
        $stmt->execute([$sayim_no]);
        $sayim = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sayim) {
            sendErrorResponse('Sayım bulunamadı', 404);
        }
        
        $sayim_id = (int)$sayim['id'];
        
        // Build query based on whether barkod is provided
        if ($barkod !== null && $barkod !== '') {
            // Filter by barkod
            $stmt = $db->prepare("
                SELECT 
                    si.id,
                    si.sayim_id,
                    s.sayim_no,
                    si.barkod,
                    si.urun_adi,
                    si.okutulma_zamani,
                    si.deleted_at
                FROM sayim_icerikleri si
                JOIN sayimlar s ON si.sayim_id = s.id
                WHERE si.sayim_id = ? AND si.barkod = ?
                ORDER BY si.okutulma_zamani DESC
            ");
            $stmt->execute([$sayim_id, $barkod]);
        } else {
            // Get all items for this sayım
            $stmt = $db->prepare("
                SELECT 
                    si.id,
                    si.sayim_id,
                    s.sayim_no,
                    si.barkod,
                    si.urun_adi,
                    si.okutulma_zamani,
                    si.deleted_at
                FROM sayim_icerikleri si
                JOIN sayimlar s ON si.sayim_id = s.id
                WHERE si.sayim_id = ?
                ORDER BY si.deleted_at IS NULL DESC, si.okutulma_zamani DESC
            ");
            $stmt->execute([$sayim_id]);
        }
        
        $icerikler = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format results
        $results = array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'sayim_id' => (int)$item['sayim_id'],
                'sayim_no' => $item['sayim_no'],
                'barkod' => $item['barkod'],
                'urun_adi' => $item['urun_adi'],
                'okutulma_zamani' => $item['okutulma_zamani'],
                'deleted_at' => $item['deleted_at'],
                'is_deleted' => $item['deleted_at'] !== null
            ];
        }, $icerikler);
        
        sendSuccessResponse([
            'sayim_no' => $sayim_no,
            'count' => count($results),
            'items' => $results
        ], 'Sayım içerikleri listelendi');
    }
]);
?>

