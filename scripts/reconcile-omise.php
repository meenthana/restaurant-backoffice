<?php
// Schedule every minute using PHP CLI; never expose this as a public HTTP endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../classes/OmiseGateway.php';
if (!OmiseGateway::configured()) { fwrite(STDERR, "Omise not configured\n"); exit(1); }
$ids = getDB()->query("SELECT order_id FROM gateway_payments WHERE status IN ('creating','pending','unknown') ORDER BY COALESCE(checked_at,'2000-01-01') LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
$failures=0;
foreach ($ids as $id) {
    try { OmiseGateway::refresh((int)$id); }
    catch (Throwable $e) { $failures++; fwrite(STDERR, 'Reconcile failed for order ' . (int)$id . "\n"); }
}
echo 'Checked ' . count($ids) . ' orders; failures=' . $failures . "\n";
exit($failures ? 1 : 0);
