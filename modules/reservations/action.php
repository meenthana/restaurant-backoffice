<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_RESERVATIONS);
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Reservation.php';
require_once __DIR__ . '/../../classes/TableSession.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireLogin();
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location:index.php');exit;}
Security::verifyCsrf();
$action=$_POST['action']??'';$id=(int)($_POST['id']??0);
try{
 if($action==='create'){Reservation::create($_POST,Auth::id());}
 elseif($action==='checkin'){Reservation::changeStatus($id,'checked_in');}
 elseif($action==='cancel'){Reservation::changeStatus($id,'cancelled');}
 elseif($action==='no_show'){Reservation::changeStatus($id,'no_show');}
 elseif($action==='seat'){
   $r=Reservation::find($id);if(!$r||$r['status']!=='checked_in')throw new Exception('ลูกค้าต้องเช็กอินก่อนจัดโต๊ะ');
   $session=TableSession::openMultiple($_POST['table_ids']??[],(int)$r['guest_count'],(int)Auth::id(),null,$id,'จองโดย '.$r['guest_name']);
   header('Location:../orders/create.php?table_session_id='.$session);exit;
 }
 header('Location:index.php');
}catch(Throwable $e){header('Location:index.php?error='.urlencode($e->getMessage()));}
exit;

