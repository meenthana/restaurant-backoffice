<?php
// ล็อตวัตถุดิบ: รับเข้า ตัดแบบใกล้หมดอายุก่อน ของเสีย แปลงหน่วย
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/StockTransaction.php';

class StockBatch
{
    public static function receive(
        int $ingredientId,
        float $quantity,
        float $costPerUnit,
        string $purchaseDate,
        ?string $expiryDate = null,
        ?int $sourceBatchId = null,
        ?int $receivedBy = null,
        ?string $receiptPhoto = null
    ): int {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO stock_batches
                (ingredient_id, source_batch_id, quantity_purchased, quantity_remaining,
                 cost_per_unit, purchase_date, expiry_date, received_by, receipt_photo)
             VALUES
                (:ingredient_id, :source_batch_id, :qty_purchased, :qty_remaining, :cost, :purchase_date, :expiry_date, :received_by, :receipt_photo)'
        );
        $stmt->execute([
            'ingredient_id'   => $ingredientId,
            'source_batch_id' => $sourceBatchId,
            'qty_purchased'   => $quantity,
            'qty_remaining'   => $quantity,
            'cost'            => $costPerUnit,
            'purchase_date'   => $purchaseDate,
            'expiry_date'     => $expiryDate,
            'received_by'     => $receivedBy,
            'receipt_photo'   => $receiptPhoto,
        ]);
        $batchId = (int) $db->lastInsertId();

        StockTransaction::log($batchId, 'purchase', $quantity, null, null, 'รับวัตถุดิบเข้าคลัง');

