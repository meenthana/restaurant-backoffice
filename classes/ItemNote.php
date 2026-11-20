<?php
// หมายเหตุต่อจาน (เช่น ไม่เผ็ด ไข่ดาวไม่สุก) ร้านแก้คำที่ใช้บ่อยได้ที่หน้าตั้งค่าร้าน
require_once __DIR__ . '/AppSetting.php';

class ItemNote
{
    public const MAX_LEN = 120;
    public const DEFAULT_KEYWORDS = "ไม่เผ็ด\nเผ็ดน้อย\nเผ็ดมาก\nไข่ดาวไม่สุก\nไข่ดาวสุก\nไม่ใส่ผัก\nไม่ใส่ถั่วฝักยาว\nไม่ใส่ผงชูรส\nแยกน้ำ\nข้าวน้อย\nข้าวเยอะ";

    // คำหมายเหตุที่ใช้บ่อย (ปุ่มลัดที่ตู้ และคำที่ระบบเสียงรับเป็นหมายเหตุ)
    public static function keywords(): array
    {
        $raw = AppSetting::get('item_note_keywords', self::DEFAULT_KEYWORDS) ?? '';
        $list = [];
        foreach (preg_split('/[\r\n,]+/u', $raw) as $w) {
            $w = self::clean($w);
            if ($w !== '' && !in_array($w, $list, true)) $list[] = $w;
        }
        return $list;
    }

    public static function clean(?string $note): string
    {
        $note = preg_replace('/[\x00-\x1F\x7F<>]/u', '', (string) $note);
        $note = trim(preg_replace('/\s+/u', ' ', $note));
        return mb_substr($note, 0, self::MAX_LEN);
    }
}
