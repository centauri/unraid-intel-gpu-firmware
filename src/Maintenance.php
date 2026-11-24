<?php
declare(strict_types=1);
namespace IntelGpuFirmware;

class Maintenance {
    const PLUGIN='intel-gpu-maintenance';
    public function __construct(public Engine $engine,public DockerApi $docker=new DockerApi()) {}
    private function plugin(): string {
        $id=$this->engine->persistent->get('maintenance')['id']??'';
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new Blocked('Invalid maintenance guard identity.');
        // Docker caches plugin activation errors by name for the daemon lifetime.
        return self::PLUGIN.'-'.substr($id,0,12);
    }
    protected function run(array $args,int $timeout=30): string {
        $r=$this->engine->exec->run($args,$timeout);
        if($r['code']!==0)throw new Blocked('Maintenance command failed: '.basename($args[0]).' '.substr($r['err'],0,250));
        return trim($r['out']);
    }
    public function target(string $bdf): array {
        $card=null;foreach($this->engine->hardware->cards() as $c)if($c['bdf']===$bdf)$card=$c;
        if(!$card)throw new Blocked('Selected GPU is no longer present.');
        $nodes=[];$numbers=[];
        foreach(['drm'=>'/dev/dri/','mei'=>'/dev/'] as $type=>$prefix)foreach(glob('/sys/class/'.$type.'/*')?:[] as $p) {
            if(!preg_match('/^(card[0-9]+|renderD[0-9]+|mei[0-9]+)$/',basename($p)))continue;
            $path=realpath($p.'/device');
            if($path && (basename($path)===$bdf || str_contains($path,'/'.$bdf.'/'))) {
                $nodes[]=$prefix.basename($p);
                $dev=trim((string)@file_get_contents($p.'/dev'));
                if(preg_match('/^([0-9]+):([0-9]+)$/',$dev,$m))$numbers[]=[(int)$m[1],(int)$m[2]];
            }
        }
        if(!$nodes)throw new Blocked('No host device nodes could be mapped to this GPU. Passthrough GPUs must first be returned to the host manually.');
        $group=realpath('/sys/bus/pci/devices/'.$bdf.'/iommu_group');
        if($group)$nodes[]='/dev/vfio/'.basename($group);
        return ['bdf'=>$bdf,'nodes'=>$nodes,'numbers'=>$numbers,'identity'=>array_intersect_key($card,array_flip(['vendor','device','subvendor','subdevice','revision']))];
    }
    public function inventory(array $target): array {
        $items=[];$blockers=[];$dockerOn=is_socket('/var/run/docker.sock');$vmOn=is_socket('/var/run/libvirt/libvirt-sock');
        if($dockerOn) {
            $info=$this->docker->call('GET','/info');
            if(($info['Swarm']['LocalNodeState']??'inactive')!=='inactive')$blockers[]='Swarm is active; targeted maintenance does not control its internal scheduler.';
            foreach($this->docker->call('GET','/containers/json?all=1') as $row) {
                $c=$this->docker->call('GET','/containers/'.$row['Id'].'/json');$why=GpuAccess::docker($c,$target);
                if(!$why)continue;
                if($c['HostConfig']['AutoRemove']??false)$blockers[]='Auto-remove container must finish first: '.ltrim($c['Name'],'/');
                if($c['State']['Paused']??false)$blockers[]='Paused container must be resumed or stopped first: '.ltrim($c['Name'],'/');
                $items[]=['kind'=>'docker','id'=>$c['Id'],'name'=>ltrim($c['Name'],'/'),'running'=>(bool)$c['State']['Running'],
                    'restart'=>$c['HostConfig']['RestartPolicy'],'reasons'=>$why];
            }
        }
        if($vmOn)foreach(preg_split('/\s+/', $this->run(['/usr/bin/virsh','list','--all','--uuid']),-1,PREG_SPLIT_NO_EMPTY) as $uuid) {
            if(!preg_match('/^[a-f0-9-]{36}$/D',$uuid))throw new Blocked('Unexpected VM identity.');
            $xml=$this->run(['/usr/bin/virsh','dumpxml',$uuid]);$inactive=$this->run(['/usr/bin/virsh','dumpxml',$uuid,'--inactive']);
            $why=array_values(array_unique([...GpuAccess::vm($xml,$target),...GpuAccess::vm($inactive,$target)]));
            if(!$why)continue;
            $info=$this->run(['/usr/bin/virsh','dominfo',$uuid]);
            $state=$this->run(['/usr/bin/virsh','domstate',$uuid]);
            if(!in_array($state,['running','shut off'],true))$blockers[]='VM state requires manual review: '.$uuid.' ('.$state.')';
            if(!preg_match('/^Persistent:\s+yes$/m',$info))$blockers[]='Transient VM requires manual review: '.$uuid;
            $items[]=['kind'=>'vm','id'=>$uuid,'name'=>$this->run(['/usr/bin/virsh','domname',$uuid]),'running'=>$state==='running',
                'autostart'=>(bool)preg_match('/^Autostart:\s+enable$/m',$info),'reasons'=>$why];
        }
        // Native LXC bypasses Docker/libvirt. Do not pretend the start guards cover it.
        if(is_executable('/usr/bin/lxc-ls'))foreach(preg_split('/\s+/',$this->run(['/usr/bin/lxc-ls','-1']),-1,PREG_SPLIT_NO_EMPTY) as $name) {
            if(!preg_match('/^[a-zA-Z0-9_.-]+$/D',$name))throw new Blocked('Unsupported LXC name.');
            $key=is_file('/sys/fs/cgroup/cgroup.controllers')?'lxc.cgroup2.devices.allow':'lxc.cgroup.devices.allow';
            // lxc-info resolves included configuration files; raw file scanning would miss them.
            $text=$this->run(['/usr/bin/lxc-info','-n',$name,'-c','lxc.mount.entry','-c',$key]);$access=false;
            foreach(preg_split('/\n/',$text) as $line) {
                if(preg_match('~(?:^|=\s*)(/[^\s]+)~',$line,$m)&&GpuAccess::path($m[1],$target))$access=true;
                $rule=trim(preg_replace('/^lxc\.cgroup2?\.devices\.allow\s*=\s*/','',$line));
                if($rule==='a')$access=true;
                if(preg_match('/^[ac]\s+(\*|[0-9]+):(\*|[0-9]+)\s+([rwm]+)$/',$rule,$m)&&strpbrk($m[3],'rw')!==false)
                    foreach($target['numbers'] as [$major,$minor])if(($m[1]==='*'||(int)$m[1]===$major)&&($m[2]==='*'||(int)$m[2]===$minor))$access=true;
            }
            if($access)$blockers[]='Native LXC GPU mapping is not guarded: '.$name.'. Remove its GPU mapping before updating.';
        }
        return ['items'=>$items,'blockers'=>$blockers,'docker'=>$dockerOn,'libvirt'=>$vmOn];
    }
    public function preview(string $bdf): array {
        $target=$this->target($bdf);$inventory=$this->inventory($target);
        $p=['id'=>bin2hex(random_bytes(16)),'bdf'=>$bdf,'target'=>$target,...$inventory,'boot_id'=>$this->engine->hardware->bootId(),
            'expires_at'=>time()+600,'checked_at'=>gmdate('c')];
        $this->engine->runtime->put('workloads',$p);return $p;
    }
    protected function save(array $s): void {
        $this->engine->persistent->put('maintenance',$s);
        $this->run(['/bin/sync','-f',$this->engine->persistent->dir]);
    }
    public function prepare(string $id): void {
        $this->engine->assertNoUnfinishedWrite();
        if(StartPolicy::active($this->engine->persistent->get('maintenance')))throw new Blocked('Restore the existing maintenance session first.');
        $p=$this->engine->runtime->get('workloads');
        if(($p['id']??'')!==$id || time()>($p['expires_at']??0)||($p['boot_id']??'')!==$this->engine->hardware->bootId())throw new Blocked('Review GPU users again; the maintenance preview expired.');
        $target=$this->target($p['bdf']);$now=$this->inventory($target);
        if($p['target']!==$target || $p['items']!==$now['items'] || $p['docker']!==$now['docker'] || $p['libvirt']!==$now['libvirt'])throw new Blocked('GPU users changed. Review the new list before stopping anything.');
        if($now['blockers'])throw new Blocked(implode(' ',$now['blockers']));
        $s=[...$p,'state'=>'preparing','started_at'=>gmdate('c'),'restored'=>[]];
        $this->save($s); // Record every original policy before any host mutation.
        try {
            foreach($s['items'] as $item) {
                if($item['kind']==='docker')$this->docker->call('POST','/containers/'.$item['id'].'/update',['RestartPolicy'=>['Name'=>'no','MaximumRetryCount'=>0]]);
                else $this->run(['/usr/bin/virsh','autostart',$item['id'],'--disable']);
            }
            $this->installGuards($s);
            foreach($s['items'] as $item)if($item['running']) {
                if($item['kind']==='docker')$this->docker->call('POST','/containers/'.$item['id'].'/stop?t=60');
                else $this->run(['/usr/bin/virsh','shutdown',$item['id']]);
            }
            // Never force-destroy a VM. A slow shutdown leaves the session blocked/recoverable.
            for($i=0;$i<60;$i++) {
                $fresh=$this->inventory($target);
                if(!array_filter($fresh['items'],fn($x)=>$x['running']))break;
                sleep(1);
            }
            $this->assertStopped($s);$this->assertGuards($s);
            $s['state']='ready';$s['ready_at']=gmdate('c');$this->save($s);
        } catch(\Throwable $e) {
            $s['state']='needs_attention';$s['message']=$e->getMessage();$this->save($s);
            throw new Blocked($e->getMessage().' Firmware was not written. Use Restore GPU workloads to recover the original policies.');
        }
        $this->engine->runtime->put('plan',[]);$this->engine->runtime->put('check',[]);
    }
    protected function assertStopped(array $s): void {
        $fresh=$this->inventory($s['target']);
        if($fresh['blockers'])throw new Blocked(implode(' ',$fresh['blockers']));
        foreach($fresh['items'] as $item) {
            if($item['running'])throw new Blocked('GPU workload is still running: '.$item['name']);
            if($item['kind']==='docker' && ($item['restart']['Name']??'')!=='no')throw new Blocked('GPU container restart policy is not disabled.');
            if($item['kind']==='vm' && $item['autostart'])throw new Blocked('GPU VM autostart is not disabled.');
        }
    }
    public function assertReady(array $card): void {
        $s=$this->engine->persistent->get('maintenance');
        if(($s['state']??'')!=='ready' || $s['target']['bdf']!==$card['bdf'] || $s['boot_id']!==$this->engine->hardware->bootId())throw new Blocked('Review GPU users and prepare targeted maintenance first.');
        if($this->target($card['bdf'])!==$s['target'])throw new Blocked('GPU device mapping changed during maintenance.');
        $this->assertGuards($s);$this->assertStopped($s);
    }
    public function restore(): void {
        $this->engine->recoverPreflight();
        $this->engine->assertNoUnfinishedWrite();
        $s=$this->engine->persistent->get('maintenance');
        if(!StartPolicy::active($s))throw new Blocked('No maintenance session needs restoration.');
        $s['state']='restoring';$this->save($s);
        // Disarm only after the flash journal permits recovery. Keep the journal on failure.
        $this->removeGuards($s);
        $errors=[];
        foreach($s['items'] as $item) {
            if(in_array($item['id'],$s['restored'],true))continue;
            try {
            if($item['kind']==='docker') {
                $c=$this->docker->call('GET','/containers/'.$item['id'].'/json');
                if($c['Id']!==$item['id'])throw new Blocked('Container identity changed; manual recovery required.');
                $this->docker->call('POST','/containers/'.$item['id'].'/update',['RestartPolicy'=>$item['restart']]);
                if($item['running']&&!$c['State']['Running'])$this->docker->call('POST','/containers/'.$item['id'].'/start');
            } else {
                $args=['/usr/bin/virsh','autostart',$item['id']];if(!$item['autostart'])$args[]='--disable';$this->run($args);
                if($item['running']&&$this->run(['/usr/bin/virsh','domstate',$item['id']])==='shut off')$this->run(['/usr/bin/virsh','start',$item['id']]);
            }
            $s['restored'][]=$item['id'];$this->save($s);
            } catch(\Throwable $e) {$errors[]=$item['name'].': '.$e->getMessage();}
        }
        if($errors) {
            $s['message']='Restoration incomplete. Retry Restore GPU workloads. '.implode(' ', $errors);
            $this->save($s);throw new Blocked($s['message']);
        }
        unset($s['message']);
        $s['state']='complete';$s['finished_at']=gmdate('c');$this->save($s);
    }
    public function rehearse(string $id): void {
        $before=$this->engine->persistent->get('flash');
        if(StartPolicy::active($this->engine->persistent->get('maintenance')))throw new Blocked('Restore the existing maintenance session first.');
        try {
            $this->prepare($id);
            $s=$this->engine->persistent->get('maintenance');$this->assertGuards($s);$this->assertStopped($s);
            foreach($s['items'] as $item)if($item['kind']==='docker') {
                try {
                    $this->docker->call('POST','/containers/'.$item['id'].'/start');
                    $this->docker->call('POST','/containers/'.$item['id'].'/stop?t=60');
                    throw new Blocked('A GPU container start was not blocked.');
                } catch(Blocked $e) {if(!str_contains($e->getMessage(),'IGF_GPU_MAINTENANCE'))throw $e;}
            }
            $this->engine->runtime->put('maintenance-test',['checked_at'=>gmdate('c'),'bdf'=>$s['bdf'],'firmware_written'=>false,
                'message'=>'Targeted stop and start guards verified. Restoring the original workload state.']);
        } finally {
            $owned=$this->engine->persistent->get('maintenance');
            if(($owned['id']??'')===$id && StartPolicy::active($owned))$this->restore();
        }
        if($before!==$this->engine->persistent->get('flash'))throw new Blocked('Unexpected flash journal change.');
        $this->engine->runtime->put('maintenance-test',['checked_at'=>gmdate('c'),'bdf'=>$s['bdf'],'firmware_written'=>false,
            'message'=>'Maintenance rehearsal passed: selected GPU workloads stopped, start guards verified, original policies and running state restored. No firmware was written.']);
    }
    /** Boot only re-arms guards; it never flashes or automatically starts workloads. */
    public function resumeAfterBoot(): void {
        $s=$this->engine->persistent->get('maintenance');
        if(!StartPolicy::active($s))return;
        $boot=$this->engine->hardware->bootId();
        if(($s['boot_id']??'')===$boot)throw new Blocked('Restore maintenance before reinstalling the plugin.');
        if(($s['state']??'')==='restoring') {
            // A prior restore disarmed the guard, but may have died mid-cleanup.
            $this->removeGuards($s);return;
        }
        $s['mapping_uncertain']=true;
        try {
            $now=$this->target($s['bdf']);
            if($now['identity']!==$s['target']['identity'])throw new Blocked('GPU identity changed.');
            // Keep old and new node names reserved after enumeration changes.
            $s['target']['nodes']=array_values(array_unique([...$s['target']['nodes'],...$now['nodes']]));
            $s['target']['numbers']=array_values(array_unique([...$s['target']['numbers'],...$now['numbers']],SORT_REGULAR));
            $s['mapping_uncertain']=false;
        } catch(\Throwable $e) {
            $s['message']='GPU mapping could not be verified after reboot. Managed starts are blocked until recovery: '.$e->getMessage();
        }
        $s['guard_boot_id']=$boot;$s['state']='reboot_guarded';$this->save($s);
        $this->installGuards($s);
    }
    private static function putFile(string $path,string $text,int $mode=0600): void {
        if(is_link($path))throw new Blocked('Refusing a symlink at '.$path);
        if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
        $tmp=$path.'.igf-tmp';if(is_link($tmp))throw new Blocked('Invalid temporary path.');
        if(file_put_contents($tmp,$text,LOCK_EX)!==strlen($text))throw new Blocked('Cannot save maintenance guard.');
        chmod($tmp,$mode);if(!rename($tmp,$path))throw new Blocked('Cannot install maintenance guard.');
    }
    protected function dockerConfig(bool $enable): void {
        $plugin=$this->plugin();
        $path='/etc/docker/daemon.json';$original=is_file($path)?(string)file_get_contents($path):null;
        $config=$original!==null?json_decode($original,true,64,JSON_THROW_ON_ERROR):[];
        if(!is_array($config))throw new Blocked('Invalid Docker daemon configuration.');
        $plugins=$config['authorization-plugins']??[];
        if(!is_array($plugins))throw new Blocked('Invalid Docker authorization configuration.');
        if(!$enable&&!in_array($plugin,$plugins,true))return;
        $backup=$this->engine->persistent->get('guard-config');
        if($enable&&!in_array($plugin,$plugins,true)) {
            $backup=['original'=>$original,'boot_id'=>$this->engine->hardware->bootId()];
            $this->engine->persistent->put('guard-config',$backup);
        }
        $plugins=array_values(array_diff($plugins,[$plugin]));if($enable)$plugins[]=$plugin;
        if($plugins)$config['authorization-plugins']=$plugins;else unset($config['authorization-plugins']);
        $text=json_encode((object)$config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $exactRestore=!$enable && isset($backup['installed_hash']) && hash_equals($backup['installed_hash'],hash('sha256',$original??''));
        if($exactRestore&&$backup['original']!==null)$text=$backup['original'];
        self::putFile($path,$text);
        if($enable){$backup['installed_hash']=hash('sha256',$text);$this->engine->persistent->put('guard-config',$backup);}
        // Reload keeps running containers alive. Verify the host PID, never signal nested dockerd.
        if(is_socket('/var/run/docker.sock')) {
            $pid=trim((string)@file_get_contents('/var/run/dockerd.pid'));
            if(!ctype_digit($pid)||trim((string)@file_get_contents('/proc/'.$pid.'/comm'))!=='dockerd')throw new Blocked('Cannot identify the host Docker daemon for a configuration reload.');
            $this->run(['/bin/kill','-HUP',$pid]);
            for($i=0;$i<50;$i++) {
                usleep(100000);
                if($this->dockerGuardActive()===$enable) {
                    if($exactRestore&&$backup['original']===null)unlink($path);
                    return;
                }
            }
            throw new Blocked('Docker did not activate the requested maintenance guard.');
        }
        if($exactRestore&&$backup['original']===null)unlink($path);
    }
    public function installGuards(array $s): void {
        if(!function_exists('pcntl_fork')||!function_exists('simplexml_load_string'))throw new Blocked('PHP pcntl and SimpleXML are required for targeted maintenance.');
        $base=$this->engine->base;
        if(!is_file('/var/run/intel-gpu-maintenance.pid') || !@posix_kill((int)file_get_contents('/var/run/intel-gpu-maintenance.pid'),0)) {
            // The installer holds fd 9; its long-lived helper must not inherit that lock.
            exec('/usr/bin/nohup /usr/bin/php '.escapeshellarg($base.'/guard-server.php').' 9>&- >/var/log/intel-gpu-maintenance.log 2>&1 &',$out,$code);
            if($code!==0)throw new Blocked('Cannot start Docker maintenance guard.');
        }
        for($i=0;$i<50&&!is_socket('/var/run/intel-gpu-maintenance.sock');$i++)usleep(100000);
        if(!is_socket('/var/run/intel-gpu-maintenance.sock'))throw new Blocked('Docker maintenance guard did not become ready.');
        self::putFile('/etc/docker/plugins/'.$this->plugin().'.spec',"unix:///var/run/intel-gpu-maintenance.sock\n");
        // The main hook runs BEFORE Unraid's existing PCI binding hook. Preserve its bytes.
        $hook='/etc/libvirt/hooks/qemu';$original=$hook.'.igf-original';
        if(!is_file($hook)||!str_contains((string)file_get_contents($hook),'IGF_START_GUARD')) {
            if(is_file($original))throw new Blocked('An earlier libvirt hook backup requires review.');
            if(is_file($hook)&&!rename($hook,$original))throw new Blocked('Cannot preserve the existing libvirt hook.');
            $wrapper="#!/bin/sh\n# IGF_START_GUARD\numask 077\ntmp=\$(mktemp /run/igf-hook.XXXXXX) || exit 1\ntrap 'rm -f \"\$tmp\"' EXIT\ncat >\"\$tmp\"\n/usr/bin/php $base/guard-hook.php \"\$@\" <\"\$tmp\" || exit 1\nif [ -x $original ]; then $original \"\$@\" <\"\$tmp\"; else exit 0; fi\n";
            self::putFile($hook,$wrapper,0755);
        }
        $this->dockerConfig(true);
        $this->assertGuards($s);
    }
    protected function assertGuards(array $s): void {
        if(is_socket('/var/run/docker.sock')) {
            if(!$this->dockerGuardActive())throw new Blocked('Docker start guard is not active.');
        }
        $hook=(string)@file_get_contents('/etc/libvirt/hooks/qemu');
        if(!str_contains($hook,'IGF_START_GUARD')||!is_executable('/etc/libvirt/hooks/qemu'))throw new Blocked('VM start guard is missing.');
        // An actual transient start proves libvirt discovered the hook. This canary has
        // NO host devices, disks or network. It cannot detach or open the real GPU.
        if(is_socket('/var/run/libvirt/libvirt-sock')) {
            $name='igf-guard-probe-'.bin2hex(random_bytes(6));
            $xml='<domain type="kvm"><name>'.$name.'</name><memory unit="MiB">64</memory><vcpu>1</vcpu><os><type arch="x86_64">hvm</type></os><devices/></domain>';
            $path=$this->engine->runtime->dir.'/'.$name.'.xml';self::putFile($path,$xml);
            try {
                $r=$this->engine->exec->run(['/usr/bin/virsh','create',$path],30);
                if($r['code']===0) {
                    $this->engine->exec->run(['/usr/bin/virsh','destroy',$name],30);
                    throw new Blocked('Libvirt did not enforce the VM start guard.');
                }
                if(!str_contains($r['err'].$r['out'],'IGF_GPU_MAINTENANCE'))throw new Blocked('VM start guard probe could not be verified: '.substr($r['err'],0,250));
            } finally {@unlink($path);}
        }
    }
    private function dockerGuardActive(): bool {
        // Docker /info may retain the startup authorization list after a live reload.
        // Prove actual enforcement instead of trusting that informational list.
        try {$this->docker->call('GET','/info?igf-maintenance-probe=1');return false;}
        catch(Blocked $e) {
            if(str_contains($e->getMessage(),'IGF_GPU_MAINTENANCE')&&str_contains($e->getMessage(),$this->plugin()))return true;
            throw $e;
        }
    }
    protected function removeGuards(array $s): void {
        $this->dockerConfig(false);
        $hook='/etc/libvirt/hooks/qemu';$original=$hook.'.igf-original';
        if(is_file($hook)&&str_contains((string)file_get_contents($hook),'IGF_START_GUARD')) {
            if(is_file($original)){if(!rename($original,$hook))throw new Blocked('Cannot restore libvirt hook.');}
            else unlink($hook);
        } elseif(is_file($original))throw new Blocked('Libvirt hook changed; preserve its backup and restore manually.');
        @unlink('/etc/docker/plugins/'.$this->plugin().'.spec');
        // Remove authorization before stopping its helper, so unrelated API calls keep working.
        $pid=trim((string)@file_get_contents('/var/run/intel-gpu-maintenance.pid'));
        if(ctype_digit($pid)&&str_contains((string)@file_get_contents('/proc/'.$pid.'/cmdline'),$this->engine->base.'/guard-server.php')) {
            $this->run(['/bin/kill','-TERM',$pid]);
            @unlink('/var/run/intel-gpu-maintenance.pid');@unlink('/var/run/intel-gpu-maintenance.sock');
        }
    }
}

function is_socket(string $path): bool {return @filetype($path)==='socket';}