        return $batchId;
    }

    // หักวัตถุดิบตามหลัก FEFO (ล็อตใกล้หมดอายุก่อน) $allowShortage = true ตัดเท่าที่มี ไม่ error
    public static function deductFEFO(
        int $ingredientId,
        float $qtyNeeded,
        ?int $orderId = null,
        ?int $orderItemId = null,
        ?string $note = null,
        bool $allowShortage = false,
        string $type = 'order_deduction'
    ): float {
        $db = getDB();
        $batches = self::getBatchesForDeduction($ingredientId);

        $remaining = $qtyNeeded;
        $totalCost = 0.0;
        $deductions = []; // เก็บไว้ก่อน ยังไม่ commit จนกว่าจะมั่นใจว่าของพอ

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $available = (float) $batch['quantity_remaining'];
            $take = min($available, $remaining);

            $deductions[] = ['batch_id' => (int) $batch['batch_id'], 'take' => $take, 'cost' => (float) $batch['cost_per_unit']];
            $totalCost += $take * (float) $batch['cost_per_unit'];
            $remaining -= $take;
        }

        if ($remaining > 0.0001 && $allowShortage) {
            $note = ($note ?? '') . ' (ในระบบไม่พอ ขาด ' . number_format($remaining, 2) . ')';
        } elseif ($remaining > 0.0001) {
            $stmt = $db->prepare('SELECT name, unit FROM ingredients WHERE ingredient_id = :id');
            $stmt->execute(['id' => $ingredientId]);
            $ing = $stmt->fetch();
            $ingName = $ing ? $ing['name'] : "#$ingredientId";
            throw new Exception("วัตถุดิบ \"$ingName\" ในคลังไม่พอ (ขาดอีก " . number_format($remaining, 2) . ' ' . ($ing['unit'] ?? '') . ')');
        }

        // ของพอแน่นอนแล้ว ค่อยหักจริงและบันทึก transaction ทีละล็อต
        $updateStmt = $db->prepare('UPDATE stock_batches SET quantity_remaining = quantity_remaining - :take WHERE batch_id = :id');
        foreach ($deductions as $d) {
            $updateStmt->execute(['take' => $d['take'], 'id' => $d['batch_id']]);
            StockTransaction::log(
                $d['batch_id'],
                $type,
                -$d['take'],
                $orderId,
                $orderItemId,
                $note ?? 'ตัดสต๊อกอัตโนมัติตามออเดอร์ (FEFO)'
            );
        }

        return $totalCost;
    }

    // ดึงล็อตของวัตถุดิบที่ยังมีของเหลือ เรียงตามวันหมดอายุใกล้สุดก่อน (FEFO)
    public static function getBatchesForDeduction(int $ingredientId): array
    {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT * FROM stock_batches
             WHERE ingredient_id = :ingredient_id AND quantity_remaining > 0
             ORDER BY expiry_date IS NULL, expiry_date ASC, batch_id ASC'
        );
        $stmt->execute(['ingredient_id' => $ingredientId]);
        return $stmt->fetchAll();
    }

    // บันทึกของเสีย: หักออกจากล็อตที่ระบุโดยตรง (ไม่ใช่ FEFO เพราะพนักงานเลือกล็อตที่เสียเองจริงๆ)
    public static function recordWastage(int $batchId, float $qty, ?string $note = null): void
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT quantity_remaining FROM stock_batches WHERE batch_id = :id');
        $stmt->execute(['id' => $batchId]);
        $remaining = $stmt->fetchColumn();

        if ($remaining === false) {
            throw new Exception("ไม่พบล็อตวัตถุดิบ #$batchId");
        }
        if ($qty > (float) $remaining) {
            throw new Exception('จำนวนที่ทิ้งมากกว่าจำนวนที่เหลืออยู่ในล็อตนี้');
        }

        $upd = $db->prepare('UPDATE stock_batches SET quantity_remaining = quantity_remaining - :qty WHERE batch_id = :id');
        $upd->execute(['qty' => $qty, 'id' => $batchId]);

        StockTransaction::log($batchId, 'wastage', -$qty, null, null, $note ?? 'บันทึกของเสีย');
    }

    // แปรรูปวัตถุดิบ: หักจากล็อตต้นทาง แล้วสร้างล็อตใหม่ของวัตถุดิบปลายทาง
    public static function convert(int $sourceBatchId, float $qty, int $targetIngredientId, ?string $expiryDate = null): int
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM stock_batches WHERE batch_id = :id');
        $stmt->execute(['id' => $sourceBatchId]);
        $source = $stmt->fetch();

        if (!$source) {
            throw new Exception("ไม่พบล็อตต้นทาง #$sourceBatchId");
        }
        if ($qty > (float) $source['quantity_remaining']) {
            throw new Exception('จำนวนที่แปรรูปมากกว่าจำนวนที่เหลืออยู่ในล็อตต้นทาง');
        }

        // หักล็อตต้นทาง
        $upd = $db->prepare('UPDATE stock_batches SET quantity_remaining = quantity_remaining - :qty WHERE batch_id = :id');
        $upd->execute(['qty' => $qty, 'id' => $sourceBatchId]);
        StockTransaction::log($sourceBatchId, 'conversion_out', -$qty, null, null, 'แปรรูปออกไปเป็นวัตถุดิบอื่น');

        // สร้างล็อตปลายทางใหม่ ต้นทุน/หน่วยยกมาจากต้นทาง
        $ins = $db->prepare(
            'INSERT INTO stock_batches
                (ingredient_id, source_batch_id, quantity_purchased, quantity_remaining, cost_per_unit, purchase_date, expiry_date)
             VALUES (:ingredient_id, :source_batch_id, :qty_purchased, :qty_remaining, :cost, CURDATE(), :expiry_date)'
        );
        $ins->execute([
            'ingredient_id'   => $targetIngredientId,
            'source_batch_id' => $sourceBatchId,
            'qty_purchased'   => $qty,
            'qty_remaining'   => $qty,
            'cost'            => $source['cost_per_unit'],
            'expiry_date'     => $expiryDate,
        ]);
        $newBatchId = (int) $db->lastInsertId();

        StockTransaction::log($newBatchId, 'conversion_in', $qty, null, null, "แปรรูปมาจากล็อต #$sourceBatchId");

        return $newBatchId;
    }
}
