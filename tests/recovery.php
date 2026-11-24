<?php
use IntelGpuFirmware\{Engine,Maintenance,Blocked,StartPolicy,Validation,Store,AuthServer};

test('Known pre-write failure permits restoration and consumes the old plan',function(){
    [$e,$p,$h]=setup();$plan=plan($e);$p->onSync=function()use($h){$h->ready=false;};
    blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));
    ok(!$p->writes);ok($e->persistent->get('flash')['state']==='not_started');
    $e->assertNoUnfinishedWrite();ok(!$e->runtime->get('plan'));
    // A stale runtime cache must not revive a durably consumed plan.
    $e->runtime->put('plan',$plan);$h->ready=true;$p->onSync=null;
    blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));
    ok(!$p->writes);
});
test('Real worker death distinguishes preflight from durable write intent',function(){
    foreach([1=>'preflight',2=>'flashing'] as $flush=>$expected) {
        [$e,$p]=setup();$plan=plan($e);$n=0;
        $p->onSync=function()use(&$n,$flush){if(++$n===$flush)posix_kill(getmypid(),SIGKILL);};
        $pid=pcntl_fork();ok($pid>=0);
        if($pid===0){$e->flash($plan['id'],$plan['confirmation']);exit(99);}
        pcntl_waitpid($pid,$status);ok(pcntl_wifsignaled($status));
        ok($e->persistent->get('flash')['state']===$expected);
        $p->onSync=null;$e->recoverPreflight();
        if($expected==='preflight') {
            ok($e->persistent->get('flash')['state']==='not_started');$e->assertNoUnfinishedWrite();
            ok(!$e->runtime->get('plan'));
        } else {
            ok($e->persistent->get('flash')['state']==='flashing');blocks(fn()=>$e->assertNoUnfinishedWrite());
        }
    }
});
test('Unknown or legacy uncertain journals never qualify as unstarted',function(){
    foreach(['flashing','verifying','needs_attention','reboot_required','unknown'] as $state) {
        [$e]=setup();$j=['state'=>$state];$e->persistent->put('flash',$j);$e->recoverPreflight();
        ok($e->persistent->get('flash')===$j);blocks(fn()=>$e->assertNoUnfinishedWrite());
    }
    [$e]=setup();$e->persistent->put('flash',['state'=>'preflight']);blocks(fn()=>$e->recoverPreflight());
});
test('Failed rehearsal preparation restores policies without another click',function(){
    foreach(['guard','stop'] as $failure) {
        [$e,$p]=setup();$d=new MaintenanceDockerFixture();$m=new MaintenanceFixture($e,$d);$before=$d->containers;
        $preview=$m->preview('0000:03:00.0');
        if($failure==='guard')$m->failGuard=true;else $d->failStop=true;
        blocks(fn()=>$m->rehearse($preview['id']));
        ok($d->containers===$before);ok(!$d->guard);ok(!$p->writes);
        ok($e->persistent->get('maintenance')['state']==='complete');
    }
});
test('Rehearsal cannot disarm a previously active maintenance session',function(){
    [$e]=setup();$d=new MaintenanceDockerFixture();$m=new MaintenanceFixture($e,$d);
    $preview=$m->preview('0000:03:00.0');$m->prepare($preview['id']);
    blocks(fn()=>$m->rehearse($preview['id']));ok($d->guard);ok(!$d->containers['gpu']['State']['Running']);
    $m->restore();
});
class PartialRestoreDocker extends MaintenanceDockerFixture {
    public bool $failRestore=true;
    public function call(string $method,string $path,?array $body=null): array {
        if($this->failRestore && str_contains($path,str_repeat('a',64).'/start'))throw new Blocked('Simulated start failure');
        return parent::call($method,$path,$body);
    }
}
test('Partial restoration continues other workloads and safely resumes missing items',function(){
    [$e]=setup();$d=new PartialRestoreDocker();$m=new MaintenanceFixture($e,$d);$before=$d->containers;
    $preview=$m->preview('0000:03:00.0');$m->prepare($preview['id']);blocks(fn()=>$m->restore());
    $s=$e->persistent->get('maintenance');ok($s['state']==='restoring');
    ok(in_array(str_repeat('c',64),$s['restored'],true));ok(!in_array(str_repeat('a',64),$s['restored'],true));
    ok(!$d->containers['off']['State']['Running']);
    $d->failRestore=false;$m->restore();ok($before===$d->containers);
    ok($e->persistent->get('maintenance')['state']==='complete');
});
class BootMaintenanceFixture extends MaintenanceFixture {
    public array $mapping;public bool $missing=false;
    public function __construct($e,$d){parent::__construct($e,$d);$this->mapping=targetFixture();}
    public function target(string $bdf): array {if($this->missing)throw new Blocked('No GPU nodes');return $this->mapping;}
}
test('Simulated reboot re-arms guards without starting workloads or flashing',function(){
    [$e,$p,$h]=setup();$d=new MaintenanceDockerFixture();$m=new BootMaintenanceFixture($e,$d);$before=$d->containers;
    $preview=$m->preview('0000:03:00.0');$m->prepare($preview['id']);
    blocks(fn()=>$m->resumeAfterBoot());
    $h->boot='00000000-0000-0000-0000-000000000002';$d->guard=false;
    $m2=new BootMaintenanceFixture($e,$d);$m2->resumeAfterBoot();
    ok($d->guard);ok(!$d->containers['gpu']['State']['Running']);ok(!$p->writes);
    $s=$e->persistent->get('maintenance');ok($s['state']==='reboot_guarded');ok(!$s['mapping_uncertain']);
    $m2->restore();ok($d->containers===$before);
});
test('Reboot keeps old and remapped GPU nodes reserved; missing identity blocks all starts',function(){
    [$e,$p,$h]=setup();$d=new MaintenanceDockerFixture();$m=new BootMaintenanceFixture($e,$d);
    $preview=$m->preview('0000:03:00.0');$m->prepare($preview['id']);$h->boot='00000000-0000-0000-0000-000000000002';
    $m->mapping['nodes']=['/dev/dri/renderD130'];$m->mapping['numbers']=[[226,130]];$m->resumeAfterBoot();
    $s=$e->persistent->get('maintenance');ok(in_array('/dev/dri/renderD129',$s['target']['nodes']));ok(in_array('/dev/dri/renderD130',$s['target']['nodes']));
    $m->missing=true;$m->resumeAfterBoot();$s=$e->persistent->get('maintenance');ok($s['mapping_uncertain']);
    ok(!StartPolicy::docker(['RequestMethod'=>'POST','RequestUri'=>'/containers/db/start'],$s,fn()=>containerFixture(false)));
    ok(StartPolicy::docker(['RequestMethod'=>'POST','RequestUri'=>'/containers/db/stop'],$s,fn()=>containerFixture(false)));
    ok(!StartPolicy::vm('prepare','<domain/>',$s));ok(StartPolicy::vm('reconnect','<domain/>',$s));ok(!$p->writes);
    $m->restore();
});
test('Reboot during partial restore does not restart workloads or reinstall guards',function(){
    [$e,$p,$h]=setup();$d=new PartialRestoreDocker();$m=new MaintenanceFixture($e,$d);$before=$d->containers;
    $preview=$m->preview('0000:03:00.0');$m->prepare($preview['id']);blocks(fn()=>$m->restore());
    $h->boot='00000000-0000-0000-0000-000000000002';$m->resumeAfterBoot();ok(!$d->guard);ok(!$p->writes);
    $d->failRestore=false;$m->restore();ok($d->containers===$before);
});
test('Physical validation never spreads to another OEM, generation, revision or driver',function(){
    ok(Validation::forCard(card())['level']==='single_board');
    foreach(['subvendor'=>'8086','device'=>'e20b','revision'=>'06','driver'=>'xe'] as $key=>$value) {
        $c=card();$c[$key]=$value;ok(Validation::forCard($c)['level']==='unvalidated');
    }
});
test('Real authorization helper can be killed and restarted from durable session state',function(){
    $dir=sys_get_temp_dir().'/igf-restart-'.bin2hex(random_bytes(5));mkdir($dir,0700);
    $store=new Store($dir.'/state');$store->put('maintenance',['state'=>'reboot_guarded','target'=>targetFixture()]);
    $socket=$dir.'/s';
    $launch=function()use($socket,$dir,$store){
        $pid=pcntl_fork();ok($pid>=0);
        if(!$pid)AuthServer::serve($socket,$dir.'/pid',fn()=>$store->get('maintenance'),fn()=>containerFixture());
        for($i=0;$i<100;$i++) {if(is_file($dir.'/pid')&&trim(file_get_contents($dir.'/pid'))===(string)$pid)return $pid;usleep(10000);}
        throw new RuntimeException('Helper failed to start');
    };
    $probe=function()use($socket){
        $f=@stream_socket_client('unix://'.$socket,$errno,$err,1);if(!$f)return null;
        stream_set_timeout($f,2);$body=json_encode(['RequestMethod'=>'POST','RequestUri'=>'/containers/gpu/start']);
        fwrite($f,"POST /AuthZPlugin.AuthZReq HTTP/1.1\r\nHost: localhost\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
        $raw=stream_get_contents($f);fclose($f);$parts=explode("\r\n\r\n",$raw,2);
        return isset($parts[1])?json_decode($parts[1],true):null;
    };
    $pid=$launch();
    try {
        ok($probe()['Allow']===false);posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);$pid=0;
        ok($probe()===null);$pid=$launch();ok($probe()['Allow']===false);
        $store->put('maintenance',['state'=>'restoring']);ok($probe()['Allow']===true);
    } finally {if($pid){posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);}}
});
