<?php
declare(strict_types=1);
namespace IntelGpuFirmware;

/** Shared by the inventory, Docker authorization endpoint and libvirt hook. */
final class GpuAccess {
    public static function path(string $path,array $target): bool {
        $path=rtrim($path,'/');if($path==='')$path='/';
        $resolved=realpath($path);if($resolved!==false)$path=$resolved;
        foreach($target['nodes'] as $node) {
            if($path==='/' || $node===$path || str_starts_with($node,$path.'/'))return true;
            $s=@stat($path);$n=@stat($node);
            if($s && $n && ($s['mode']&0170000)===0020000 && $s['rdev']===$n['rdev'])return true;
        }
        // Access to host sysfs or VFIO can rebind the GPU, including its audio function.
        if($path==='/sys')return true;
        return str_contains($path,'/sys/bus/pci') || str_contains($path,'/sys/devices');
    }
    public static function docker(array $c,array $target): array {
        $h=$c['HostConfig']??[];$why=[];
        if($h['Privileged']??false)$why[]='Privileged: access to all host devices';
        foreach($h['Devices']??[] as $d)if(self::path($d['PathOnHost']??'', $target))$why[]='Device: '.$d['PathOnHost'];
        foreach($c['Mounts']??[] as $m)if(self::path($m['Source']??'', $target))$why[]='Mount: '.$m['Source'];
        // DeviceRequests/CDI and custom cgroup rules are conservatively included.
        foreach($h['DeviceRequests']??[] as $d)if(!in_array(strtolower($d['Driver']??''),['nvidia','amd'],true))$why[]='Intel or unspecified GPU/CDI device request';
        foreach($h['DeviceCgroupRules']??[] as $rule) {
            if(preg_match('/^([ac])\s+(\*|[0-9]+):(\*|[0-9]+)\s+[rwm]+$/',$rule,$m)) {
                foreach($target['numbers']??[] as [$major,$minor])if(($m[2]==='*'||(int)$m[2]===$major)&&($m[3]==='*'||(int)$m[3]===$minor))$why[]='Device cgroup rule: '.$rule;
            } else $why[]='Unrecognized device cgroup rule';
        }
        if(!in_array($h['Runtime']??'runc',['','runc','io.containerd.runc.v2'],true))$why[]='Custom container runtime';
        return array_values(array_unique($why));
    }
    public static function vm(string $xml,array $target): array {
        if(stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false)throw new Blocked('VM XML entities are unsupported.');
        $prev=libxml_use_internal_errors(true);
        try {$x=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET);if(!$x)throw new Blocked('Cannot inspect VM XML.');}
        finally {libxml_clear_errors();libxml_use_internal_errors($prev);}
        $why=[];
        foreach($x->xpath('//hostdev/source/address')?:[] as $a) {
            if(!isset($a['bus'],$a['slot']))continue;
            $slot=sprintf('%04x:%02x:%02x',intval((string)($a['domain']??'0'),0),intval((string)$a['bus'],0),intval((string)$a['slot'],0));
            if($slot===substr($target['bdf'],0,-2))$why[]='PCI passthrough: '.$slot;
        }
        foreach($x->xpath('//@rendernode | //hostdev/source/char/@path | //filesystem/source/@dir')?:[] as $p)
            if(self::path((string)$p,$target))$why[]='GPU/device path: '.(string)$p;
        foreach($x->xpath('//graphics/gl | //graphics[@type="egl-headless"]')?:[] as $g)
            if(!isset($g['rendernode']) && (string)($g['enable']??'yes')!=='no')$why[]='Automatic host OpenGL device selection';
        foreach($x->xpath('//*[local-name()="commandline"]/*/@value')?:[] as $a) {
            $arg=(string)$a;
            if(str_contains($arg,$target['bdf'])||str_contains($arg,substr($target['bdf'],5)))$why[]='GPU PCI address in custom QEMU arguments';
            foreach($target['nodes'] as $node)if(str_contains($arg,$node))$why[]='GPU device in custom QEMU arguments';
            if(str_contains($arg,'egl-headless')||str_contains($arg,'gl=on'))$why[]='Host OpenGL in custom QEMU arguments';
        }
        if($x->xpath('//hostdev[@type="mdev"]'))$why[]='Mediated GPU device';
        return array_values(array_unique($why));
    }
}

