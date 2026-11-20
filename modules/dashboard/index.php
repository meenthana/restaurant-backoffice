<?php
// แดชบอร์ดภาพรวมวันนี้
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Order.php';
Auth::requireAnyRole(['owner','manager']);
Order::expireOverdueOnlineOrders();
$db=getDB();
$today=$db->query("SELECT
 (SELECT COUNT(*) FROM orders WHERE DATE(opened_at)=CURRENT_DATE) AS orders_today,
 (SELECT COUNT(*) FROM orders WHERE status IN ('pending','active','ready')) AS active_orders,
 (SELECT COUNT(*) FROM restaurant_tables WHERE status='occupied') AS occupied_tables,
 (SELECT COUNT(*) FROM queue_entries WHERE queue_date=CURRENT_DATE AND status IN ('waiting','called')) AS waiting_queues,
 (SELECT COUNT(*) FROM reservations WHERE DATE(reservation_at)=CURRENT_DATE AND status IN ('pending','confirmed','checked_in')) AS reservations_today,
 (SELECT COALESCE(SUM(CASE WHEN transaction_type='payment' THEN amount ELSE -amount END),0) FROM payments WHERE status='completed' AND DATE(created_at)=CURRENT_DATE) AS revenue_today,
 (SELECT COUNT(*) FROM kitchen_tickets WHERE status IN ('queued','cooking')) AS kitchen_jobs,
 (SELECT COUNT(*) FROM orders WHERE channel IN ('kiosk','online') AND status='pending' AND is_paid=0) AS awaiting_payment,
 (SELECT COUNT(*) FROM orders WHERE channel='kiosk' AND DATE(opened_at)=CURRENT_DATE AND status<>'cancelled') AS kiosk_today,
 (SELECT COUNT(*) FROM stock_batches WHERE quantity_remaining>0 AND expiry_date<=DATE_ADD(CURRENT_DATE,INTERVAL 3 DAY)) AS expiring_batches,
 (SELECT COUNT(*) FROM (SELECT i.ingredient_id, COALESCE(SUM(sb.quantity_remaining),0) cs, i.reorder_point rp FROM ingredients i LEFT JOIN stock_batches sb ON sb.ingredient_id=i.ingredient_id AND sb.quantity_remaining>0 WHERE i.reorder_point IS NOT NULL GROUP BY i.ingredient_id, i.reorder_point HAVING cs<=rp) t) AS low_stock_ingredients")->fetch();
$daily=$db->query("SELECT DATE(created_at) d, SUM(CASE WHEN transaction_type='payment' THEN amount ELSE -amount END) total FROM payments WHERE status='completed' AND created_at>=DATE_SUB(CURRENT_DATE,INTERVAL 6 DAY) GROUP BY DATE(created_at) ORDER BY d")->fetchAll();
$top=$db->query("SELECT m.name,SUM(oi.quantity) qty,SUM(oi.quantity*oi.unit_price) sales FROM order_items oi JOIN menu m ON m.menu_id=oi.menu_id JOIN orders o ON o.order_id=oi.order_id WHERE o.status='closed' AND o.closed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY m.menu_id ORDER BY qty DESC LIMIT 5")->fetchAll();
$dailyValues=array_map(fn($r)=>(float)$r['total'],$daily);
$maxDaily=$dailyValues ? max(1,max($dailyValues)) : 1;
require_once __DIR__ . '/../../includes/header.php';
?>
<?php pageHeader('ภาพรวมวันนี้', date('d/m/Y')); ?>
<div class="row g-3 mb-4">
<?php $cards=[['ยอดขายวันนี้','฿'.number_format($today['revenue_today'],2),'ti-cash'],['ออเดอร์วันนี้',$today['orders_today'].' รายการ','ti-receipt'],['สั่งจากตู้วันนี้',$today['kiosk_today'].' ออเดอร์','ti-device-tablet'],['รอชำระที่เคาน์เตอร์',$today['awaiting_payment'].' คิว','ti-cash'],['งานครัวค้าง',$today['kitchen_jobs'].' ใบงาน','ti-flame'],['ออเดอร์กำลังทำ',$today['active_orders'].' รายการ','ti-chef-hat'],['ล็อตใกล้หมดอายุ',$today['expiring_batches'].' ล็อต','ti-alert-triangle'],['วัตถุดิบต่ำกว่าจุดสั่งซื้อ',$today['low_stock_ingredients'].' รายการ','ti-shopping-cart-exclamation']];foreach($cards as $c):?>
<div class="col-6 col-lg-3"><div class="metric-card"><i class="ti <?=$c[2]?> metric-icon"></i><div class="text-muted small"><?=$c[0]?></div><div class="fs-4 fw-bold"><?=$c[1]?></div></div></div><?php endforeach;?>
</div>
<div class="row g-3"><div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold">รายได้สุทธิ 7 วันล่าสุด</div><div class="card-body"><div class="revenue-bars"><?php for($i=6;$i>=0;$i--):$d=date('Y-m-d',strtotime("-$i day"));$match=array_values(array_filter($daily,fn($r)=>$r['d']===$d));$value=$match?(float)$match[0]['total']:0;?><div class="revenue-day"><div class="bar-value">฿<?=number_format($value,0)?></div><div class="bar-track"><div class="bar-fill" style="height:<?=max(3,$value/$maxDaily*100)?>%"></div></div><div class="small text-muted"><?=date('d/m',strtotime($d))?></div></div><?php endfor;?></div></div></div></div>
<div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold">เมนูขายดี 30 วัน</div><div class="card-body"><?php if(!$top):?><div class="text-muted text-center py-5">ยังไม่มีข้อมูลยอดขาย</div><?php endif;?><?php foreach($top as $i=>$m):?><div class="d-flex align-items-center gap-3 py-2 border-bottom"><span class="ranking-number"><?=$i+1?></span><div class="flex-grow-1"><div class="fw-semibold"><?=htmlspecialchars($m['name'])?></div><div class="small text-muted"><?=$m['qty']?> รายการ</div></div><div>฿<?=number_format($m['sales'],2)?></div></div><?php endforeach;?></div></div></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php';?>
