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

    // คำพูดที่เป็น "หมายเหตุถึงครัว": [ที่ได้ยิน, หมายเหตุที่บันทึก]
    // ถ้าคำที่ได้ยินมีชื่อตัวเลือกเสริมอยู่ (เช่น ไข่ดาวเยิ้ม) ชื่อนั้นถูกเก็บไว้ให้เลือกตัวเลือกเสริมต่อ
    private const NOTE_ALIASES = [
        ['ไข่ดาวไม่สุก', 'ไข่ดาวไม่สุก'], ['ไข่ดาวเยิ้มๆ', 'ไข่ดาวไม่สุก'], ['ไข่ดาวเยิ้ม', 'ไข่ดาวไม่สุก'],
        ['ไข่ดาวสุกๆ', 'ไข่ดาวสุก'], ['ไข่ดาวสุก', 'ไข่ดาวสุก'], ['ไข่ดาวกรอบๆ', 'ไข่ดาวกรอบ'], ['ไข่ดาวกรอบ', 'ไข่ดาวกรอบ'],
        ['ไข่เยิ้มๆ', 'ไข่ไม่สุก'], ['ไข่เยิ้ม', 'ไข่ไม่สุก'], ['ไข่ไม่สุก', 'ไข่ไม่สุก'], ['ไข่สุกๆ', 'ไข่สุก'],
        ['เยิ้มๆ', 'ไข่ไม่สุก'], ['เยิ้ม', 'ไข่ไม่สุก'], ['ยางมะตูม', 'ไข่ไม่สุก'], ['ไม่สุก', 'ไข่ไม่สุก'],
        ['ไม่เผ็ดเลย', 'ไม่เผ็ด'], ['ไม่เอาเผ็ด', 'ไม่เผ็ด'], ['ไม่ต้องเผ็ด', 'ไม่เผ็ด'], ['ไม่ใส่พริก', 'ไม่เผ็ด'],
        ['ไม่เอาพริก', 'ไม่เผ็ด'], ['ไม่เผ็ด', 'ไม่เผ็ด'],
        ['เผ็ดนิดหน่อย', 'เผ็ดน้อย'], ['เผ็ดนิดนึง', 'เผ็ดน้อย'], ['เผ็ดนิดเดียว', 'เผ็ดน้อย'], ['เผ็ดน้อย', 'เผ็ดน้อย'],
        ['พริกน้อย', 'เผ็ดน้อย'],
        ['เผ็ดมากๆ', 'เผ็ดมาก'], ['เผ็ดจัด', 'เผ็ดมาก'], ['เผ็ดๆ', 'เผ็ดมาก'], ['เผ็ดมาก', 'เผ็ดมาก'], ['พริกเยอะ', 'เผ็ดมาก'],
        ['ไม่เอาผัก', 'ไม่ใส่ผัก'], ['ไม่ใส่ผัก', 'ไม่ใส่ผัก'],
        ['ไม่เอาผงชูรส', 'ไม่ใส่ผงชูรส'], ['ไม่ใส่ผงชูรส', 'ไม่ใส่ผงชูรส'], ['ไม่ใส่ชูรส', 'ไม่ใส่ผงชูรส'],
    ];

    // คำท้ายที่ตัดออกจากหมายเหตุแบบ "ไม่ใส่..." เช่น "ไม่ใส่หอมนะครับ" -> "ไม่ใส่หอม"
    private const NOTE_TAIL = ['ครับ', 'ค่ะ', 'คะ', 'นะ', 'ด้วย', 'จ้า', 'หน่อย', 'เลย'];

    // ================================================================

    // แยกคำพูดเป็นรายการ: เมนู + จำนวน + ตัวเลือกเสริม + หมายเหตุ
    // ท่อนที่ไม่ตรงเมนู ตัวเลือก หรือหมายเหตุใด ๆ ถือเป็นเสียงรบกวน (เช่น คนคุยกันข้างหลัง) และไม่ใส่ตะกร้า
    public static function parse(string $transcript): array
    {
        $threshold = (float) AppSetting::get('voice_match_threshold', '0.62');
        $menus  = self::menuIndex();
        $addons = self::addonIndex();
        $noteRules = self::noteRules($addons);

        $normalized = self::normalize($transcript);
        $segments = [];
        foreach (self::segment($normalized) as $seg) {
            foreach (self::splitByMenus(preg_replace('/\s+/u', '', $seg), $menus, $addons) as $part) $segments[] = $part;
        }

        $items = [];
        $ignored = [];

        foreach ($segments as $segment) {
            if (self::strip($segment) === '') continue;

            [$notes, $rest] = self::extractNotes($segment, $noteRules);
            [$qty, $rest] = self::extractQuantity($rest);
            $match = self::bestMatch($rest, $menus, $addons);

            if ($match === null || $match['score'] < $threshold) {
                // ท่อนต่อท้าย เช่น "| ไข่ดาวด้วย" หรือ "| ไม่เผ็ดนะ" เป็นของเมนูก่อนหน้า
                $last = count($items) - 1;
                if ($last >= 0) {
                    $extra = self::findAddons($rest, $addons, $items[$last]['menu_id']);
                    $have = array_column($items[$last]['addons'], 'addon_id');
                    foreach ($extra as $x) {
                        if (!in_array($x['addon_id'], $have, true)) $items[$last]['addons'][] = $x;
                    }
                    $more = array_merge($notes, self::freeNotes($rest, $addons));
                    if ($more) $items[$last]['notes'] = array_values(array_unique(array_merge($items[$last]['notes'], $more)));
                    if ($extra || $more) continue;
                    // "ไม่เอาไข่ดาว" ต่อท้าย = ไม่เลือกตัวเลือกนั้น
                    if (preg_match('/^(' . implode('|', self::NEGATIONS) . ')/u', self::strip($rest))) continue;
                }
                $ignored[] = $segment;
                continue;
            }

            $menuId = (int) $match['row']['menu_id'];
            // ตัดชื่อเมนูออกก่อนหาตัวเลือกเสริม กัน "ข้าวไข่เจียว" ได้ตัวเลือก "ไข่เจียว" ซ้ำ
            $addonText = isset($match['cand']) ? str_replace($match['cand'], ' ', preg_replace('/\s+/u', '', $rest)) : $rest;
            $found = self::findAddons($addonText, $addons, $menuId);
            $items[] = [
                'menu_id'       => $menuId,
                'menu_name'     => $match['row']['name'],
                'price'         => (float) $match['row']['price'],
                'quantity'      => $qty,
                'addons'        => $found,
                'notes'         => array_values(array_unique(array_merge($notes, self::freeNotes($rest, $addons)))),
                'confidence'    => round($match['score'], 3),
                // คะแนนกลาง ๆ = เดาได้แต่ไม่มั่นใจ ให้หน้าจอไฮไลต์ไว้ให้คนตรวจ
                'needs_confirm' => $match['score'] < 0.78,
                'segment'       => trim($segment),
            ];
        }

        foreach ($items as &$it) {
            $it['notes'] = self::refineNotes($it['notes'], $it['addons']);
            $it['note'] = mb_substr(implode(', ', $it['notes']), 0, 120);
        }
        unset($it);

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
            'transcript'  => $transcript,
            'normalized'  => $normalized,
            'items'       => array_values($items),
            'unmatched'   => $ignored,
            'ignored'     => $ignored,
        ];
    }

    // ================================================================

    // กฎหมายเหตุ: คำพูดในตัว + คำที่ร้านตั้งไว้ในหลังบ้าน (เรียงคำยาวก่อน)
    private static function noteRules(array $addons): array
    {
        $rules = [];
        foreach (self::NOTE_ALIASES as [$heard, $note]) $rules[$heard] = $note;
        require_once __DIR__ . '/ItemNote.php';
        foreach (ItemNote::keywords() as $kw) {
            $key = preg_replace('/\s+/u', '', self::normalize($kw));
            if ($key !== '' && !isset($rules[$key])) $rules[$key] = $kw;
        }
        $out = [];
        foreach ($rules as $heard => $note) {
            $keep = '';
            foreach ($addons as $a) {
                if (mb_strpos($heard, $a['row']['name']) !== false) { $keep = $a['row']['name']; break; }
            }
            $out[] = ['heard' => $heard, 'note' => $note, 'keep' => $keep];
        }
        usort($out, fn($x, $y) => mb_strlen($y['heard']) <=> mb_strlen($x['heard']));
        return $out;
    }

    private static function extractNotes(string $segment, array $rules): array
    {
        $notes = [];
        foreach ($rules as $r) {
            if (mb_strpos($segment, $r['heard']) === false) continue;
            $notes[] = $r['note'];
            $segment = str_replace($r['heard'], $r['keep'] !== '' ? ' ' . $r['keep'] . ' ' : ' ', $segment);
        }
        return [array_values(array_unique($notes)), trim(preg_replace('/\s+/u', ' ', $segment))];
    }

    // "ไม่ใส่หอม" / "ไม่เอาถั่ว" ที่ไม่ใช่ตัวเลือกเสริม -> หมายเหตุ
    private static function freeNotes(string $text, array $addons): array
    {
        $text = preg_replace('/\s+/u', '', $text);
        $notes = [];
        if (!preg_match_all('/(ไม่ใส่|ไม่เอา)([\x{0E00}-\x{0E7F}]{2,12})/u', $text, $m, PREG_SET_ORDER)) return [];
        foreach ($m as $hit) {
            $what = $hit[2];
            foreach ($addons as $a) {
                $p = mb_strpos($what, $a['row']['name']);
                if ($p !== false) $what = mb_substr($what, 0, $p);
            }
            foreach (self::NEGATIONS as $neg) {
                $p = mb_strpos($what, $neg);
                if ($p !== false) $what = mb_substr($what, 0, $p);
            }
            do {
                $before = $what;
                foreach (self::NOTE_TAIL as $t) {
                    if ($what !== '' && mb_substr($what, -mb_strlen($t)) === $t) $what = mb_substr($what, 0, -mb_strlen($t));
                }
            } while ($what !== $before);
            if (mb_strlen($what) >= 2 && !in_array($what, ['แล้ว', 'อะไร', 'ครับ', 'ค่ะ'], true)) $notes[] = 'ไม่ใส่' . $what;
        }
        return $notes;
    }

    // "ไข่ไม่สุก" + เลือกไข่ดาว -> "ไข่ดาวไม่สุก"
    private static function refineNotes(array $notes, array $addons): array
    {
        $hasFried = false;
        foreach ($addons as $a) if (mb_strpos($a['name'], 'ไข่ดาว') !== false) $hasFried = true;
        $out = [];
        foreach ($notes as $n) {
            if ($hasFried && $n === 'ไข่ไม่สุก') $n = 'ไข่ดาวไม่สุก';
            if ($hasFried && $n === 'ไข่สุก') $n = 'ไข่ดาวสุก';
            if (!in_array($n, $out, true)) $out[] = $n;
        }
        return $out;
    }

    // ประโยคเดียวที่พูดหลายจานติดกัน เช่น "กะเพราหมูสับไข่ดาวผัดไทยกุ้งสด" -> ตัดตามตำแหน่งชื่อเมนู
    private static function splitByMenus(string $text, array $menus, array $addons): array
    {
        // ชื่อที่ตรงกับตัวเลือกเสริม (เช่น "ไข่เจียว") ไม่นับเป็นจุดเริ่มจานใหม่
        $addonNames = array_map(fn($a) => $a['row']['name'], $addons);
        $hits = [];
        foreach ($menus as $m) {
            foreach ($m['candidates'] as $cand) {
                if (mb_strlen($cand) < 4 || in_array($cand, $addonNames, true)) continue;
                $offset = 0;
                while (($pos = mb_strpos($text, $cand, $offset)) !== false) {
                    $hits[] = ['pos' => $pos, 'len' => mb_strlen($cand)];
                    $offset = $pos + 1;
                }
            }
        }
        if (count($hits) < 2) return [$text];
        // ชื่อยาวชนะ และไม่ให้ทับกัน
        usort($hits, fn($a, $b) => $b['len'] <=> $a['len'] ?: $a['pos'] <=> $b['pos']);
        $taken = [];
        foreach ($hits as $h) {
            foreach ($taken as $t) {
                if ($h['pos'] < $t['pos'] + $t['len'] && $t['pos'] < $h['pos'] + $h['len']) continue 2;
            }
            $taken[] = $h;
        }
        if (count($taken) < 2) return [$text];
        usort($taken, fn($a, $b) => $a['pos'] <=> $b['pos']);
        $parts = [];
        $cuts = array_column($taken, 'pos');
        $cuts[0] = 0;
        foreach ($cuts as $i => $c) {
            $len = isset($cuts[$i + 1]) ? $cuts[$i + 1] - $c : null;
            $parts[] = $len === null ? mb_substr($text, $c) : mb_substr($text, $c, $len);
        }
        return array_values(array_filter($parts, fn($p) => trim($p) !== ''));
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

    private static function bestMatch(string $segment, array $menus, array $addons = []): ?array
    {
        $compact = preg_replace('/[\s0-9]/u', '', $segment);
        // 1) ชื่อเมนูอยู่ในประโยคตรง ๆ (ชื่อยาวสุดชนะ) แม้มีคำอื่นปนอยู่
        $best = null;
        foreach ($menus as $m) {
            foreach ($m['candidates'] as $cand) {
                if (mb_strlen($cand) >= 3 && mb_strpos($compact, $cand) !== false
                    && ($best === null || mb_strlen($cand) > $best['len'])) {
                    $best = ['row' => $m['row'], 'score' => 1.0, 'len' => mb_strlen($cand), 'cand' => $cand];
                }
            }
        }
        if ($best) return $best;

        // 2) ไม่ตรงเป๊ะ: ตัดชื่อตัวเลือกเสริมออกก่อน แล้วเทียบความคล้ายทั้งท่อนและทีละช่วง
        foreach ($addons as $a) $compact = str_replace($a['row']['name'], '', $compact);
        $needle = self::strip($compact);
        if ($needle === '') return null;

        foreach ($menus as $m) {
            $score = 0.0;
            foreach ($m['candidates'] as $cand) {
                $s = self::similarity($needle, $cand);
                if (mb_strlen($needle) >= 3 && mb_strpos($cand, $needle) !== false) $s = max($s, 0.92);
                // มีคำอื่นปนหน้า/หลัง: เทียบเฉพาะช่วงที่ยาวใกล้ชื่อเมนู
                $len = mb_strlen($cand);
                $n = mb_strlen($needle);
                if ($n > $len + 1) {
                    for ($w = max(2, $len - 1); $w <= $len + 1; $w++) {
                        for ($i = 0; $i + $w <= $n; $i++) {
                            $s = max($s, self::similarity(mb_substr($needle, $i, $w), $cand) * 0.95);
                        }
                    }
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
        // เมนูตามสั่ง (DIY) สั่งกับพนักงานเท่านั้น ไม่รับจากเสียงที่ตู้
        require_once __DIR__ . '/Menu.php';
        $rows = $db->query('SELECT menu_id, name, category, price' . (Menu::diyReady() ? ', is_diy' : '') . ' FROM menu WHERE is_active = 1')->fetchAll();
        $rows = array_values(array_filter($rows, fn($r) => empty($r['is_diy'])));

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
        // ตัวเลือกที่ผูกไว้เฉพาะเมนูตามสั่ง (เช่น หมูสับ ไก่) ไม่ใช้ตีความเสียงที่ตู้
        $orderable = array_map(fn($m) => (int) $m['row']['menu_id'], self::menuIndex());
        $out = [];
        foreach ($rows as $r) {
            $ids = $map[(int) $r['addon_id']] ?? [];
            if ($ids && !array_intersect($ids, $orderable)) continue;
            $out[] = ['row' => $r, 'menu_ids' => $ids];
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
            $key = $it['menu_id'] . '|' . $addonKey . '|' . ($it['note'] ?? '');
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
