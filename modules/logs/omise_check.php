<?php
// ตรวจหาสาเหตุ Omise "ข้อมูลรายการชำระไม่ตรงกัน" (เจ้าของ/ผู้จัดการเท่านั้น ไม่แสดงคีย์)
require_once __DIR__.'/../../classes/Auth.php';
Auth::requireAnyRole(['owner','manager']);
require_once __DIR__.'/../../classes/OmiseGateway.php';
header('Content-Type: text/html; charset=utf-8');
function e($v){return htmlspecialchars(is_scalar($v)||$v===null?var_export($v,true):json_encode($v,JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8');}
echo '<pre style="font:14px/1.5 monospace;padding:16px">';
echo 'configured=',e(OmiseGateway::configured()),'  mode=',e(OMISE_MODE),"\n\n";
$rows=getDB()->query('SELECT * FROM gateway_payments ORDER BY created_at DESC LIMIT 5')->fetchAll();
try { $list=OmiseGateway::request('/charges?order=reverse_chronological&limit=20'); }
catch(Throwable $ex){ echo 'เรียก Omise ไม่ได้: ',e($ex->getMessage()); exit; }
foreach($rows as $r){
  echo "=== order {$r['order_id']}  status={$r['status']}  charge_id=",e($r['charge_id']),"  amount_satang={$r['amount_satang']}  livemode={$r['livemode']}\n";
  $c=null;
  foreach($list['data']??[] as $x){ if(($x['metadata']['request_key']??'')===$r['request_key'] || ($r['charge_id'] && $x['id']===$r['charge_id'])){$c=$x;break;} }
  if(!$c){ echo "  ไม่พบ charge ที่ request_key ตรงกันใน 20 รายการล่าสุด\n\n"; continue; }
  $checks=[
   'object=charge'=>[$c['object']??null, ($c['object']??'')==='charge'],
   'id format'=>[$c['id']??null, (bool)preg_match('/^chrg_[a-zA-Z0-9]+$/D',$c['id']??'')],
   'amount'=>[$c['amount']??null, (int)($c['amount']??-1)===(int)$r['amount_satang']],
   'currency'=>[$c['currency']??null, strtolower($c['currency']??'')==='thb'],
   'livemode'=>[$c['livemode']??'(ไม่มี)', isset($c['livemode']) && (bool)$c['livemode']===(bool)$r['livemode']],
   'source.type'=>[$c['source']['type']??'(ไม่มี)', ($c['source']['type']??'')==='promptpay'],
   'metadata.order_id'=>[$c['metadata']['restaurant_order_id']??'(ไม่มี)', (string)($c['metadata']['restaurant_order_id']??'')===(string)$r['order_id']],
   'metadata.request_key'=>['(ซ่อน)', hash_equals($r['request_key'],(string)($c['metadata']['request_key']??''))],
   'charge_id'=>[$c['id'], !$r['charge_id'] || $r['charge_id']===$c['id']],
  ];
  foreach($checks as $k=>[$val,$ok]) echo '  ',$ok?'OK  ':'FAIL',"  $k = ",e($val),"\n";
  echo '  status=',e($c['status']??null),'  paid=',e($c['paid']??null),"\n";
  echo '  source keys=',e(array_keys($c['source']??[])),"\n\n";
}
echo '</pre>';
