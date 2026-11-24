<?php
declare(strict_types=1);
namespace IntelGpuFirmware;

/** Published evidence is separate from detection, compatibility and local history. */
final class Validation {
    public static function forCard(array $card): array {
        $tested=['vendor'=>'8086','device'=>'56a5','subvendor'=>'1849','subdevice'=>'6004','revision'=>'05','driver'=>'i915'];
        foreach($tested as $key=>$value)if(($card[$key]??null)!==$value)
            return ['level'=>'unvalidated','label'=>'No physical update evidence for this configuration',
                'detail'=>'Detection and Intel image compatibility are not physical update validation. Public alpha writes no firmware.'];
        return ['level'=>'single_board','label'=>'One matching ASRock A380 configuration tested',
            'detail'=>'One board on Unraid 7.4.1 / i915: DG02_2.2353 to DG02_2.2359, verified after reboot. Tested writer: 2026.11.24.8-candidate. This is not certification of this device, the current build or other firmware.',
            'record'=>'docs/A380-FIRST-FLASH-20261124.md'];
    }
}
