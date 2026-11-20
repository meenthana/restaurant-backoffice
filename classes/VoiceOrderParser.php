<?php
// แปลงข้อความจากเสียงพูดเป็นรายการเมนู + จำนวน
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AppSetting.php';

// VoiceOrderParser — แปลง "ข้อความภาษาไทยที่ถอดจากเสียงพูด" ให้เป็นรายการออเดอร์
class VoiceOrderParser
{
    // คำที่ตัดทิ้งได้เพราะไม่มีผลต่อความหมายของออเดอร์
    private const FILLER = [
        'ขอ', 'เอา', 'สั่ง', 'ครับ', 'ค่ะ', 'คะ', 'ค่า', 'จ้า', 'นะ', 'น่ะ',
        'หน่อย', 'ด้วย', 'ที', 'อะ', 'อ่ะ', 'แล้ว', 'คือ', 'เอ่อ', 'อืม',
    ];

    // คำที่ถอดเสียงมักสะกดเพี้ยน -> รูปที่ใช้ในฐานข้อมูล
    private const SPELLING = [
        'กระเพรา' => 'กะเพรา', 'กระเพา' => 'กะเพรา', 'กะเพา' => 'กะเพรา',
        'กะเพรา'  => 'กะเพรา', 'กระเพราะ' => 'กะเพรา',
        'ผัดกระเพรา' => 'ผัดกะเพรา',
        'คะน้า'   => 'คะน้า',  'คะน่า' => 'คะน้า',
        'หมูกรอป' => 'หมูกรอบ', 'หมูกรอบ' => 'หมูกรอบ',
        'ใข่'     => 'ไข่',    'ไข่ดาว' => 'ไข่ดาว', 'ไข่เจียว' => 'ไข่เจียว',
        'พิเสด'   => 'พิเศษ',  'พิเศด' => 'พิเศษ',
        'ก๋วยเตี๋ยว' => 'ก๋วยเตี๋ยว', 'กวยเตี๋ยว' => 'ก๋วยเตี๋ยว',
    ];

    // คำเชื่อมที่ใช้ตัดรายการ
    private const SEPARATORS = ['แล้วก็', 'และก็', 'กับอีก', 'และ', 'กับ', ',', '　', '/'];

    // หน่วยนับที่มักตามหลังจำนวน
    private const UNITS = ['จาน', 'ที่', 'ชาม', 'ถ้วย', 'กล่อง', 'ถุง', 'แก้ว', 'ขวด', 'ชุด', 'อัน', 'ชิ้น', 'ห่อ'];

    // คำบอกจำนวนภาษาไทย
    private const THAI_NUMBERS = [
        'ยี่สิบเอ็ด' => 21, 'ยี่สิบ' => 20, 'สิบเอ็ด' => 11, 'สิบสอง' => 12, 'สิบสาม' => 13,
        'สิบสี่' => 14, 'สิบห้า' => 15, 'สิบหก' => 16, 'สิบเจ็ด' => 17, 'สิบแปด' => 18,
        'สิบเก้า' => 19, 'สิบ' => 10, 'โหล' => 12,
        'หนึ่ง' => 1, 'นึง' => 1, 'เอ็ด' => 1, 'สอง' => 2, 'สาม' => 3, 'สี่' => 4, 'ห้า' => 5,
        'หก' => 6, 'เจ็ด' => 7, 'แปด' => 8, 'เก้า' => 9,
        'โล' => 1, 'คู่' => 2,
    ];

    // คำปฏิเสธที่ทำให้ Add-on ที่ตามมา "ไม่ถูกเลือก"
    private const NEGATIONS = ['ไม่เอา', 'ไม่ใส่', 'ไม่ต้อง', 'งด', 'ไม่'];

    // ================================================================

