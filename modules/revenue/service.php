<?php
require_once __DIR__.'/../../classes/Auth.php';
require_once __DIR__.'/../../classes/Security.php';
require_once __DIR__.'/../../classes/ServiceFlow.php';
Auth::requireAnyRole(['owner','manager']);
$ready=ServiceFlow::ready();
if($_SERVER['REQUEST_METHOD']==='POST') {
    Security::verifyCsrf();
    if(!$ready){http_response_code(409);exit('รายงานบริการยังไม่พร้อมใช้งาน');}
    $db=getDB();$db->beginTransaction();
    try {
        $id=(int)($_POST['menu_id'] ?? 0);$value=(string)($_POST['counts_as_dish'] ?? '');
        if(!in_array($value,['0','1'],true))throw new RuntimeException('ประเภทไม่ถูกต้อง');
        $stmt=$db->prepare('SELECT menu_id FROM menu WHERE menu_id=?');$stmt->execute([$id]);if(!$stmt->fetchColumn())throw new RuntimeException('ไม่พบเมนู');
        $db->prepare('INSERT INTO menu_queue_rules(menu_id,counts_as_dish) VALUES (?,?) ON DUPLICATE KEY UPDATE counts_as_dish=VALUES(counts_as_dish)')->execute([$id,(int)$value]);
        $db->prepare("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,detail) VALUES (?,'queue_rule','menu',?,?)")->execute([Auth::id(),(string)$id,'counts_as_dish='.$value]);
        $db->commit();header('Location: service.php?saved=1');exit;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();http_response_code(400);exit(htmlspecialchars($e->getMessage()));}
}
$from=(string)($_GET['from'] ?? date('Y-m-d',strtotime('-30 days')));$to=(string)($_GET['to'] ?? date('Y-m-d'));
foreach([$from,$to] as $date){$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date){http_response_code(400);exit('วันที่ไม่ถูกต้อง');}}
if($from>$to){http_response_code(400);exit('ช่วงวันที่ไม่ถูกต้อง');}
$params=[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00'];
$summary=[];$timing=[];$menu=[];
if($ready){
    $stmt=getDB()->prepare("SELECT COUNT(*) orders_count,SUM(o.member_id IS NOT NULL) members_count,SUM(d.receipt_preference='digital') digital_count,SUM(d.receipt_preference='paper') paper_count,COALESCE(SUM(d.gross_amount),0) gross,COALESCE(SUM(d.vat_amount),0) vat FROM order_service_details d JOIN orders o ON o.order_id=d.order_id WHERE o.opened_at>=? AND o.opened_at<?");$stmt->execute($params);$summary=$stmt->fetch();
    $stmt=getDB()->prepare("SELECT d.queue_type,COUNT(DISTINCT o.order_id) orders_count,AVG(CASE WHEN kt.started_at>=o.paid_at THEN TIMESTAMPDIFF(SECOND,o.paid_at,kt.started_at)/60 END) wait_minutes,AVG(CASE WHEN kt.ready_at>=kt.started_at THEN TIMESTAMPDIFF(SECOND,kt.started_at,kt.ready_at)/60 END) cook_minutes,AVG(CASE WHEN o.status='closed' AND o.closed_at>=kt.ready_at THEN TIMESTAMPDIFF(SECOND,kt.ready_at,o.closed_at)/60 END) pickup_minutes FROM order_service_details d JOIN orders o ON o.order_id=d.order_id LEFT JOIN (SELECT order_id,MIN(started_at) started_at,MAX(ready_at) ready_at FROM kitchen_tickets WHERE status<>'cancelled' GROUP BY order_id) kt ON kt.order_id=o.order_id WHERE o.opened_at>=? AND o.opened_at<? GROUP BY d.queue_type");$stmt->execute($params);$timing=$stmt->fetchAll();
    $menu=getDB()->query('SELECT m.menu_id,m.name,m.category,r.counts_as_dish FROM menu m LEFT JOIN menu_queue_rules r ON r.menu_id=m.menu_id ORDER BY m.name')->fetchAll();
}
require_once __DIR__.'/../../includes/header.php';pageHeader('รายงานบริการ','ออเดอร์ Kiosk · จัดกลุ่มตามวันที่สั่ง');
?>
<?php if(!$ready): ?><p class="text-muted">รายงานบริการยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ</p><?php else: ?>
<form method="get" class="d-flex gap-2 mb-3"><label>ตั้งแต่<input name="from" type="date" class="form-control" value="<?= htmlspecialchars($from) ?>"></label><label>ถึง<input name="to" type="date" class="form-control" value="<?= htmlspecialchars($to) ?>"></label><button class="btn btn-dark align-self-end">แสดงรายงาน</button></form>
<p>ออเดอร์ <?= (int)$summary['orders_count'] ?> · ผูกสมาชิก <?= (int)$summary['members_count'] ?> · เลือกไม่รับกระดาษ <?= (int)$summary['digital_count'] ?> · เลือกรับกระดาษ <?= (int)$summary['paper_count'] ?></p>
<p>ยอดขายที่บันทึกเมื่อชำระ <?= number_format((float)$summary['gross'],2) ?> บาท (รวม VAT <?= number_format((float)$summary['vat'],2) ?> บาท) · เป็นยอดขายเดิมก่อนหักคืนเงิน โปรดเทียบรับเงินสุทธิในรายงานรายได้</p>
<table class="table"><thead><tr><th>ประเภท</th><th>ออเดอร์</th><th>รอเริ่มทำเฉลี่ย (นาที)</th><th>ทำอาหารเฉลี่ย</th><th>รอรับเฉลี่ย</th></tr></thead><tbody><?php foreach($timing as $r): ?><tr><td><?= ServiceFlow::typeLabel($r['queue_type']) ?></td><td><?= (int)$r['orders_count'] ?></td><?php foreach(['wait_minutes','cook_minutes','pickup_minutes'] as $key): ?><td><?= $r[$key]===null?'ยังไม่มีข้อมูล':number_format((float)$r[$key],1) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table>
<p>ค่าเฉลี่ยนับเฉพาะรายการที่มีเวลาครบ ไม่ได้แทนเวลารอที่รับประกัน การเลือกไม่พิมพ์ไม่เท่ากับยืนยันประหยัดกระดาษจริง และคำขอพิมพ์ไม่ใช่หลักฐานว่าเครื่องพิมพ์สำเร็จ</p>
<h2 class="h4 mt-4">กำหนดว่าเมนูใดนับเป็นจาน</h2><p>ค่าเริ่มต้นนับทุกเมนูเป็นจาน กรุณากำหนดเครื่องดื่ม/รายการอื่นเป็นไม่นับ ก่อนใช้งานจริง การแก้ใช้กับออเดอร์ใหม่เท่านั้น</p>
<table class="table"><thead><tr><th>เมนู</th><th>หมวด</th><th>การนับ</th></tr></thead><tbody><?php foreach($menu as $m): ?><tr><td><?= htmlspecialchars($m['name']) ?></td><td><?= htmlspecialchars($m['category']) ?></td><td><form method="post" class="d-flex gap-2"><?= Security::csrfInput() ?><input type="hidden" name="menu_id" value="<?= (int)$m['menu_id'] ?>"><select name="counts_as_dish" class="form-select"><option value="1" <?= $m['counts_as_dish']===null||(int)$m['counts_as_dish']===1?'selected':'' ?>>นับตามจำนวนจาน</option><option value="0" <?= $m['counts_as_dish']!==null&&(int)$m['counts_as_dish']===0?'selected':'' ?>>ไม่นับเป็นจาน</option></select><button class="btn btn-dark">บันทึก</button></form></td></tr><?php endforeach; ?></tbody></table>
<?php endif; require_once __DIR__.'/../../includes/footer.php'; ?>
