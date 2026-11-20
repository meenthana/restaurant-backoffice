DELETE FROM menu_addons; DELETE FROM order_item_addons; DELETE FROM order_items; DELETE FROM kitchen_tickets; DELETE FROM payments; DELETE FROM order_service_details; DELETE FROM orders; DELETE FROM recipe; DELETE FROM menu_aliases; DELETE FROM menu;
INSERT INTO menu (menu_id,name,category,price) VALUES
(1,'กะเพราหมูสับ','อาหารจานเดียว',50),(2,'กะเพราไก่','อาหารจานเดียว',50),(3,'กะเพราหมูกรอบ','อาหารจานเดียว',60),
(4,'ผัดพริกแกงหมู','อาหารจานเดียว',50),(5,'ข้าวผัดกุ้ง','อาหารจานเดียว',60),(6,'ข้าวผัดหมู','อาหารจานเดียว',50),
(7,'ผัดซีอิ๊วหมู','อาหารจานเดียว',50),(8,'ราดหน้าหมู','อาหารจานเดียว',50),(9,'ต้มยำกุ้ง','ต้ม/แกง',120),
(10,'ข้าวไข่เจียว','อาหารจานเดียว',35),(11,'ผัดไทยกุ้งสด','อาหารจานเดียว',70),(12,'คะน้าหมูกรอบ','อาหารจานเดียว',60);
INSERT INTO menu_addons (menu_id, addon_id) SELECT m.menu_id, a.addon_id FROM menu m JOIN addons a WHERE a.addon_id IN (1,2,3);
UPDATE addons SET extra_price=10 WHERE addon_id IN (1,3);
