<?php
// SCB ส่งแจ้งเตือนการจ่ายมาที่นี่ ระบบไม่เชื่อข้อมูลที่ส่งมา แต่จะถามยืนยันกับ SCB อีกครั้ง
require_once __DIR__ . '/../classes/ScbGateway.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input', false, null, 0, 65537);
$data = json_decode((string) $raw, true);
$ref1 = is_array($data) ? (string) ($data['billPaymentRef1'] ?? '') : '';
$txn = is_array($data) ? (string) ($data['transactionId'] ?? '') : '';
try {
    if (preg_match('/^[A-Z0-9]{1,20}$/D', $ref1) && ($row = ScbGateway::findByRef1($ref1))) {
        ScbGateway::refresh((int) $row['order_id'], true);
    }
    echo json_encode(['resCode' => '00', 'resDesc' => 'success', 'transactionId' => $txn]);
} catch (Throwable $e) {
    error_log('SCB callback could not be verified');
    http_response_code(503);
    echo json_encode(['resCode' => '99', 'resDesc' => 'retry', 'transactionId' => $txn]);
}
