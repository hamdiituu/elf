<?php
require_once '../common/api-helper.php';
require_once '../../config/config.php';

// Handle GET, POST, PUT, DELETE methods
handleMethod([
    // GET method - List products
    HttpMethod::GET => function() {
        $barkod = getOptionalParam('barkod', null, false);
        $include_deleted = getOptionalParam('include_deleted', false, false);
        
        $db = getDB();
        
        // Build query based on parameters
        if ($barkod !== null && $barkod !== '') {
            // Get specific product by barkod
            if ($include_deleted) {
                $stmt = $db->prepare("SELECT * FROM urun_tanimi WHERE barkod = ? ORDER BY created_at DESC LIMIT 1");
            } else {
                $stmt = $db->prepare("SELECT * FROM urun_tanimi WHERE barkod = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1");
            }
            $stmt->execute([$barkod]);
            $urun = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$urun) {
                sendErrorResponse('Ürün bulunamadı', 404);
            }
            
            sendSuccessResponse([
                'id' => (int)$urun['id'],
                'barkod' => $urun['barkod'],
                'urun_aciklamasi' => $urun['urun_aciklamasi'],
                'deleted_at' => $urun['deleted_at'],
                'created_at' => $urun['created_at'],
                'updated_at' => $urun['updated_at'],
                'is_deleted' => $urun['deleted_at'] !== null
            ], 'Ürün bulundu');
        } else {
            // Get all products
            if ($include_deleted) {
                $stmt = $db->query("SELECT * FROM urun_tanimi ORDER BY deleted_at IS NULL DESC, created_at DESC");
            } else {
                $stmt = $db->query("SELECT * FROM urun_tanimi WHERE deleted_at IS NULL ORDER BY created_at DESC");
            }
            
            $urunler = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format results
            $results = array_map(function($urun) {
                return [
                    'id' => (int)$urun['id'],
                    'barkod' => $urun['barkod'],
                    'urun_aciklamasi' => $urun['urun_aciklamasi'],
                    'deleted_at' => $urun['deleted_at'],
                    'created_at' => $urun['created_at'],
                    'updated_at' => $urun['updated_at'],
                    'is_deleted' => $urun['deleted_at'] !== null
                ];
            }, $urunler);
            
            sendSuccessResponse([
                'count' => count($results),
                'products' => $results
            ], 'Ürünler listelendi');
        }
    },
    
    // POST method - Create product
    HttpMethod::POST => function() {
        $barkod = getRequiredParam('barkod', true);
        $urun_aciklamasi = getOptionalParam('urun_aciklamasi', null, true);
        
        $db = getDB();
        
        // Check if product with same barkod already exists (not deleted)
        $stmt = $db->prepare("SELECT id FROM urun_tanimi WHERE barkod = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$barkod]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            sendErrorResponse('Bu barkod zaten tanımlı', 409);
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO urun_tanimi (barkod, urun_aciklamasi) VALUES (?, ?)");
            $stmt->execute([$barkod, $urun_aciklamasi]);
            
            $urun_id = $db->lastInsertId();
            
            // Get created product
            $stmt = $db->prepare("SELECT * FROM urun_tanimi WHERE id = ? LIMIT 1");
            $stmt->execute([$urun_id]);
            $urun = $stmt->fetch(PDO::FETCH_ASSOC);
            
            sendSuccessResponse([
                'id' => (int)$urun['id'],
                'barkod' => $urun['barkod'],
                'urun_aciklamasi' => $urun['urun_aciklamasi'],
                'deleted_at' => $urun['deleted_at'],
                'created_at' => $urun['created_at'],
                'updated_at' => $urun['updated_at'],
                'is_deleted' => false
            ], 'Ürün başarıyla oluşturuldu', 201);
            
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                sendErrorResponse('Bu barkod zaten tanımlı', 409);
            } else {
                sendErrorResponse('Ürün oluşturulurken hata oluştu: ' . $e->getMessage(), 500);
            }
        }
    },
    
    // PUT method - Update product
    HttpMethod::PUT => function() {
        $barkod = getRequiredParam('barkod', true);
        $urun_aciklamasi = getOptionalParam('urun_aciklamasi', null, true);
        
        $db = getDB();
        
        // Check if product exists and not deleted
        $stmt = $db->prepare("SELECT id FROM urun_tanimi WHERE barkod = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$barkod]);
        $urun = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$urun) {
            sendErrorResponse('Ürün bulunamadı veya silinmiş', 404);
        }
        
        try {
            // Update product
            $stmt = $db->prepare("UPDATE urun_tanimi SET urun_aciklamasi = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$urun_aciklamasi, $urun['id']]);
            
            // Get updated product
            $stmt = $db->prepare("SELECT * FROM urun_tanimi WHERE id = ? LIMIT 1");
            $stmt->execute([$urun['id']]);
            $updated_urun = $stmt->fetch(PDO::FETCH_ASSOC);
            
            sendSuccessResponse([
                'id' => (int)$updated_urun['id'],
                'barkod' => $updated_urun['barkod'],
                'urun_aciklamasi' => $updated_urun['urun_aciklamasi'],
                'deleted_at' => $updated_urun['deleted_at'],
                'created_at' => $updated_urun['created_at'],
                'updated_at' => $updated_urun['updated_at'],
                'is_deleted' => false
            ], 'Ürün başarıyla güncellendi');
            
        } catch (PDOException $e) {
            sendErrorResponse('Ürün güncellenirken hata oluştu: ' . $e->getMessage(), 500);
        }
    },
    
    // DELETE method - Soft delete product
    HttpMethod::DELETE => function() {
        $barkod = getRequiredParam('barkod', true);
        
        $db = getDB();
        
        // Check if product exists and not already deleted
        $stmt = $db->prepare("SELECT id FROM urun_tanimi WHERE barkod = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$barkod]);
        $urun = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$urun) {
            sendErrorResponse('Ürün bulunamadı veya zaten silinmiş', 404);
        }
        
        try {
            // Soft delete - set deleted_at timestamp
            $stmt = $db->prepare("UPDATE urun_tanimi SET deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$urun['id']]);
            
            // Get deleted product
            $stmt = $db->prepare("SELECT * FROM urun_tanimi WHERE id = ? LIMIT 1");
            $stmt->execute([$urun['id']]);
            $deleted_urun = $stmt->fetch(PDO::FETCH_ASSOC);
            
            sendSuccessResponse([
                'id' => (int)$deleted_urun['id'],
                'barkod' => $deleted_urun['barkod'],
                'urun_aciklamasi' => $deleted_urun['urun_aciklamasi'],
                'deleted_at' => $deleted_urun['deleted_at'],
                'created_at' => $deleted_urun['created_at'],
                'updated_at' => $deleted_urun['updated_at'],
                'is_deleted' => true
            ], 'Ürün başarıyla silindi');
            
        } catch (PDOException $e) {
            sendErrorResponse('Ürün silinirken hata oluştu: ' . $e->getMessage(), 500);
        }
    }
]);
?>

