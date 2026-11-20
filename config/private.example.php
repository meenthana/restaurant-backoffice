<?php
// คัดลอกไฟล์นี้เป็น config/private.php บนเซิร์ฟเวอร์ แล้วใส่ค่าจริง (ห้ามอัปไฟล์ private.php ขึ้น Git)
putenv('RESTAURANT_ENV=production');

// ฐานข้อมูล (ดูจากหน้า MySQL Databases ของโฮสติ้ง)
putenv('RESTAURANT_DB_HOST=sqlXXX.infinityfree.com');
putenv('RESTAURANT_DB_NAME=if0_XXXXXXXX_restaurant');
putenv('RESTAURANT_DB_USER=if0_XXXXXXXX');
putenv('RESTAURANT_DB_PASS=รหัสผ่านฐานข้อมูล');

// Omise (โหมดทดสอบ) — เว้นไว้ถ้าไม่ใช้
putenv('OMISE_ENABLED=0');
putenv('OMISE_MODE=test');
putenv('OMISE_SECRET_KEY=');
putenv('OMISE_WEBHOOK_SECRET=');

// SCB QR Payment (Sandbox) — เว้นไว้ถ้าไม่ใช้
putenv('SCB_ENABLED=0');
putenv('SCB_MODE=sandbox');
putenv('SCB_API_KEY=');
putenv('SCB_API_SECRET=');
putenv('SCB_BILLER_ID=');
putenv('SCB_REF3_PREFIX=');

// LINE Login + แจ้งเตือนอาหารพร้อม — เว้น CHANNEL_ID ไว้ = ใช้ล็อกอินสาธิต
putenv('LINE_LOGIN_CHANNEL_ID=');
putenv('LINE_LOGIN_CHANNEL_SECRET=');
putenv('LINE_LOGIN_REDIRECT_URI=https://ชื่อเว็บของคุณ/restaurant-backoffice/online/callback.php');
putenv('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN=');
putenv('LINE_BOT_PROMPT=aggressive');
