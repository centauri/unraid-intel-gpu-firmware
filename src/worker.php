#!/usr/bin/php
<?php
declare(strict_types=1);
require __DIR__.'/Core.php';
require __DIR__.'/JobWorker.php';
use IntelGpuFirmware\{Engine,Worker};
if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||posix_geteuid()!==0)exit(1);
exit(Worker::run(Engine::production(),$argv[1]??''));
