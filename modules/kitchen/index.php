<?php
// จอครัว: ใบงานที่ต้องทำ
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/KitchenTicket.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireLogin();

$tickets = KitchenTicket::activeBoard();
$columns = ['queued' => 'รอทำ', 'cooking' => 'กำลังทำ', 'ready' => 'พร้อมเสิร์ฟ/รับ'];
$nextActions = [
    'queued' => ['cooking', 'เริ่มทำ', 'btn-warning'],
    'cooking' => ['ready', 'ทำเสร็จแล้ว', 'btn-success'],
    'ready' => ['served', 'ส่งมอบ/เสิร์ฟแล้ว', 'btn-dark'],
];

require_once __DIR__ . '/../../includes/header.php';
?>
<?php pageHeader('ครัว', 'อาหารที่ต้องทำตอนนี้', '<span class="text-muted small"><i class="ti ti-refresh"></i> อัปเดตทุก 30 วินาที</span>'); ?>

<?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>

<div class="voice-kitchen"><h2 class="h5">ยืนยันสถานะด้วยเสียง</h2><p>เช่น “เริ่มทำ เค 6” หรือ “พร้อมรับ ดับเบิลยู 3” แล้วพูด “ยืนยัน” ภายใน 15 วินาที · ไม่รองรับส่งมอบด้วยเสียง</p><button type="button" class="btn btn-warning" id="kitchen-listen">เปิดรับเสียง</button> <button type="button" class="btn btn-outline-dark" id="kitchen-stop">ปิดรับเสียง</button><p id="kitchen-voice-status" role="status" aria-live="polite">ยังไม่เปิดไมโครโฟน — ปุ่มบนใบงานยังใช้ได้ตามปกติ</p></div>
<div class="row g-3 kitchen-board">
<?php foreach ($columns as $status => $title): ?>
  <div class="col-12 col-lg-4">
    <section class="kitchen-column kitchen-<?= $status ?>">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0"><?= $title ?></h2>
        <span class="badge text-bg-light border"><?= count(array_filter($tickets, fn($t) => $t['status'] === $status)) ?> ใบงาน</span>
      </div>
      <?php $found = false; foreach ($tickets as $ticket): if ($ticket['status'] !== $status) continue; $found = true; ?>
      <article class="card border-0 shadow-sm mb-3 kitchen-ticket <?= $ticket['priority'] === 'urgent' ? 'ticket-urgent' : '' ?>">
        <div class="card-body">
          <div class="d-flex justify-content-between gap-2 mb-2">
            <div>
              <?php if (!empty($ticket['queue_no'])): ?>
              <div class="fw-bold fs-4"><?= !empty($ticket['queue_no']) ? 'คิว ' . KioskOrder::queueLabel((int) $ticket['queue_no'], $ticket['channel'], (int)$ticket['order_id']) : 'ออเดอร์ #' . $ticket['order_id'] ?></div>
              <div class="small"><span class="badge <?= $ticket['service_type'] === 'takeaway' ? 'text-bg-warning' : 'text-bg-success' ?>"><?= $ticket['service_type'] === 'takeaway' ? 'กลับบ้าน' : 'ทานที่ร้าน' ?></span>
                <span class="badge text-bg-light border"><?= ['kiosk' => 'ตู้สั่งเอง', 'online' => 'ออนไลน์'][$ticket['channel']] ?? 'หน้าร้าน' ?></span>
                <?= KioskOrder::cardBadge($ticket) ?></div>
              <?php else: ?>
              <div class="fw-bold fs-5"><?= htmlspecialchars($ticket['table_codes'] ?: ($ticket['service_type'] === 'takeaway' ? 'รับกลับบ้าน' : 'หน้าร้าน')) ?></div>
              <?php endif; ?>
              <?php if(!ServiceFlow::details((int)$ticket['order_id'])): ?><div class="small text-muted"><?= htmlspecialchars($ticket['order_code'] ?: '#'.$ticket['order_id']) ?> · รอบ <?= (int) $ticket['round_no'] ?></div><?php endif; ?>
            </div>
            <span class="badge <?= (int)$ticket['waiting_minutes'] >= 20 ? 'text-bg-danger' : 'text-bg-light border' ?> align-self-start">
              <?= (int) $ticket['waiting_minutes'] ?> นาที
            </span>
          </div>
          <div class="kitchen-items">
          <?php foreach (KitchenTicket::items((int) $ticket['ticket_id']) as $item): ?>
            <div class="py-2 border-top">
              <div class="fw-semibold"><span class="me-2">×<?= (int)$item['quantity'] ?></span><?= htmlspecialchars($item['menu_name']) ?></div>
              <?php if ($item['addons']): ?><div class="small text-primary"><?= htmlspecialchars($item['addons']) ?></div><?php endif; ?>
              <?php if ($item['item_note']): ?><div class="small text-danger">หมายเหตุ: <?= htmlspecialchars($item['item_note']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
          <?php [$nextStatus, $buttonText, $buttonClass] = $nextActions[$status]; $service=ServiceFlow::details((int)$ticket['order_id']); ?>
          <?php if ($service): ?><span class="queue-category queue-type-<?= htmlspecialchars($service['queue_type']) ?>"><?= ServiceFlow::typeLabel($service['queue_type']) ?></span><?php endif; ?>
          <?php if ($service && $status==='ready'): ?><p class="mt-3 text-success">รอแคชเชียร์เรียกคิวและส่งมอบ</p><?php else: ?>
          <form method="post" action="action.php" class="mt-3" data-voice-ticket="<?= htmlspecialchars(KioskOrder::queueLabel((int)$ticket['queue_no'],$ticket['channel'],(int)$ticket['order_id'])) ?>" data-next="<?= $nextStatus ?>">
            <?= Security::csrfInput() ?>
            <input type="hidden" name="ticket_id" value="<?= (int)$ticket['ticket_id'] ?>">
            <input type="hidden" name="status" value="<?= $nextStatus ?>">
            <button class="btn <?= $buttonClass ?> w-100"><?= $buttonText ?></button>
          </form><?php endif; ?>
        </div>
      </article>
      <?php endforeach; if (!$found): ?>
        <div class="empty-state text-center text-muted py-5"><i class="ti ti-checks fs-1"></i><div>ไม่มีรายการ</div></div>
      <?php endif; ?>
    </section>
  </div>
<?php endforeach; ?>
</div>
<script src="../../assets/js/kitchen-voice.js?v=<?= filemtime(__DIR__ . '/../../assets/js/kitchen-voice.js') ?>"></script>
<script>setInterval(() => { if(!window.kitchenVoiceActive && !document.hidden) location.reload(); }, 30000);</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

