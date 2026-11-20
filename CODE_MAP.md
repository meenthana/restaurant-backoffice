# แผนที่โค้ด — ระบบสั่งอาหารด้วยตนเองและจัดการงานครัว

ทุกไฟล์ .php มีบรรทัดที่ 2 บอกว่าไฟล์นั้นทำอะไร เปิดไฟล์ดูบรรทัดบนสุดได้เลย

## โครงสร้างโฟลเดอร์

| โฟลเดอร์ | คืออะไร |
|---|---|
| `kiosk/` | หน้าตู้สั่งอาหารบนแท็บเล็ต จอคิวลูกค้า หน้าติดตามสถานะ |
| `online/` | หน้าสั่งออนไลน์ของลูกค้า |
| `modules/` | หน้าหลังร้านของพนักงาน แยกโฟลเดอร์ละ 1 เมนู |
| `classes/` | ตรรกะหลักของระบบ (คำนวณ บันทึกฐานข้อมูล) — หน้าใน modules เรียกใช้ไฟล์พวกนี้ |
| `api/` | จุดที่ JavaScript เรียกข้อมูล (แปลงเสียงเป็นออเดอร์) และจุดรับแจ้งผลจาก Omise/SCB |
| `config/` | ตั้งค่าฐานข้อมูล เวลา เปิด/ปิดโมดูล ช่องทางชำระเงิน LINE |
| `includes/` | หัว/ท้ายของทุกหน้าหลังร้าน (เมนูด้านบน) |
| `assets/css/` | `style.css` หลังร้าน · `customer.css` ตู้และออนไลน์ · `printers.css` หน้าเครื่องพิมพ์ |
| `assets/js/` | `kiosk.js` ตู้สั่ง · `voice-order.js` สั่งด้วยเสียง · `kitchen-voice.js` ครัวสั่งด้วยเสียง · `printer.js` เชื่อมเครื่องพิมพ์ |
| `sql/` | ไฟล์สร้าง/อัปเดตฐานข้อมูล (`install_hosting.sql` = ติดตั้งครบในไฟล์เดียว) |

## ลำดับการทำงานของออเดอร์ (ทุกช่องทาง: จ่ายก่อน)

```
หยิบบัตรคิว ──> สั่ง ──> จ่ายเงิน ──> เข้าครัว ──> ครัวทำ ──> อาหารพร้อม ──> ส่งมอบ + คืนบัตร (ปิดออเดอร์)
```

| ขั้น | หน้า | โค้ดหลัก (classes/…) |
|---|---|---|
| ลูกค้ากรอกรหัสบัตรคิว 4 หลัก | `kiosk/index.php` + `kiosk.js` → `kiosk/api.php?action=card` | `QueueCard::check()` |
| ลูกค้าสั่งที่ตู้ (ผูกบัตรกับออเดอร์) | `kiosk/api.php?action=place` | `KioskOrder::place()` → `QueueCard::attach()` |
| พนักงานรับหน้าร้าน | `orders/create.php` → `orders/save.php` | `Order::create()` |
| สั่งออนไลน์ | `online/index.php` → `online/order.php` | `KioskOrder::place()` |
| เลขคิว K/W/O (เริ่มใหม่ทุกวัน) | — | `KioskOrder::assignQueue()`, `queueLabel()` |
| สแกนจ่าย ระบบตรวจยอดเอง | `kiosk/api.php?action=gateway`, `online/pay.php` | `PaymentGateway::start()` / `refresh()` → `ScbGateway` (หลัก) หรือ `OmiseGateway` (สำรอง) |
| รับเงินสดที่เคาน์เตอร์ | `pos/index.php` → `pos/checkout.php` | `KioskOrder::acceptCounterPayment()` |
| บันทึกการจ่าย → ส่งเข้าครัว | — | `Payment::record()` → `KitchenTicket::sendPaidOrderToKitchen()` |
| จอครัว | `kitchen/index.php` → `kitchen/action.php` | `KitchenTicket::changeStatus()` |
| จอคิวลูกค้า | `kiosk/board.php` | — |
| ส่งมอบ / ปิดออเดอร์ (บัตรว่างเอง) | `pos/kiosk_action.php` | `Order::changeStatus($id, 'closed')` |
| ใบเสร็จ / พิมพ์ | `pos/receipt.php` + `assets/js/printer.js`, `receipts/index.php` | `PrinterSettings` |
| คืนเงิน (ยกเลิกออกจากคิวด้วย) | `refunds/` | `KioskOrder::cancelRefunded()` |
| ยกเลิกออเดอร์ค้างจ่ายอัตโนมัติ | (ทำงานตอนเปิดหน้า pos) | `KioskOrder::expireUnpaid()` |

