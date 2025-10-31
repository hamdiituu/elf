<?php
require_once '../../api/common/api-helper.php';
require_once '../../config/config.php';

// Handle POST and DELETE methods
handleMethod([
    // POST method - Add product to counting
    HttpMethod::POST => function() {
        // Get required parameters from body
        $sayim_no = getRequiredParam('sayim_no', true);
        $barkod = getRequiredParam('barkod', true);
        
        $db = getDB();
        
        // Check if sayım exists and is active
        $stmt = $db->prepare("SELECT id FROM sayimlar WHERE sayim_no = ? AND aktif = 1 LIMIT 1");
        $stmt->execute([$sayim_no]);
        $sayim = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sayim) {
            sendErrorResponse('Aktif sayım bulunamadı', 404);
        }
        
        $sayim_id = (int)$sayim['id'];
        
        // Check if product (urun_tanimi) exists
        $stmt = $db->prepare("SELECT id, urun_aciklamasi FROM urun_tanimi WHERE barkod = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$barkod]);
        $urun = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$urun) {
            sendErrorResponse('Ürün tanımı bulunamadı veya silinmiş', 404);
        }
        
        $urun_adi = $urun['urun_aciklamasi'] ?? null;
        
        try {
            // Insert product into sayım içerikleri
            $stmt = $db->prepare("INSERT INTO sayim_icerikleri (sayim_id, barkod, urun_adi) VALUES (?, ?, ?)");
            $stmt->execute([$sayim_id, $barkod, $urun_adi]);
            
            $icerik_id = $db->lastInsertId();
            
            sendSuccessResponse([
                'id' => (int)$icerik_id,
                'sayim_no' => $sayim_no,
                'barkod' => $barkod,
                'urun_adi' => $urun_adi,
                'message' => 'Ürün sayıma başarıyla eklendi'
            ], 'Ürün eklendi');
            
        } catch (PDOException $e) {
            sendErrorResponse('Ürün eklenirken hata oluştu: ' . $e->getMessage(), 500);
        }
    },
    
    // DELETE method - Remove product from counting (soft delete)
    HttpMethod::DELETE => function() {
        // Get required parameters from body
        $sayim_no = getRequiredParam('sayim_no', true);
        $barkod = getRequiredParam('barkod', true);
        
        $db = getDB();
        
        // Check if sayım exists and is active
        $stmt = $db->prepare("SELECT id FROM sayimlar WHERE sayim_no = ? AND aktif = 1 LIMIT 1");
        $stmt->execute([$sayim_no]);
        $sayim = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sayim) {
            sendErrorResponse('Aktif sayım bulunamadı', 404);
        }
        
        $sayim_id = (int)$sayim['id'];
        
        // Check if product (urun_tanimi) exists
        $stmt = $db->prepare("SELECT id FROM urun_tanimi WHERE barkod = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$barkod]);
        $urun = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$urun) {
            sendErrorResponse('Ürün tanımı bulunamadı veya silinmiş', 404);
        }
        
        // Check if product exists in sayım içerikleri and not already deleted
        $stmt = $db->prepare("SELECT id, urun_adi FROM sayim_icerikleri WHERE sayim_id = ? AND barkod = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$sayim_id, $barkod]);
        $icerik = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$icerik) {
            sendErrorResponse('Sayım içinde bu ürün bulunamadı veya zaten silinmiş', 404);
        }
        
        try {
            // Soft delete - set deleted_at timestamp
            $stmt = $db->prepare("UPDATE sayim_icerikleri SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$icerik['id']]);
            
            sendSuccessResponse([
                'id' => (int)$icerik['id'],
                'sayim_no' => $sayim_no,
                'barkod' => $barkod,
                'urun_adi' => $icerik['urun_adi'],
                'deleted_at' => date('Y-m-d H:i:s'),
                'message' => 'Ürün sayımdan başarıyla silindi'
            ], 'Ürün silindi');
            
        } catch (PDOException $e) {
            sendErrorResponse('Ürün silinirken hata oluştu: ' . $e->getMessage(), 500);
        }
    }
]);
?>

