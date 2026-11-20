<?php
// ออเดอร์ที่ลูกค้าเลือกรับใบเสร็จกระดาษ ชำระแล้ว แต่ยังไม่ได้พิมพ์ (หน้าแคชเชียร์ดึงไปพิมพ์อัตโนมัติ)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireAnyRole(['owner','manager','cashier','staff']);

header('Content-Type: application/json; charset=utf-8');
try {
$stmt = getDB()->query("SELECT o.order_id FROM orders o
    JOIN order_service_details d ON d.order_id = o.order_id
    WHERE d.receipt_preference = 'paper' AND o.is_paid = 1 AND o.status <> 'cancelled'
      AND COALESCE(o.paid_at, o.opened_at) >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
      AND NOT EXISTS (SELECT 1 FROM audit_logs a
                      WHERE a.entity_type = 'order' AND a.entity_id = o.order_id
                        AND a.action = 'receipt_print_requested')
    ORDER BY COALESCE(o.paid_at, o.opened_at), o.order_id
    LIMIT 1");
$id = $stmt->fetchColumn();
echo json_encode(['order_id' => $id ? (int) $id : null]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['order_id' => null, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
