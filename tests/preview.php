<?php
// Local visual fixture only. Never includes Core/Http/worker or invokes a command.
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$root=dirname(__DIR__);
require $root.'/src/Validation.php';
if($path==='/plugins/intel.gpu.firmware/intel-gpu-firmware.svg'){header('Content-Type: image/svg+xml');readfile($root.'/web/intel-gpu-firmware.svg');exit;}
if(in_array($path,['/app.js','/style.css'],true)) {
    header('Content-Type: '.($path==='/app.js'?'text/javascript':'text/css'));readfile($root.'/web'.$path);exit;
}
if(str_starts_with($path,'/preview-api/')) {
    header('Content-Type: application/json');header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']==='POST') {
        $request=json_decode($_POST['payload']??'{}',true);
        if(($request['action']??'')==='flash') {
            http_response_code(403);echo json_encode(['error'=>'Preview only: no firmware can be written.']);exit;
        }
        http_response_code(202);echo json_encode(['job_id'=>'fixture']);exit;
    }
    if(($_GET['action']??'')==='report') {echo json_encode(['preview'=>true,'vendor'=>'8086','device'=>'56a5']);exit;}
    $ready=str_ends_with($path,'/ready');
    $readonly=str_ends_with($path,'/readonly');
    $card=['name'=>'Intel Arc A380 · example','board_vendor'=>'ASRock','bdf'=>'0000:03:00.0','vendor'=>'8086','device'=>'56a5','subvendor'=>'1849','subdevice'=>'6004',
        'capability'=>'inspect','firmware'=>['text'=>'DG02_2.2353'],'driver'=>'i915','reason'=>'Intel firmware interface available. Package compatibility still needs to be checked.',
        'components'=>['fw-data'=>"Format: 1\nMajor Version: 101",'oprom-code'=>'14 00 2C 04 00 00 00 00','oprom-data'=>'14 00 28 04 00 00 00 00']];
    $integrated=['name'=>'Intel integrated graphics · example','board_vendor'=>'ASUS','bdf'=>'0000:00:02.0','vendor'=>'8086','device'=>'3e92','subvendor'=>'1043','subdevice'=>'0000',
        'capability'=>'inventory','driver'=>'i915','reason'=>'No Intel firmware-update interface is exposed. Integrated graphics may use BIOS and Unraid runtime firmware instead.'];
    $card['revision']='05';$card['validation']=\IntelGpuFirmware\Validation::forCard($card);
    $integrated['validation']=\IntelGpuFirmware\Validation::forCard($integrated);
    $package=['version'=>'32.0.101.9175'];
    $release=['status'=>'catalog_current','checked_at'=>'2026-11-24T14:00:00Z','latest'=>$package,
        'releases'=>[['first_seen_at'=>'2026-11-24T14:00:00Z','package'=>$package]]];
    if(str_ends_with($path,'/new')){$release['status']='new_package';$release['latest']['version']='32.0.101.9999';}
    if(str_ends_with($path,'/offline')){$release['status']='unknown';$release['attempted_at']='2026-11-25T10:00:00Z';$release['error']='Intel could not be reached.';}
    $history=['schema'=>1,'cards'=>['example'=>['identity'=>['bdf'=>$card['bdf']],'name'=>$card['name'],
        'last_observation'=>['at'=>'2026-11-24T14:00:00Z','versions'=>['gsc'=>$card['firmware']['text'],'components'=>$card['components']]],
        'events'=>[
            ['id'=>'one','kind'=>'update','at'=>'2026-11-23T10:00:00Z','from'=>'DG02_2.2300','to'=>'DG02_2.2353','state'=>'complete','verified_at'=>'2026-11-23T10:10:00Z','package'=>'example'],
            ['id'=>'two','kind'=>'observed','at'=>'2026-11-24T14:00:00Z','versions'=>['gsc'=>$card['firmware']['text']]],
            ['id'=>'three','kind'=>'available','at'=>'2026-11-24T14:01:00Z','from'=>'DG02_2.2353','to'=>'DG02_2.2359','package'=>$package['version']]
        ]]]];
    echo json_encode(['catalog'=>$package,'intel_release'=>$release,'history'=>$history,'version'=>'2026.11.24i-alpha2','writes_enabled'=>!$readonly,'mode'=>$readonly?'read_only_test':'experimental_write','inventory'=>['scanned_at'=>'2026-11-24T14:00:00Z','cards'=>[$card,$integrated]],'staged'=>['checked_at'=>'2026-11-24T14:00:00Z'],
        'workloads'=>['id'=>str_repeat('a',32),'bdf'=>$card['bdf'],'checked_at'=>'2026-11-24T14:00:00Z','expires_at'=>time()+600,'blockers'=>[],'items'=>[['kind'=>'docker','name'=>'Plex','running'=>true,'reasons'=>['Device: /dev/dri/renderD128']],['kind'=>'docker','name'=>'Example runner','running'=>true,'reasons'=>['Privileged: access to all host devices']]]],
        'job'=>['state'=>'complete'],'flash'=>[],
        'check'=>['checked_at'=>'2026-11-24T14:00:00Z','bdf'=>$card['bdf'],'eligible'=>true,
            'reason'=>'Newer GSC image verified by Intel hardware checks. This is not a hardware certification or rollback guarantee.',
            'plan'=>['id'=>str_repeat('1',32),'bdf'=>$card['bdf'],'from'=>['text'=>'DG02_2.2353'],'to'=>['text'=>'DG02_2.2359'],
                'expires_at'=>time()+600,'package'=>'32.0.101.9175','confirmation'=>'UPDATE '.$card['bdf']],
            'maintenance'=>['ready'=>$ready,'reasons'=>$ready?[]:['Review GPU users and prepare targeted maintenance first.'],
                'power'=>['ok'=>false,'required'=>false,'reason'=>'No suitable UPS status is available.'],
                'warnings'=>['No suitable UPS status is available. A UPS is recommended, not required. Keep power stable throughout the update.']]]]);exit;
}
$dark=$path==='/dark';$ready=$path==='/ready';$mode=match($path){'/alpha'=>'alpha','/readonly'=>'readonly','/new'=>'new','/offline'=>'offline','/ready'=>'ready',default=>'blocked'};
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Intel GPU Firmware · local preview</title>
<style>body{margin:0;font:15px -apple-system,BlinkMacSystemFont,Arial,sans-serif;background:<?=$dark?'#1d2024':'#fafaf9'?>;color:<?=$dark?'#e8e7e5':'#252621'?>;color-scheme:<?=$dark?'dark':'light'?>}.preview-bar{padding:10px 24px;background:#282d32;color:#fff;font-size:12px;letter-spacing:.3px}.preview-bar a{color:#ffb97b;margin-left:16px}iframe{display:block;width:390px;height:1800px;border:1px solid #777;margin:20px auto;max-width:100%}</style>
<div class="preview-bar">LOCAL FIXTURE · NO HARDWARE ACCESS <a href="/alpha">Public alpha</a><a href="/">Blocked</a><a href="/ready">Ready</a><a href="/readonly">Read-only</a><a href="/dark">Dark</a><a href="/mobile">Mobile 390px</a></div>
<?php if($path==='/mobile'): ?><iframe src="/" title="Mobile preview"></iframe><?php else: ?>
<link rel="stylesheet" href="/style.css"><div id="igf" data-api="/preview-api/<?=$mode?>" data-csrf="local-preview-csrf-not-a-secret">
<?php include $root.'/web/view.html'; ?></div><script src="/app.js"></script>
<?php endif; ?></html>
