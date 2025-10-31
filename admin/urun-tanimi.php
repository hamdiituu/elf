<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'Ürün Tanımı - Stok Sayım Sistemi';
$db = getDB();

// Handle product definition operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_product':
                $barkod = trim($_POST['barkod'] ?? '');
                $urun_aciklamasi = trim($_POST['urun_aciklamasi'] ?? '');
                
                if (!empty($barkod)) {
                    try {
                        $stmt = $db->prepare("INSERT INTO urun_tanimi (barkod, urun_aciklamasi) VALUES (?, ?)");
                        $stmt->execute([$barkod, $urun_aciklamasi]);
                        $success_message = "Ürün tanımı başarıyla eklendi!";
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                            $error_message = "Bu barkod zaten tanımlı!";
                        } else {
                            $error_message = "Ürün tanımı eklenirken hata oluştu: " . $e->getMessage();
                        }
                    }
                } else {
                    $error_message = "Barkod alanı zorunludur!";
                }
                break;
                
            case 'update_product':
                $product_id = intval($_POST['product_id'] ?? 0);
                $barkod = trim($_POST['barkod'] ?? '');
                $urun_aciklamasi = trim($_POST['urun_aciklamasi'] ?? '');
                
                if ($product_id > 0 && !empty($barkod)) {
                    try {
                        $stmt = $db->prepare("UPDATE urun_tanimi SET barkod = ?, urun_aciklamasi = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$barkod, $urun_aciklamasi, $product_id]);
                        $success_message = "Ürün tanımı başarıyla güncellendi!";
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                            $error_message = "Bu barkod zaten başka bir üründe kullanılıyor!";
                        } else {
                            $error_message = "Ürün tanımı güncellenirken hata oluştu: " . $e->getMessage();
                        }
                    }
                }
                header('Location: urun-tanimi.php');
                exit;
                
            case 'delete_product':
                $product_id = intval($_POST['product_id'] ?? 0);
                if ($product_id > 0) {
                    try {
                        // Soft delete - set deleted_at timestamp
                        $stmt = $db->prepare("UPDATE urun_tanimi SET deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$product_id]);
                        $success_message = "Ürün tanımı başarıyla silindi!";
                    } catch (PDOException $e) {
                        $error_message = "Ürün tanımı silinirken hata oluştu: " . $e->getMessage();
                    }
                }
                header('Location: urun-tanimi.php');
                exit;
                
            case 'restore_product':
                $product_id = intval($_POST['product_id'] ?? 0);
                if ($product_id > 0) {
                    try {
                        // Restore - clear deleted_at
                        $stmt = $db->prepare("UPDATE urun_tanimi SET deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$product_id]);
                        $success_message = "Ürün tanımı başarıyla geri alındı!";
                    } catch (PDOException $e) {
                        $error_message = "Ürün tanımı geri alınırken hata oluştu: " . $e->getMessage();
                    }
                }
                header('Location: urun-tanimi.php');
                exit;
        }
    }
}

// Get all product definitions (including soft deleted)
$urunler = $db->query("
    SELECT * FROM urun_tanimi 
    ORDER BY deleted_at IS NULL DESC, created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Ürün Tanımları</h1>
                
                <?php if (isset($success_message)): ?>
                    <div class="mb-6 rounded-md bg-green-50 p-4 border border-green-200">
                        <div class="text-sm text-green-800">
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="mb-6 rounded-md bg-red-50 p-4 border border-red-200">
                        <div class="text-sm text-red-800">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Add New Product Definition -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Yeni Ürün Tanımı Ekle</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_product">
                            <div class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <label for="barkod" class="block text-sm font-medium text-foreground mb-1.5">
                                        Barkod <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="barkod"
                                        name="barkod"
                                        required
                                        autofocus
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="Barkod numarası"
                                    >
                                </div>
                                <div class="md:col-span-2">
                                    <label for="urun_aciklamasi" class="block text-sm font-medium text-foreground mb-1.5">
                                        Ürün Açıklaması
                                    </label>
                                    <input
                                        type="text"
                                        id="urun_aciklamasi"
                                        name="urun_aciklamasi"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="Ürün açıklaması veya adı"
                                    >
                                </div>
                            </div>
                            <div class="mt-4">
                                <button
                                    type="submit"
                                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                                >
                                    Ekle
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Product Definitions List -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Tüm Ürün Tanımları</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <?php if (empty($urunler)): ?>
                            <div class="text-center py-8 text-muted-foreground">
                                Henüz ürün tanımı eklenmemiş.
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">ID</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Barkod</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Ürün Açıklaması</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Durum</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Oluşturulma</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Güncellenme</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($urunler as $urun): ?>
                                            <?php $is_deleted = !empty($urun['deleted_at']); ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/50 <?php echo $is_deleted ? 'opacity-60' : ''; ?>">
                                                <td class="p-4 align-middle text-sm <?php echo $is_deleted ? 'line-through text-muted-foreground' : ''; ?>">
                                                    <?php echo $urun['id']; ?>
                                                </td>
                                                <td class="p-4 align-middle font-mono text-sm <?php echo $is_deleted ? 'line-through text-muted-foreground' : ''; ?>">
                                                    <?php echo htmlspecialchars($urun['barkod']); ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm <?php echo $is_deleted ? 'line-through text-muted-foreground' : ''; ?>">
                                                    <?php echo htmlspecialchars($urun['urun_aciklamasi'] ?? '-'); ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <?php if ($is_deleted): ?>
                                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                                            Silindi
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                                            Aktif
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground <?php echo $is_deleted ? 'line-through' : ''; ?>">
                                                    <?php echo date('d.m.Y H:i', strtotime($urun['created_at'])); ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground <?php echo $is_deleted ? 'line-through' : ''; ?>">
                                                    <?php echo date('d.m.Y H:i', strtotime($urun['updated_at'])); ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <?php if ($is_deleted): ?>
                                                        <form method="POST" action="" class="inline">
                                                            <input type="hidden" name="action" value="restore_product">
                                                            <input type="hidden" name="product_id" value="<?php echo $urun['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-green-100 text-green-800 hover:bg-green-200 h-9 px-3"
                                                            >
                                                                Geri Al
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" action="" class="inline mr-2">
                                                            <input type="hidden" name="action" value="delete_product">
                                                            <input type="hidden" name="product_id" value="<?php echo $urun['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                onclick="return confirm('Bu ürün tanımını silmek istediğinizden emin misiniz?');"
                                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-red-100 text-red-800 hover:bg-red-200 h-9 px-3"
                                                            >
                                                                Sil
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php include '../includes/footer.php'; ?>
