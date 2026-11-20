<?php
require_once __DIR__.'/ServiceFlow.php';

class PhoneMember
{
    // ตรวจเบอร์ที่ตู้ก่อนเข้าหน้าเมนู (อ่านอย่างเดียว ไม่สร้างสมาชิก): member | none | duplicate | suspended
    public static function lookup(string $phone): string
    {
        if (!ServiceFlow::ready()) return 'none';
        $phone = ServiceFlow::phone($phone);
        $db = getDB();
        $stmt = $db->prepare('SELECT member_id FROM member_phone_accounts WHERE phone=? AND member_id IS NOT NULL');
        $stmt->execute([$phone]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            foreach ($db->query("SELECT member_id,phone FROM members WHERE phone IS NOT NULL AND phone<>''")->fetchAll() as $row) {
                try { if (ServiceFlow::phone($row['phone']) === $phone) $ids[] = (int)$row['member_id']; }
                catch (InvalidArgumentException $e) { }
            }
        }
        if (!$ids) return 'none';
        if (count(array_unique($ids)) > 1) return 'duplicate';
        $stmt = $db->prepare('SELECT is_suspended FROM members WHERE member_id=?');
        $stmt->execute([$ids[0]]);
        $suspended = $stmt->fetchColumn();
        return ($suspended === false || (int)$suspended === 1) ? 'suspended' : 'member';
    }

    // Called only from order submission; no public directory lookup or profile disclosure.
    public static function resolve(string $phone, bool $register, string $name, bool $consent): int
    {
        if (!ServiceFlow::ready()) throw new RuntimeException('ยังไม่ได้ติดตั้งฐานข้อมูลสมาชิกเวอร์ชันใหม่ กรุณาสั่งแบบบุคคลทั่วไป');
        $phone = ServiceFlow::phone($phone);
        $db = getDB();
        if (!$db->inTransaction()) throw new LogicException('Member registration requires an order transaction');
        // Serialize check/create for the same phone, including concurrent kiosks.
        $db->prepare('INSERT INTO member_phone_accounts(phone,member_id) VALUES (?,NULL) ON DUPLICATE KEY UPDATE phone=VALUES(phone)')->execute([$phone]);
        $stmt = $db->prepare('SELECT member_id FROM member_phone_accounts WHERE phone=? FOR UPDATE');
        $stmt->execute([$phone]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            // Match existing LINE members in PHP using the same canonical phone rule.
            $matches = [];
            foreach ($db->query("SELECT member_id,phone FROM members WHERE phone IS NOT NULL AND phone<>''")->fetchAll() as $row) {
                try { if (ServiceFlow::phone($row['phone']) === $phone) $matches[] = (int)$row['member_id']; }
                catch (InvalidArgumentException $e) { /* Invalid historical numbers do not become identities. */ }
            }
            if (count($matches) > 1) throw new RuntimeException('เบอร์นี้มีข้อมูลซ้ำ กรุณาให้พนักงานตรวจสอบ หรือเลือกบุคคลทั่วไป');
            $id = $matches[0] ?? null;
            if (!$id) {
                if (!$register) throw new RuntimeException('ไม่สามารถผูกสมาชิกได้ กรุณาตรวจเบอร์ เลือกสมัครสมาชิก หรือสั่งแบบบุคคลทั่วไป');
                $name = trim($name);
                if (!$consent || mb_strlen($name) < 1 || mb_strlen($name) > 100) throw new RuntimeException('กรุณาระบุชื่อและยินยอมบันทึกชื่อ/เบอร์เพื่อสมัครสมาชิก');
                $db->prepare('INSERT INTO members(line_user_id,display_name,phone) VALUES (NULL,?,?)')->execute([$name,$phone]);
                $id = (int)$db->lastInsertId();
                $db->prepare("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,detail,ip_address) VALUES (NULL,'phone_member_registered','member',?,?,?)")->execute([(string)$id,json_encode(['consent'=>'membership_only','phone_suffix'=>substr($phone,-4)],JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR'] ?? null]);
            }
            $db->prepare('UPDATE member_phone_accounts SET member_id=? WHERE phone=?')->execute([$id,$phone]);
        }
        $stmt = $db->prepare('SELECT is_suspended FROM members WHERE member_id=?');
        $stmt->execute([$id]);
        $suspended = $stmt->fetchColumn();
        if ($suspended === false || (int)$suspended === 1) throw new RuntimeException('ไม่สามารถใช้สมาชิกนี้ได้ กรุณาติดต่อพนักงานหรือเลือกบุคคลทั่วไป');
        return (int)$id;
    }
}
