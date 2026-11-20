<?php
// เลือกช่องทางสแกนจ่ายอัตโนมัติ: SCB ก่อน ถ้าไม่ได้เปิดใช้ Omise ถ้าไม่มีทั้งคู่ใช้ QR พร้อมเพย์ + ส่งสลิป
require_once __DIR__ . '/OmiseGateway.php';
require_once __DIR__ . '/ScbGateway.php';
require_once __DIR__ . '/AppSetting.php';

final class PaymentGateway
{
    public static function provider(): ?string
    {
        if (SCB_ENABLED) return 'scb';
        if (OMISE_ENABLED) return 'omise';
        return null;
    }

    public static function configured(): bool
    {
        return match (self::provider()) {
            'scb' => ScbGateway::configured(),
            'omise' => OmiseGateway::configured(),
            default => false,
        };
    }

    public static function isTest(): bool
    {
        return match (self::provider()) {
            'scb' => SCB_MODE !== 'live',
            'omise' => OMISE_MODE === 'test',
            default => false,
        };
    }

    // ส่งสลิปได้ไหม (บนเว็บจริงปิดการตรวจสลิปแบบจำลอง)
    public static function slipAllowed(): bool
    {
        require_once __DIR__ . '/SlipVerifier.php';
        return !(restaurant_env('RESTAURANT_ENV') === 'production' && SlipVerifier::mode() === 'mock');
    }

    // ตู้/ออนไลน์เปิดปุ่มสแกนจ่ายได้ไหม: มี Gateway ที่ตั้งค่าครบ หรือมีพร้อมเพย์ร้าน + ส่งสลิปได้
    public static function qrAvailable(): bool
    {
        if (self::provider()) return self::configured();
        return trim((string) AppSetting::get('promptpay_id', '')) !== '' && self::slipAllowed();
    }

    public static function start(int $orderId): array
    {
        return self::provider() === 'scb' ? ScbGateway::start($orderId) : OmiseGateway::start($orderId);
    }

    public static function refresh(int $orderId): array
    {
        return self::provider() === 'scb' ? ScbGateway::refresh($orderId) : OmiseGateway::refresh($orderId);
    }
}
