-- v3.0 บัตร 2 ประเภท + เมนูตามสั่ง (DIY) ที่แคชเชียร์ + คลังคำหมายเหตุสำหรับสั่งด้วยเสียง (รันหลัง v2.13)
-- รันซ้ำได้ ไม่ลบข้อมูลเดิม

-- 1) ประเภทบัตรคิว: kiosk = บัตรตู้ (เมนูจานเดียว) · counter = บัตรอาหารตามสั่ง (สั่งกับพนักงาน)
ALTER TABLE queue_cards
  ADD COLUMN IF NOT EXISTS card_type ENUM('kiosk','counter') NOT NULL DEFAULT 'kiosk' AFTER card_no;
