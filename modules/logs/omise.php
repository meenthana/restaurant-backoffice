<?php
require_once __DIR__.'/../../classes/Auth.php';
Auth::requireAnyRole(['owner','manager']);
require_once __DIR__.'/../../includes/header.php';
pageHeader('รายการ Omise','สถานะ review / unknown ต้องตรวจสอบก่อนรับเงินซ้ำหรือส่งเข้าครัว');
$rows=[];
try { $rows=getDB()->query('SELECT * FROM gateway_payments ORDER BY updated_at DESC LIMIT 200')->fetchAll(); }
catch (PDOException $e) { echo '<p>รายการชำระเงินยังไม่พร้อมใช้งาน</p>'; }
?>
<div class="table-responsive"><table class="table"><thead><tr><th>ออเดอร์</th><th>โหมด</th><th>ยอด</th><th>สถานะ</th><th>Omise Charge</th><th>เวลาล่าสุด</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
<td><?= (int)$r['order_id'] ?></td><td><?= $r['livemode'] ? 'เงินจริง' : 'ทดสอบ' ?></td>
<td><?= number_format($r['amount_satang']/100,2) ?></td><td><?= htmlspecialchars($r['status']) ?></td>
<td><?= htmlspecialchars($r['charge_id'] ?? 'ยังไม่ทราบผลสร้างรายการ') ?></td><td><?= htmlspecialchars($r['updated_at']) ?></td>
</tr><?php endforeach; ?></tbody></table></div>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