    // transcript:string, normalized:string,
    public static function parse(string $transcript): array
    {
        $threshold = (float) AppSetting::get('voice_match_threshold', '0.62');
        $menus  = self::menuIndex();
        $addons = self::addonIndex();

        $normalized = self::normalize($transcript);
        $segments   = self::segment($normalized);

        $items = [];
        $unmatched = [];

        foreach ($segments as $segment) {
            if (self::strip($segment) === '') continue;

            [$qty, $rest] = self::extractQuantity($segment);
            $match = self::bestMatch($rest !== '' ? $rest : $segment, $menus);

            if ($match === null || $match['score'] < $threshold) {
                // เช่น "กะเพราสองจาน | ไข่ดาว" ท่อนนี้เป็นตัวเลือกเสริมของเมนูก่อนหน้า
                $last = count($items) - 1;
                $extra = $last >= 0 ? self::findAddons($segment, $addons, $items[$last]['menu_id']) : [];
                if ($extra) {
                    $have = array_column($items[$last]['addons'], 'addon_id');
                    foreach ($extra as $x) {
                        if (!in_array($x['addon_id'], $have, true)) $items[$last]['addons'][] = $x;
                    }
                    continue;
                }
                // "ไม่เอาไข่ดาว" ต่อท้าย = ไม่เลือกตัวเลือกนั้น ไม่ต้องแจ้งว่าไม่เข้าใจ
                if ($last >= 0 && preg_match('/^(' . implode('|', self::NEGATIONS) . ')/u', self::strip($segment))) {
                    continue;
                }
                $unmatched[] = $segment;
                continue;
            }

            $items[] = [
                'menu_id'       => (int) $match['row']['menu_id'],
                'menu_name'     => $match['row']['name'],
                'price'         => (float) $match['row']['price'],
                'quantity'      => $qty,
                'addons'        => self::findAddons($segment, $addons, $match['row']['menu_id']),
                'confidence'    => round($match['score'], 3),
                // คะแนนกลาง ๆ = เดาได้แต่ไม่มั่นใจ ให้หน้าจอไฮไลต์ไว้ให้คนยืนยัน
                'needs_confirm' => $match['score'] < 0.78,
                'segment'       => trim($segment),
            ];
        }

        // รวมรายการเมนูเดียวกันที่มี Add-on ชุดเดียวกัน
        $items = self::mergeDuplicates($items);

        // เมนูที่ปิดขายหรือวัตถุดิบหลักไม่พอ ไม่ใส่ตะกร้า แต่บอกผู้ใช้
        require_once __DIR__ . '/Menu.php';
        $unavailable = [];
        foreach ($items as $k => $it) {
            $menu = Menu::find($it['menu_id']);
            if ($menu && ($reason = Menu::unavailableReason($menu))) {
                $unavailable[] = $it['menu_name'] . ' (' . $reason . ')';
                unset($items[$k]);
            }
        }

        return [
            'unavailable' => $unavailable,
            'transcript' => $transcript,
            'normalized' => $normalized,
            'items'      => array_values($items),
            'unmatched'  => $unmatched,
        ];
    }

    // ================================================================

    private static function normalize(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        // รวมรูป "ํา" (นิคหิต + สระอา) ให้เป็น "ำ" ตัวเดียว
        $text = str_replace("\u{0E4D}\u{0E32}", "\u{0E33}", $text);
        foreach (self::SPELLING as $wrong => $right) {
            $text = str_replace($wrong, $right, $text);
        }
        // แปลงเลขไทย ๐-๙ เป็นอารบิก
        $text = str_replace(['๐','๑','๒','๓','๔','๕','๖','๗','๘','๙'],
                            ['0','1','2','3','4','5','6','7','8','9'], $text);
        return $text;
    }

    // ตัดคำฟุ่มเฟือยและหน่วยนับออก เหลือแต่แกนของข้อความ (ใช้ตอนเทียบชื่อเมนู)
    private static function strip(string $text): string
    {
        $text = preg_replace('/[0-9\s\.,!\?]/u', '', $text);
        $words = array_merge(self::FILLER, self::UNITS);
        $changed = true;
        while ($changed && $text !== '') {
            $changed = false;
            foreach ($words as $w) {
                if ($w === '') continue;
                $len = mb_strlen($w);
                if (mb_strpos($text, $w) === 0) {
                    $text = mb_substr($text, $len);
                    $changed = true;
                }
                if ($text !== '' && mb_substr($text, -$len) === $w) {
                    $text = mb_substr($text, 0, mb_strlen($text) - $len);
                    $changed = true;
                }
            }
        }
        return trim($text);
    }

    // ================================================================

    private static function segment(string $text): array
    {
        $marker = "\x01";
        foreach (self::SEPARATORS as $sep) {
            $text = str_replace($sep, $marker, $text);
        }
        // "สองจาน" ที่ตามด้วยตัวอักษรอื่นมักเป็นรอยต่อของรายการถัดไป
        $numberHead = implode('|', array_map(
            fn($w) => preg_quote($w, '/'),
            array_keys(self::THAI_NUMBERS)
        ));
        foreach (self::UNITS as $u) {
            $text = preg_replace(
                '/(' . preg_quote($u, '/') . ')(?!\s*(?:' . $numberHead . '|\d))(?=\s*\S)/u',
                '$1' . $marker,
                $text
            );
        }
        $parts = array_map('trim', explode($marker, $text));
        return array_values(array_filter($parts, fn($p) => $p !== ''));
    }

