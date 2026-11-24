<?php
declare(strict_types=1);
$codeRoot=$argv[1]??(__DIR__.'/../src');
require $codeRoot.'/Core.php';
require $codeRoot.'/Http.php';
require $codeRoot.'/JobWorker.php';
use IntelGpuFirmware\{Blocked,Executor,Process,Igsc,Hardware,Catalog,Store,Engine,Versions,Http};

$passed=0;
function test(string $name,callable $fn): void {
    global $passed;
    try{$fn();$passed++;echo "PASS $name\n";}
    catch(Throwable $e){fwrite(STDERR,"FAIL $name: ".$e->getMessage()."\n");exit(1);}
}
function ok(bool $value,string $why='Assertion failed'): void {if(!$value)throw new RuntimeException($why);}
function blocks(callable $fn): void {
    try{$fn();}catch(Blocked $e){return;}throw new RuntimeException('Expected a blocked operation.');
}
function v(string $s): array {return Versions::parse("Device: FW Version: $s\n");}
function card(string $vendor='1849'): array {
    return ['bdf'=>'0000:03:00.0','vendor'=>'8086','device'=>'56a5','subvendor'=>$vendor,'subdevice'=>'6004',
        'revision'=>'05','driver'=>'i915','boot_vga'=>false,'vfs'=>0,'name'=>'Intel Arc A380',
        'board_vendor'=>$vendor,'mei'=>'/dev/mei7'];
}
class TestHardware extends Hardware {
    public array $card;public bool $ready=true;public string $boot='00000000-0000-0000-0000-000000000001';
    public function __construct(){ $this->card=card(); unset($this->card['mei']); }
    public function cards(): array{return [$this->card];}
    public function bootId(): string{return $this->boot;}
    public function matchInterface(array $c,array $i): bool {
        foreach(['bdf','vendor','device','subvendor','subdevice'] as $k)if($c[$k]!==$i[$k])return false;
        return true;
    }
    public function maintenance(array $card): array{return ['ready'=>$this->ready,'reasons'=>$this->ready?[]:['GPU in use.'],'power'=>['ok'=>true,'required'=>false],'warnings'=>[]];}
}
class FakeProcess implements Executor {
    public array $writes=[];public array $interface;public string $current='DG02_2.2353';
    public string $target='DG02_2.2359';public bool $compatible=true;public bool $failWrite=false;
    public bool $wrongReadback=false;public bool $noInterface=false;public bool $failVersion=false;
    public bool $failSync=false;
    public bool $hiddenWriteError=false;public ?Closure $onSync=null;
    public function __construct(){ $this->interface=card(); }
    public function run(array $argv,int $timeout=45): array {
        if($argv[0]==='/bin/sync') {if($this->onSync)($this->onSync)();return ['code'=>$this->failSync?1:0,'out'=>'','err'=>''];}
        $args=array_slice($argv,3);$out='';$code=0;
        if(($args[0]??'')==='--version')$out="igsc version 1.3.2\nigsc library version 1.3.2.0\n";
        elseif(($args[0]??'')==='list-devices') {
            $c=$this->interface;
            $out=$this->noInterface ? "No device found\n" :
                "Device [1] '".$c['mei']."': ".$c['vendor'].':'.$c['device'].' '.$c['subvendor'].':'.$c['subdevice'].' '.$c['bdf']."\n";
        }
        elseif(($args[0]??'')==='fw'&&($args[1]??'')==='version') {
            if($this->failVersion){$code=1;$out='Unreadable';}
            else $out=(in_array('--image',$args,true)?'Image: FW Version: '.$this->target:'Device: FW Version: '.$this->current)."\n";
        }
        elseif(($args[0]??'')==='fw'&&($args[1]??'')==='hwconfig') {
            $out=in_array('--check',$args,true)?"image: hw sku: [ SOC2 ]\ndevice: hw sku: [ SOC2 ]\n":"device: hw sku: [ SOC2 ]\n";
            if(!$this->compatible && in_array('--check',$args,true))$code=1;
        }
        elseif(($args[1]??'')==='update') {
            $from=$this->current;
            $this->writes[]=$argv;
            ok($timeout===0,'Writes must not have a kill timeout.');
            ok(!in_array('--force',$args,true)&&!in_array('--allow-downgrade',$args,true));
            ok(in_array('/dev/mei7',$args,true),'Must select the enumerated MEI device.');
            $code=$this->failWrite?1:0;
            if(!$this->failWrite&&!$this->wrongReadback)$this->current=$this->target;
            $out="Image: FW Version: ".$this->target."\nDevice: FW Version: $from\nProgress: 100%\nDevice: FW Version: ".$this->current."\n";
        } else $out="Version: 1\n";
        return ['code'=>$code,'out'=>$out,'err'=>$code?'Test failure':(($args[1]??'')==='update'&&$this->hiddenWriteError?'Error: Update process failed':'')];
    }
}
function setup(): array {
    $dir=sys_get_temp_dir().'/igf-tests-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    $run=new Store($dir.'/runtime');$persistent=new Store($dir.'/persistent');
    $path='Graphics/ifwi/acm/fwcode/test.bin';mkdir($run->dir.'/images/'.dirname($path),0700,true);
    file_put_contents($run->dir.'/images/'.$path,'firmware-fixture');
    $img=['path'=>$path,'component'=>'gsc','size'=>16,'sha256'=>hash('sha256','firmware-fixture')];
    file_put_contents($dir.'/catalog.json',json_encode(['schema'=>1,'package'=>['version'=>'test'],'images'=>[$img]]));
    $proc=new FakeProcess();$hw=new TestHardware();
    $engine=new Engine(new Igsc($proc,'/test'),$hw,new Catalog($dir.'/catalog.json'),$run,$persistent,$proc,'/test');
    return [$engine,$proc,$hw,$dir,$img];
}
function plan(Engine $e): array {$r=$e->check('0000:03:00.0');ok($r['eligible']);return $r['plan'];}

