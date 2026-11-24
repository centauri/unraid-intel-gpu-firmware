<?php
declare(strict_types=1);
namespace IntelGpuFirmware;

/** Informational history only; the separate flash journal remains authoritative. */
final class History {
    public static function key(array $card): string {
        $identity=[];
        foreach(['bdf','vendor','device','subvendor','subdevice','revision'] as $field)
            $identity[$field]=$card[$field]??'';
        return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
    }
    private static function device(array &$data,array $card): string {
        $key=self::key($card);
        if(!isset($data['cards'][$key])) {
            $identity=array_intersect_key($card,array_flip(['bdf','vendor','device','subvendor','subdevice','revision']));
            $data['cards'][$key]=['identity'=>$identity,'name'=>$card['name']??('Intel GPU '.($card['device']??'')),
                'events'=>[]];
        }
        if(isset($card['name']))$data['cards'][$key]['name']=$card['name'];
        return $key;
    }
    private static function event(array &$device,array $event): void {
        foreach($device['events'] as $i=>$old) {
            if($old['id']===$event['id']) {$device['events'][$i]=$event;return;}
        }
        $device['events'][]=$event;
        // Bound USB storage; keep the most recent 100 observations/checks/updates per slot identity.
        usort($device['events'],fn($a,$b)=>strcmp($a['at'],$b['at']));
        if(count($device['events'])>100) {
            $device['events']=array_slice($device['events'],-100);
            $device['older_events_pruned']=true;
        }
    }
    public static function read(Store $store): array {
        $data=$store->get('history',['schema'=>1,'cards'=>[]]);
        if(($data['schema']??null)!==1 || !is_array($data['cards']??null))
            throw new Blocked('Firmware history could not be read. The update journal is separate and has been retained.');
        // Display/import an existing journal, including updates made before history was introduced.
        $j=$store->get('flash');$plan=$j['plan']??[];
        if(isset($plan['id'],$plan['bdf'],$plan['identity'],$plan['from']['text'],$plan['to']['text'],$j['started_at'])) {
            $card=['bdf'=>$plan['bdf'],...$plan['identity']];$key=self::device($data,$card);
            self::event($data['cards'][$key],['id'=>'update-'.$plan['id'],'kind'=>'update',
                'at'=>$j['started_at'],'from'=>$plan['from']['text'],'to'=>$plan['to']['text'],
                'state'=>$j['state']??'needs_attention','verified_at'=>$j['verified_at']??null,
                'package'=>$plan['package']??null]);
        }
        return $data;
    }
    public static function capture(Engine $engine): void {
        $before=$engine->persistent->get('history');$data=self::read($engine->persistent);
        $inventory=$engine->runtime->get('inventory');$check=$engine->runtime->get('check');
        foreach($inventory['cards']??[] as $card) {
            if(!isset($card['firmware']['text']) || ($card['capability']??'')!=='inspect')continue;
            $key=self::device($data,$card);$device=&$data['cards'][$key];
            $snapshot=['gsc'=>$card['firmware']['text'],'components'=>$card['components']??[]];
            // Repeated scans/status refreshes do not write the USB drive or add duplicate rows.
            if(($device['last_observation']['versions']??null)!==$snapshot) {
                $observation=['at'=>$inventory['scanned_at'],'versions'=>$snapshot];
                $device['last_observation']=$observation;
                self::event($device,['id'=>'seen-'.hash('sha256',json_encode($observation)),
                    'kind'=>'observed',...$observation]);
            }
            if(($check['eligible']??false) && ($check['bdf']??null)===$card['bdf']
                && ($check['plan']['fingerprint']??'')===Engine::fingerprint($card)) {
                $p=$check['plan'];$signature=hash('sha256',json_encode([$p['from'],$p['to'],$p['package']]));
                $existing=array_filter($device['events'],fn($e)=>$e['id']==='available-'.$signature);
                if(!$existing)self::event($device,['id'=>'available-'.$signature,'kind'=>'available',
                    'at'=>$check['checked_at'],'from'=>$p['from']['text'],'to'=>$p['to']['text'],'package'=>$p['package']]);
            }
            unset($device);
        }
        if($data!==$before)$engine->persistent->put('history',$data);
    }
}