    // ================================================================

    private static function extractQuantity(string $segment): array
    {
        // 3.1 เลขอารบิก เช่น "2 จาน" หรือ "กะเพรา2"
        if (preg_match('/(\d+)/u', $segment, $m)) {
            $qty = max(1, min(99, (int) $m[1]));
            return [$qty, str_replace($m[0], '', $segment)];
        }

        // 3.2 คำบอกจำนวนภาษาไทย — ไล่จากคำยาวไปสั้นเพื่อให้ "สิบสอง" ชนะ "สิบ"
        foreach (self::THAI_NUMBERS as $word => $value) {
            $pos = mb_strpos($segment, $word);
            if ($pos === false) continue;

            // ต้องอยู่ติดหน่วยนับ หรืออยู่ท้ายประโยค จึงจะนับว่าเป็นจำนวน
            $after = mb_substr($segment, $pos + mb_strlen($word));
            $isQuantity = $after === '';
            foreach (self::UNITS as $u) {
                if (mb_strpos($after, $u) === 0) { $isQuantity = true; break; }
            }
            if ($isQuantity) {
                return [$value, str_replace($word, '', $segment)];
            }
        }

        return [1, $segment];
    }

    // ================================================================

    // ความคล้ายของสตริงภาษาไทยด้วย Character Bigram
    public static function similarity(string $a, string $b): float
    {
        $ga = self::bigrams($a);
        $gb = self::bigrams($b);
        if (!$ga || !$gb) return 0.0;

        $inter = count(array_intersect_key($ga, $gb));
        if ($inter === 0) return 0.0;

        $dice = (2 * $inter) / (count($ga) + count($gb));

        // Overlap ใช้ได้เฉพาะเมื่อฝั่งที่สั้นกว่ายาวพอ มิฉะนั้นคำสั้น ๆ อย่าง "หมู"
        $minSize = min(count($ga), count($gb));
        if ($minSize < 3) {
            return $dice;
        }
        $overlap = $inter / $minSize;

        return max($dice, $overlap * 0.85);
    }

    private static function bigrams(string $s): array
    {
        $len = mb_strlen($s);
        if ($len < 2) return $len === 1 ? [$s => true] : [];
        $out = [];
        for ($i = 0; $i < $len - 1; $i++) {
            $out[mb_substr($s, $i, 2)] = true;
        }
        return $out;
    }

    private static function bestMatch(string $segment, array $menus): ?array
    {
        $needle = self::strip($segment);
        if ($needle === '') return null;

        $best = null;
        foreach ($menus as $m) {
            // เทียบกับทั้งชื่อจริงและคำเรียกลัดทุกตัว เลือกคะแนนที่ดีที่สุด
            $score = 0.0;
            foreach ($m['candidates'] as $cand) {
                $s = self::similarity($needle, $cand);
                // ถ้าสิ่งที่ลูกค้าพูดเป็นส่วนหนึ่งของชื่อเมนูตรง ๆ ให้โบนัส
                if (mb_strlen($needle) >= 3 && mb_strpos($cand, $needle) !== false) {
                    $s = max($s, 0.92);
                }
                if ($s > $score) $score = $s;
            }
            if ($best === null || $score > $best['score']) {
                $best = ['row' => $m['row'], 'score' => $score];
            }
        }
        return $best;
    }

    // ================================================================

    private static function findAddons(string $segment, array $addons, int $menuId): array
    {
        $found = [];
        foreach ($addons as $a) {
            if (!empty($a['menu_ids']) && !in_array($menuId, $a['menu_ids'], true)) {
                continue; // Add-on ตัวนี้ไม่ได้เปิดให้เมนูนี้
            }
            $name = $a['row']['name'];
            $pos = mb_strpos($segment, $name);
            if ($pos === false) {
                // เผื่อพูดไม่ตรงเป๊ะ ใช้ความคล้ายช่วย แต่ตั้งเกณฑ์สูงกว่าเมนู
                if (self::similarity(self::strip($segment), $name) < 0.85) continue;
                $pos = 0;
            }
            // ดูคำ 8 ตัวอักษรก่อนหน้า ถ้ามีคำปฏิเสธให้ข้าม
            $before = mb_substr($segment, max(0, $pos - 8), min(8, $pos));
            $negated = false;
            foreach (self::NEGATIONS as $neg) {
                if (mb_strpos($before, $neg) !== false) { $negated = true; break; }
            }
            if ($negated) continue;

            $found[] = [
                'addon_id'    => (int) $a['row']['addon_id'],
                'name'        => $name,
                'extra_price' => (float) $a['row']['extra_price'],
            ];
        }
        return $found;
    }

