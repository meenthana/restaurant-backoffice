<?php
// เปิด/ปิดบัญชีสมาชิก
require_once __DIR__ . '/../../classes/Auth.php';require_once __DIR__ . '/../../classes/Security.php';
Auth::requireAnyRole(['owner','manager']);Security::verifyCsrf();$id=(int)($_POST['id']??0);
getDB()->prepare('UPDATE members SET is_suspended=IF(is_suspended=1,0,1) WHERE member_id=:id')->execute(['id'=>$id]);
header('Location:index.php');exit;