test('Intel version comparison: Alchemist',fn()=>ok(Versions::compare(v('DG02_2.2359'),v('DG02_2.2353'))===1));
test('Intel version comparison: Battlemage',fn()=>ok(Versions::compare(v('BMG__31.1062'),v('BMG__31.1058'))===1));
test('Unknown future project remains parseable',fn()=>ok(v('NEW__4.123')['project']==='NEW_'));
test('Project mismatch is blocked',fn()=>blocks(fn()=>Versions::compare(v('BMG__31.1062'),v('DG02_2.2353'))));
test('Malformed and overflowing versions are blocked',function(){foreach(['','DG02_999999.1','2.2359','DG02_2.2359; rm -rf /'] as $s)blocks(fn()=>v($s));});
test('Equal and older versions never produce a plan',function(){
    foreach(['DG02_2.2353','DG02_2.2200'] as $s){[$e,$p]=setup();$p->target=$s;ok(!$e->check('0000:03:00.0')['eligible']);ok(!$p->writes);}
});
test('Read-only API refuses update and unsafe flags',function(){
    [$e,$p]=setup();foreach([['fw','update'],['fw','version','--force'],['fw','version','--allow-downgrade'],['ifr','run-test']] as $a)blocks(fn()=>$e->igsc->read($a));ok(!$p->writes);
});
test('General process executor refuses every write command before spawning',function(){
    $p=new Process();
    foreach([[['/bin/false'],0],[['/bin/false','update'],45],[['/bin/false','--force'],45],[['/bin/false','--allow-downgrade'],45]] as [$args,$timeout]) {
        try{$p->run($args,$timeout);throw new RuntimeException('Write was not blocked');}
        catch(Blocked $e){ok(str_contains($e->getMessage(),'general tool runner cannot launch firmware writes'));}
    }
    blocks(fn()=>(new Igsc($p,'/nonexistent'))->writeGsc('/dev/mei7','/nonexistent'));
});
test('Build policy gates the HTTP queue before a worker or journal is created',function(){
    [$e,$p]=setup();$plan=plan($e);
    if(\IntelGpuFirmware\WRITES_ENABLED) {
        $launched=[];
        $id=Http::queue($e,['action'=>'flash','args'=>['plan_id'=>$plan['id'],'confirmation'=>$plan['confirmation']]],
            function($id)use(&$launched){$launched[]=$id;return 0;});
        ok($launched===[$id]);ok($e->runtime->get('job')['action']==='flash');
        ok(!$p->writes);ok(!$e->persistent->get('flash'));return;
    }
    blocks(fn()=>Http::queue($e,['action'=>'flash','args'=>['plan_id'=>$plan['id'],'confirmation'=>$plan['confirmation']]]));
    ok(!$e->runtime->get('job'));ok(!$e->persistent->get('flash'));
    $e->exec=new Process();blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(!$p->writes);ok(!$e->persistent->get('flash'));
});
test('Dry run verifies the real selection path without simulated or physical writes',function(){
    [$e,$p,$h]=setup();$h->ready=false;$r=$e->dryRun($h->card['bdf']);
    ok($r['firmware_written']===false && $r['writes_enabled']===false);ok(count($r['checks'])===6);
    ok(!$r['maintenance']['ready']);ok(!$p->writes);ok(!$e->persistent->get('flash'));
    ok($e->runtime->get('dry-run')===$e->persistent->get('dry-run'));
});
test('Dynamic MEI discovery works (not mei0)',function(){[$e]=setup();ok($e->scan()['cards'][0]['mei']==='/dev/mei7');});
test('ASRock, ASUS, Sparkle, Acer, GUNNIR and unknown OEM all eligible by capability',function(){
    foreach(['1849','1043','172f','1025','1ef7','9999','8086'] as $brand) {
        [$e,$p,$h]=setup();$h->card['subvendor']=$brand;$p->interface['subvendor']=$brand;ok($e->check($h->card['bdf'])['eligible']);ok(!$p->writes);
    }
});
test('Battlemage and future family use the same capability checks',function(){
    foreach([['BMG__31.1058','BMG__31.1062'],['NEW__1.12','NEW__1.13']] as [$from,$to]){
        [$e,$p]=setup();$p->current=$from;$p->target=$to;ok($e->check('0000:03:00.0')['eligible']);ok(!$p->writes);
    }
});
test('PCI identity mismatch cannot attach another cards interface',function(){[$e,$p]=setup();$p->interface['subdevice']='9999';ok($e->scan()['cards'][0]['capability']==='inventory');});
test('Missing firmware interface stays visible without update capability',function(){[$e,$p]=setup();$p->noInterface=true;ok(count($e->scan()['cards'])===1);blocks(fn()=>plan($e));ok(!$p->writes);});
test('Unreadable live firmware never grants update capability',function(){[$e,$p]=setup();$p->failVersion=true;ok($e->scan()['cards'][0]['capability']==='inventory');ok(!$p->writes);});
test('Incompatible image produces no plan',function(){[$e,$p]=setup();$p->compatible=false;ok(!$e->check('0000:03:00.0')['eligible']);ok(!$p->writes);});
test('Tampered image is rejected',function(){[$e,$p,$h,$d,$img]=setup();file_put_contents($e->imageRoot().'/'.$img['path'],'tampered-fixture');blocks(fn()=>plan($e));ok(!$p->writes);});
test('Symlink outside cache is rejected',function(){[$e,$p,$h,$d,$img]=setup();unlink($e->imageRoot().'/'.$img['path']);file_put_contents($d.'/outside','firmware-fixture');symlink($d.'/outside',$e->imageRoot().'/'.$img['path']);blocks(fn()=>plan($e));ok(!$p->writes);});
test('Ambiguous compatible images are blocked',function(){
    [$e,$p,$h,$d,$img]=setup();$other=$img;$other['path']='Graphics/ifwi/acm/fwcode/second.bin';file_put_contents($e->imageRoot().'/'.$other['path'],'firmware-fixture');
    file_put_contents($d.'/two.json',json_encode(['schema'=>1,'package'=>['version'=>'test'],'images'=>[$img,$other]]));
    $e->catalog=new Catalog($d.'/two.json');ok(!$e->check('0000:03:00.0')['eligible']);ok(!$p->writes);
});
test('Scan and check never write firmware',function(){[$e,$p]=setup();$e->scan();plan($e);ok(!$p->writes);});
test('Maintenance blocker is rechecked after confirmation',function(){[$e,$p,$h]=setup();$plan=plan($e);$h->ready=false;blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(!$p->writes);});
test('A device change after check blocks flash',function(){[$e,$p,$h]=setup();$plan=plan($e);$p->current='DG02_2.2354';blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(!$p->writes);});
test('Image tamper after confirmation blocks flash',function(){[$e,$p,$h,$d,$img]=setup();$plan=plan($e);file_put_contents($e->imageRoot().'/'.$img['path'],'tampered-fixture');blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(!$p->writes);});
test('Expired and previous-boot plans are blocked',function(){
    [$e,$p,$h]=setup();$plan=plan($e);
    blocks(fn()=>Engine::assertPlan($plan,$plan['id'],$plan['confirmation'],$h->boot,time()+601));
    blocks(fn()=>Engine::assertPlan($plan,$plan['id'],$plan['confirmation'],'another-boot',time()));ok(!$p->writes);
});
test('Wrong plan ID or phrase cannot flash',function(){
    [$e,$p]=setup();$plan=plan($e);blocks(fn()=>$e->flash(str_repeat('0',32),$plan['confirmation']));
    blocks(fn()=>$e->flash($plan['id'],'yes'));ok(!$p->writes);
});
test('Confirmed GSC update selects correct device and requires reboot',function(){
    [$e,$p]=setup();$plan=plan($e);$e->flash($plan['id'],$plan['confirmation']);
    ok(count($p->writes)===1);ok($e->persistent->get('flash')['state']==='reboot_required');
    blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(count($p->writes)===1);
});
test('Journal filesystem must be flushed before writing',function(){
    [$e,$p]=setup();$plan=plan($e);$p->failSync=true;
    blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(!$p->writes);
    ok($e->persistent->get('flash')['state']==='not_started');
});
test('A possibly orphaned flash blocks new device reads in the same boot',function(){
    [$e,$p,$h]=setup();$e->persistent->put('flash',['state'=>'flashing','plan'=>['boot_id'=>$h->boot]]);
    blocks(fn()=>$e->scan());$h->boot='00000000-0000-0000-0000-000000000002';ok(count($e->scan()['cards'])===1);ok(!$p->writes);
});
test('Write failure persists needs_attention and never retries',function(){
    [$e,$p]=setup();$plan=plan($e);$p->failWrite=true;blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));
    ok($e->persistent->get('flash')['state']==='needs_attention');blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(count($p->writes)===1);
});
test('Wrong post-flash readback cannot report success',function(){
    [$e,$p]=setup();$plan=plan($e);$p->wrongReadback=true;blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));
    ok($e->persistent->get('flash')['state']==='needs_attention');
});
test('Intel CLI error cannot be hidden by exit zero and the target version',function(){
    [$e,$p]=setup();$plan=plan($e);$p->hiddenWriteError=true;
    blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));
    ok($p->current===$p->target);ok($e->persistent->get('flash')['state']==='needs_attention');
    blocks(fn()=>$e->scan());blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(count($p->writes)===1);
});
test('Successful exit without the complete Intel version transcript is uncertain',function(){
    foreach(['','Update finished',"Image: FW Version: DG02_2.2359\nDevice: FW Version: DG02_2.2359\n"] as $out)
        blocks(fn()=>Igsc::assertWriteResult(['code'=>0,'err'=>'','out'=>$out],v('DG02_2.2353'),v('DG02_2.2359')));
});
test('A failed compatibility check invalidates an earlier executable plan',function(){
    [$e,$p]=setup();$old=plan($e);$p->compatible=false;ok(!$e->check('0000:03:00.0')['eligible']);
    ok(!$e->runtime->get('plan'));$p->compatible=true;
    blocks(fn()=>$e->flash($old['id'],$old['confirmation']));ok(!$p->writes);
});
test('A scan failure during checking also invalidates the earlier plan',function(){
    [$e,$p]=setup();plan($e);$p->noInterface=true;blocks(fn()=>$e->check('0000:03:00.0'));
    ok(!$e->runtime->get('plan'));ok(!$e->runtime->get('check'));ok(!$p->writes);
});
test('Plans cannot cross plugin versions or build policies',function(){
    [$e,$p,$h]=setup();$plan=plan($e);
    foreach(['plugin_version'=>'old','mode'=>'other'] as $key=>$value) {
        $changed=$plan;$changed[$key]=$value;
        blocks(fn()=>Engine::assertPlan($changed,$plan['id'],$plan['confirmation'],$h->boot,time()));
    }
});
test('Power and workload are rechecked after the journal flush',function(){
    [$e,$p,$h]=setup();$plan=plan($e);$p->onSync=function()use($h){$h->ready=false;};
    blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(!$p->writes);
    ok($e->persistent->get('flash')['state']==='not_started');
});
test('An unreadable dangling journal cannot be treated as absent',function(){
    [$e,$p]=setup();symlink($e->persistent->dir.'/missing',$e->persistent->dir.'/flash.json');
    blocks(fn()=>$e->assertNoUnfinishedWrite());ok(!$p->writes);
});
test('Device identity, driver and MEI remapping during journal flush stop the write',function(){
    foreach(['identity','driver','mei'] as $change) {
        [$e,$p,$h]=setup();$plan=plan($e);
        $p->onSync=function()use($h,$p,$change){
            if($change==='identity')$h->card['subdevice']='9999';
            if($change==='driver')$h->card['driver']='vfio-pci';
            if($change==='mei')$p->interface['mei']='/dev/mei8';
        };
        blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(!$p->writes);
    }
});
test('Native writer rejects arbitrary paths and devices before launching',function(){
    $p=new Process();
    foreach([['/dev/mei7','/tmp/image.bin'],['/dev/mei7;false','/nonexistent'],['/dev/mei7','/var/lib/intel-gpu-firmware/images/../bad.bin']] as [$mei,$path])
        blocks(fn()=>$p->writeGsc($mei,$path));
});
test('Interrupted write journal blocks future flash',function(){
    foreach(['flashing','verifying','needs_attention','reboot_required'] as $s) {
        [$e,$p]=setup();$plan=plan($e);$e->persistent->put('flash',['state'=>$s]);blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(!$p->writes);
    }
});
test('Verify requires reboot and matching identity',function(){
    [$e,$p,$h]=setup();$plan=plan($e);$e->flash($plan['id'],$plan['confirmation']);blocks(fn()=>$e->verify());
    $h->boot='00000000-0000-0000-0000-000000000002';$h->card['subdevice']='9999';$p->interface['subdevice']='9999';blocks(fn()=>$e->verify());
    $h->card['subdevice']='6004';$p->interface['subdevice']='6004';$e->verify();ok($e->persistent->get('flash')['state']==='complete');
});
test('Redacted report excludes host identity and paths',function(){
    [$e]=setup();$e->scan();$report=json_encode($e->report());
    foreach(['/dev/','0000:03:00.0','fingerprint','hostname','serial'] as $s)ok(!str_contains($report,$s));
    ok(str_contains($report,'1849'));
});
test('CSRF, GET writes, malformed targets and unexpected parameters are rejected',function(){
    $csrf=str_repeat('a',32);
    foreach([
        ['GET',['action'=>'scan','csrf_token'=>$csrf]],
        ['POST',['action'=>'scan','csrf_token'=>'wrong']],
        ['POST',['action'=>'scan','csrf_token'=>$csrf,'command'=>'reboot']],
        ['POST',['action'=>'check','csrf_token'=>$csrf,'bdf'=>'03:00.0; reboot']],
        ['POST',['action'=>'flash','csrf_token'=>$csrf,'plan_id'=>str_repeat('b',32),'confirmation'=>'yes','acknowledged'=>false]],
        ['POST',['action'=>'destroy','csrf_token'=>$csrf]],
    ] as [$method,$body])blocks(fn()=>Http::validate($method,$body,$csrf));
});
test('Only explicit confirmed flash requests are accepted',function(){
    $c=str_repeat('a',32);
    $r=Http::validate('POST',['action'=>'flash','csrf_token'=>$c,'plan_id'=>str_repeat('b',32),'confirmation'=>'UPDATE 0000:03:00.0','acknowledged'=>true],$c);
    ok($r['action']==='flash');
});
test('Native Unraid form envelope preserves and verifies both CSRF tokens',function(){
    $c=str_repeat('a',32);$body=['action'=>'scan','csrf_token'=>$c];
    $form=['csrf_token'=>$c,'payload'=>json_encode($body)];
    ok(Http::formRequest('POST',$form,$c)['action']==='scan');
    blocks(fn()=>Http::formRequest('GET',$form,$c));
    blocks(fn()=>Http::formRequest('POST',array_replace($form,['csrf_token'=>'wrong']),$c));
    blocks(fn()=>Http::formRequest('POST',$form+['command'=>'reboot'],$c));
    blocks(fn()=>Http::formRequest('POST',[],$c));
});
test('Request decoding survives Unraid removing CSRF from POST after validation',function(){
    $c=str_repeat('a',32);$form=['csrf_token'=>$c,'payload'=>json_encode(['action'=>'scan','csrf_token'=>$c])];
    $raw=http_build_query($form);unset($form['csrf_token']);
    blocks(fn()=>Http::formRequest('POST',$form,$c));
    ok(Http::rawFormRequest('POST',$raw,'application/x-www-form-urlencoded;charset=UTF-8',$c)['action']==='scan');
    blocks(fn()=>Http::rawFormRequest('POST',$raw,'application/json',$c));
    blocks(fn()=>Http::rawFormRequest('POST',str_repeat('a',4097),'application/x-www-form-urlencoded',$c));
});
test('Dead workers, reused PIDs and stale queues do not stay busy forever',function(){
    $q=['state'=>'queued','created_at'=>100];ok(Http::jobActive($q,120));ok(!Http::jobActive($q,131));
    $r=['state'=>'running','pid'=>123,'id'=>'expected'];
    ok(Http::jobActive($r,140,fn($pid,$id)=>$pid===123&&$id==='expected'));
    ok(!Http::jobActive($r,140,fn()=>false));ok(!Http::jobActive(['state'=>'complete'],140));
});
test('State writes are atomic and path traversal is refused',function(){
    [$e]=setup();$e->runtime->put('example',['state'=>'ok']);ok($e->runtime->get('example')['state']==='ok');
    blocks(fn()=>$e->runtime->put('../bad',[]));
});
test('Real sysfs enumeration discovers all Intel display classes only',function(){
    $d=sys_get_temp_dir().'/igf-sysfs-'.bin2hex(random_bytes(6));mkdir($d.'/bus/pci/devices',0700,true);
    foreach([['0000:01:00.0','0x8086','0x030000'],['0000:02:00.0','0x8086','0x030200'],['0000:03:00.0','0x10de','0x030000'],['0000:04:00.0','0x8086','0x040300']] as [$b,$v,$c]){
        $p=$d.'/bus/pci/devices/'.$b;mkdir($p);foreach(['vendor'=>$v,'class'=>$c,'device'=>'0x56a5','subsystem_vendor'=>'0x9999','subsystem_device'=>'0x1234','revision'=>'0x05'] as $f=>$s)file_put_contents($p.'/'.$f,$s);
    }
    $h=new Hardware(new FakeProcess(),$d);ok(count($h->cards())===2);
});
class PolicyHardware extends Hardware {
    public bool $upsInstalled=true;
    protected function executable(string $path): bool{return $this->upsInstalled || !in_array($path,['/usr/bin/upsc','/sbin/apcaccess','/usr/sbin/apcaccess'],true);}
}
class PolicyProcess implements Executor {
    public string $ups='ups';public string $status="ups.status: OL\nbattery.runtime: 1200\nbattery.charge: 100\n";
    public bool $inUse=false;public bool $checkError=false;
    public function run(array $argv,int $timeout=45): array {
        if($argv[0]==='/usr/bin/fuser')return ['code'=>$this->inUse?0:1,'out'=>'','err'=>$this->checkError?'Cannot inspect device':''];
        if($argv[0]==='/usr/bin/upsc')return ['code'=>0,'out'=>($argv[1]==='-l'?$this->ups:$this->status),'err'=>''];
        return ['code'=>1,'out'=>'','err'=>''];
    }
}
function policySetup(): array {
    $d=sys_get_temp_dir().'/igf-policy-'.bin2hex(random_bytes(6));
    mkdir($d.'/sys/class/drm/renderD128',0700,true);mkdir($d.'/sys/devices/0000:03:00.0',0700,true);mkdir($d.'/proc',0700);
    symlink($d.'/sys/devices/0000:03:00.0',$d.'/sys/class/drm/renderD128/device');
    $p=new PolicyProcess();return [new PolicyHardware($p,$d.'/sys',$d.'/proc'),$p,$d];
}
test('Actual maintenance policy rejects processes, passthrough, boot display and VFs',function(){
    [$h,$p,$d]=policySetup();ok($h->maintenance(card())['ready']);
    foreach(['dockerd','containerd','libvirtd','virtqemud','qemu-system-x86','qemu-kvm'] as $name){
        if(!is_dir($d.'/proc/123'))mkdir($d.'/proc/123');file_put_contents($d.'/proc/123/comm',$name);
        ok(!$h->maintenance(card())['ready']);
    }
    unlink($d.'/proc/123/comm');
    foreach([['driver'=>'vfio-pci'],['boot_vga'=>true],['vfs'=>1]] as $change)ok(!$h->maintenance(array_replace(card(),$change))['ready']);
    $p->inUse=true;ok(!$h->maintenance(card())['ready']);$p->inUse=false;$p->checkError=true;ok(!$h->maintenance(card())['ready']);
});
test('UPS advice reports insufficient runtime, charge and ambiguous power without blocking',function(){
    [$h,$p]=policySetup();ok($h->power()['ok']);
    foreach(["ups.status: OB\nbattery.runtime: 1200\nbattery.charge: 100", "ups.status: OL LB\nbattery.runtime: 1200\nbattery.charge: 100", "ups.status: OL\nbattery.runtime: 599\nbattery.charge: 100", "ups.status: OL\nbattery.runtime: 1200\nbattery.charge: 49", 'ups.status: OL'] as $status){$p->status=$status;ok(!$h->power()['ok']);$m=$h->maintenance(card());ok($m['ready'] && $m['warnings'] && !$m['power']['required']);}
    $p->ups="ups1\nups2\n";ok(!$h->power()['ok']);ok($h->maintenance(card())['ready']);
});
test('No UPS permits a simulated confirmed update while device-use checks still block',function(){
    [$policy,$policyProcess]=policySetup();$policy->upsInstalled=false;
    [$e,$p,$h]=setup();
    $e->hardware=new class($policy) extends TestHardware {
        public function __construct(private Hardware $policy){parent::__construct();}
        public function maintenance(array $card): array{return $this->policy->maintenance($card);}
    };
    $checked=$e->check($h->card['bdf']);ok($checked['maintenance']['ready']);
    ok(!$checked['maintenance']['power']['ok'] && count($checked['maintenance']['warnings'])===1);
    $plan=$checked['plan'];$policyProcess->inUse=true;
    blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(!$p->writes);
    $policyProcess->inUse=false;$e->flash($plan['id'],$plan['confirmation']);
    ok(count($p->writes)===1);ok($e->persistent->get('flash')['state']==='reboot_required');
});
test('Broken UPS monitoring cannot abort maintenance checks',function(){
    [$unused,$p,$dir]=policySetup();
    $h=new class($p,$dir.'/sys',$dir.'/proc') extends PolicyHardware {
        public function power(): array {throw new Blocked('Monitor timed out');}
    };
    $m=$h->maintenance(card());ok($m['ready'] && !$m['reasons']);
    ok(!$m['power']['ok'] && !$m['power']['required'] && count($m['warnings'])===1);
});
test('Queued checks use the real worker orchestration and cannot replay',function(){
    [$e,$p]=setup();
    $id=Http::queue($e,['action'=>'check','args'=>['bdf'=>'0000:03:00.0']],fn()=>0);
    ok(\IntelGpuFirmware\Worker::run($e,$id)===0);ok($e->runtime->get('check')['eligible']);
    ok($e->runtime->get('job')['state']==='complete');ok(!isset($e->runtime->get('job')['args']));
    ok(\IntelGpuFirmware\Worker::run($e,$id)===4);ok(!$p->writes);
});
test('Worker lock rejects competing operations and releases after completion',function(){
    [$e,$p]=setup();$id=Http::queue($e,['action'=>'scan','args'=>[]],fn()=>0);
    $lock=fopen($e->runtime->dir.'/work.lock','c');ok(flock($lock,LOCK_EX|LOCK_NB));
    ok(\IntelGpuFirmware\Worker::run($e,$id)===3);ok($e->runtime->get('job')['state']==='failed');
    flock($lock,LOCK_UN);fclose($lock);
    $new=Http::queue($e,['action'=>'scan','args'=>[]],fn()=>0);
    ok(\IntelGpuFirmware\Worker::run($e,$new)===0);ok(!$p->writes);
});
test('A queued job cannot be overwritten by a second request',function(){
    [$e,$p]=setup();$count=0;$launch=function()use(&$count){$count++;return 0;};
    $id=Http::queue($e,['action'=>'scan','args'=>[]],$launch);
    blocks(fn()=>Http::queue($e,['action'=>'scan','args'=>[]],$launch));
    ok($count===1);ok($e->runtime->get('job')['id']===$id);ok(!$p->writes);
});
test('Simulated worker writes once, survives reload and verifies after reboot',function(){
    [$e,$p,$h]=setup();$plan=plan($e);$id=bin2hex(random_bytes(16));
    $csrf=str_repeat('c',32);
    $request=Http::validate('POST',['action'=>'flash','csrf_token'=>$csrf,'plan_id'=>$plan['id'],
        'confirmation'=>$plan['confirmation'],'acknowledged'=>true],$csrf);
    if(\IntelGpuFirmware\WRITES_ENABLED)$id=Http::queue($e,$request,fn()=>0);
    else $e->runtime->put('job',['id'=>$id,'state'=>'queued',...$request]);
    ok(\IntelGpuFirmware\Worker::run($e,$id)===0);ok(count($p->writes)===1);
    ok(\IntelGpuFirmware\Worker::run($e,$id)===4);ok(count($p->writes)===1);
    ok($e->persistent->get('flash')['state']==='reboot_required');
    $h->boot='00000000-0000-0000-0000-000000000002';
    $id=Http::queue($e,['action'=>'verify','args'=>[]],fn()=>0);
    ok(\IntelGpuFirmware\Worker::run($e,$id)===0);ok($e->persistent->get('flash')['state']==='complete');
});
test('HTTP-to-worker workflow rejects missing consent, wrong GPU, expiry and changed maintenance',function(){
    foreach(['csrf','ack','phrase','expiry','maintenance'] as $failure) {
        [$e,$p,$h]=setup();$plan=plan($e);$csrf=str_repeat('c',32);$launched=0;
        $body=['action'=>'flash','csrf_token'=>$csrf,'plan_id'=>$plan['id'],'confirmation'=>$plan['confirmation'],'acknowledged'=>true];
        if($failure==='csrf')$body['csrf_token']='invalid';
        if($failure==='ack')unset($body['acknowledged']);
        if($failure==='phrase')$body['confirmation']='UPDATE 0000:04:00.0';
        if($failure==='expiry'){$plan['expires_at']=time()-1;$e->runtime->put('plan',$plan);}
        if($failure==='maintenance')$h->ready=false;
        $attempt=function()use($e,$body,$csrf,&$launched){
            return Http::queue($e,Http::validate('POST',$body,$csrf),function()use(&$launched){$launched++;return 0;});
        };
        if($failure==='maintenance' && \IntelGpuFirmware\WRITES_ENABLED) {
            $id=$attempt();ok(\IntelGpuFirmware\Worker::run($e,$id)===1);ok($launched===1);
            ok(!$e->persistent->get('flash'));
        } else {blocks($attempt);ok($launched===0);ok(!$e->persistent->get('flash'));}
        ok(!$p->writes);
    }
});
test('Worker preserves an uncertain Intel result and refuses replay',function(){
    [$e,$p]=setup();$plan=plan($e);$p->hiddenWriteError=true;$id=bin2hex(random_bytes(16));
    $e->runtime->put('job',['id'=>$id,'state'=>'queued','action'=>'flash','args'=>['plan_id'=>$plan['id'],'confirmation'=>$plan['confirmation']]]);
    ok(\IntelGpuFirmware\Worker::run($e,$id)===1);ok($e->runtime->get('job')['state']==='failed');
    ok($e->persistent->get('flash')['state']==='needs_attention');
    ok(\IntelGpuFirmware\Worker::run($e,$id)===4);ok(count($p->writes)===1);
});
test('Unflushed completion never opens the path to another write',function(){
    [$e,$p,$h]=setup();$plan=plan($e);$n=0;$p->onSync=function()use(&$n,$p){if(++$n===3)$p->failSync=true;};
    blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));ok(count($p->writes)===1);
    ok($e->persistent->get('flash')['state']==='needs_attention');
    blocks(fn()=>$e->assertNoUnfinishedWrite());
});
require __DIR__.'/tracking.php';
require __DIR__.'/maintenance.php';
require __DIR__.'/recovery.php';
echo "\n$passed tests passed (".\IntelGpuFirmware\MODE."). No physical device was written.\n";
