<?php
require_once __DIR__ . '/AppSetting.php';
// โปรไฟล์เครื่องพิมพ์ต่อจุดใช้งาน (การเชื่อมต่อจริงทำในเบราว์เซอร์ด้วย assets/js/printer.js)
final class PrinterSettings {
    public const STATIONS = ['cashier'=>'Cashier']; // ตู้ลูกค้าไม่พิมพ์เอง (ลูกค้าเลือกรับ/ไม่รับใบเสร็จ)
    public const CONNECTIONS = ['usb'=>'USB','bluetooth'=>'Bluetooth','serial'=>'Serial / COM','system'=>'เครื่องพิมพ์ของระบบ'];
    public const PAPERS = ['58'=>'58 มม.','80'=>'80 มม.','a4'=>'A4'];
    public const MODELS = ['thermal'=>'เครื่องพิมพ์ใบเสร็จตั้งโต๊ะ','portable'=>'เครื่องพิมพ์ใบเสร็จพกพา','office'=>'เครื่องพิมพ์สำนักงาน','other'=>'อื่น ๆ'];
    // ค่าเดิมที่เคยเป็นชื่อรุ่น แปลงเป็นประเภท
    private const OLD_MODELS = ['unspecified'=>'thermal','xprinter_xp80'=>'thermal','aiyin'=>'portable'];
    public static function defaults(string $station): array {
        return ['station_name'=>'Cashier 1','model'=>'thermal','connection'=>'system','device_name'=>'','device_id'=>'','paper_width'=>'80','document'=>'receipt_queue','notes'=>'','updated_at'=>null];
    }
    public static function get(string $station): array {
        self::station($station);
        $saved=json_decode(AppSetting::get('printer_profile_'.$station,'{}'),true);
        $p=array_replace(self::defaults($station),is_array($saved)?$saved:[]);
        $p['model']=self::OLD_MODELS[$p['model']] ?? $p['model'];
        if(!isset(self::MODELS[$p['model']])) $p['model']='thermal';
        if(!isset(self::PAPERS[$p['paper_width']])) $p['paper_width']='80';
        if(!isset(self::CONNECTIONS[$p['connection']])) $p['connection']='system';
        return $p;
    }
    public static function validate(array $input): array {
        $values=[];
        foreach(['station_name'=>60,'device_name'=>100,'device_id'=>100,'notes'=>300] as $key=>$limit){
            if(!is_string($input[$key]??'')) throw new InvalidArgumentException('ข้อมูลช่องข้อความไม่ถูกต้อง');
            $value=trim((string)($input[$key]??''));
            if(mb_strlen($value,'UTF-8')>$limit) throw new InvalidArgumentException('ข้อความยาวเกินกำหนด');
            $values[$key]=$value;
        }
        if($values['station_name']==='') throw new InvalidArgumentException('กรุณาระบุชื่อจุดใช้งาน');
        foreach(['model'=>array_keys(self::MODELS),'connection'=>array_keys(self::CONNECTIONS),'paper_width'=>array_map('strval',array_keys(self::PAPERS)),'document'=>['receipt_queue','queue_only','receipt_only']] as $key=>$allowed){
            if(!is_string($input[$key]??null)||!in_array($input[$key],$allowed,true)) throw new InvalidArgumentException('ตัวเลือกเครื่องพิมพ์ไม่ถูกต้อง');
            $values[$key]=$input[$key];
        }
        return $values;
    }
    public static function save(string $station,array $input,?int $userId): void {
        self::station($station); $values=self::validate($input); $values['updated_at']=gmdate('c');
        $db=getDB(); $db->beginTransaction();
        try{
            AppSetting::setMany(['printer_profile_'.$station=>json_encode($values,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)],$userId);
            $db->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,detail) VALUES(?,?,?,?,?)')->execute([$userId,'printer_profile_saved','settings',$station,'บันทึกค่าเครื่องพิมพ์ '.$values['connection'].' '.$values['paper_width']]);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    // ค่าที่ส่งให้ JavaScript (ไม่มีข้อมูลลับ)
    public static function forJs(string $station): array {
        $p=self::get($station);
        return ['connection'=>$p['connection'],'device_id'=>$p['device_id'],'device_name'=>$p['device_name'],'paper'=>$p['paper_width']];
    }
    private static function station(string $station): void {
        if(!array_key_exists($station,self::STATIONS)) throw new InvalidArgumentException('จุดใช้งานไม่ถูกต้อง');
    }
}