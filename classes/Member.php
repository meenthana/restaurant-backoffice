<?php
// สมาชิก แต้มสะสม และประวัติไม่มารับ
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AppSetting.php';
require_once __DIR__ . '/ServiceFlow.php';

class Member
{
    // ทุกกี่บาท ได้ 1 แต้ม
    public const BAHT_PER_POINT = 25;

    // ใช้แต้มเป็นส่วนลดได้สูงสุดกี่ % ของยอดบิล (1 แต้ม = ฿1)
    public const REDEEM_CAP_PERCENT = 50;

    // จำนวนครั้งที่ไม่มารับสะสมได้สูงสุด ก่อนถูกระงับสิทธิ์สั่งออนไลน์อัตโนมัติ
    public const NO_SHOW_THRESHOLD = 3;

    // หา/สร้างสมาชิกจาก LINE User ID (ใช้ตอน Mock LINE Login หรือ LINE Login จริง)
    public static function findOrCreateByLineId(string $lineUserId, string $displayName): array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM members WHERE line_user_id = :lid');
        $stmt->execute(['lid' => $lineUserId]);
        $member = $stmt->fetch();

        if ($member) {
            return $member;
        }

        $ins = $db->prepare('INSERT INTO members (line_user_id, display_name) VALUES (:lid, :name)');
        $ins->execute(['lid' => $lineUserId, 'name' => $displayName]);
        $memberId = (int) $db->lastInsertId();

