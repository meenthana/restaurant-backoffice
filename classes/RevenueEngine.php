<?php
// คำนวณรายได้ กำไร ความนิยมเมนู และวันที่ยอดผิดปกติ
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/features.php';

class RevenueEngine
{
    // สรุปรายได้รายวัน จากออเดอร์ที่ปิดแล้วเท่านั้น (status = closed)
    public static function dailyRevenue(string $dateFrom, string $dateTo): array
    {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT DATE(p.created_at) AS sale_date,
                    SUM(CASE WHEN p.transaction_type='payment' THEN p.amount ELSE -p.amount END) AS revenue
             FROM payments p
             WHERE p.status='completed' AND DATE(p.created_at) BETWEEN :from AND :to
             GROUP BY DATE(p.created_at)
             ORDER BY sale_date"
        );
        $stmt->execute(['from' => $dateFrom, 'to' => $dateTo]);
        return $stmt->fetchAll();
    }

    // กำไรที่แท้จริงต่อเมนู จากข้อมูลขายจริง (รวมต้นทุนวัตถุดิบที่หักจริงจาก FEFO)
    public static function costAdjustedProfitByMenu(string $dateFrom, string $dateTo): array
    {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT m.menu_id, m.name AS menu_name,
                    SUM(oi.quantity) AS qty_sold,
                    SUM(oi.subtotal + oi.quantity * COALESCE((SELECT SUM(oia.addon_price) FROM order_item_addons oia WHERE oia.order_item_id=oi.order_item_id),0)) AS revenue,
                    SUM(COALESCE(oi.cost_of_goods, 0)) AS cost,
                    SUM(oi.subtotal + oi.quantity * COALESCE((SELECT SUM(oia.addon_price) FROM order_item_addons oia WHERE oia.order_item_id=oi.order_item_id),0)) - SUM(COALESCE(oi.cost_of_goods, 0)) AS profit
             FROM order_items oi
             JOIN orders o ON o.order_id = oi.order_id
             JOIN menu m ON m.menu_id = oi.menu_id
             WHERE o.status = 'closed' AND DATE(o.closed_at) BETWEEN :from AND :to
             GROUP BY m.menu_id, m.name
             ORDER BY profit DESC"
        );
        $stmt->execute(['from' => $dateFrom, 'to' => $dateTo]);
        $rows = $stmt->fetchAll();
        if (FEATURE_AUTO_STOCK) return $rows;

        // Soft Stock ไม่ได้ตัดสต๊อกจริง จึงใช้ต้นทุนตามสูตร (สูตร x ราคาซื้อล่าสุด) แทน
        $unit = self::recipeCostPerServing();
        foreach ($rows as &$r) {
            $r['cost'] = round(($unit[(int) $r['menu_id']] ?? 0) * (float) $r['qty_sold'], 2);
            $r['profit'] = round((float) $r['revenue'] - $r['cost'], 2);
            $r['no_recipe'] = !isset($unit[(int) $r['menu_id']]);
        }
        unset($r);
        usort($rows, fn($a, $b) => $b['profit'] <=> $a['profit']);
        return $rows;
    }

    // ต้นทุนวัตถุดิบต่อ 1 จาน = ผลรวม (ปริมาณในสูตร x ราคาต่อหน่วยของล็อตที่ซื้อล่าสุด)
    public static function recipeCostPerServing(): array
    {
        $rows = getDB()->query(
            "SELECT r.menu_id, SUM(r.quantity_required * COALESCE((
                    SELECT b.cost_per_unit FROM stock_batches b WHERE b.ingredient_id = r.ingredient_id
                    ORDER BY b.purchase_date DESC, b.batch_id DESC LIMIT 1), 0)) AS cost
             FROM recipe r GROUP BY r.menu_id"
        )->fetchAll();
        $map = [];
        foreach ($rows as $r) $map[(int) $r['menu_id']] = (float) $r['cost'];
        return $map;
    }

    // Menu Engineering Matrix (Kasavana & Smith, 1982)
    public static function menuEngineeringMatrix(string $dateFrom, string $dateTo): array
    {
        $rows = self::costAdjustedProfitByMenu($dateFrom, $dateTo);
        if (empty($rows)) {
            return [];
        }

        $qtyValues = array_map(fn($r) => (float) $r['qty_sold'], $rows);
        $profitValues = array_map(fn($r) => (float) $r['profit'], $rows);
        $medianQty = self::median($qtyValues);
        $medianProfit = self::median($profitValues);

        foreach ($rows as &$r) {
            $highQty = (float) $r['qty_sold'] >= $medianQty;
            $highProfit = (float) $r['profit'] >= $medianProfit;

            if ($highQty && $highProfit) {
                $r['category'] = 'star';
            } elseif ($highQty && !$highProfit) {
                $r['category'] = 'plowhorse';
            } elseif (!$highQty && $highProfit) {
                $r['category'] = 'puzzle';
            } else {
                $r['category'] = 'dog';
            }
        }
        unset($r);

        return $rows;
    }

    // ตรวจจับวันที่รายได้ผิดปกติด้วย Z-score เทียบกับค่าเฉลี่ยและส่วนเบี่ยงเบนมาตรฐานของช่วงเวลาที่กำหนด
    public static function zScoreAnomalies(string $dateFrom, string $dateTo, float $threshold = 2.0): array
    {
        $daily = self::dailyRevenue($dateFrom, $dateTo);
        $values = array_map(fn($d) => (float) $d['revenue'], $daily);
        $n = count($values);

        if ($n < 2) {
            return [];
        }

        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / $n;
        $stddev = sqrt($variance);

        $anomalies = [];
        foreach ($daily as $d) {
            if ($stddev == 0.0) {
                continue;
            }
            $z = ((float) $d['revenue'] - $mean) / $stddev;
            if (abs($z) > $threshold) {
                $anomalies[] = [
                    'sale_date' => $d['sale_date'],
                    'revenue'   => $d['revenue'],
                    'z_score'   => round($z, 2),
                    'direction' => $z > 0 ? 'สูงกว่าปกติ' : 'ต่ำกว่าปกติ',
                ];
            }
        }

        return $anomalies;
    }

    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }
        return $values[$mid];
    }
}
