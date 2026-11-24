<?php
use IntelGpuFirmware\{GpuAccess,StartPolicy,Maintenance,DockerApi,Blocked,Http};
function targetFixture(): array {return ['bdf'=>'0000:03:00.0','nodes'=>['/dev/dri/card1','/dev/dri/renderD129','/dev/mei1','/dev/vfio/12'],'numbers'=>[[226,1],[226,129],[241,1]],'identity'=>[]];}
function containerFixture(bool $gpu=true): array {
    return ['Id'=>str_repeat($gpu?'a':'b',64),'Name'=>$gpu?'/plex':'/database','State'=>['Running'=>true,'Paused'=>false],
        'HostConfig'=>['Privileged'=>false,'Devices'=>$gpu?[['PathOnHost'=>'/dev/dri/renderD129']]:[],
        'RestartPolicy'=>['Name'=>'unless-stopped','MaximumRetryCount'=>0]],'Mounts'=>[]];
}
test('GPU access distinguishes selected nodes, other GPUs, broad mounts and privileged containers',function(){
    $t=targetFixture();$c=containerFixture();ok((bool)GpuAccess::docker($c,$t));
    $c['HostConfig']['Devices']=[['PathOnHost'=>'/dev/dri/renderD128']];ok(!GpuAccess::docker($c,$t));
    $c['HostConfig']['Devices']=[['PathOnHost'=>'/dev/dri']];ok((bool)GpuAccess::docker($c,$t));
    $c['HostConfig']['Devices']=[];$c['Mounts']=[['Source'=>'/dev']];ok((bool)GpuAccess::docker($c,$t));
    $c['Mounts']=[['Source'=>'/mnt/user/appdata']];ok(!GpuAccess::docker($c,$t));
    $c['HostConfig']['Privileged']=true;ok((bool)GpuAccess::docker($c,$t));
});
test('Cgroup and device requests include Intel access without stopping an NVIDIA-only container',function(){
    $t=targetFixture();$c=containerFixture(false);
    $c['HostConfig']['DeviceRequests']=[['Driver'=>'nvidia']];ok(!GpuAccess::docker($c,$t));
    $c['HostConfig']['DeviceRequests']=[['Driver'=>'']];ok((bool)GpuAccess::docker($c,$t));
    $c['HostConfig']['DeviceRequests']=[];$c['HostConfig']['DeviceCgroupRules']=['c 226:* rwm'];ok((bool)GpuAccess::docker($c,$t));
    $c['HostConfig']['DeviceCgroupRules']=['c 226:128 rwm'];ok(!GpuAccess::docker($c,$t));
});
test('VM policy matches PCI source instead of guest addresses and detects render nodes',function(){
    $t=targetFixture();$xml='<domain><devices><hostdev><source><address domain="0x0000" bus="0x03" slot="0x00" function="0x1"/></source></hostdev></devices></domain>';
    ok((bool)GpuAccess::vm($xml,$t));ok(!GpuAccess::vm(str_replace('bus="0x03"','bus="0x04"',$xml),$t));
    ok((bool)GpuAccess::vm('<domain><devices><graphics><gl rendernode="/dev/dri/renderD129"/></graphics></devices></domain>',$t));
    ok(!GpuAccess::vm('<domain><devices><graphics><gl rendernode="/dev/dri/renderD128"/></graphics></devices></domain>',$t));
    blocks(fn()=>GpuAccess::vm('<!DOCTYPE x [<!ENTITY ext SYSTEM "file:///etc/passwd">]><domain/>',$t));
});
test('Docker start guard rejects GPU start, restart, unpause, exec and policy changes but allows unrelated workloads',function(){
    $s=['state'=>'ready','target'=>targetFixture()];$gpu=containerFixture();$other=containerFixture(false);
    $inspect=fn($path)=>str_contains($path,'/exec/')?['ContainerID'=>'gpu']:(str_contains($path,'gpu')?$gpu:$other);
    foreach(['start','restart','unpause','exec','update','rename','attach'] as $op) {
        ok(!StartPolicy::docker(['RequestMethod'=>'POST','RequestURI'=>'/v1.47/containers/gpu/'.$op],$s,$inspect));
        ok(StartPolicy::docker(['RequestMethod'=>'POST','RequestURI'=>'/containers/other/'.$op],$s,$inspect));
    }
    ok(!StartPolicy::docker(['RequestMethod'=>'POST','RequestURI'=>'/exec/123/start'],$s,$inspect));
    ok(StartPolicy::docker(['RequestMethod'=>'POST','RequestURI'=>'/containers/gpu/stop'],$s,$inspect));
    ok(StartPolicy::docker(['RequestMethod'=>'GET','RequestURI'=>'/containers/gpu/json'],$s,$inspect));
    foreach(['/containers/create','/containers/prune','/services/create','/grpc','/plugins/p/enable'] as $path)
        ok(!StartPolicy::docker(['RequestMethod'=>'POST','RequestURI'=>$path],$s,$inspect));
    ok(!StartPolicy::docker(['RequestMethod'=>'POST','RequestURI'=>'/containers/other/start','RequestBody'=>base64_encode('{"Privileged":true}')],$s,$inspect));
    foreach(['ready','restoring','complete'] as $phase)foreach(['/info','/v1.56/info'] as $path) {
        ok(!StartPolicy::docker(['RequestMethod'=>'GET','RequestUri'=>$path.'?igf-maintenance-probe=1'],['state'=>$phase,'target'=>targetFixture()],$inspect));
    }
    ok(!StartPolicy::docker(['RequestMethod'=>'POST'],$s,$inspect));
    ok(!StartPolicy::docker(['RequestMethod'=>'POST','RequestUri'=>'/containers/gpu/start'],$s,$inspect));
    ok(!StartPolicy::docker(['RequestMethod'=>'POST','RequestUri'=>'/unknown-new-gpu-api'],$s,$inspect));
});
test('VM starts and restores blocked while disconnect/reconnect cleanup cannot kill running VMs',function(){
    $s=['state'=>'needs_attention','target'=>targetFixture()];$xml='<domain><devices><graphics><gl rendernode="/dev/dri/renderD129"/></graphics></devices></domain>';
    foreach(['prepare','start','migrate','restore','attach'] as $op)ok(!StartPolicy::vm($op,$xml,$s));
    foreach(['reconnect','release','stopped'] as $op)ok(StartPolicy::vm($op,$xml,$s));
    ok(StartPolicy::vm('prepare','<domain><devices/></domain>',$s));
    $s['state']='restoring';ok(StartPolicy::vm('prepare',$xml,$s));
});
class MaintenanceDockerFixture extends DockerApi {
    public array $containers;public array $calls=[];public bool $guard=false;public bool $failStop=false;
    public function __construct(){$this->containers=['gpu'=>containerFixture(),'db'=>containerFixture(false),'off'=>containerFixture()];$this->containers['off']['Id']=str_repeat('c',64);$this->containers['off']['Name']='/off';$this->containers['off']['State']['Running']=false;}
    public function call(string $method,string $path,?array $body=null): array {
        $this->calls[]=[$method,$path,$body];
        preg_match('~^/containers/([a-f0-9]{64})/(json|update|stop|start)~',$path,$m);
        if(!$m)throw new RuntimeException('Unexpected test API call: '.$path);
        foreach($this->containers as &$c)if($c['Id']===$m[1]) {
            if($m[2]==='json')return $c;
            if($m[2]==='update'){$c['HostConfig']['RestartPolicy']=$body['RestartPolicy'];return [];}
            if($m[2]==='stop'){if($this->failStop)throw new Blocked('Simulated stop failure');$c['State']['Running']=false;return [];}
            if($m[2]==='start'){if($this->guard)throw new Blocked('IGF_GPU_MAINTENANCE');$c['State']['Running']=true;return [];}
        }
        throw new RuntimeException('Missing fixture container.');
    }
}
class MaintenanceFixture extends Maintenance {
    public bool $failGuard=false;
    public function target(string $bdf): array {return targetFixture();}
    public function inventory(array $target): array {
        $items=[];foreach($this->docker->containers as $c)if($why=GpuAccess::docker($c,$target))$items[]=['kind'=>'docker','id'=>$c['Id'],'name'=>ltrim($c['Name'],'/'),'running'=>$c['State']['Running'],'restart'=>$c['HostConfig']['RestartPolicy'],'reasons'=>$why];
        return ['items'=>$items,'blockers'=>[],'docker'=>true,'libvirt'=>false];
    }
    public function installGuards(array $s): void {if($this->failGuard)throw new Blocked('Simulated activation failure');$this->docker->guard=true;}
    protected function assertGuards(array $s): void {if(!$this->docker->guard)throw new Blocked('Guard absent');}
    protected function removeGuards(array $s): void {$this->docker->guard=false;}
}
test('Rehearsal stops only GPU users, rejects starts, restores policies and preserves previously stopped containers',function(){
    [$e,$proc]=setup();$d=new MaintenanceDockerFixture();$m=new MaintenanceFixture($e,$d);$before=$d->containers;
    $p=$m->preview('0000:03:00.0');$m->rehearse($p['id']);
    ok($d->containers===$before);ok(!$d->guard);ok(!$proc->writes);ok(!$e->persistent->get('flash'));
    ok($e->persistent->get('maintenance')['state']==='complete');
    foreach($d->calls as $call)ok(!str_contains($call[1],str_repeat('b',64)),'Unrelated database was touched');
    ok($e->runtime->get('maintenance-test')['firmware_written']===false);
});
test('Changed inventory or expired preview refuses stopping anything',function(){
    [$e]=setup();$d=new MaintenanceDockerFixture();$m=new MaintenanceFixture($e,$d);$p=$m->preview('0000:03:00.0');
    $d->containers['gpu']['State']['Running']=false;blocks(fn()=>$m->prepare($p['id']));ok(!$d->calls);
    $p=$m->preview('0000:03:00.0');$p['expires_at']=0;$e->runtime->put('workloads',$p);blocks(fn()=>$m->prepare($p['id']));ok(!$d->calls);
});
test('Guard or stop failure preserves recoverable original policies; no firmware writes',function(){
    foreach(['guard','stop'] as $failure) {
        [$e,$proc]=setup();$d=new MaintenanceDockerFixture();$m=new MaintenanceFixture($e,$d);$before=$d->containers;
        $p=$m->preview('0000:03:00.0');if($failure==='guard')$m->failGuard=true;else $d->failStop=true;
        blocks(fn()=>$m->prepare($p['id']));ok($e->persistent->get('maintenance')['state']==='needs_attention');
        $m->restore();ok($d->containers===$before);ok(!$proc->writes);
    }
});
test('Uncertain firmware outcome cannot release the start guard or restore workloads',function(){
    [$e]=setup();$d=new MaintenanceDockerFixture();$m=new MaintenanceFixture($e,$d);$p=$m->preview('0000:03:00.0');$m->prepare($p['id']);
    $e->persistent->put('flash',['state'=>'needs_attention']);blocks(fn()=>$m->restore());ok($d->guard);ok(!$d->containers['gpu']['State']['Running']);
});
test('Maintenance API requires a fresh preview identifier and explicit acknowledgement',function(){
    $csrf=str_repeat('s',32);
    foreach(['prepare','rehearse'] as $action) {
        blocks(fn()=>Http::validate('POST',['csrf_token'=>$csrf,'action'=>$action,'preview_id'=>str_repeat('a',32)],$csrf));
        ok(Http::validate('POST',['csrf_token'=>$csrf,'action'=>$action,'preview_id'=>str_repeat('a',32),'acknowledged'=>true],$csrf)['args']['preview_id']===str_repeat('a',32));
    }
});
test('Real Unix authorization server survives child exits, repeated requests and malformed bodies',function(){
    $dir=sys_get_temp_dir().'/igf-auth-'.bin2hex(random_bytes(6));mkdir($dir,0700);$socket=$dir.'/auth.sock';
    $pid=pcntl_fork();ok($pid>=0);
    if($pid===0)\IntelGpuFirmware\AuthServer::serve($socket,$dir.'/pid',fn()=>['state'=>'ready','target'=>targetFixture()],fn()=>containerFixture());
    try {
        for($i=0;$i<100&&!file_exists($socket);$i++)usleep(10000);
        $request=function(string $route,string $body)use($socket):array {
            $f=stream_socket_client('unix://'.$socket,$errno,$err,3);ok(is_resource($f));stream_set_timeout($f,3);
            fwrite($f,"POST $route HTTP/1.1\r\nHost: localhost\r\n".($body!==''?'Content-Length: '.strlen($body)."\r\n":'')."\r\n".$body);
            $raw=stream_get_contents($f);fclose($f);$parts=explode("\r\n\r\n",$raw,2);ok(count($parts)===2);
            return json_decode($parts[1],true,64,JSON_THROW_ON_ERROR);
        };
        ok($request('/Plugin.Activate','')['Implements']===['authz']);
        for($i=0;$i<40;$i++) {
            usleep(10000); // Deliver SIGCHLD while parent is back inside accept().
            ok($request('/AuthZPlugin.AuthZReq',json_encode(['RequestMethod'=>'GET','RequestUri'=>'/info']))['Allow']);
            ok(!$request('/AuthZPlugin.AuthZReq',json_encode(['RequestMethod'=>'POST','RequestUri'=>'/containers/gpu/start']))['Allow']);
        }
        ok(!$request('/AuthZPlugin.AuthZReq','{invalid')['Allow']);
        ok($request('/AuthZPlugin.AuthZRes','{}')['Allow']);
        ok(posix_kill($pid,0));
    } finally {posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}
});
