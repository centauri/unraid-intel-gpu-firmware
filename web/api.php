<?php
declare(strict_types=1);
// This endpoint is served only inside the authenticated Unraid webGUI.
require '/usr/local/libexec/intel-gpu-firmware/Core.php';
require '/usr/local/libexec/intel-gpu-firmware/Http.php';
use IntelGpuFirmware\{Engine,Http,Blocked};
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $engine=Engine::production();$method=$_SERVER['REQUEST_METHOD']??'';
    if($method==='GET') {
        if(($_GET['action']??'status')==='report') {
            header('Content-Disposition: attachment; filename="intel-gpu-support.json"');
            echo json_encode($engine->report(),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);exit;
        }
        if($_GET && $_GET!==['action'=>'status'])throw new Blocked('Unknown query.');
        $job=$engine->runtime->get('job');
        $active=Http::jobActive($job);
        if(in_array($job['state']??'',['queued','running'],true) && !$active) {
            $job['state']='failed';$job['message']='The worker stopped. Its operation was not retried.';
        }
        unset($job['args'],$job['pid']);
        $flash=$engine->persistent->get('flash');
        if(in_array($flash['state']??'',['flashing','verifying'],true) && !($active && ($job['action']??'')==='flash')) {
            $flash['state']='needs_attention';
            $flash['last_message']='The update worker is no longer running. The result is uncertain. Do not retry automatically; inspect the local update journal.';
        }
        // Raw flasher diagnostics stay local; do not leak them into exported reports.
        unset($flash['tool_output']);
        $historyWarning=$engine->runtime->get('history-warning');
        try {$history=\IntelGpuFirmware\History::read($engine->persistent);}
        catch(Throwable $e) {$history=[];$historyWarning=['message'=>'Firmware history could not be read. The separate update journal remains available.'];}
        echo json_encode(['version'=>\IntelGpuFirmware\VERSION,'mode'=>\IntelGpuFirmware\MODE,'writes_enabled'=>\IntelGpuFirmware\WRITES_ENABLED,
            'history'=>$history,'history_warning'=>$historyWarning,'intel_release'=>$engine->persistent->get('intel-release'),
            'catalog'=>$engine->catalog->package(),
            'workloads'=>$engine->runtime->get('workloads'),'maintenance'=>$engine->persistent->get('maintenance'),
            'maintenance_test'=>$engine->runtime->get('maintenance-test'),
            'dry_run'=>$engine->runtime->get('dry-run'),
            'inventory'=>$engine->runtime->get('inventory'),'check'=>$engine->runtime->get('check'),
            'staged'=>$engine->runtime->get('staged'),'job'=>$job,'flash'=>$flash,
            'progress'=>$engine->runtime->get('progress')],JSON_THROW_ON_ERROR);exit;
    }
    if((int)($_SERVER['CONTENT_LENGTH']??0)>4096)throw new Blocked('Request is too large.');
    $var=parse_ini_file('/var/local/emhttp/var.ini',false,INI_SCANNER_RAW);
    // Unraid validates then removes $_POST['csrf_token']. Decode the original form
    // to perform our own validation without relying on that modified superglobal.
    $raw=file_get_contents('php://input',false,null,0,4097);
    $request=Http::rawFormRequest($method,$raw,(string)($_SERVER['CONTENT_TYPE']??''),(string)($var['csrf_token']??''));
    $id=Http::queue($engine,$request);
    http_response_code(202);echo json_encode(['job_id'=>$id],JSON_THROW_ON_ERROR);
} catch(Blocked|JsonException $e) {
    http_response_code(400);echo json_encode(['error'=>$e->getMessage()]);
} catch(Throwable $e) {
    http_response_code(500);echo json_encode(['error'=>'The operation could not be completed. Check plugin storage and dependencies.']);
}
