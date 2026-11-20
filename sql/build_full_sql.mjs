import fs from 'node:fs/promises';
const base = await fs.readFile(new URL('./schema.sql', import.meta.url), 'utf8');
const migration = await fs.readFile(new URL('./migration_v2_complete.sql', import.meta.url), 'utf8');
const tableMigration = await fs.readFile(new URL('./migration_v2_1_tables.sql', import.meta.url), 'utf8');
const memberMigration = await fs.readFile(new URL('./migration_v2_2_members.sql', import.meta.url), 'utf8');
const pinMigration = await fs.readFile(new URL('./migration_v2_3_pin_login.sql', import.meta.url), 'utf8');
const imageMigration = await fs.readFile(new URL('./migration_v2_4_menu_images.sql', import.meta.url), 'utf8');
const soldOutMigration = await fs.readFile(new URL('./migration_v2_5_sold_out.sql', import.meta.url), 'utf8');
const reorderMigration = await fs.readFile(new URL('./migration_v2_6_reorder_point.sql', import.meta.url), 'utf8');
const marker = '-- หลัง Import ไฟล์นี้ ให้ Import migration_v2_complete.sql ต่อทันที';
const cleanBase = base.includes(marker) ? base.slice(0, base.indexOf(marker)).trimEnd() : base.trimEnd();
const banner = '\n\n-- ============================================================\n-- ส่วนขยาย V2 (รวมไว้สำหรับติดตั้งใหม่ในไฟล์เดียว)\n-- ============================================================\n';
const tableBanner = '\n\n-- ============================================================\n-- ส่วนอัปเดต V2.1 : การจัดการโต๊ะ\n-- ============================================================\n';
const memberBanner = '\n\n-- ============================================================\n-- ส่วนอัปเดต V2.2 : สมาชิกและคะแนน\n-- ============================================================\n';
const pinBanner = '\n\n-- ============================================================\n-- ส่วนอัปเดต V2.3 : PIN Quick Login\n-- ============================================================\n';
const imageBanner = '\n\n-- ============================================================\n-- ส่วนอัปเดต V2.4 : รูปภาพเมนู\n-- ============================================================\n';
const soldOutBanner = '\n\n-- ============================================================\n-- ส่วนอัปเดต V2.5 : ปิดขายเมนูชั่วคราว\n-- ============================================================\n';
const reorderBanner = '\n\n-- ============================================================\n-- ส่วนอัปเดต V2.6 : Reorder Point\n-- ============================================================\n';
await fs.writeFile(new URL('./install_v2_full.sql', import.meta.url), cleanBase + banner + migration + tableBanner + tableMigration + memberBanner + memberMigration + pinBanner + pinMigration + imageBanner + imageMigration + soldOutBanner + soldOutMigration + reorderBanner + reorderMigration, 'utf8');

// Keep fresh installations aligned with the service-flow code.
for (const file of ['migration_v2_7_selfservice.sql','migration_v2_8_stock_abc.sql','migration_v2_9_service_flow.sql']) {
 await fs.appendFile(new URL('./install_v2_full.sql', import.meta.url), '\n\n-- '+file+'\n'+await fs.readFile(new URL('./'+file,import.meta.url),'utf8'),'utf8');
}
