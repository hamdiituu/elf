<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'Sayımlar - Vira Stok Sistemi';
$db = getDB();

// Handle sayim operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_sayim':
                $sayim_no = trim($_POST['sayim_no'] ?? '');
                if (!empty($sayim_no)) {
                    try {
                        $stmt = $db->prepare("INSERT INTO sayimlar (sayim_no, aktif) VALUES (?, 1)");
                        $stmt->execute([$sayim_no]);
                        $success_message = "Sayım başarıyla eklendi!";
                    } catch (PDOException $e) {
                        $error_message = "Sayım eklenirken hata oluştu: " . $e->getMessage();
                    }
                }
                break;
                
            case 'toggle_active':
                $sayim_id = intval($_POST['sayim_id'] ?? 0);
                if ($sayim_id > 0) {
                    $stmt = $db->prepare("UPDATE sayimlar SET aktif = NOT aktif, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$sayim_id]);
                }
                header('Location: sayimlar.php');
                exit;
        }
    }
}

// Get all sayimlar
$sayimlar = $db->query("SELECT * FROM sayimlar ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Sayımlar</h1>
                
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
                
                <!-- Add New Sayım -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Yeni Sayım Ekle</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_sayim">
                            <div class="flex gap-4">
                                <div class="flex-1">
                                    <label for="sayim_no" class="block text-sm font-medium text-foreground mb-1.5">
                                        Sayım Numarası
                                    </label>
                                    <input
                                        type="text"
                                        id="sayim_no"
                                        name="sayim_no"
                                        required
                                        autofocus
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="Örn: SAYIM-2024-001"
                                    >
                                </div>
                                <div class="flex items-end">
                                    <button
                                        type="submit"
                                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                                    >
                                        Ekle
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sayımlar List -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Tüm Sayımlar</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <?php if (empty($sayimlar)): ?>
                            <div class="text-center py-8 text-muted-foreground">
                                Henüz sayım eklenmemiş.
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">ID</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Sayım No</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Durum</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Oluşturulma</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Güncellenme</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sayimlar as $sayim): ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/50">
                                                <td class="p-4 align-middle text-sm"><?php echo $sayim['id']; ?></td>
                                                <td class="p-4 align-middle font-medium"><?php echo htmlspecialchars($sayim['sayim_no']); ?></td>
                                                <td class="p-4 align-middle">
                                                    <?php if ($sayim['aktif']): ?>
                                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                                            Aktif
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
                                                            Pasif
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground"><?php echo date('d.m.Y H:i', strtotime($sayim['created_at'])); ?></td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground"><?php echo date('d.m.Y H:i', strtotime($sayim['updated_at'])); ?></td>
                                                <td class="p-4 align-middle">
                                                    <form method="POST" action="" class="inline">
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="sayim_id" value="<?php echo $sayim['id']; ?>">
                                                        <button
                                                            type="submit"
                                                            class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-yellow-100 text-yellow-800 hover:bg-yellow-200 h-9 px-3"
                                                        >
                                                            <?php echo $sayim['aktif'] ? 'Pasif Yap' : 'Aktif Yap'; ?>
                                                        </button>
                                                    </form>
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

