<?php
// เปิด/ปิดโมดูล โต๊ะ จองโต๊ะ คิว (true = เปิด)
const FEATURE_TABLES       = false;   // ผังโต๊ะ / เปิดโต๊ะ / บัญชีโต๊ะ
const FEATURE_RESERVATIONS = false;   // จองโต๊ะ (ทั้งหลังร้านและหน้าออนไลน์)
const FEATURE_QUEUE        = false;   // คิวรอโต๊ะ

// เรียกบนสุดของหน้าในโมดูลที่ถูกปิด ถ้าโมดูลปิดอยู่จะพาไปหน้าออเดอร์แทน
function requireFeature(bool $enabled, string $fallback = '/restaurant-backoffice/modules/orders/index.php'): void
{
    if ($enabled) return;
    header('Location: ' . $fallback);
    exit;
}

// Soft Stock: retain recipes/history; sales and kitchen never deduct or block on balances.
const FEATURE_AUTO_STOCK = false;
