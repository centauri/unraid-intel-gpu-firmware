<?php
declare(strict_types=1);
require __DIR__.'/Core.php';
use IntelGpuFirmware\{Engine,Maintenance,StartPolicy};
if(PHP_SAPI!=='cli')exit(2);
$e=Engine::production();
(new Maintenance($e))->resumeAfterBoot();