**ช่องทางสแกนจ่าย** เลือกอัตโนมัติใน `PaymentGateway`: เปิด SCB ใช้ SCB → ไม่มีใช้ Omise → ไม่มีทั้งคู่ใช้ QR พร้อมเพย์ร้าน + ส่งสลิป (ปิดบนเว็บจริง) → ถ้าใช้ไม่ได้เลย ตู้ปิดปุ่มสแกนจ่าย และหน้าออนไลน์ให้จ่ายที่ร้าน

## บัตรคิว

- ตาราง `queue_cards` (เลขบัตร + รหัส 4 หลัก) · ออเดอร์เก็บ `queue_card_id`, `queue_card_no`
- บัตร "ใช้งานอยู่" = มีออเดอร์สถานะ pending / active / ready ผูกอยู่ ไม่ต้องกดคืนบัตรในระบบ
- กรอกรหัสผิด 8 ครั้งใน 5 นาที ตู้ล็อกชั่วคราว
- จัดการ / พิมพ์บัตร: **ตั้งค่าร้าน → บัตรคิว** (`modules/settings/queue_cards.php`, `queue_cards_print.php`)

## สั่งด้วยเสียง

1. `assets/js/voice-order.js` — เปิดไมค์ แปลงเสียงเป็นข้อความด้วย Web Speech API (ภาษาไทย `th-TH`)
2. `api/parse_voice_order.php` — รับข้อความ ส่งต่อให้ตัวแปลง
3. `classes/VoiceOrderParser.php` — ตัดคำ หาจำนวน จับคู่ชื่อเมนูด้วยความคล้าย (`similarity()`)
   - เกณฑ์ความคล้ายขั้นต่ำ 0.62 (`voice_match_threshold` ใน `store_settings`) ต่ำกว่า 0.78 ให้ลูกค้ากดยืนยันก่อน
   - ชื่อเรียกอื่นของเมนู (เช่น "กระเพรา") เพิ่มได้ในตาราง `menu_aliases`
4. ครัว: `assets/js/kitchen-voice.js` เช่น "เริ่มทำ เค 6" แล้วพูด "ยืนยัน"

## สต๊อก (Soft Stock)

- `config/features.php` → `FEATURE_AUTO_STOCK = false` : เก็บสูตร ล็อต ของเสีย ปรับยอด ได้ครบ แต่ **ไม่ตัดสต๊อกและไม่ปิดเมนูอัตโนมัติ** พนักงานกด "หมด" ที่หน้าเมนูเอง
- รายงานกำไร / ความนิยมเมนูใช้ **ต้นทุนตามสูตร** = ปริมาณในสูตร × ราคาซื้อวัตถุดิบล่าสุด (`RevenueEngine::recipeCostPerServing()`)
- เปลี่ยนเป็น `true` = กลับไปตัดสต๊อกแบบ FEFO ตอนครัวเริ่มทำ (`OrderFulfillment`, `StockBatch::deductFEFO()`)

## อยากแก้อะไร ไปที่ไหน

| อยากแก้ | ไฟล์ |
|---|---|
| สี / ฟอนต์หลังร้าน | `assets/css/style.css` (ตัวแปรสีอยู่บนสุด) |
| สี / หน้าตาตู้และออนไลน์ | `assets/css/customer.css` |
| เมนูด้านบน (navbar) | `includes/header.php` |
| ชื่อหัวข้อ + คำอธิบายสั้นของแต่ละหน้า | หาบรรทัด `pageHeader('…', '…')` ในไฟล์หน้านั้น |
| ข้อความ/ปุ่มบนตู้สั่งอาหาร | `kiosk/index.php` (หน้าตา) + `assets/js/kiosk.js` (การทำงาน) |
| ลำดับสถานะออเดอร์ที่อนุญาต | `classes/Order.php` → `ALLOWED_TRANSITIONS` |
| สิทธิ์ของแต่ละบทบาท | บรรทัด `Auth::requireAnyRole([...])` บนสุดของแต่ละหน้า |
| เบอร์พร้อมเพย์ / ชื่อร้าน / เวลารับอาหาร / เวลายกเลิกค้างจ่าย | หน้า **ตั้งค่าร้าน** (ไม่ต้องแก้โค้ด) |
| บัตรคิว (เพิ่ม ปิด เปลี่ยนรหัส พิมพ์) | หน้า **ตั้งค่าร้าน → บัตรคิว** |
| เครื่องพิมพ์ใบเสร็จ | หน้า **ตั้งค่าร้าน → เครื่องพิมพ์** (`modules/settings/printer.php`) |
| รหัสฐานข้อมูล / คีย์ SCB / คีย์ Omise | `config/private.php` (สร้างจาก `private.example.php` ห้ามขึ้น Git) |
| เปิด/ปิดระบบโต๊ะ จองโต๊ะ คิวรอโต๊ะ / ตัดสต๊อกอัตโนมัติ | `config/features.php` |
| เวลาไทย (PHP + MySQL) | `config/database.php` |
| รายงานรายได้ / ความนิยมเมนู | `classes/RevenueEngine.php` |

