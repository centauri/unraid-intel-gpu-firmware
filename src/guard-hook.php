<?php
declare(strict_types=1);
require __DIR__.'/Core.php';
use IntelGpuFirmware\{Store,StartPolicy};
if(PHP_SAPI!=='cli')exit(2);
$op=$argv[2]??'';
if(!in_array($op,['prepare','start','restore','migrate','attach'],true))exit(0);
try {
    $s=(new Store('/boot/config/plugins/intel.gpu.firmware/state'))->get('maintenance');
    $xml=stream_get_contents(STDIN,2097153);
    if(strlen($xml)>2097152 || !StartPolicy::vm($op,$xml,$s))throw new RuntimeException();
} catch(Throwable $e) {
    fwrite(STDERR,"IGF_GPU_MAINTENANCE: selected GPU is reserved; VM start blocked.\n");exit(1);
}
