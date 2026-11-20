<?php
// วัดความแม่นยำการแยกคำสั่งเสียง (ระดับข้อความที่ถอดเสียงแล้ว) จากชุดทดสอบ voice_cases.json
// ใช้บนเครื่องพัฒนาเท่านั้น: php tests/voice_eval.php [ไฟล์คลาส] [ชื่อคลาส] [--holdout] [--fails]
// ต้องโหลดเมนูตัวอย่างจาก tests/voice_seed_menu.sql เข้าฐานข้อมูลทดสอบก่อน
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args  = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$file  = $args[0] ?? __DIR__ . '/../classes/VoiceOrderParser.php';
$class = $args[1] ?? 'VoiceOrderParser';
require_once $file;
$set = in_array('--holdout', $argv, true) ? 'voice_cases_holdout.json' : 'voice_cases.json';
$cases = json_decode(file_get_contents(__DIR__ . '/' . $set), true);

function norm(array $items): array {
    $out = [];
    foreach ($items as $it) {
        $addons = $it['addons']; sort($addons);
        $notes = $it['notes']; sort($notes);
        $out[] = $it['menu'] . '|' . $it['qty'] . '|' . implode(',', $addons) . '|' . implode(',', $notes);
    }
    sort($out);
    return $out;
}

$groups = [];
$tot = ['cases' => 0, 'exact' => 0, 'menu_tp' => 0, 'menu_fp' => 0, 'menu_fn' => 0, 'noise_cases' => 0, 'noise_false_items' => 0];
$fails = [];
foreach ($cases as $c) {
    $r = $class::parse($c['text']);
    $got = array_map(fn($it) => [
        'menu' => $it['menu_name'], 'qty' => (int) $it['quantity'],
        'addons' => array_column($it['addons'], 'name'),
        'notes' => $it['notes'] ?? [],
    ], $r['items']);
    $ok = norm($got) === norm($c['expect']);
    $g = $c['group'];
    $groups[$g] ??= ['n' => 0, 'ok' => 0];
    $groups[$g]['n']++; $tot['cases']++;
    if ($ok) { $groups[$g]['ok']++; $tot['exact']++; }
    else $fails[] = ['text' => $c['text'], 'expect' => norm($c['expect']), 'got' => norm($got)];
    // ความแม่นยำระดับเมนู+จำนวน (ไม่นับตัวเลือก/หมายเหตุ)
    $e = array_map(fn($x) => $x['menu'] . '|' . $x['qty'], $c['expect']);
    $o = array_map(fn($x) => $x['menu'] . '|' . $x['qty'], $got);
    foreach ($o as $x) { $i = array_search($x, $e, true); if ($i !== false) { $tot['menu_tp']++; unset($e[$i]); } else $tot['menu_fp']++; }
    $tot['menu_fn'] += count($e);
    if (!$c['expect']) { $tot['noise_cases']++; $tot['noise_false_items'] += count($got); }
}
$p = $tot['menu_tp'] / max(1, $tot['menu_tp'] + $tot['menu_fp']);
$rcl = $tot['menu_tp'] / max(1, $tot['menu_tp'] + $tot['menu_fn']);
printf("ถูกทั้งประโยค %d/%d (%.1f%%)\n", $tot['exact'], $tot['cases'], 100 * $tot['exact'] / $tot['cases']);
printf("เมนู+จำนวน precision %.1f%% recall %.1f%%\n", 100 * $p, 100 * $rcl);
printf("ประโยคที่เป็นเสียงคุยล้วน %d ประโยค ใส่ตะกร้าผิด %d รายการ\n", $tot['noise_cases'], $tot['noise_false_items']);
foreach ($groups as $g => $v) printf("  %-14s %d/%d\n", $g, $v['ok'], $v['n']);
if (in_array('--fails', $argv, true)) foreach ($fails as $f) echo "\n✗ {$f['text']}\n  คาด: " . implode(' ; ', $f['expect']) . "\n  ได้: " . implode(' ; ', $f['got']) . "\n";
echo json_encode(['total' => $tot, 'groups' => $groups], JSON_UNESCAPED_UNICODE) . "\n";
