<?php
declare(strict_types=1);
use IntelGpuFirmware\{History,IntelRelease,Store,Worker,Http,Blocked};

test('History deduplicates scans and does not rewrite flash storage for unchanged versions',function(){
    [$e,$p]=setup();$e->scan();History::capture($e);
    $first=$e->persistent->get('history');$path=$e->persistent->dir.'/history.json';$inode=fileinode($path);
    $e->scan();History::capture($e);clearstatcache(true,$path);
    ok($inode===fileinode($path));ok($first===$e->persistent->get('history'));
    $p->current='DG02_2.2360';$e->scan();History::capture($e);
    $device=array_values($e->persistent->get('history')['cards'])[0];
    ok(count($device['events'])===2);ok($device['last_observation']['versions']['gsc']==='DG02_2.2360');ok(!$p->writes);
});
test('Identical GPUs in different slots and changed hardware keep separate histories',function(){
    [$e]=setup();$a=$e->scan()['cards'][0];$b=$a;$b['bdf']='0000:04:00.0';
    $e->runtime->put('inventory',['scanned_at'=>gmdate('c'),'cards'=>[$a,$b]]);History::capture($e);
    ok(count($e->persistent->get('history')['cards'])===2);
    $a['subdevice']='9999';$e->runtime->put('inventory',['scanned_at'=>gmdate('c'),'cards'=>[$a]]);History::capture($e);
    ok(count($e->persistent->get('history')['cards'])===3);
});
test('History retains compatibility findings without marking offered firmware as installed',function(){
    [$e,$p]=setup();plan($e);History::capture($e);History::capture($e);
    $device=array_values($e->persistent->get('history')['cards'])[0];
    $found=array_values(array_filter($device['events'],fn($v)=>$v['kind']==='available'));
    ok(count($found)===1);ok($found[0]['to']===$p->target);
    ok($device['last_observation']['versions']['gsc']===$p->current);ok(!$p->writes);
});
test('Existing journal is backfilled without hardware reads or mutation and survives a new journal',function(){
    [$e,$p]=setup();$plan=plan($e);
    $j=['state'=>'complete','plan'=>$plan,'started_at'=>'2026-11-24T15:45:41+00:00','verified_at'=>'2026-11-24T15:59:44+00:00'];
    $e->persistent->put('flash',$j);$view=History::read($e->persistent);
    ok(!$e->persistent->get('history'));ok($e->persistent->get('flash')===$j);
    ok(array_values($view['cards'])[0]['events'][0]['state']==='complete');History::capture($e);
    $j['plan']['id']=str_repeat('9',32);$j['state']='needs_attention';$e->persistent->put('flash',$j);History::capture($e);
    $view=History::read(new Store($e->persistent->dir));
    $updates=array_filter(array_values($view['cards'])[0]['events'],fn($x)=>$x['kind']==='update');
    ok(count($updates)===2);ok(!$p->writes);
});
test('History preserves uncertain results and cannot authorize another flash',function(){
    [$e,$p]=setup();$plan=plan($e);$p->failWrite=true;
    blocks(fn()=>$e->flash($plan['id'],$plan['confirmation']));History::capture($e);
    $events=array_values($e->persistent->get('history')['cards'])[0]['events'];
    $updates=array_values(array_filter($events,fn($x)=>$x['kind']==='update'));
    ok($updates[0]['state']==='needs_attention');blocks(fn()=>$e->assertNoUnfinishedWrite());
});
test('Broken history cannot change a worker result or erase the update journal',function(){
    [$e,$p]=setup();file_put_contents($e->persistent->dir.'/history.json','broken');
    $id=Http::queue($e,['action'=>'scan','args'=>[]],fn()=>0);ok(Worker::run($e,$id)===0);
    ok($e->runtime->get('history-warning')['message']!=='');
    ok(file_get_contents($e->persistent->dir.'/history.json')==='broken');ok(!$p->writes);
});
test('History bounds events without conflating component version changes',function(){
    [$e]=setup();$inventory=$e->scan();
    for($i=0;$i<105;$i++) {
        $inventory['scanned_at']=gmdate('c',1700000000+$i);
        $inventory['cards'][0]['components']['fw-data']='Version '.$i;
        $e->runtime->put('inventory',$inventory);History::capture($e);
    }
    $device=array_values($e->persistent->get('history')['cards'])[0];
    ok(count($device['events'])===100 && $device['older_events_pruned']);
    ok($device['last_observation']['versions']['components']['fw-data']==='Version 104');
});
function releasePage(string $version='32.0.101.9175',string $hash=''): string {
    $hash=$hash?:str_repeat('a',128);$parts=explode('.',$version);
    return '<span class="cmp-teaser__required-info">Version '.$version.' WHQL Certified</span>'
        .'<p><span>SHA512: </span>'.$hash.'</p><a href="https://downloadmirror.intel.com/932449/gfx_win_'
        .$parts[2].'.'.$parts[3].'.exe">Download</a>';
}
class ReleaseProcess extends FakeProcess {
    public bool $offline=false;public string $html='';public array $downloads=[];
    public function run(array $argv,int $timeout=45): array {
        if($argv[0]!=='/usr/bin/curl')return parent::run($argv,$timeout);
        $this->downloads[]=$argv;
        ok(end($argv)===IntelRelease::SOURCE);ok(!in_array('--location',$argv,true));
        $dest=$argv[array_search('--output',$argv,true)+1];
        file_put_contents($dest,$this->html);
        return ['code'=>$this->offline?22:0,'out'=>$this->offline?'403':'200','err'=>''];
    }
}
test('Intel parser uses current package metadata and ignores previous-version listings',function(){
    $parsed=IntelRelease::parse(releasePage().'<table><tr><td>Version 99.0.999.99999</td></tr></table>');
    ok($parsed['version']==='32.0.101.9175');ok(strlen($parsed['sha512'])===128);
});
test('Intel parser rejects incomplete, conflicting, oversized or redirected-source content',function(){
    foreach(['Access Denied',releasePage().releasePage('32.0.101.9999'),
        str_replace('downloadmirror.intel.com','evil.example',releasePage()),
        str_replace('gfx_win_101.9175','gfx_win_101.9999',releasePage()),
        str_replace(str_repeat('a',128),'abc',releasePage()),str_repeat('x',4194305)] as $html)
        blocks(fn()=>IntelRelease::parse($html));
});
test('Release comparison distinguishes new, changed and older packages without firmware claims',function(){
    $trusted=IntelRelease::parse(releasePage());
    ok(IntelRelease::classify($trusted,$trusted)==='catalog_current');
    ok(IntelRelease::classify(IntelRelease::parse(releasePage('32.0.101.9999')),$trusted)==='new_package');
    ok(IntelRelease::classify(IntelRelease::parse(releasePage('32.0.101.9000')),$trusted)==='unexpected_older');
    ok(IntelRelease::classify(IntelRelease::parse(releasePage('32.0.101.9175',str_repeat('b',128))),$trusted)==='package_changed');
});
test('Online release check preserves last success after failure and never modifies catalog or plans',function(){
    [$e,$p,$h,$dir]=setup();$e->exec=$net=new ReleaseProcess();$net->html=releasePage();
    $before=file_get_contents($dir.'/catalog.json');$plan=plan($e);
    IntelRelease::check($e);$ok=$e->persistent->get('intel-release');
    ok($ok['latest']['version']==='32.0.101.9175');
    IntelRelease::check($e);ok(count($e->persistent->get('intel-release')['releases'])===1);
    $net->offline=true;blocks(fn()=>IntelRelease::check($e));$failed=$e->persistent->get('intel-release');
    ok($failed['status']==='unknown');ok($failed['latest']===$ok['latest']);ok($failed['checked_at']===$ok['checked_at']);
    ok($before===file_get_contents($dir.'/catalog.json'));ok($e->runtime->get('plan')===$plan);
    ok(!$net->writes && !$p->writes);ok(count(glob($e->runtime->dir.'/intel-release-*'))===0);
});
test('Release checks pass through CSRF and worker locks and reject custom URLs',function(){
    $csrf=str_repeat('s',32);$request=Http::validate('POST',['action'=>'releases','csrf_token'=>$csrf],$csrf);
    blocks(fn()=>Http::validate('GET',['action'=>'releases','csrf_token'=>$csrf],$csrf));
    blocks(fn()=>Http::validate('POST',['action'=>'releases','csrf_token'=>$csrf,'url'=>'https://example.com'],$csrf));
    [$e,$p]=setup();$net=new ReleaseProcess();$net->html=releasePage();$e->exec=$net;
    $id=Http::queue($e,$request,fn()=>0);ok(Worker::run($e,$id)===0);
    ok(count($net->downloads)===1 && !$p->writes);ok($e->runtime->get('job')['state']==='complete');
});
