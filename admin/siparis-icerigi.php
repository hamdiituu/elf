<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$page_title = 'Siparis İçeriği (Integ) - Vira Stok Sistemi';
$db = getDB();

// Handle operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $stmt = $db->prepare("INSERT INTO integ_siparis_icerigi (siparis_no, barkod, iptal_mi, toplandi_mi) VALUES (?, ?, ?, ?)");
                    $values = [];
                    if (isset($_POST['siparis_no'])) {
                        $values[] = trim($_POST['siparis_no']);
                    } else {
                        $values[] = '';
                    }
                    if (isset($_POST['barkod'])) {
                        $values[] = trim($_POST['barkod']);
                    } else {
                        $values[] = '';
                    }
                    if (isset($_POST['iptal_mi'])) {
                        $values[] = intval($_POST['iptal_mi']);
                    } else {
                        $values[] = '';
                    }
                    if (isset($_POST['toplandi_mi'])) {
                        $values[] = intval($_POST['toplandi_mi']);
                    } else {
                        $values[] = '';
                    }
                    $stmt->execute($values);
                    $success_message = "Kayıt başarıyla eklendi!";
                } catch (PDOException $e) {
                    $error_message = "Kayıt eklenirken hata: " . $e->getMessage();
                }
                break;

            case 'update':
                $record_id = intval($_POST['record_id'] ?? 0);
                if ($record_id > 0) {
                    try {
                        $set_parts = [];
                        $values = [];
                        if (isset($_POST['siparis_no'])) {
                            $set_parts[] = "siparis_no = ?";
                            $values[] = trim($_POST['siparis_no']);
                        }
                        if (isset($_POST['barkod'])) {
                            $set_parts[] = "barkod = ?";
                            $values[] = trim($_POST['barkod']);
                        }
                        if (isset($_POST['iptal_mi'])) {
                            $set_parts[] = "iptal_mi = ?";
                            $values[] = intval($_POST['iptal_mi']);
                        }
                        if (isset($_POST['toplandi_mi'])) {
                            $set_parts[] = "toplandi_mi = ?";
                            $values[] = intval($_POST['toplandi_mi']);
                        }
                        $values[] = $record_id;
                        $stmt = $db->prepare("UPDATE integ_siparis_icerigi SET " . implode(", ", $set_parts) . " WHERE id = ?");
                        $stmt->execute($values);
                        $success_message = "Kayıt başarıyla güncellendi!";
                    } catch (PDOException $e) {
                        $error_message = "Kayıt güncellenirken hata: " . $e->getMessage();
                    }
                }
                break;

            case 'delete':
                $record_id = intval($_POST['record_id'] ?? 0);
                if ($record_id > 0) {
                    try {
                        $stmt = $db->prepare("DELETE FROM integ_siparis_icerigi WHERE id = ?");
                        $stmt->execute([$record_id]);
                        $success_message = "Kayıt başarıyla silindi!";
                    } catch (PDOException $e) {
                        $error_message = "Kayıt silinirken hata: " . $e->getMessage();
                    }
                }
                header('Location: siparis-icerigi.php');
                exit;
                break;

        }
    }
}

// Get all records
$records = $db->query("SELECT * FROM integ_siparis_icerigi ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing
$edit_record = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id && 1) {
    $stmt = $db->prepare("SELECT * FROM integ_siparis_icerigi WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Siparis İçeriği (Integ)</h1>

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

                <!-- Add/Edit Form -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">
                            <?php echo $edit_record ? 'Kayıt Düzenle' : 'Yeni Kayıt Ekle'; ?>
                        </h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="<?php echo $edit_record ? 'update' : 'add'; ?>">
                            <?php if ($edit_record): ?>
                                <input type="hidden" name="record_id" value="<?php echo $edit_record['id']; ?>">
                            <?php endif; ?>

                            <div class="mb-4">
                                <label for="siparis_no" class="block text-sm font-medium text-foreground mb-1.5">
                                    Siparis no
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="siparis_no"
                                    name="siparis_no"
                                    required
                                    value="<?php echo $edit_record ? htmlspecialchars($edit_record['siparis_no']) : ''; ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Siparis no giriniz"
                                >
                            </div>

                            <div class="mb-4">
                                <label for="barkod" class="block text-sm font-medium text-foreground mb-1.5">
                                    Barkod
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="barkod"
                                    name="barkod"
                                    required
                                    value="<?php echo $edit_record ? htmlspecialchars($edit_record['barkod']) : ''; ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Barkod giriniz"
                                >
                            </div>

                            <div class="mb-4">
                                <label for="iptal_mi" class="block text-sm font-medium text-foreground mb-1.5">
                                    Iptal mi
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="number"
                                    id="iptal_mi"
                                    name="iptal_mi"
                                    required
                                    value="<?php echo $edit_record ? htmlspecialchars($edit_record['iptal_mi']) : ''; ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Iptal mi giriniz"
                                >
                            </div>

                            <div class="mb-4">
                                <label for="toplandi_mi" class="block text-sm font-medium text-foreground mb-1.5">
                                    Toplandi mi
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="number"
                                    id="toplandi_mi"
                                    name="toplandi_mi"
                                    required
                                    value="<?php echo $edit_record ? htmlspecialchars($edit_record['toplandi_mi']) : ''; ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Toplandi mi giriniz"
                                >
                            </div>

                            <div class="flex gap-2">
                                <button
                                    type="submit"
                                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                                >
                                    <?php echo $edit_record ? 'Güncelle' : 'Ekle'; ?>
                                </button>
                                <?php if ($edit_record): ?>
                                    <a
                                        href="siparis-icerigi.php"
                                        class="rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-500 transition-all"
                                    >
                                        İptal
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Records List -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Tüm Kayıtlar</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <?php if (empty($records)): ?>
                            <div class="text-center py-8 text-muted-foreground">
                                Henüz kayıt eklenmemiş.
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Id</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Siparis no</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Barkod</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Iptal mi</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Toplandi mi</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($records as $record): ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/50">
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['id'] ?? ''); ?></td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['siparis_no'] ?? ''); ?></td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['barkod'] ?? ''); ?></td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['iptal_mi'] ?? ''); ?></td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['toplandi_mi'] ?? ''); ?></td>
                                                <td class="p-4 align-middle">
                                                    <div class="flex gap-2">
                                                        <a
                                                            href="?edit=<?php echo $record['id']; ?>"
                                                            class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-blue-100 text-blue-800 hover:bg-blue-200 h-9 px-3"
                                                        >
                                                            Düzenle
                                                        </a>
                                                        <form method="POST" action="" class="inline" onsubmit="return confirm('Bu kaydı silmek istediğinizden emin misiniz?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-red-100 text-red-800 hover:bg-red-200 h-9 px-3"
                                                            >
                                                                Sil
                                                            </button>
                                                        </form>
                                                    </div>
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
