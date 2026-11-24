<?php
declare(strict_types=1);
require __DIR__.'/Core.php';
use IntelGpuFirmware\{Store,DockerApi,AuthServer};
if(PHP_SAPI!=='cli'||!function_exists('pcntl_fork'))exit(2);
$api=new DockerApi();
AuthServer::serve('/var/run/intel-gpu-maintenance.sock','/var/run/intel-gpu-maintenance.pid',
    fn()=>(new Store('/boot/config/plugins/intel.gpu.firmware/state'))->get('maintenance'),
    fn($path)=>$api->call('GET',$path));