/** Fixed local Docker socket; never accepts a remote URL or a shell command. */
class DockerApi {
    public function call(string $method,string $path,?array $body=null): array {
        if(!in_array($method,['GET','POST'],true) || !preg_match('~^/[a-zA-Z0-9_./?=&%-]+$~D',$path))throw new Blocked('Invalid Docker operation.');
        $f=@stream_socket_client('unix:///var/run/docker.sock',$errno,$error,5);
        if(!$f)throw new Blocked('Docker API is unavailable.');
        stream_set_timeout($f,90);$data=$body===null?'':json_encode($body,JSON_THROW_ON_ERROR);
        $request="$method $path HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\nContent-Type: application/json\r\nContent-Length: ".strlen($data)."\r\n\r\n".$data;
        $offset=0;while($offset<strlen($request)){$n=fwrite($f,substr($request,$offset));if(!$n){fclose($f);throw new Blocked('Docker request failed.');}$offset+=$n;}
        $raw=stream_get_contents($f,16777217);$meta=stream_get_meta_data($f);fclose($f);
        if($meta['timed_out']||strlen($raw)>16777216||!str_contains($raw,"\r\n\r\n"))throw new Blocked('Docker response incomplete.');
        [$head,$out]=explode("\r\n\r\n",$raw,2);
        if(!preg_match('~^HTTP/1\.[01] ([0-9]{3})~',$head,$m))throw new Blocked('Invalid Docker response.');
        $code=(int)$m[1];
        if($code<200||$code>=300)throw new Blocked('Docker operation failed ('.$code.'): '.substr($out,0,350));
        return trim($out)===''?[]:json_decode($out,true,64,JSON_THROW_ON_ERROR);
    }
}

final class StartPolicy {
    public static function active(array $s): bool {return $s && ($s['state']??'')!=='complete';}
    public static function docker(array $request,array $s,callable $inspect): bool {
        $method=$request['RequestMethod']??'';
        // The actual Engine JSON tag is RequestUri (despite RequestURI in prose docs).
        $raw=$request['RequestUri']??$request['RequestURI']??'';
        if(!is_string($raw)||!str_starts_with($raw,'/')||!in_array($method,['GET','HEAD','OPTIONS','POST','PUT','DELETE','PATCH'],true))return false;
        if(isset($request['RequestUri'],$request['RequestURI'])&&$request['RequestUri']!==$request['RequestURI'])return false;
        $uri=rawurldecode(explode('?', $raw,2)[0]);
        $uri=preg_replace('~^/v[0-9]+\.[0-9]+/~','/',$uri);
        parse_str(explode('?', $raw,2)[1]??'',$query);
        if($uri==='/info'&&($query['igf-maintenance-probe']??'')==='1')return false;
        if(!self::active($s) || ($s['state']??'')==='restoring')return true;
        if(in_array($method,['GET','HEAD','OPTIONS'],true))return true;
        if($s['mapping_uncertain']??false)
            return (bool)preg_match('~^/containers/[^/]+/(stop|kill|wait|logs)$~',$uri);
        // Freeze creation/configuration entry points during the short maintenance window.
        // This avoids trusting client JSON parsing, CDI resolution or a new orchestrator.
        if(preg_match('~^/(containers/create|containers/prune|services|swarm|nodes|plugins|grpc|build|session)(/|$)~',$uri))return false;
        if(preg_match('~^/containers/([^/]+)(?:/([^/]+))?$~',$uri,$m)) {
            $op=$m[2]??'remove';
            if(in_array($op,['stop','kill','wait','logs'],true))return true;
            $c=$inspect('/containers/'.rawurlencode($m[1]).'/json');
            if(GpuAccess::docker($c,$s['target']))return false;
            // Legacy API clients could include HostConfig in a start request.
            if(in_array($op,['start','restart'],true) && !in_array($request['RequestBody']??'',['','e30=','bnVsbA=='],true))return false;
            return true;
        }
        if(preg_match('~^/exec/([^/]+)/start$~',$uri,$m)) {
            $e=$inspect('/exec/'.rawurlencode($m[1]).'/json');
            return !GpuAccess::docker($inspect('/containers/'.rawurlencode($e['ContainerID']).'/json'),$s['target']);
        }
        return (bool)preg_match('~^/(images|networks|volumes|auth|distribution)(/|$)~',$uri);
    }
    public static function vm(string $op,string $xml,array $s): bool {
        // Never reject reconnect/release: libvirt could kill an existing unrelated VM.
        if(!in_array($op,['prepare','start','restore','migrate','attach'],true)||!self::active($s)||($s['state']??'')==='restoring')return true;
        if($s['mapping_uncertain']??false)return false;
        if(preg_match('~<name>igf-guard-probe-[a-f0-9]{12}</name>~',$xml))return false;
        return !GpuAccess::vm($xml,$s['target']);
    }
}
