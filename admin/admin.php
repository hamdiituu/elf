<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'Ana Sayfa - Stok Sayım Sistemi';
$db = getDB();

// Get statistics
$total_sayimlar = $db->query("SELECT COUNT(*) FROM sayimlar")->fetchColumn();
$aktif_sayimlar_count = $db->query("SELECT COUNT(*) FROM sayimlar WHERE aktif = 1")->fetchColumn();
$total_icerikler = $db->query("SELECT COUNT(*) FROM sayim_icerikleri WHERE deleted_at IS NULL")->fetchColumn();
$total_kullanicilar = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Get recent sayimlar
$recent_sayimlar = $db->query("SELECT * FROM sayimlar ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Get recent icerikler (excluding soft deleted)
$recent_icerikler = $db->query("
    SELECT si.*, s.sayim_no
    FROM sayim_icerikleri si
    JOIN sayimlar s ON si.sayim_id = s.id
    WHERE si.deleted_at IS NULL
    ORDER BY si.okutulma_zamani DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Dashboard</h1>
                
                <!-- Statistics Cards -->
                <div class="grid gap-4 md:grid-cols-4 mb-8">
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Toplam Sayım</p>
                                <p class="text-2xl font-bold mt-1"><?php echo $total_sayimlar; ?></p>
                            </div>
                            <div class="rounded-full bg-primary/10 p-3">
                                <svg class="h-6 w-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Aktif Sayım</p>
                                <p class="text-2xl font-bold mt-1"><?php echo $aktif_sayimlar_count; ?></p>
                            </div>
                            <div class="rounded-full bg-green-100 p-3">
                                <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Toplam Ürün</p>
                                <p class="text-2xl font-bold mt-1"><?php echo $total_icerikler; ?></p>
                            </div>
                            <div class="rounded-full bg-blue-100 p-3">
                                <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Kullanıcılar</p>
                                <p class="text-2xl font-bold mt-1"><?php echo $total_kullanicilar; ?></p>
                            </div>
                            <div class="rounded-full bg-purple-100 p-3">
                                <svg class="h-6 w-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="grid gap-6 md:grid-cols-2">
                    <!-- Recent Sayımlar -->
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                        <div class="p-6 pb-0">
                            <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Son Sayımlar</h3>
                        </div>
                        <div class="p-6 pt-0">
                            <?php if (empty($recent_sayimlar)): ?>
                                <div class="text-center py-8 text-muted-foreground">
                                    Henüz sayım eklenmemiş.
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($recent_sayimlar as $sayim): ?>
                                        <div class="flex items-center justify-between p-3 rounded-md border border-border hover:bg-muted/50 transition-colors">
                                            <div>
                                                <p class="font-medium text-sm"><?php echo htmlspecialchars($sayim['sayim_no']); ?></p>
                                                <p class="text-xs text-muted-foreground mt-1">
                                                    <?php echo date('d.m.Y H:i', strtotime($sayim['created_at'])); ?>
                                                </p>
                                            </div>
                                            <?php if ($sayim['aktif']): ?>
                                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                                    Aktif
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
                                                    Pasif
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4">
                                    <a href="sayimlar.php" class="text-sm text-primary hover:underline">
                                        Tümünü gör →
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent İçerikler -->
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                        <div class="p-6 pb-0">
                            <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Son Eklenen Ürünler</h3>
                        </div>
                        <div class="p-6 pt-0">
                            <?php if (empty($recent_icerikler)): ?>
                                <div class="text-center py-8 text-muted-foreground">
                                    Henüz ürün eklenmemiş.
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($recent_icerikler as $icerik): ?>
                                        <div class="p-3 rounded-md border border-border hover:bg-muted/50 transition-colors">
                                            <div class="flex items-center justify-between">
                                                <div class="flex-1">
                                                    <p class="font-medium text-sm"><?php echo htmlspecialchars($icerik['sayim_no']); ?></p>
                                                    <p class="text-xs text-muted-foreground mt-1 font-mono">
                                                        <?php echo htmlspecialchars($icerik['barkod']); ?>
                                                    </p>
                                                </div>
                                                <p class="text-xs text-muted-foreground ml-4">
                                                    <?php echo date('H:i', strtotime($icerik['okutulma_zamani'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4">
                                    <a href="sayim-icerikleri.php" class="text-sm text-primary hover:underline">
                                        Tümünü gör →
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php include '../includes/footer.php'; ?>
