<?php
// ขอคืนเงิน
require_once __DIR__ . '/../../classes/Auth.php';require_once __DIR__ . '/../../classes/Security.php';require_once __DIR__ . '/../../classes/Payment.php';
Auth::requireLogin();$orderId=(int)($_GET['order_id']??$_POST['order_id']??0);
$stmt=getDB()->prepare('SELECT * FROM orders WHERE order_id=:id');$stmt->execute(['id'=>$orderId]);$order=$stmt->fetch();if(!$order)die('ไม่พบออเดอร์');
$pend=getDB()->prepare("SELECT COALESCE(SUM(amount),0) FROM refund_requests WHERE order_id=:id AND status='pending'");$pend->execute(['id'=>$orderId]);
$max=round(max(0,Payment::paidAmount($orderId)-(float)$pend->fetchColumn()),2);
if($_SERVER['REQUEST_METHOD']==='POST'){Security::verifyCsrf();$amount=(float)($_POST['amount']??0);$reason=trim($_POST['reason']??'');if($amount<=0||$amount>$max||$reason===''){$error='กรุณาระบุจำนวนเงินและเหตุผลให้ถูกต้อง';}else{$k=getDB()->prepare("SELECT COUNT(*) FROM kitchen_tickets WHERE order_id=:id AND status IN ('cooking','ready','served')");$k->execute(['id'=>$orderId]);$started=(int)$k->fetchColumn()>0;getDB()->prepare("INSERT INTO refund_requests(order_id,amount,reason,kitchen_started,status,requested_by)VALUES(:order_id,:amount,:reason,:started,'pending',:user_id)")->execute(['order_id'=>$orderId,'amount'=>$amount,'reason'=>$reason,'started'=>$started?1:0,'user_id'=>Auth::id()]);header('Location:../orders/view.php?id='.$orderId);exit;}}
require_once __DIR__ . '/../../includes/header.php';?>
<?php pageHeader('ขอคืนเงิน ' . htmlspecialchars($order['order_code'] ?: '#' . $orderId), 'คืนได้สูงสุด ฿' . number_format($max, 2)); ?><?php if(!empty($error)):?><div class="alert alert-danger"><?=$error?></div><?php endif;?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body"><?=Security::csrfInput()?><input type="hidden" name="order_id" value="<?=$orderId?>"><div class="mb-3"><label class="form-label">จำนวนเงิน</label><input type="number" step="0.01" min="0.01" max="<?=$max?>" name="amount" value="<?=$max?>" class="form-control" required></div><div class="mb-3"><label class="form-label">เหตุผล</label><textarea name="reason" class="form-control" required></textarea></div><button class="btn btn-dark">ส่งคำขอให้ผู้จัดการอนุมัติ</button></div></form>
<?php require_once __DIR__ . '/../../includes/footer.php';?>

