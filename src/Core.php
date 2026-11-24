<?php
declare(strict_types=1);
namespace IntelGpuFirmware;

const VERSION = '2026.11.24h-alpha1';
const NAME = 'intel.gpu.firmware';
const MODE = 'read_only_test';
const WRITES_ENABLED = false;
const WRITE_BLOCK = 'Public alpha: physical firmware writes are disabled. There is no setting to enable them.';

final class Blocked extends \RuntimeException {}

interface Executor {
    /** timeout=0 is reserved for an explicitly authorised firmware write. */
    public function run(array $argv, int $timeout = 45): array;
}

final class Process implements Executor {
    public ?\Closure $progress = null;
    public function run(array $argv, int $timeout = 45): array {
        // Fixed build boundary, not a user setting. Reject writes before spawning any process.
        if($timeout===0 || in_array('update',$argv,true) || in_array('--force',$argv,true)
            || in_array('--allow-downgrade',$argv,true))throw new Blocked(WRITE_BLOCK);
        return $this->execute($argv,$timeout);
    }
    public function writeGsc(string $mei,string $path): array {
        if(!WRITES_ENABLED)throw new Blocked(WRITE_BLOCK);
        if(!preg_match('~\A/dev/mei[0-9]+\z~D',$mei)
            || !preg_match('~\A/var/lib/intel-gpu-firmware/images/Graphics/ifwi/[a-z][a-z0-9_-]{1,31}/fwcode/[a-zA-Z0-9_.-]+\.bin\z~D',$path)
            || realpath($path)!==$path || !is_file($path))
            throw new Blocked('Invalid native firmware target or staged image.');
        $base='/usr/local/libexec/intel-gpu-firmware';
        // The only native write entry point constructs the complete command itself.
        return $this->execute(['/usr/bin/env','LD_LIBRARY_PATH='.$base.'/vendor/lib',
            $base.'/vendor/bin/igsc','fw','update','--device',$mei,'--image',$path],0);
    }
    private function execute(array $argv,int $timeout): array {
        $pipes = [];
        $p = proc_open($argv, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null,
            ['PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL'=>'C']);
        if (!is_resource($p)) throw new Blocked('Could not start the required tool.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $err = ''; $start = microtime(true); $lastProgress=0.0; $exit = -1;
        while (true) {
            $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
            // Bound retained output, but always drain both pipes.
            $out = substr($out, -1048576); $err = substr($err, -65536);
            if($timeout===0 && $this->progress && microtime(true)-$lastProgress>=2) {
                // A progress-display failure must never abort an in-flight flash.
                try { ($this->progress)(substr($out."\n".$err,-2000)); } catch(\Throwable $ignored) {}
                $lastProgress=microtime(true);
            }
            $s = proc_get_status($p);
            if (!$s['running']) { $exit = $s['exitcode']; break; }
            if ($timeout > 0 && microtime(true)-$start > $timeout) {
                proc_terminate($p); usleep(200000);
                if (proc_get_status($p)['running']) proc_terminate($p, 9);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
                throw new Blocked('A tool timed out during a read-only operation. Consult the update journal for the firmware-write outcome.');
            }
            usleep(50000);
        }
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($p);
        return ['code'=>$exit >= 0 ? $exit : $closed,'out'=>$out,'err'=>$err];
    }
}

require_once __DIR__.'/History.php';
require_once __DIR__.'/Validation.php';
require_once __DIR__.'/IntelRelease.php';
require_once __DIR__.'/Workloads.php';
require_once __DIR__.'/AuthServer.php';
require_once __DIR__.'/Maintenance.php';

final class Store {
    public function __construct(public readonly string $dir) {
        if (is_link($dir)) throw new Blocked('State directory may not be a symlink.');
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new Blocked('State storage unavailable.');
    }
    private function path(string $name): string {
        if (!preg_match('/\A[a-z0-9_.-]+\z/D',$name)) throw new Blocked('Invalid state key.');
        return $this->dir.'/'.$name.'.json';
    }
    public function get(string $name, array $default=[]): array {
        $p=$this->path($name);
        if (is_link($p)) throw new Blocked('Invalid state file.');
        if (!file_exists($p)) return $default;
        $v=json_decode((string)file_get_contents($p),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($v)) throw new Blocked('Invalid saved state.');
        return $v;
    }
    public function put(string $name, array $value): void {
        $p=$this->path($name); $tmp=$p.'.'.bin2hex(random_bytes(8)).'.tmp';
        $f=fopen($tmp,'xb'); if (!$f) throw new Blocked('Cannot write state.');
        chmod($tmp,0600);
        $data=json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if (fwrite($f,$data)!==strlen($data) || !fflush($f) || (function_exists('fsync') && !fsync($f))) {
            fclose($f); @unlink($tmp); throw new Blocked('Cannot persist update state.');
        }
        fclose($f);
        if (!rename($tmp,$p)) throw new Blocked('Cannot commit update state.');
        if (file_get_contents($p)!==$data) throw new Blocked('State verification failed.');
    }
}

final class Versions {
    public static function parse(string $text): array {
        if (!preg_match('/(?:Device|Image):\s*FW Version:\s*([A-Za-z0-9_]{4})_(\d+)\.(\d+)\s*(?:$|\n)/',$text,$m))
            throw new Blocked('The firmware version format is not understood by this tool version.');
        if ((int)$m[2]>65535 || (int)$m[3]>65535) throw new Blocked('Invalid firmware version.');
        return ['project'=>$m[1],'hotfix'=>(int)$m[2],'build'=>(int)$m[3],
            'text'=>$m[1].'_'.$m[2].'.'.$m[3]];
    }
    public static function compare(array $a,array $b): int {
        if ($a['project']!==$b['project']) throw new Blocked('Different firmware projects.');
        return [$a['hotfix'],$a['build']] <=> [$b['hotfix'],$b['build']];
    }
}

final class Igsc {
    public function __construct(private Executor $exec, private string $base) {}
    private function argv(array $args): array {
        return ['/usr/bin/env','LD_LIBRARY_PATH='.$this->base.'/vendor/lib',
            $this->base.'/vendor/bin/igsc',...$args];
    }
    public function read(array $args): string {
        if (in_array('update',$args,true) || in_array('--force',$args,true) || in_array('--allow-downgrade',$args,true))
            throw new Blocked('Writing is forbidden in the read-only interface.');
        $allowed=['list-devices','fw','fw-data','oprom-code','oprom-data','image-type','--version'];
        if (!in_array($args[0]??'',$allowed,true)) throw new Blocked('Unsupported tool operation.');
        if (in_array($args[0],['fw','fw-data','oprom-code','oprom-data'],true)
            && !in_array($args[1]??'',['version','hwconfig','supported-devices'],true))
            throw new Blocked('Unsupported read-only operation.');
        $r=$this->exec->run($this->argv($args));
        if ($r['code']!==0) throw new Blocked('Intel tool could not complete the check: '.trim(substr($r['err']?:$r['out'],0,1200)));
        return $r['out'];
    }
    public function devices(): array {
        $out=$this->read(['list-devices','--info']); $result=[];
        preg_match_all("~Device \\[\\d+\\] '(/dev/mei\\d+)': ([0-9a-fA-F]{4}):([0-9a-fA-F]{4}) ([0-9a-fA-F]{4}):([0-9a-fA-F]{4}) ([0-9a-fA-F]{4}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}\\.[0-7])~",$out,$matches,PREG_SET_ORDER);
        foreach($matches as $m) $result[]=['mei'=>$m[1],'vendor'=>strtolower($m[2]),'device'=>strtolower($m[3]),
            'subvendor'=>strtolower($m[4]),'subdevice'=>strtolower($m[5]),'bdf'=>strtolower($m[6])];
        return $result;
    }
    public function version(string $mei): array { return Versions::parse($this->read(['fw','version','--device',$mei])); }
    public function imageVersion(string $path): array { return Versions::parse($this->read(['fw','version','--image',$path])); }
    public function compatible(string $mei,string $path): bool {
        $out=$this->read(['fw','hwconfig','--check','--device',$mei,'--image',$path]);
        // An exit status alone is insufficient if a future CLI silently changes its contract.
        if (!str_contains($out,'image: hw sku:') || !str_contains($out,'device: hw sku:'))
            throw new Blocked('Hardware compatibility result is incomplete.');
        return true;
    }
    public function writeGsc(string $mei,string $path): array {
        if (!preg_match('~\A/dev/mei[0-9]+\z~D',$mei)) throw new Blocked('Invalid target interface.');
        if($this->exec instanceof Process)return $this->exec->writeGsc($mei,$path);
        // Never use force/downgrade, shell interpolation, implicit first-device selection or a kill timeout.
        return $this->exec->run($this->argv(['fw','update','--device',$mei,'--image',$path]),0);
    }
    public static function assertWriteResult(array $result,array $from,array $to): void {
        // IGSC 1.3.2 overwrites the update return code during its later version read.
        // Even exit 0 plus the target version must not erase earlier diagnostics.
        if(($result['code']??-1)!==0 || trim($result['err']??'')!==''
            || preg_match('/\b(error|failed|failure)\b/i',$result['out']??''))
            throw new Blocked('Intel reported an error or uncertain update result. Do not retry automatically.');
        preg_match_all('/(?:Image|Device):\s*FW Version:\s*([A-Za-z0-9_]{4}_\d+\.\d+)/',
            $result['out']??'', $versions);
        if(($versions[1]??[])!==[$to['text'],$from['text'],$to['text']])
            throw new Blocked('Intel update output is incomplete or does not match this plan. Do not retry automatically.');
    }
}

class Hardware {
    public ?\Closure $maintenanceGuard = null;
    public function __construct(private Executor $exec,private string $sys='/sys',private string $proc='/proc') {}
    protected function executable(string $path): bool { return is_executable($path); }
    public function bootId(): string {
        $id=trim((string)@file_get_contents($this->proc.'/sys/kernel/random/boot_id'));
        if (!preg_match('/\A[a-f0-9-]{36}\z/D',$id)) throw new Blocked('Cannot identify this boot.');
        return $id;
    }
    public function cards(): array {
        $cards=[];
        foreach (glob($this->sys.'/bus/pci/devices/*')?:[] as $p) {
            $vendor=$this->hex($p.'/vendor'); $class=$this->hex($p.'/class');
            if ($vendor!=='8086' || !str_starts_with($class,'03')) continue;
            $bdf=basename($p);
            if (!preg_match('/\A[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]\z/D',$bdf)) continue;
            $driver=realpath($p.'/driver');
            $card=['bdf'=>$bdf,'vendor'=>$vendor,'device'=>$this->hex($p.'/device'),
                'subvendor'=>$this->hex($p.'/subsystem_vendor'),'subdevice'=>$this->hex($p.'/subsystem_device'),
                'revision'=>$this->hex($p.'/revision'),'driver'=>$driver?basename($driver):'unbound',
                'boot_vga'=>trim((string)@file_get_contents($p.'/boot_vga'))==='1',
                'vfs'=>(int)@file_get_contents($p.'/sriov_numvfs')];
            $card['name']='Intel GPU '.$card['device']; $card['board_vendor']=$this->brand($card['subvendor']);
            if ($this->executable('/sbin/lspci') || $this->executable('/usr/bin/lspci')) {
                $cmd=$this->executable('/sbin/lspci')?'/sbin/lspci':'/usr/bin/lspci';
                $r=$this->exec->run([$cmd,'-D','-s',$bdf],10);
                if ($r['code']===0 && trim($r['out'])!=='') $card['name']=trim(preg_replace('/^[^ ]+ [^:]+: /','',$r['out']));
            }
            $cards[]=$card;
        }
        return $cards;
    }
    private function hex(string $p): string { return strtolower(preg_replace('/^0x/','',trim((string)@file_get_contents($p)))); }
    private function brand(string $id): string {
        return ['8086'=>'Intel','1849'=>'ASRock','1043'=>'ASUS','172f'=>'Sparkle','1025'=>'Acer','1ef7'=>'GUNNIR',
            '1458'=>'Gigabyte','1028'=>'Dell','103c'=>'HP','17aa'=>'Lenovo'][$id]??('Board vendor '.$id);
    }
    public function matchInterface(array $card,array $interface): bool {
        foreach(['bdf','vendor','device','subvendor','subdevice'] as $k)
            if (($card[$k]??null)!==($interface[$k]??null)) return false;
        $path=realpath($this->sys.'/class/mei/'.basename($interface['mei']).'/device');
        return $path!==false && str_contains($path,'/'.$card['bdf'].'/');
    }
    public function maintenance(array $card): array {
        $reasons=[];
        if (!in_array($card['driver'],['i915','xe'],true)) $reasons[]='Bind the GPU to its Intel host driver; passthrough and unbound devices cannot be updated.';
        if ($card['boot_vga']) $reasons[]='This GPU is the boot display. Use another display adapter before updating.';
        if ($card['vfs']>0) $reasons[]='SR-IOV virtual functions are active.';
        if($this->maintenanceGuard) {
            try {($this->maintenanceGuard)($card);}catch(\Throwable $e){$reasons[]=$e->getMessage();}
        } else {
            // Test hardware can inject its own guard. Production always supplies one.
            foreach(glob($this->proc.'/[0-9]*/comm')?:[] as $p)
                if(preg_match('/^(dockerd|containerd|libvirtd|virtqemud|qemu-system.*|qemu-kvm)$/',trim((string)@file_get_contents($p))))
                    $reasons[]='Targeted maintenance start guards are unavailable.';
        }
        $nodes=[];
        foreach(glob($this->sys.'/class/drm/*')?:[] as $p) {
            if (!preg_match('/^(card[0-9]+|renderD[0-9]+)$/',basename($p))) continue;
            $target=realpath($p.'/device');
            if ($target && basename($target)===$card['bdf']) $nodes[]='/dev/dri/'.basename($p);
        }
        if (!$nodes) $reasons[]='The GPU render devices cannot be identified.';
        if (!$this->executable('/usr/bin/fuser')) $reasons[]='The device-use checker is unavailable.';
        elseif ($nodes) {
            $r=$this->exec->run(['/usr/bin/fuser',...$nodes,$card['mei']],15);
            if ($r['code']===0) $reasons[]='A process still has the GPU or firmware interface open.';
            elseif ($r['code']!==1 || trim($r['err'])!=='') $reasons[]='Device-use status could not be verified.';
        }
        try {$power=$this->power();}
        catch(\Throwable $e) {$power=['ok'=>false,'reason'=>'UPS monitoring could not be read.'];}
        // Power monitoring is advice, never a prerequisite or a reason to abort an update.
        $power['required']=false;
        return ['ready'=>!$reasons,'reasons'=>array_values(array_unique($reasons)),'power'=>$power,
            'warnings'=>$power['ok']?[]:[$power['reason'].' A UPS is recommended, not required. Keep power stable throughout the update.']];
    }
    public function power(): array {
        if ($this->executable('/usr/bin/upsc')) {
            $list=$this->exec->run(['/usr/bin/upsc','-l','localhost'],10);
            $names=preg_split('/\s+/',trim($list['out']));
            if($list['code']===0 && count($names)>1)
                return ['ok'=>false,'reason'=>'Multiple UPS devices are reported; the server power source is uncertain.'];
            if ($list['code']===0) foreach($names as $ups) {
                if (!preg_match('/\A[a-zA-Z0-9_.-]+\z/D',$ups)) continue;
                $r=$this->exec->run(['/usr/bin/upsc',$ups.'@localhost'],10);
                if ($r['code']!==0) continue;
                $v=[];foreach(explode("\n",$r['out']) as $line) {
                    $pair=explode(': ',$line,2);if(count($pair)===2)$v[$pair[0]]=$pair[1];
                }
                $status=explode(' ',$v['ups.status']??'');
                if (in_array('OL',$status,true) && !array_intersect($status,['OB','LB','FSD'])
                    && is_numeric($v['battery.runtime']??null) && (float)$v['battery.runtime']>=600
                    && is_numeric($v['battery.charge']??null) && (float)$v['battery.charge']>=50)
                    return ['ok'=>true,'reason'=>'UPS online; at least 10 minutes of reported runtime.'];
                return ['ok'=>false,'reason'=>'UPS is not online with at least 50% charge and 10 minutes of reported runtime.'];
            }
        }
        $apc=$this->executable('/sbin/apcaccess')?'/sbin/apcaccess':'/usr/sbin/apcaccess';
        if($this->executable($apc)) {
            $r=$this->exec->run([$apc,'status'],10);
            if($r['code']===0 && preg_match('/^STATUS\s*:\s*ONLINE\s*$/m',$r['out'])
                && preg_match('/^TIMELEFT\s*:\s*([0-9.]+)/m',$r['out'],$t)
                && preg_match('/^BCHARGE\s*:\s*([0-9.]+)/m',$r['out'],$c)
                && (float)$t[1]>=10 && (float)$c[1]>=50) return ['ok'=>true,'reason'=>'UPS online; at least 10 minutes of reported runtime.'];
        }
        return ['ok'=>false,'reason'=>'No suitable UPS status is available through NUT or apcupsd.'];
    }
}

final class Catalog {
    private array $data;
    public function __construct(string $path) {
        $this->data=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if (($this->data['schema']??0)!==1) throw new Blocked('Unsupported catalog.');
    }
    public function package(): array { return $this->data['package']; }
    public function images(): array { return $this->data['images']; }
    public function image(string $hash): array {
        foreach($this->images() as $image) if(hash_equals($image['sha256'],$hash)) return $image;
        throw new Blocked('Image is not in the trusted catalog.');
    }
    public static function checkedPath(string $root,array $image): string {
        if(!preg_match('~\AGraphics/ifwi/[a-z][a-z0-9_-]{1,31}/[a-z0-9_-]+/[a-zA-Z0-9_.-]+\.(bin|rom)\z~D',$image['path']))
            throw new Blocked('Invalid image path in catalog.');
        $p=$root.'/'.$image['path'];$rp=realpath($p);$rr=realpath($root);
        if(!$rp||!$rr||!str_starts_with($rp,$rr.'/')||is_link($p)||!is_file($p)
            ||filesize($p)!==$image['size']||!hash_equals($image['sha256'],hash_file('sha256',$p)))
            throw new Blocked('Image is missing or its checksum does not match.');
        return $rp;
    }
}

final class Engine {
    public function __construct(public Igsc $igsc,public Hardware $hardware,public Catalog $catalog,
        public Store $runtime,public Store $persistent,public Executor $exec,public string $base) {}
    public static function production(): self {
        $base='/usr/local/libexec/intel-gpu-firmware'; $exec=new Process();
        $e=new self(new Igsc($exec,$base),new Hardware($exec),new Catalog($base.'/catalog.json'),
            new Store('/var/lib/intel-gpu-firmware'),new Store('/boot/config/plugins/'.NAME.'/state'),$exec,$base);
        $e->hardware->maintenanceGuard=fn($card)=>(new Maintenance($e))->assertReady($card);
        return $e;
    }
    public function imageRoot(): string { return $this->runtime->dir.'/images'; }
    public function scan(): array {
        $journal=$this->persistent->get('flash');
        if($journal && !in_array($journal['state']??'',['complete','reboot_required','not_started'],true)
            && ($journal['plan']['boot_id']??'')===$this->hardware->bootId())
            throw new Blocked('An unfinished firmware write exists for this boot. Further device access is blocked pending review.');
        $toolError=null;$interfaces=[];
        try {
            $v=$this->igsc->read(['--version']);
            if (!preg_match('/igsc version 1\.3\.2\s/',$v)) throw new Blocked('This build requires the bundled Intel tool version 1.3.2.');
            $interfaces=$this->igsc->devices();
        } catch(\Throwable $e) { $toolError=$e->getMessage(); }
        $cards=$this->hardware->cards();
        foreach($cards as &$card) {
            $card['validation']=Validation::forCard($card);
            $card['capability']='inventory';$card['reason']=$toolError??'No Intel firmware-update interface is exposed. Integrated graphics may use BIOS and Unraid runtime firmware instead.';
            foreach($interfaces as $interface) {
                if(!$this->hardware->matchInterface($card,$interface)) continue;
                if(isset($card['mei'])) { $card['capability']='inventory';$card['reason']='Ambiguous firmware interfaces.';unset($card['mei']);break; }
                $card['mei']=$interface['mei'];
                try {
                    $card['firmware']=$this->igsc->version($interface['mei']);
                    $card['hardware']=$this->igsc->read(['fw','hwconfig','--device',$interface['mei']]);
                    $card['components']=[];
                    foreach(['fw-data','oprom-code','oprom-data'] as $part) {
                        try { $card['components'][$part]=$this->igsc->read([$part,'version','--device',$interface['mei']]); }
                        catch(\Throwable $e) { $card['components'][$part]='Unavailable'; }
                    }
                    $card['capability']='inspect';$card['reason']='Intel firmware interface available. Package compatibility still needs to be checked.';
                    $card['evidence']='Detected on this machine; hardware update not certified.';
                } catch(\Throwable $e) { $card['reason']=$e->getMessage(); }
            }
        } unset($card);
        $result=['version'=>VERSION,'mode'=>MODE,'writes_enabled'=>WRITES_ENABLED,'scanned_at'=>gmdate('c'),'cards'=>$cards,'package'=>$this->catalog->package(),
            'tool_error'=>$toolError,'scope'=>'GSC firmware only. OPROM, board data, HDMI firmware and runtime firmware are not written.'];
        $this->runtime->put('inventory',$result); return $result;
    }
    public function current(string $bdf): array {
        foreach($this->scan()['cards'] as $card) if($card['bdf']===$bdf && $card['capability']==='inspect') return $card;
        throw new Blocked('The selected GPU no longer has a verified Intel update interface.');
    }
    public static function fingerprint(array $card): string {
        $identity=[];foreach(['bdf','vendor','device','subvendor','subdevice','revision','driver','mei','firmware','hardware','components'] as $k)
            $identity[$k]=$card[$k]??null;
        return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
    }
    public function stage(): void {
        $this->runtime->put('staged',[]);
        $package=$this->catalog->package();
        if(!preg_match('~\Ahttps://downloadmirror\.intel\.com/[0-9]+/[a-zA-Z0-9_.-]+\.exe\z~D',$package['url'])
            ||!preg_match('/\A[a-f0-9]{128}\z/D',$package['sha512'])) throw new Blocked('Invalid catalog source.');
        if(disk_free_space($this->runtime->dir)<$package['size']*2+268435456) throw new Blocked('At least 2.1 GB of free temporary space is needed.');
        $archive=$this->runtime->dir.'/intel-package.exe';
        try {
            $r=$this->exec->run(['/usr/bin/curl','--fail','--silent','--show-error','--proto','=https',
                '--tlsv1.2','--connect-timeout','20','--max-time','900','--max-filesize',(string)$package['size'],
                '--output',$archive,$package['url']],930);
        } catch(\Throwable $e) { @unlink($archive); throw $e; }
        if($r['code']!==0 || !is_file($archive) || filesize($archive)!==$package['size']
            ||!hash_equals($package['sha512'],hash_file('sha512',$archive))) {
            @unlink($archive); throw new Blocked('Intel package download or checksum verification failed.');
        }
        try {
            $root=$this->imageRoot();if(!is_dir($root))mkdir($root,0700,true);
            $paths=array_column($this->catalog->images(),'path');
            $r=$this->exec->run([$this->base.'/vendor/bin/7zz','x','-y','-mmt=2','-o'.$root,$archive,...$paths],600);
            // A signed PE wrapper can cause a trailing-data warning. Independent hashes are mandatory.
            if(!in_array($r['code'],[0,1],true)) throw new Blocked('Firmware extraction failed.');
            foreach($this->catalog->images() as $image) Catalog::checkedPath($root,$image);
        } finally { @unlink($archive); }
        $this->runtime->put('staged',['package'=>$package['version'],'checked_at'=>gmdate('c')]);
    }
    public function check(string $bdf): array {
        // A failed/new check must never leave an earlier executable plan behind.
        $this->runtime->put('plan',[]);
        $this->runtime->put('check',[]);
        $card=$this->current($bdf);$matches=[];$notes=[];
        foreach($this->catalog->images() as $image) {
            if($image['component']!=='gsc')continue;
            $path=Catalog::checkedPath($this->imageRoot(),$image);
            try {
                $version=$this->igsc->imageVersion($path);
                if(Versions::compare($version,$card['firmware'])<=0)continue;
                $this->igsc->compatible($card['mei'],$path);
                $matches[]=['image'=>$image,'version'=>$version];
            } catch(Blocked $e) { $notes[]=$e->getMessage(); }
        }
        if(count($matches)!==1) {
            $result=['bdf'=>$bdf,'eligible'=>false,'reason'=>count($matches)>1
                ?'More than one compatible image was found. An explicit package profile is required.'
                :'No uniquely verified newer GSC image is available in this catalog. This does not establish that every firmware component is current.',
                'checked_at'=>gmdate('c')];
            $this->runtime->put('check',$result);return $result;
        }
        $match=$matches[0];$maintenance=$this->hardware->maintenance($card);
        $plan=['id'=>bin2hex(random_bytes(16)),'bdf'=>$bdf,'fingerprint'=>self::fingerprint($card),
            'plugin_version'=>VERSION,'mode'=>MODE,
            'identity'=>array_intersect_key($card,array_flip(['vendor','device','subvendor','subdevice','revision'])),
            'boot_id'=>$this->hardware->bootId(),'created_at'=>time(),'expires_at'=>time()+600,
            'image_sha256'=>$match['image']['sha256'],'from'=>$card['firmware'],'to'=>$match['version'],
            'component'=>'gsc','package'=>$this->catalog->package()['version'],'confirmation'=>'UPDATE '.$bdf];
        $this->runtime->put('plan',$plan);
        $result=['bdf'=>$bdf,'eligible'=>true,'plan'=>$plan,'maintenance'=>$maintenance,
            'checked_at'=>gmdate('c'),'reason'=>'Newer GSC image verified by Intel hardware checks. This is not a hardware certification or rollback guarantee.'];
        $this->runtime->put('check',$result);return $result;
    }
    public static function assertPlan(array $plan,string $id,string $confirmation,string $boot,int $now): void {
        if(($plan['plugin_version']??'')!==VERSION || ($plan['mode']??'')!==MODE)
            throw new Blocked('The plugin changed. Run a new compatibility check.');
        if(($plan['id']??'')!==$id || !hash_equals($plan['confirmation']??'', $confirmation))
            throw new Blocked('Confirmation does not match this exact update plan.');
        if(($plan['boot_id']??'')!==$boot || $now>($plan['expires_at']??0) || $now<($plan['created_at']??PHP_INT_MAX))
            throw new Blocked('This plan expired or belongs to another boot. Check the package again.');
    }
    public function dryRun(string $bdf): array {
        $this->assertNoUnfinishedWrite();
        $checked=$this->check($bdf);
        if(!$checked['eligible'])throw new Blocked($checked['reason']);
        $plan=$checked['plan'];
        self::assertPlan($plan,$plan['id'],$plan['confirmation'],$this->hardware->bootId(),time());
        $card=$this->current($bdf);
        if(!hash_equals($plan['fingerprint'],self::fingerprint($card)))throw new Blocked('Card changed during the dry test.');
        $image=$this->catalog->image($plan['image_sha256']);
        $path=Catalog::checkedPath($this->imageRoot(),$image);
        $version=$this->igsc->imageVersion($path);
        if($version!==$plan['to'] || Versions::compare($version,$card['firmware'])<=0)
            throw new Blocked('Target version changed during the dry test.');
        $this->igsc->compatible($card['mei'],$path);
        $maintenance=$this->hardware->maintenance($card);
        $result=['mode'=>MODE,'checked_at'=>gmdate('c'),'bdf'=>$bdf,'from'=>$card['firmware'],'to'=>$version,
            'checks'=>['fresh_identity'=>true,'image_hash'=>true,'newer_version'=>true,'intel_compatibility'=>true,
                'plan_valid'=>true,'state_storage'=>true],
            'maintenance'=>$maintenance,'writes_enabled'=>false,'firmware_written'=>false,
            'message'=>'Dry test completed. Firmware was not written. Maintenance blockers are reported without stopping services.'];
        $this->persistent->put('dry-run',$result);
        if($this->persistent->get('dry-run')!==$result)throw new Blocked('Dry-test state read-back failed.');
        $this->runtime->put('dry-run',$result);
        return $result;
    }
    public function assertNoUnfinishedWrite(): void {
        $j=$this->persistent->get('flash');
        if($j && !in_array($j['state']??'', ['complete','not_started'],true))
            throw new Blocked('An earlier update needs verification or attention. Automatic retry is disabled.');
    }
    public function assertPlanUnconsumed(string $id): void {
        if(($this->persistent->get('flash')['plan']['id']??null)===$id)
            throw new Blocked('This update plan was already consumed. Review compatibility again.');
    }
    /** Called by the locked worker before restoring workloads, never to retry a write. */
    public function recoverPreflight(): void {
        $j=$this->persistent->get('flash');
        if(($j['state']??'')!=='preflight')return;
        // This phase precedes the durable flashing marker. No writer may run in it.
        // Legacy flashing/needs_attention records deliberately do not qualify.
        if(($j['journal_schema']??0)!==2)throw new Blocked('Unknown preflight journal; manual review required.');
        $j['state']='not_started';$j['finished_at']=gmdate('c');
        $j['last_message']='Preparation was interrupted before the write boundary. No firmware writer was invoked. Workloads may be restored; make a new plan before any update.';
        $this->persistent->put('flash',$j);
        if($this->exec->run(['/bin/sync','-f',$this->persistent->dir],30)['code']!==0)
            throw new Blocked('Cannot flush the recovery record. Workloads remain guarded.');
        $this->runtime->put('plan',[]);$this->runtime->put('check',[]);
    }
    private function assertTargetStillPresent(array $expected): void {
        $found=null;
        foreach($this->hardware->cards() as $card)if($card['bdf']===$expected['bdf'])$found=$card;
        if(!$found)throw new Blocked('GPU disappeared before the write.');
        foreach(['vendor','device','subvendor','subdevice','revision','driver','boot_vga','vfs'] as $key)
            if(($found[$key]??null)!==($expected[$key]??null))throw new Blocked('GPU identity or driver changed before the write.');
        $matches=[];
        foreach($this->igsc->devices() as $interface)
            if($this->hardware->matchInterface($found,$interface))$matches[]=$interface;
        if(count($matches)!==1 || $matches[0]['mei']!==$expected['mei'])
            throw new Blocked('The GPU firmware interface changed before the write.');
    }
    public function flash(string $planId,string $confirmation): void {
        // Native execution additionally requires the separately built candidate release.
        if($this->exec instanceof Process && !WRITES_ENABLED)throw new Blocked(WRITE_BLOCK);
        $this->assertNoUnfinishedWrite();
        $this->assertPlanUnconsumed($planId);
        $plan=$this->runtime->get('plan');
        self::assertPlan($plan,$planId,$confirmation,$this->hardware->bootId(),time());
        $card=$this->current($plan['bdf']);
        if(!hash_equals($plan['fingerprint'],self::fingerprint($card)))throw new Blocked('Card identity or firmware changed after the check.');
        $image=$this->catalog->image($plan['image_sha256']);
        if($image['component']!=='gsc')throw new Blocked('This release only writes GSC firmware.');
        $path=Catalog::checkedPath($this->imageRoot(),$image);
        $to=$this->igsc->imageVersion($path);
        if($to!==$plan['to']||Versions::compare($to,$card['firmware'])<=0)throw new Blocked('Target firmware changed or is not newer.');
        $this->igsc->compatible($card['mei'],$path);
        $ready=$this->hardware->maintenance($card);
        if(!$ready['ready'])throw new Blocked(implode(' ', $ready['reasons']));
        // preflight is recoverable after worker death: no writer can run before the
        // separate flashing marker has been saved AND flushed below.
        $journal=['journal_schema'=>2,'state'=>'preflight','plan'=>$plan,'started_at'=>gmdate('c'),'last_message'=>'Final preparation. Firmware writing has not started.'];
        $this->persistent->put('flash',$journal);
        $writeAttempted=false;
        try {
            // Flush the containing filesystem too, including the rename of the journal.
            if($this->exec->run(['/bin/sync','-f',$this->persistent->dir],30)['code']!==0)
                throw new Blocked('Could not flush the update journal. No firmware write was started.');
            // Power/workload status and plan lifetime can change while storage is flushed.
            self::assertPlan($plan,$planId,$confirmation,$this->hardware->bootId(),time());
            $this->assertTargetStillPresent($card);
            $ready=$this->hardware->maintenance($card);
            if(!$ready['ready'])throw new Blocked(implode(' ', $ready['reasons']));
            Catalog::checkedPath($this->imageRoot(),$image);
            $journal['state']='flashing';$journal['last_message']='Intel update may be in progress. Do not reboot or remove power.';
            $this->persistent->put('flash',$journal);
            if($this->exec->run(['/bin/sync','-f',$this->persistent->dir],30)['code']!==0)
                throw new Blocked('Could not flush the write-intent record. No firmware writer was invoked.');
            self::assertPlan($plan,$planId,$confirmation,$this->hardware->bootId(),time());
            $this->assertTargetStillPresent($card);
            $ready=$this->hardware->maintenance($card);
            if(!$ready['ready'])throw new Blocked(implode(' ', $ready['reasons']));
            Catalog::checkedPath($this->imageRoot(),$image);
            if($this->exec instanceof Process)$this->exec->progress=function(string $text): void {
                $this->runtime->put('progress',['text'=>$text,'updated_at'=>gmdate('c')]);
            };
            // From here onward, exceptions and crashes are uncertain, even if the
            // native tool never actually starts. Never infer safety from its PID.
            $writeAttempted=true;
            $r=$this->igsc->writeGsc($card['mei'],$path);
            $journal['tool_output']=substr($r['out']."\n".$r['err'],-16384);
            Igsc::assertWriteResult($r,$plan['from'],$to);
            $journal['state']='verifying';$this->persistent->put('flash',$journal);
            $after=$this->igsc->version($card['mei']);
            if($after!==$to)throw new Blocked('The read-back version does not match the target.');
            $journal['state']='reboot_required';
            $journal['last_message']='GSC version verified. Reboot in Unraid, then use Verify after reboot. Other firmware components were not changed.';
            $this->persistent->put('flash',$journal);
            if($this->exec->run(['/bin/sync','-f',$this->persistent->dir],30)['code']!==0)
                throw new Blocked('The update finished but its result could not be flushed. Keep the server powered and inspect the journal.');
        } catch(\Throwable $e) {
            $journal['state']=$writeAttempted?'needs_attention':'not_started';$journal['last_message']=$e->getMessage();
            if(!$writeAttempted) {
                $journal['last_message'].=' No firmware writer was invoked. Restore GPU workloads, then review again.';
                $journal['finished_at']=gmdate('c');
                $this->runtime->put('plan',[]);$this->runtime->put('check',[]);
            }
            $this->persistent->put('flash',$journal);
            // If persistence fails the prior marker stays conservative; do not restore here.
            if($this->exec->run(['/bin/sync','-f',$this->persistent->dir],30)['code']!==0)
                throw new Blocked('Could not flush the outcome. Inspect persistent storage before recovery.');
            throw $e;
        }
    }
    public function verify(): void {
        $j=$this->persistent->get('flash');
        if(($j['state']??'')!=='reboot_required')throw new Blocked('No successful update is waiting for reboot verification.');
        if($this->hardware->bootId()===$j['plan']['boot_id'])throw new Blocked('Reboot the server first.');
        $card=$this->current($j['plan']['bdf']);
        foreach($j['plan']['identity'] as $k=>$v)if(($card[$k]??null)!==$v)
            throw new Blocked('Card identity changed after the update.');
        if($card['firmware']!==$j['plan']['to'])throw new Blocked('Target firmware is not active after reboot.');
        $j['state']='complete';$j['verified_at']=gmdate('c');
        $j['last_message']='GSC version and Intel interface verified after reboot. Test your applications before resuming normal service.';
        $this->persistent->put('flash',$j);
        if($this->exec->run(['/bin/sync','-f',$this->persistent->dir],30)['code']!==0) {
            $j['state']='needs_attention';$j['last_message']='Post-reboot verification could not be flushed to persistent storage.';
            $this->persistent->put('flash',$j);throw new Blocked($j['last_message']);
        }
    }
    public function report(): array {
        $inventory=$this->runtime->get('inventory');$cards=[];
        foreach($inventory['cards']??[] as $c) {
            $public=[];foreach(['vendor','device','subvendor','subdevice','revision','driver','firmware','capability','validation'] as $k)
                if(isset($c[$k]))$public[$k]=$c[$k];
            $cards[]=$public;
        }
        // No hostname, IP, serial, BDF, local paths, container names or raw logs.
        return ['schema'=>1,'plugin'=>VERSION,'igsc'=>'1.3.2','cards'=>$cards,
            'note'=>'Inventory only. Does not certify a successful firmware update.'];
    }
}