    // ================================================================

    // เมนูที่ขายอยู่ พร้อมคำเรียกลัด และชื่อแบบตัดคำนำหน้า "ข้าว/ผัด" ออก
    private static function menuIndex(): array
    {
        $db = getDB();
        $rows = $db->query('SELECT menu_id, name, category, price FROM menu WHERE is_active = 1')->fetchAll();

        $aliases = [];
        try {
            foreach ($db->query('SELECT menu_id, alias FROM menu_aliases')->fetchAll() as $a) {
                $aliases[(int) $a['menu_id']][] = $a['alias'];
            }
        } catch (Exception $e) {
            // ยังไม่ได้รันไมเกรชัน v2.7 ก็ยังทำงานได้ เพียงแต่ไม่มีคำเรียกลัด
        }

        $index = [];
        foreach ($rows as $r) {
            $id = (int) $r['menu_id'];
            $cands = [$r['name']];
            // ลูกค้ามักพูดสั้น เช่น "กะเพราหมู" แทน "ข้าวกะเพราหมูสับ"
            foreach (['ข้าว', 'ผัด', 'ข้าวผัด'] as $prefix) {
                if (mb_strpos($r['name'], $prefix) === 0) {
                    $short = mb_substr($r['name'], mb_strlen($prefix));
                    // ชื่อที่เหลือสั้นเกินไป (เช่น "ข้าวผัดหมู" -> "หมู") ใช้เทียบไม่ได้
                    if (mb_strlen($short) >= 4) $cands[] = $short;
                }
            }
            foreach ($aliases[$id] ?? [] as $al) {
                $cands[] = $al;
            }
            $index[] = [
                'row'        => $r,
                'candidates' => array_values(array_unique(array_filter($cands))),
            ];
        }
        return $index;
    }

    private static function addonIndex(): array
    {
        $db = getDB();
        $rows = $db->query('SELECT addon_id, name, extra_price FROM addons WHERE is_active = 1')->fetchAll();
        $map = [];
        foreach ($db->query('SELECT menu_id, addon_id FROM menu_addons')->fetchAll() as $ma) {
            $map[(int) $ma['addon_id']][] = (int) $ma['menu_id'];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['row' => $r, 'menu_ids' => $map[(int) $r['addon_id']] ?? []];
        }
        // เรียงชื่อยาวก่อน เพื่อให้ "ไข่เจียว" ถูกจับก่อน "ไข่"
        usort($out, fn($x, $y) => mb_strlen($y['row']['name']) <=> mb_strlen($x['row']['name']));
        return $out;
    }

    private static function mergeDuplicates(array $items): array
    {
        $merged = [];
        foreach ($items as $it) {
            $addonKey = implode(',', array_column($it['addons'], 'addon_id'));
            $key = $it['menu_id'] . '|' . $addonKey;
            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += $it['quantity'];
                $merged[$key]['confidence'] = max($merged[$key]['confidence'], $it['confidence']);
            } else {
                $merged[$key] = $it;
            }
        }
        return $merged;
    }

    // บันทึกไว้ดูย้อนหลังว่าระบบตีความถูกหรือผิด ใช้ประเมินความแม่นยำในรายงาน
    public static function log(string $source, ?int $userId, ?int $memberId,
                              string $transcript, array $parsed): int
    {
        try {
            $db = getDB();
            $stmt = $db->prepare(
                'INSERT INTO voice_order_logs
                    (source, user_id, member_id, transcript, parsed_json, matched_count, unmatched_count)
                 VALUES (:source, :uid, :mid, :transcript, :json, :matched, :unmatched)'
            );
            $stmt->execute([
                'source'     => in_array($source, ['staff', 'customer', 'kiosk'], true) ? $source : 'staff',
                'uid'        => $userId,
                'mid'        => $memberId,
                'transcript' => mb_substr($transcript, 0, 500),
                'json'       => json_encode($parsed['items'], JSON_UNESCAPED_UNICODE),
                'matched'    => count($parsed['items']),
                'unmatched'  => count($parsed['unmatched']),
            ]);
            return (int) $db->lastInsertId();
        } catch (Exception $e) {
            return 0; // การบันทึก log ต้องไม่ทำให้การสั่งอาหารล้ม
        }
    }
}
