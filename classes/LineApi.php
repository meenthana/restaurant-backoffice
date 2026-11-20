<?php
// ส่งข้อความแจ้งเตือนทาง LINE
require_once __DIR__ . '/../config/line_loader.php';

class LineApi
{
    // สร้าง URL สำหรับพาลูกค้าไปหน้า Login ของ LINE พร้อม state กัน CSRF
    public static function getLoginUrl(string $state): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => LINE_LOGIN_CHANNEL_ID,
            'redirect_uri'  => LINE_LOGIN_REDIRECT_URI,
            'state'         => $state,
            'scope'         => 'profile openid',
        ] + (in_array(LINE_BOT_PROMPT, ['normal', 'aggressive'], true) ? ['bot_prompt' => LINE_BOT_PROMPT] : []));
        return 'https://access.line.me/oauth2/v2.1/authorize?' . $params;
    }

    // เอา authorization code ที่ได้กลับมาจาก callback ไปแลก access token
    public static function exchangeCodeForToken(string $code): array
    {
        $response = self::post('https://api.line.me/oauth2/v2.1/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => LINE_LOGIN_REDIRECT_URI,
            'client_id'     => LINE_LOGIN_CHANNEL_ID,
            'client_secret' => LINE_LOGIN_CHANNEL_SECRET,
        ], ['Content-Type: application/x-www-form-urlencoded']);

        if (!isset($response['access_token'])) {
            throw new Exception('แลก LINE access token ไม่สำเร็จ: ' . ($response['error_description'] ?? 'ไม่ทราบสาเหตุ'));
        }

        return $response;
    }

    // ดึงข้อมูลโปรไฟล์ (User ID, ชื่อที่แสดง) จาก access token
    public static function getProfile(string $accessToken): array
    {
        $response = self::get('https://api.line.me/v2/profile', [
            'Authorization: Bearer ' . $accessToken,
        ]);

        if (!isset($response['userId'])) {
            throw new Exception('ดึงโปรไฟล์ LINE ไม่สำเร็จ');
        }

        return $response;
    }

    // ส่งข้อความแจ้งเตือนหาสมาชิกผ่าน LINE Messaging API (Push Message)
    public static function pushMessage(string $lineUserId, string $text): bool
    {
        if (!LINE_API_ENABLED || LINE_MESSAGING_CHANNEL_ACCESS_TOKEN === '' || trim($lineUserId) === '') {
            return false;
        }

        try {
            $res = self::post('https://api.line.me/v2/bot/message/push', [
                'to'       => $lineUserId,
                'messages' => [['type' => 'text', 'text' => $text]],
            ], [
                'Content-Type: application/json',
                'Authorization: Bearer ' . LINE_MESSAGING_CHANNEL_ACCESS_TOKEN,
            ], true);
            // ส่งสำเร็จ LINE ตอบ {} ถ้าผิดพลาดจะมี message เช่น ลูกค้ายังไม่ได้เพิ่มเพื่อน
            if (isset($res['message'])) {
                error_log('LINE push message failed: ' . $res['message']);
                return false;
            }
            return true;
        } catch (Exception $e) {
            error_log('LINE push message failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function post(string $url, array $data, array $headers, bool $asJson = false): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $asJson ? json_encode($data) : http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $raw = curl_exec($ch);

        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("เชื่อมต่อ LINE API ไม่สำเร็จ: $err");
        }
        curl_close($ch);

        return json_decode($raw, true) ?? [];
    }

    private static function get(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $raw = curl_exec($ch);

        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("เชื่อมต่อ LINE API ไม่สำเร็จ: $err");
        }
        curl_close($ch);

        return json_decode($raw, true) ?? [];
    }
}
