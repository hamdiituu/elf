<?php
/**
 * Mock Data Insert Script
 * Inserts 10000 product definitions and 10000 stock records
 */

require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    
    // Ensure tables exist
    $db->exec("CREATE TABLE IF NOT EXISTS urun_tanimi (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        barkod TEXT NOT NULL UNIQUE,
        urun_aciklamasi TEXT,
        deleted_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Ensure urun_stok table exists
    $db->exec("CREATE TABLE IF NOT EXISTS urun_stok (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        barkod TEXT NOT NULL,
        adet INTEGER DEFAULT 0
    )");
    
    echo "Starting mock data insertion...\n";
    echo "This may take a while for 10000 records...\n\n";
    
    // Start transaction for better performance
    $db->beginTransaction();
    
    // Insert 10000 product definitions
    echo "Inserting 10000 product definitions...\n";
    $product_names = [
        'Ürün', 'Mal', 'Eşya', 'Parça', 'Modül', 'Komponent', 'Aksesuar', 
        'Kılıf', 'Kasa', 'Kutu', 'Şişe', 'Ambalaj', 'Paket', 'Kartuş', 
        'Yedek', 'Montaj', 'Set', 'Takım', 'Grup', 'Seri'
    ];
    
    $product_types = [
        'Elektronik', 'Mekanik', 'Plastik', 'Metal', 'Ahşap', 'Cam', 
        'Kumaş', 'Kağıt', 'Kimyasal', 'Gıda', 'Tekstil', 'Kozmetik'
    ];
    
    $insert_product_stmt = $db->prepare("INSERT INTO urun_tanimi (barkod, urun_aciklamasi) VALUES (?, ?)");
    
    $start_time = microtime(true);
    for ($i = 1; $i <= 10000; $i++) {
        // Generate unique barcode (13 digits)
        $barcode = str_pad($i, 13, '0', STR_PAD_LEFT);
        
        // Generate product description
        $product_name = $product_names[array_rand($product_names)];
        $product_type = $product_types[array_rand($product_types)];
        $product_number = str_pad($i, 4, '0', STR_PAD_LEFT);
        $description = "$product_name $product_type $product_number";
        
        try {
            $insert_product_stmt->execute([$barcode, $description]);
        } catch (PDOException $e) {
            // Skip if duplicate barcode (shouldn't happen)
            if (strpos($e->getMessage(), 'UNIQUE constraint') === false) {
                throw $e;
            }
        }
        
        // Progress indicator every 1000 records
        if ($i % 1000 == 0) {
            $elapsed = microtime(true) - $start_time;
            $rate = $i / $elapsed;
            $remaining = (10000 - $i) / $rate;
            echo "  Inserted $i / 10000 products (Estimated time remaining: " . round($remaining, 1) . " seconds)...\n";
        }
    }
    
    echo "✓ 10000 product definitions inserted successfully!\n\n";
    
    // Insert 10000 stock records
    echo "Inserting 10000 stock records...\n";
    
    $insert_stok_stmt = $db->prepare("INSERT INTO urun_stok (barkod, adet) VALUES (?, ?)");
    
    // Get all barcodes from urun_tanimi
    $barcodes = $db->query("SELECT barkod FROM urun_tanimi ORDER BY id LIMIT 10000")->fetchAll(PDO::FETCH_COLUMN);
    
    $start_time = microtime(true);
    foreach ($barcodes as $index => $barcode) {
        // Random stock quantity between 0 and 1000
        $adet = rand(0, 1000);
        
        try {
            $insert_stok_stmt->execute([$barcode, $adet]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint') === false) {
                throw $e;
            }
        }
        
        // Progress indicator every 1000 records
        if (($index + 1) % 1000 == 0) {
            $elapsed = microtime(true) - $start_time;
            $rate = ($index + 1) / $elapsed;
            $remaining = (10000 - ($index + 1)) / $rate;
            echo "  Inserted " . ($index + 1) . " / 10000 stock records (Estimated time remaining: " . round($remaining, 1) . " seconds)...\n";
        }
    }
    
    // Commit transaction
    $db->commit();
    
    echo "✓ 10000 stock records inserted successfully!\n\n";
    
    // Verify counts
    $product_count = $db->query("SELECT COUNT(*) FROM urun_tanimi")->fetchColumn();
    $stock_count = $db->query("SELECT COUNT(*) FROM urun_stok")->fetchColumn();
    
    echo "Verification:\n";
    echo "  Product definitions: $product_count\n";
    echo "  Stock records: $stock_count\n";
    echo "\n✓ Mock data insertion completed successfully!\n";
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    die("Error inserting mock data: " . $e->getMessage() . "\n");
}
?>
