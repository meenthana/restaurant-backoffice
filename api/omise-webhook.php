<?php
require_once __DIR__ . '/../classes/OmiseGateway.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input', false, null, 0, 262145);
if (strlen($raw) > 262144 || !OmiseGateway::signature($raw, $_SERVER['HTTP_OMISE_SIGNATURE_TIMESTAMP'] ?? '', $_SERVER['HTTP_OMISE_SIGNATURE'] ?? '', OMISE_WEBHOOK_SECRET)) {
    http_response_code(401); exit('{"ok":false}');
}
try {
    $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!in_array($event['key'] ?? '', ['charge.create','charge.complete','charge.update'], true)) exit('{"ok":true}');
    $id = $event['data']['id'] ?? '';
    if (!preg_match('/^chrg_(?:test_)?[a-zA-Z0-9]+$/D', $id)) { http_response_code(400); exit; }
    $charge = OmiseGateway::request('/charges/' . rawurlencode($id));
    $orderId = (int)($charge['metadata']['restaurant_order_id'] ?? 0);
    if ($orderId && OmiseGateway::row($orderId)) OmiseGateway::acceptCharge($orderId, $charge);
    echo '{"ok":true}';
} catch (Throwable $e) {
    error_log('Omise webhook could not be reconciled');
    http_response_code(503); echo '{"ok":false}';
}