        return self::find($memberId);
    }

    public static function find(int $memberId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM members WHERE member_id = :id');
        $stmt->execute(['id' => $memberId]);
        $member = $stmt->fetch();
        return $member ?: null;
    }

    // คำนวณแต้มจากยอดออเดอร์ (รวม Add-on) แล้วบวกเข้าบัญชีสมาชิก
    public static function awardPointsForOrder(int $memberId, int $orderId): int
    {
        $db = getDB();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            $memberStmt = $db->prepare('SELECT points FROM members WHERE member_id = :id FOR UPDATE');
            $memberStmt->execute(['id' => $memberId]);
            $currentPoints = $memberStmt->fetchColumn();
            if ($currentPoints === false) {
                throw new Exception('ไม่พบสมาชิก');
            }

            $existing = $db->prepare(
                "SELECT points_change FROM member_point_transactions
                 WHERE member_id = :member_id AND order_id = :order_id AND transaction_type = 'earn'"
            );
            $existing->execute(['member_id' => $memberId, 'order_id' => $orderId]);
            if ($existing->fetchColumn() !== false) {
                if ($ownsTransaction) $db->commit();
                return 0;
            }

            $stmt = $db->prepare(
                'SELECT COALESCE(SUM(oi.subtotal), 0) AS items_total,
                    COALESCE((SELECT SUM(oia.addon_price * oi2.quantity)
                              FROM order_item_addons oia
                              JOIN order_items oi2 ON oi2.order_item_id = oia.order_item_id
                              WHERE oi2.order_id = oi.order_id), 0) AS addons_total
                 FROM order_items oi
                 WHERE oi.order_id = :order_id AND oi.voided_at IS NULL'
            );
            $stmt->execute(['order_id' => $orderId]);
            $row = $stmt->fetch();
            $total = (float) ($row['items_total'] ?? 0) + (float) ($row['addons_total'] ?? 0);

            // นับแต้มจากยอดที่จ่ายจริง (หักส่วนลดจากแต้มแล้ว)
            $discStmt = $db->prepare('SELECT COALESCE(discount_amount, 0) FROM orders WHERE order_id = :id');
            $discStmt->execute(['id' => $orderId]);
            $total = max(0, $total - (float) $discStmt->fetchColumn());

            $bahtPerPoint = max(1, (int) AppSetting::get('baht_per_point', (string) self::BAHT_PER_POINT));
            $points = (int) floor($total / $bahtPerPoint);

            if ($points > 0) {
                $newBalance = (int) $currentPoints + $points;
                $upd = $db->prepare('UPDATE members SET points = :balance, last_visit_at = NOW() WHERE member_id = :id');
                $upd->execute(['balance' => $newBalance, 'id' => $memberId]);
                $log = $db->prepare(
                    "INSERT INTO member_point_transactions
                     (member_id, transaction_type, points_change, balance_after, order_id, note)
                     VALUES (:member_id, 'earn', :points, :balance, :order_id, :note)"
                );
                $log->execute([
                    'member_id' => $memberId,
                    'points' => $points,
                    'balance' => $newBalance,
                    'order_id' => $orderId,
                    'note' => "รับแต้มจากยอดซื้อ ฿" . number_format($total, 2),
                ]);
            }

            if ($ownsTransaction) $db->commit();
            return $points;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    // สรุปข้อมูลสมาชิกสำหรับหน้าใบเสร็จของลูกค้า (null = ลูกค้าทั่วไป)
    public static function receiptSummary(array $order): ?array
    {
        if (empty($order['member_id'])) return null;
        $m = self::find((int) $order['member_id']);
        if (!$m) return null;
        $q = getDB()->prepare('SELECT transaction_type, note, SUM(points_change) AS s FROM member_point_transactions
                               WHERE order_id = ? AND member_id = ? GROUP BY transaction_type, note');
        $q->execute([(int) $order['order_id'], (int) $m['member_id']]);
        $earned = 0; $used = 0; $returned = 0; $hasEarn = false;
        foreach ($q->fetchAll() as $r) {
            $v = (int) $r['s'];
            if ($r['transaction_type'] === 'earn') { $earned += $v; $hasEarn = true; }
            elseif ($r['transaction_type'] === 'redeem') $used -= $v;
            elseif ($r['transaction_type'] === 'reversal') {
                if ($r['note'] === 'คืนแต้มจากการคืนเงินเต็มจำนวน') $earned += $v; else $returned += $v;
            }
        }
        // ยังไม่ได้รับอาหาร: แสดงแต้มที่จะได้รับโดยประมาณ
        $pending = !$hasEarn && in_array($order['status'], ['pending', 'active', 'ready'], true);
        if ($pending) $earned = self::estimatePoints((int) $order['order_id']);
        $phone = preg_replace('/\D/', '', (string) ($m['phone'] ?? ''));
        return [
            'name'     => (string) $m['display_name'],
            'phone'    => strlen($phone) >= 4 ? 'xxx-xxx-' . substr($phone, -4) : '',
            'balance'  => (int) $m['points'],
            'earned'   => max(0, $earned),
            'pending'  => $pending,
            'used'     => max(0, $used),
            'returned' => max(0, $returned),
        ];
    }

    public static function estimatePoints(int $orderId): int
    {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(oi.subtotal), 0)
                  + COALESCE((SELECT SUM(oia.addon_price * oi2.quantity) FROM order_item_addons oia
                              JOIN order_items oi2 ON oi2.order_item_id = oia.order_item_id
                              WHERE oi2.order_id = :o1 AND oi2.voided_at IS NULL), 0)
             FROM order_items oi WHERE oi.order_id = :o2 AND oi.voided_at IS NULL'
        );
        $stmt->execute(['o1' => $orderId, 'o2' => $orderId]);
        $total = (float) $stmt->fetchColumn();
        $d = $db->prepare('SELECT COALESCE(discount_amount, 0) FROM orders WHERE order_id = ?');
        $d->execute([$orderId]);
        $total = max(0, $total - (float) $d->fetchColumn());
        $bahtPerPoint = max(1, (int) AppSetting::get('baht_per_point', (string) self::BAHT_PER_POINT));
        return (int) floor($total / $bahtPerPoint);
    }

    public static function adjustPoints(int $memberId, int $pointsChange, string $note, int $userId): int
    {
        $note = trim($note);
        if ($pointsChange === 0) throw new Exception('จำนวนแต้มต้องไม่เท่ากับ 0');
        if ($note === '') throw new Exception('กรุณาระบุเหตุผลการปรับแต้ม');

        $db = getDB();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT points FROM members WHERE member_id = :id FOR UPDATE');
            $stmt->execute(['id' => $memberId]);
            $current = $stmt->fetchColumn();
            if ($current === false) throw new Exception('ไม่พบสมาชิก');
            $balance = (int) $current + $pointsChange;
            if ($balance < 0) throw new Exception('ไม่สามารถลดแต้มจนติดลบได้');

            $db->prepare('UPDATE members SET points = :points WHERE member_id = :id')
               ->execute(['points' => $balance, 'id' => $memberId]);
            $db->prepare(
                "INSERT INTO member_point_transactions
                 (member_id, transaction_type, points_change, balance_after, note, created_by)
                 VALUES (:member_id, 'adjustment', :change, :balance, :note, :user_id)"
            )->execute([
                'member_id' => $memberId,
                'change' => $pointsChange,
                'balance' => $balance,
                'note' => $note,
                'user_id' => $userId,
            ]);
            $db->commit();
            return $balance;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function redeemCapPercent(): int
    {
        return min(100, max(1, (int) AppSetting::get('point_redeem_cap_percent', (string) self::REDEEM_CAP_PERCENT)));
    }

    // ใช้แต้มเป็นส่วนลดท้ายบิล (1 แต้ม = ฿1) ไม่เกินเพดาน % ของยอดบิล คืนค่าจำนวนแต้มที่ใช้
    public static function redeemPointsForOrder(int $memberId, int $orderId): int
    {
        $db = getDB();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $already = $db->prepare("SELECT 1 FROM member_point_transactions WHERE order_id = :order_id AND transaction_type = 'redeem'");
            $already->execute(['order_id' => $orderId]);
            if ($already->fetchColumn()) { if ($own) $db->commit(); return 0; }

            $memberStmt = $db->prepare('SELECT points FROM members WHERE member_id = :id FOR UPDATE');
            $memberStmt->execute(['id' => $memberId]);
            $current = $memberStmt->fetchColumn();
            if ($current === false) throw new Exception('ไม่พบสมาชิก');

            $totalStmt = $db->prepare(
                'SELECT COALESCE(SUM(oi.subtotal), 0)
                      + COALESCE((SELECT SUM(oia.addon_price * oi2.quantity)
                                  FROM order_item_addons oia
                                  JOIN order_items oi2 ON oi2.order_item_id = oia.order_item_id
                                  WHERE oi2.order_id = :oid2), 0)
                      - COALESCE((SELECT discount_amount FROM orders WHERE order_id = :oid3), 0)
                 FROM order_items oi WHERE oi.order_id = :oid AND oi.voided_at IS NULL'
            );
            $totalStmt->execute(['oid' => $orderId, 'oid2' => $orderId, 'oid3' => $orderId]);
            $total = (float) $totalStmt->fetchColumn();

            $points = min((int) $current, (int) floor($total * self::redeemCapPercent() / 100));
            if ($points <= 0) { if ($own) $db->commit(); return 0; }

            $balance = (int) $current - $points;
            $db->prepare('UPDATE members SET points = :points WHERE member_id = :id')
               ->execute(['points' => $balance, 'id' => $memberId]);
            $db->prepare('UPDATE orders SET discount_amount = COALESCE(discount_amount, 0) + :d WHERE order_id = :id')
               ->execute(['d' => $points, 'id' => $orderId]);
            $db->prepare(
                "INSERT INTO member_point_transactions
                 (member_id, transaction_type, points_change, balance_after, order_id, note)
                 VALUES (:member_id, 'redeem', :change, :balance, :order_id, :note)"
            )->execute([
                'member_id' => $memberId,
                'change' => -$points,
                'balance' => $balance,
                'order_id' => $orderId,
                'note' => 'ใช้แต้มเป็นส่วนลด ฿' . number_format($points, 2),
            ]);
            if ($own) $db->commit();
            return $points;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    // คืนแต้มที่ใช้เป็นส่วนลด เมื่อออเดอร์ถูกยกเลิก
    public static function returnRedeemedPoints(int $orderId): int
    {
        $db = getDB();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $redeem = $db->prepare(
                "SELECT member_id, SUM(-points_change) AS used FROM member_point_transactions
                 WHERE order_id = :order_id AND transaction_type = 'redeem' GROUP BY member_id"
            );
            $redeem->execute(['order_id' => $orderId]);
            $row = $redeem->fetch();
            if (!$row || (int) $row['used'] <= 0) { if ($own) $db->commit(); return 0; }

            $already = $db->prepare(
                "SELECT 1 FROM member_point_transactions
                 WHERE order_id = :order_id AND transaction_type = 'reversal'
                   AND note = 'คืนแต้มที่ใช้เป็นส่วนลด เพราะออเดอร์ถูกยกเลิก'"
            );
            $already->execute(['order_id' => $orderId]);
            if ($already->fetchColumn()) { if ($own) $db->commit(); return 0; }

            $memberId = (int) $row['member_id'];
            $give = (int) $row['used'];
            $member = $db->prepare('SELECT points FROM members WHERE member_id = :id FOR UPDATE');
            $member->execute(['id' => $memberId]);
            $balance = (int) $member->fetchColumn() + $give;
            $db->prepare('UPDATE members SET points = :points WHERE member_id = :id')
               ->execute(['points' => $balance, 'id' => $memberId]);
            $db->prepare(
                "INSERT INTO member_point_transactions
                 (member_id, transaction_type, points_change, balance_after, order_id, note)
                 VALUES (:member_id, 'reversal', :change, :balance, :order_id, 'คืนแต้มที่ใช้เป็นส่วนลด เพราะออเดอร์ถูกยกเลิก')"
            )->execute([
                'member_id' => $memberId,
                'change' => $give,
                'balance' => $balance,
                'order_id' => $orderId,
            ]);
            if ($own) $db->commit();
            return $give;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function reversePointsForOrder(int $orderId, ?int $userId = null): int
    {
        $db = getDB();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            $earn = $db->prepare(
                "SELECT * FROM member_point_transactions
                 WHERE order_id = :order_id AND transaction_type = 'earn' FOR UPDATE"
            );
            $earn->execute(['order_id' => $orderId]);
            $transaction = $earn->fetch();
            if (!$transaction) {
                if ($ownsTransaction) $db->commit();
                return 0;
            }
            $already = $db->prepare(
                "SELECT 1 FROM member_point_transactions
                 WHERE order_id = :order_id AND transaction_type = 'reversal'
                   AND note = 'คืนแต้มจากการคืนเงินเต็มจำนวน'"
            );
            $already->execute(['order_id' => $orderId]);
            if ($already->fetchColumn()) {
                if ($ownsTransaction) $db->commit();
                return 0;
            }

            $memberId = (int) $transaction['member_id'];
            $member = $db->prepare('SELECT points FROM members WHERE member_id = :id FOR UPDATE');
            $member->execute(['id' => $memberId]);
            $current = (int) $member->fetchColumn();
            $deduct = min($current, max(0, (int) $transaction['points_change']));
            $balance = $current - $deduct;
            $db->prepare('UPDATE members SET points = :points WHERE member_id = :id')
               ->execute(['points' => $balance, 'id' => $memberId]);
            $db->prepare(
                "INSERT INTO member_point_transactions
                 (member_id, transaction_type, points_change, balance_after, order_id, note, created_by)
                 VALUES (:member_id, 'reversal', :change, :balance, :order_id, 'คืนแต้มจากการคืนเงินเต็มจำนวน', :user_id)"
            )->execute([
                'member_id' => $memberId,
                'change' => -$deduct,
                'balance' => $balance,
                'order_id' => $orderId,
                'user_id' => $userId,
            ]);
            if ($ownsTransaction) $db->commit();
            return $deduct;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function updateProfile(int $memberId, string $displayName, ?string $phone, ?string $email): void
    {
        $displayName = trim($displayName);
        if ($displayName === '') throw new Exception('กรุณาระบุชื่อสมาชิก');
        $email = trim((string) $email) ?: null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
        }
        $phone = trim((string)$phone) ?: null;
        $db=getDB();$own=!$db->inTransaction();if($own)$db->beginTransaction();
        try {
            $lock=$db->prepare('SELECT phone FROM members WHERE member_id=? FOR UPDATE');$lock->execute([$memberId]);$previous=$lock->fetch();
            if(!$previous)throw new Exception('ไม่พบสมาชิก');
            if(ServiceFlow::ready() && $phone!==$previous['phone']) {
                if($phone!==null) {
                    $phone=ServiceFlow::phone($phone);
                    $db->prepare('INSERT INTO member_phone_accounts(phone,member_id) VALUES (?,NULL) ON DUPLICATE KEY UPDATE phone=VALUES(phone)')->execute([$phone]);
                    $mapped=$db->prepare('SELECT member_id FROM member_phone_accounts WHERE phone=? FOR UPDATE');$mapped->execute([$phone]);$other=$mapped->fetchColumn();
                    if($other && (int)$other!==$memberId)throw new Exception('เบอร์นี้ผูกกับสมาชิกอื่นแล้ว');
                    foreach($db->query("SELECT member_id,phone FROM members WHERE phone IS NOT NULL AND phone<>''")->fetchAll() as $row){if((int)$row['member_id']===$memberId)continue;try{$same=ServiceFlow::phone($row['phone'])===$phone;}catch(InvalidArgumentException $e){$same=false;}if($same)throw new Exception('มีสมาชิกอื่นใช้เบอร์นี้อยู่ กรุณาตรวจข้อมูลซ้ำ');}
                }
                $db->prepare('DELETE FROM member_phone_accounts WHERE member_id=?')->execute([$memberId]);
                if($phone!==null)$db->prepare('UPDATE member_phone_accounts SET member_id=? WHERE phone=?')->execute([$memberId,$phone]);
            }
            $db->prepare('UPDATE members SET display_name=?,phone=?,email=? WHERE member_id=?')->execute([$displayName,$phone,$email,$memberId]);
            if($own)$db->commit();
        }catch(Throwable $e){if($own && $db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function pointHistory(int $memberId): array
    {
        $stmt = getDB()->prepare(
            "SELECT mpt.*, u.full_name, o.order_code
             FROM member_point_transactions mpt
             LEFT JOIN users u ON u.user_id = mpt.created_by
             LEFT JOIN orders o ON o.order_id = mpt.order_id
             WHERE mpt.member_id = :id
             ORDER BY mpt.created_at DESC, mpt.point_transaction_id DESC"
        );
        $stmt->execute(['id' => $memberId]);
        return $stmt->fetchAll();
    }

    // บันทึกการไม่มารับ 1 ครั้ง นับสะสม และระงับสิทธิ์อัตโนมัติถ้าเกินเกณฑ์
    public static function recordNoShow(int $memberId): void
    {
        $db = getDB();
        $upd = $db->prepare('UPDATE members SET no_show_count = no_show_count + 1 WHERE member_id = :id');
        $upd->execute(['id' => $memberId]);

        $stmt = $db->prepare('SELECT no_show_count FROM members WHERE member_id = :id');
        $stmt->execute(['id' => $memberId]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= self::NO_SHOW_THRESHOLD) {
            $sus = $db->prepare('UPDATE members SET is_suspended = 1 WHERE member_id = :id');
            $sus->execute(['id' => $memberId]);
        }
    }

    // เจ้าของร้านปลดล็อกสมาชิกที่ถูกระงับสิทธิ์ด้วยตนเอง
    public static function unsuspend(int $memberId): void
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE members SET is_suspended = 0, no_show_count = 0 WHERE member_id = :id');
        $stmt->execute(['id' => $memberId]);
    }

    public static function all(): array
    {
        $db = getDB();
        return $db->query('SELECT * FROM members ORDER BY created_at DESC')->fetchAll();
    }
}