## บทบาทผู้ใช้

| บทบาท | ทำอะไรได้ |
|---|---|
| `owner` เจ้าของร้าน | ทุกอย่าง รวมรายงานรายได้และบัญชีพนักงาน |
| `manager` ผู้จัดการ | งานร้านทั้งหมด ตั้งค่าร้าน คืนเงิน (ยกเว้นรายงานรายได้บางหน้าและบัญชีพนักงาน) |
| `cashier` แคชเชียร์ | รับเงิน ส่งมอบ ใบเสร็จ |
| `kitchen` ครัว | จอครัว เปลี่ยนสถานะใบงาน |
| `staff` พนักงานทั่วไป | ทั้งงานแคชเชียร์และครัว (ร้านเล็กคนเดียวทำหลายหน้าที่) |

## ตารางฐานข้อมูลที่ใช้บ่อย

| ตาราง | เก็บอะไร |
|---|---|
| `orders` | ออเดอร์ (สถานะ ช่องทาง เลขคิว บัตรคิว จ่ายแล้วหรือยัง) |
| `order_items`, `order_item_addons` | รายการอาหาร + ตัวเลือกเสริม |
| `order_service_details` | ประเภทคิว (หนึ่งจาน/หลายจาน) การรับใบเสร็จ ลิงก์ติดตาม |
| `payments` | การจ่ายเงิน / คืนเงิน |
| `scb_payments`, `gateway_payments`, `gateway_events` | รายการสแกนจ่ายผ่าน SCB / Omise |
| `queue_cards` | บัตรคิว |
| `kitchen_tickets` | ใบงานครัว |
| `menu`, `addons`, `menu_addons`, `menu_aliases` | เมนู ตัวเลือกเสริม ชื่อเรียกอื่น |
| `recipe` | สูตร: เมนูหนึ่งใช้วัตถุดิบอะไรเท่าไร |
| `ingredients`, `stock_batches`, `stock_transactions` | วัตถุดิบ ล็อต และประวัติ |
| `members`, `member_phone_accounts` | สมาชิก (LINE หรือเบอร์โทรที่ตู้) |
| `store_settings` | ค่าตั้งค่าร้าน |
| `users` | บัญชีพนักงาน |
| `audit_logs` | ประวัติการทำรายการ (คืนเงิน ยกเลิก จัดการบัตร) |

## สถานะออเดอร์

| ค่าในฐานข้อมูล | ความหมาย |
|---|---|
| `pending` + ยังไม่จ่าย | รอชำระเงิน |
| `pending` + จ่ายแล้ว | เข้าครัวแล้ว รอครัวรับ |
| `active` | ครัวกำลังทำ |
| `ready` | อาหารพร้อม รอลูกค้ารับ |
| `closed` | ส่งมอบแล้ว จบ (บัตรคิวว่าง) |
| `cancelled` | ยกเลิก (บัตรคิวว่าง) |
| `no_show` | ลูกค้าออนไลน์ไม่มารับ |

## ขึ้นเว็บโฮสติ้ง (InfinityFree)

- เว็บจริง: `https://thanas.freehosting.dev/restaurant-backoffice/`
- โฮสต์ฟรีปิด `putenv()` → `config/env.php` อ่านบรรทัด `putenv('KEY=VALUE')` จาก `config/private.php` เอง
- โฮสต์อยู่ต่างประเทศ → `config/database.php` ตั้งเวลาไทยทั้ง PHP และ MySQL
- โฮสต์ฟรีไม่รับการแจ้งผลจากภายนอก (webhook) → ระบบถามสถานะการจ่ายเองทุกไม่กี่วินาที (`PaymentGateway::refresh()`)
- โฟลเดอร์ `config/`, `classes/`, `sql/` และไฟล์ `.sql` ถูกบล็อกด้วย `.htaccess`

## โมดูลที่ปิดไว้ (ไม่ได้ลบ)

`modules/tables/`, `modules/reservations/`, `modules/queue/`, `online/reserve.php` และคลาส `TableSession`, `RestaurantTable`, `Reservation`, `QueueEntry` — ปิดด้วย `config/features.php`
