<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'Sayım İçerikleri - Vira Stok Sistemi';
$db = getDB();

// Handle sayim içerik operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_icerik':
                $sayim_id = intval($_POST['sayim_id'] ?? 0);
                $barkod = trim($_POST['barkod'] ?? '');
                $urun_adi = trim($_POST['urun_adi'] ?? '');
                
                if ($sayim_id > 0 && !empty($barkod)) {
                    try {
                        $stmt = $db->prepare("INSERT INTO sayim_icerikleri (sayim_id, barkod, urun_adi) VALUES (?, ?, ?)");
                        $stmt->execute([$sayim_id, $barkod, $urun_adi]);
                        $success_message = "Ürün başarıyla eklendi!";
                    } catch (PDOException $e) {
                        $error_message = "Ürün eklenirken hata oluştu: " . $e->getMessage();
                    }
                }
                break;
                
            case 'delete_icerik':
                $icerik_id = intval($_POST['icerik_id'] ?? 0);
                if ($icerik_id > 0) {
                    try {
                        // Soft delete - set deleted_at timestamp
                        $stmt = $db->prepare("UPDATE sayim_icerikleri SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$icerik_id]);
                        $success_message = "Ürün başarıyla silindi!";
                    } catch (PDOException $e) {
                        $error_message = "Ürün silinirken hata oluştu: " . $e->getMessage();
                    }
                }
                header('Location: sayim-icerikleri.php');
                exit;
                
            case 'restore_icerik':
                $icerik_id = intval($_POST['icerik_id'] ?? 0);
                if ($icerik_id > 0) {
                    try {
                        // Restore - clear deleted_at
                        $stmt = $db->prepare("UPDATE sayim_icerikleri SET deleted_at = NULL WHERE id = ?");
                        $stmt->execute([$icerik_id]);
                        $success_message = "Ürün başarıyla geri alındı!";
                    } catch (PDOException $e) {
                        $error_message = "Ürün geri alınırken hata oluştu: " . $e->getMessage();
                    }
                }
                header('Location: sayim-icerikleri.php');
                exit;
        }
    }
}

// Get all sayim_icerikleri with sayim info (including soft deleted)
$icerikler = $db->query("
    SELECT si.*, s.sayim_no, s.aktif as sayim_aktif
    FROM sayim_icerikleri si
    JOIN sayimlar s ON si.sayim_id = s.id
    ORDER BY si.deleted_at IS NULL DESC, si.okutulma_zamani DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get active sayimlar for dropdown
$aktif_sayimlar = $db->query("SELECT * FROM sayimlar WHERE aktif = 1 ORDER BY sayim_no")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Sayım İçerikleri</h1>
                
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
                
                <!-- Add Sayım İçeriği -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Sayıma Ürün Ekle</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_icerik">
                            <div class="grid gap-4 md:grid-cols-12">
                                <div class="md:col-span-4">
                                    <label for="sayim_id" class="block text-sm font-medium text-foreground mb-1.5">
                                        Sayım Seçin
                                    </label>
                                    <select
                                        id="sayim_id"
                                        name="sayim_id"
                                        required
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                        <option value="">Seçiniz...</option>
                                        <?php foreach ($aktif_sayimlar as $sayim): ?>
                                            <option value="<?php echo $sayim['id']; ?>">
                                                <?php echo htmlspecialchars($sayim['sayim_no']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="md:col-span-3">
                                    <label for="barkod" class="block text-sm font-medium text-foreground mb-1.5">
                                        Barkod
                                    </label>
                                    <input
                                        type="text"
                                        id="barkod"
                                        name="barkod"
                                        required
                                        autofocus
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="Barkod okuyun"
                                    >
                                </div>
                                <div class="md:col-span-3">
                                    <label for="urun_adi" class="block text-sm font-medium text-foreground mb-1.5">
                                        Ürün Adı
                                    </label>
                                    <input
                                        type="text"
                                        id="urun_adi"
                                        name="urun_adi"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="Ürün adı (opsiyonel)"
                                    >
                                </div>
                                <div class="md:col-span-2 flex items-end">
                                    <button
                                        type="submit"
                                        class="w-full rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 transition-all"
                                    >
                                        Ekle
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sayım İçerikleri List -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Tüm Sayım İçerikleri</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <?php if (empty($icerikler)): ?>
                            <div class="text-center py-8 text-muted-foreground">
                                Henüz ürün eklenmemiş.
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">ID</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Sayım No</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Barkod</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Ürün Adı</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Okutulma Zamanı</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Sayım Durumu</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($icerikler as $icerik): ?>
                                            <?php $is_deleted = !empty($icerik['deleted_at']); ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/50 <?php echo $is_deleted ? 'opacity-60' : ''; ?>">
                                                <td class="p-4 align-middle text-sm <?php echo $is_deleted ? 'line-through text-muted-foreground' : ''; ?>">
                                                    <?php echo $icerik['id']; ?>
                                                </td>
                                                <td class="p-4 align-middle font-medium <?php echo $is_deleted ? 'line-through text-muted-foreground' : ''; ?>">
                                                    <?php echo htmlspecialchars($icerik['sayim_no']); ?>
                                                </td>
                                                <td class="p-4 align-middle font-mono text-sm <?php echo $is_deleted ? 'line-through text-muted-foreground' : ''; ?>">
                                                    <?php echo htmlspecialchars($icerik['barkod']); ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm <?php echo $is_deleted ? 'line-through text-muted-foreground' : ''; ?>">
                                                    <?php echo htmlspecialchars($icerik['urun_adi'] ?? '-'); ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground <?php echo $is_deleted ? 'line-through' : ''; ?>">
                                                    <?php echo date('d.m.Y H:i:s', strtotime($icerik['okutulma_zamani'])); ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <?php if ($is_deleted): ?>
                                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                                            Silindi
                                                        </span>
                                                    <?php elseif ($icerik['sayim_aktif']): ?>
                                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                                            Aktif
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
                                                            Pasif
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <?php if ($is_deleted): ?>
                                                        <form method="POST" action="" class="inline">
                                                            <input type="hidden" name="action" value="restore_icerik">
                                                            <input type="hidden" name="icerik_id" value="<?php echo $icerik['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-green-100 text-green-800 hover:bg-green-200 h-9 px-3"
                                                            >
                                                                Geri Al
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" action="" class="inline">
                                                            <input type="hidden" name="action" value="delete_icerik">
                                                            <input type="hidden" name="icerik_id" value="<?php echo $icerik['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                onclick="return confirm('Bu ürünü silmek istediğinizden emin misiniz?');"
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

<script>
    // Auto-focus on barcode input and handle Enter key
    document.getElementById('barkod')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const sayimSelect = document.getElementById('sayim_id');
            if (sayimSelect.value && this.value) {
                this.closest('form').submit();
            }
        }
    });
    
    // Auto-focus barcode input after page load
    window.addEventListener('load', function() {
        const barkodInput = document.getElementById('barkod');
        if (barkodInput) {
            setTimeout(() => barkodInput.focus(), 100);
        }
    });
</script>
<?php include '../includes/footer.php'; ?>

