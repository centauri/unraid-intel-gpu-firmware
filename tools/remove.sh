#!/bin/bash
set -euo pipefail
mkdir -p /var/lib/intel-gpu-firmware
exec 9>/var/lib/intel-gpu-firmware/work.lock
flock -n 9 || { echo "A firmware operation is running. Removal stopped."; exit 1; }
maintenance=/boot/config/plugins/intel.gpu.firmware/state/maintenance.json
if [ -f "$maintenance" ]; then
  php -r '$s=json_decode(file_get_contents($argv[1]),true); if(!is_array($s)||($s&&($s["state"]??"")!=="complete"))exit(1);' "$maintenance" ||
    { echo "Restore GPU maintenance before removing the plugin."; exit 1; }
fi
state=/boot/config/plugins/intel.gpu.firmware/state/flash.json
if [ -f "$state" ]; then
  php -r '$v=json_decode(file_get_contents($argv[1]),true); if(!is_array($v)||!in_array($v["state"]??"",["complete","not_started"],true))exit(1);' "$state" ||
    { echo "An interrupted firmware operation needs attention. Removal stopped."; exit 1; }
fi
rm -rf -- /usr/local/emhttp/plugins/intel.gpu.firmware /usr/local/libexec/intel-gpu-firmware
echo "Plugin removed. Firmware was not changed. Cached downloads and update history remain on the boot device."
