<?php
// รายการคำขอคืนเงิน
require_once __DIR__ . '/../../classes/Auth.php';require_once __DIR__ . '/../../classes/Security.php';
Auth::requireAnyRole(['owner','manager']);
$rows=getDB()->query("SELECT rr.*,o.order_code,o.status AS order_status,u.full_name AS requester,a.full_name AS approver FROM refund_requests rr JOIN orders o ON o.order_id=rr.order_id JOIN users u ON u.user_id=rr.requested_by LEFT JOIN users a ON a.user_id=rr.approved_by ORDER BY rr.requested_at DESC LIMIT 200")->fetchAll();
require_once __DIR__ . '/../../includes/header.php';
?>
<?php pageHeader('คำขอคืนเงิน', 'รายการที่รออนุมัติและที่คืนแล้ว'); ?>
<?php if(!empty($_GET['error'])):?><div class="alert alert-danger"><?=htmlspecialchars($_GET['error'])?></div><?php endif;?>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>ออเดอร์</th><th>จำนวน</th><th>เหตุผล</th><th>ครัวเริ่มแล้ว</th><th>ผู้ขอ</th><th>สถานะ</th><th class="text-end">ดำเนินการ</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีคำขอคืนเงิน</td></tr><?php endif;?>
<?php foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['order_code']?:'#'.$r['order_id'])?></td><td>฿<?=number_format($r['amount'],2)?></td><td><?=htmlspecialchars($r['reason'])?></td><td><?=$r['kitchen_started']?'ใช่':'ไม่ใช่'?></td><td><?=htmlspecialchars($r['requester'])?></td><td><span class="badge text-bg-light border"><?=htmlspecialchars(['pending'=>'รออนุมัติ','completed'=>'คืนเงินแล้ว','rejected'=>'ปฏิเสธ'][$r['status']] ?? $r['status'])?></span></td><td class="text-end"><?php if($r['status']==='pending'):?><form method="post" action="action.php" class="d-inline"><?=Security::csrfInput()?><input type="hidden" name="id" value="<?=$r['refund_id']?>"><button name="action" value="approve" class="btn btn-success btn-sm">อนุมัติและบันทึกคืน</button><button name="action" value="reject" class="btn btn-outline-danger btn-sm">ปฏิเสธ</button></form><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php';?>

