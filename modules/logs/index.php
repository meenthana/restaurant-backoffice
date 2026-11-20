<?php
require_once __DIR__.'/../../classes/Auth.php';
Auth::requireAnyRole(['owner','manager']);
$from=(string)($_GET['from'] ?? date('Y-m-d',strtotime('-7 days')));
$to=(string)($_GET['to'] ?? date('Y-m-d'));
foreach([$from,$to] as $date) { $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date); if(!$parsed || $parsed->format('Y-m-d')!==$date){http_response_code(400);exit('วันที่ไม่ถูกต้อง');} }
if($from>$to){http_response_code(400);exit('ช่วงวันที่ไม่ถูกต้อง');}
$orderId=(int)($_GET['order_id'] ?? 0);
$params=[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00'];
$sql="SELECT a.*,u.full_name FROM audit_logs a LEFT JOIN users u ON u.user_id=a.user_id WHERE a.created_at>=? AND a.created_at<?";
if($orderId>0){$sql.=" AND a.entity_type='order' AND a.entity_id=?";$params[]=(string)$orderId;}
$sql.=' ORDER BY a.audit_id DESC LIMIT 200';
$stmt=getDB()->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
require_once __DIR__.'/../../includes/header.php';
pageHeader('ประวัติการทำงาน','แสดงล่าสุดสูงสุด 200 เหตุการณ์ในช่วงที่เลือก · ข้อมูลเดิมอาจยังบันทึกไม่ครบ');
?>
<p><a href="omise.php">ตรวจรายการชำระเงิน Omise</a></p>
<form class="row g-2 mb-3" method="get"><label class="col">ตั้งแต่<input class="form-control" name="from" type="date" value="<?= htmlspecialchars($from) ?>"></label><label class="col">ถึง<input class="form-control" name="to" type="date" value="<?= htmlspecialchars($to) ?>"></label><label class="col">รหัสออเดอร์ในระบบ<input class="form-control" name="order_id" type="number" min="1" value="<?= $orderId ?: '' ?>"></label><div class="col-auto align-self-end"><button class="btn btn-dark">ค้นหา</button></div></form>
<div class="table-responsive"><table class="table"><thead><tr><th>เวลา</th><th>ผู้ทำ</th><th>เหตุการณ์</th><th>อ้างอิง</th><th>รายละเอียด</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= htmlspecialchars($row['created_at']) ?></td><td><?= htmlspecialchars($row['full_name'] ?? 'ระบบ / ลูกค้า') ?></td><td><?= htmlspecialchars($row['action']) ?></td><td><?= htmlspecialchars($row['entity_type'].' #'.$row['entity_id']) ?></td><td style="max-width:500px;overflow-wrap:anywhere"><?= htmlspecialchars($row['detail'] ?? '') ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="5">ไม่พบเหตุการณ์</td></tr><?php endif; ?></tbody></table></div>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
