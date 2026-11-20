<?php
// บันทึกการเคลื่อนไหวสต๊อกทุกครั้ง
require_once __DIR__ . '/../config/database.php';

class StockTransaction
{
    public static function log(
        int $batchId,
        string $type,
        float $quantity,
        ?int $relatedOrderId = null,
        ?int $relatedOrderItemId = null,
        ?string $note = null
    ): int {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO stock_transactions
                (batch_id, type, quantity, related_order_id, related_order_item_id, note)
             VALUES
                (:batch_id, :type, :quantity, :order_id, :order_item_id, :note)'
        );
        $stmt->execute([
            'batch_id'      => $batchId,
            'type'          => $type,
            'quantity'      => $quantity,
            'order_id'      => $relatedOrderId,
            'order_item_id' => $relatedOrderItemId,
            'note'          => $note,
        ]);
        return (int) $db->lastInsertId();
    }

    // TODO: getExpiringSoon(int $days = 2): array
}
