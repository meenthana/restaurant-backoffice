<?php
// รายการออเดอร์ทั้งหมด
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';

$db = getDB();
$orders = $db->query(
    'SELECT o.*, COUNT(oi.order_item_id) AS item_count,
            GREATEST(0, COALESCE(SUM(oi.quantity * (oi.unit_price + COALESCE(ad.addon_sum,0))),0) - COALESCE(o.discount_amount,0)) AS total
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.order_id AND oi.voided_at IS NULL
     LEFT JOIN (SELECT order_item_id, SUM(addon_price) AS addon_sum FROM order_item_addons GROUP BY order_item_id) ad
            ON ad.order_item_id = oi.order_item_id
     GROUP BY o.order_id
     ORDER BY o.opened_at DESC'
)->fetchAll();

$badgeClass = ['pending' => 'text-bg-secondary', 'active' => 'text-bg-warning', 'ready' => 'text-bg-info', 'closed' => 'text-bg-success', 'no_show' => 'text-bg-danger', 'cancelled' => 'text-bg-dark'];
$channelLabel = ['kiosk' => 'ตู้สั่งเอง', 'online' => 'ออนไลน์', 'walkin' => 'หน้าร้าน'];
$badgeLabel = ['pending' => 'รอส่งครัว', 'active' => 'กำลังเตรียม', 'ready' => 'อาหารพร้อม', 'closed' => 'เสร็จแล้ว', 'no_show' => 'ไม่มารับ', 'cancelled' => 'ยกเลิกแล้ว'];

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('ออเดอร์', 'ออเดอร์ทั้งหมดของร้าน',
    '<a href="create.php" class="btn btn-dark"><i class="ti ti-plus"></i> รับออเดอร์หน้าร้าน</a>'
    . (FEATURE_TABLES ? '<a href="../tables/index.php" class="btn btn-outline-dark"><i class="ti ti-table-plus"></i> เปิดโต๊ะ</a>' : '')); ?>

<div class="card">
    <div class="card-header bg-white fw-semibold">รายการออเดอร์</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>คิว / #</th><th>ช่องทาง</th><th>รูปแบบ</th><th>สถานะ</th><th>รายละเอียด</th><th>รายการ</th><th>ยอดรวม</th><th>เปิดเมื่อ</th><th>จัดการ</th></tr>
            </thead>
            <tbody>
                <?php if (!$orders): ?>
                <tr><td colspan="9" class="text-muted text-center py-4">ยังไม่มีออเดอร์</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?php if (!empty($o['queue_no'])): ?><strong><?= KioskOrder::queueLabel((int) $o['queue_no'], $o['channel'], (int)$o['order_id']) ?></strong> <?php endif; ?><span class="text-muted small">#<?= $o['order_id'] ?></span></td>
                    <td><span class="badge text-bg-light border"><?= $channelLabel[$o['channel']] ?? htmlspecialchars($o['channel']) ?></span><?php if ((int) $o['is_paid'] === 0 && $o['status'] === 'pending' && $o['channel'] !== 'walkin'): ?> <span class="badge text-bg-warning">รอชำระ</span><?php endif; ?></td>
                    <td><span class="badge text-bg-light border"><?= $o['service_type'] === 'dine_in' ? 'ทานที่ร้าน' : 'รับกลับบ้าน' ?></span></td>
                    <td><span class="badge <?= $badgeClass[$o['status']] ?? 'text-bg-secondary' ?>"><?= $badgeLabel[$o['status']] ?? htmlspecialchars($o['status']) ?></span></td>
                    <td><?= htmlspecialchars($o['note'] ?? '-') ?></td>
                    <td><?= $o['item_count'] ?></td>
                    <td>฿<?= number_format($o['total'], 2) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($o['opened_at'])) ?></td>
                    <td><a href="view.php?id=<?= $o['order_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-eye"></i> ดูรายละเอียด</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
