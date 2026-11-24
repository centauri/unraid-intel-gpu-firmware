#!/bin/bash
set -euo pipefail
umask 077
cache=/boot/config/plugins/intel.gpu.firmware
runtime=/var/lib/intel-gpu-firmware
backend=/usr/local/libexec/intel-gpu-firmware
payload="$cache/payload-@PAYLOAD_SHA256@.tar.gz"
mkdir -p "$cache" "$runtime"
# Installation, reinstallation and removal must not replace a running worker.
exec 9>"$runtime/work.lock"
flock -n 9 || { echo "A firmware operation is running. Try again after it finishes."; exit 1; }
php -r 'if(PHP_VERSION_ID<80100)exit(1); foreach(["pcntl_fork","posix_kill","simplexml_load_string","fsync"] as $f)if(!function_exists($f))exit(1);' ||
  { echo "PHP 8.1+, pcntl, POSIX, SimpleXML and fsync are required."; exit 1; }
if [ -f "$cache/state/maintenance.json" ]; then
  php -r '$s=json_decode(file_get_contents($argv[1]),true); if(!is_array($s))exit(1); if($s && ($s["state"]??"")!=="complete" && ($s["boot_id"]??"")===trim(file_get_contents("/proc/sys/kernel/random/boot_id"))) {fwrite(STDERR,"Restore GPU maintenance before reinstalling.\n");exit(1);}' "$cache/state/maintenance.json"
fi
if [ -f "$cache/state/flash.json" ]; then
  php -r '$v=json_decode(file_get_contents($argv[1]),true); if (!is_array($v)) exit(1); $same=($v["plan"]["boot_id"]??"")===trim(file_get_contents("/proc/sys/kernel/random/boot_id")); if ($same && in_array($v["state"]??"",["preflight","flashing","verifying","needs_attention"],true)) {fwrite(STDERR,"An interrupted firmware update needs attention; installation stopped.\n");exit(1);}' "$cache/state/flash.json"
fi
fetch() {
  local file=$1 hash=$2 url=$3
  if [ ! -f "$cache/$file" ] || ! printf '%s  %s\n' "$hash" "$cache/$file" | sha256sum --status -c -; then
    curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 20 --max-time 180 "$url" -o "$cache/$file.partial"
    printf '%s  %s\n' "$hash" "$cache/$file.partial" | sha256sum -c -
    mv "$cache/$file.partial" "$cache/$file"
  fi
}
fetch igsc.deb bb7b8d2fad24d017f17a057684c459176ec7ea483cabc622e8ac68f27c560727 'https://deb.debian.org/debian/pool/main/i/igsc/igsc_1.3.2+~metee6.2.6-5_amd64.deb'
fetch libigsc.deb 14cf0b016add49a4843a6e52364605614b2ad759f8a9e2c1e268ac92092ddee0 'https://deb.debian.org/debian/pool/main/i/igsc/libigsc1_1.3.2+~metee6.2.6-5_amd64.deb'
fetch 7zip.tar.xz 2b539cf067b2ad48e1baa5093dbaa8818b73542d1222a0ffde9dc5f2922ea62e 'https://github.com/ip7z/7zip/releases/download/26.06/7z2606-linux-x64.tar.xz'
tmp=$(mktemp -d /tmp/intel-gpu-firmware-install.XXXXXX)
trap 'rm -rf -- "$tmp"' EXIT
mkdir -p "$tmp/igsc" "$tmp/libigsc" "$tmp/root" "$tmp/7zip"
bsdtar -xf "$cache/igsc.deb" -C "$tmp/igsc"
bsdtar -xf "$cache/libigsc.deb" -C "$tmp/libigsc"
tar -xJf "$tmp/igsc/data.tar.xz" -C "$tmp/root"
tar -xJf "$tmp/libigsc/data.tar.xz" -C "$tmp/root"
tar -xJf "$cache/7zip.tar.xz" -C "$tmp/7zip"
# Validate the dependency bundle before changing the installed plugin.
LD_LIBRARY_PATH="$tmp/root/usr/lib/x86_64-linux-gnu" "$tmp/root/usr/bin/igsc" --version
"$tmp/7zip/7zz" i >/dev/null
tar -xzf "$payload" -C "$tmp"
mkdir -p "$backend/vendor/bin" "$backend/vendor/lib" "$backend/vendor/licenses"
cp "$tmp/root/usr/bin/igsc" "$backend/vendor/bin/"
cp -a "$tmp/root/usr/lib/x86_64-linux-gnu/"libigsc.so* "$backend/vendor/lib/"
cp "$tmp/7zip/7zz" "$backend/vendor/bin/"
cp "$tmp/7zip/License.txt" "$backend/vendor/licenses/7zip.txt"
mkdir -p "$backend/vendor/licenses/debian"
cp -a "$tmp/root/usr/share/doc/." "$backend/vendor/licenses/debian/"
cp "$tmp/src/"*.php "$backend/"
cp "$tmp/catalog/catalog.json" "$backend/catalog.json"
cp "$tmp/LICENSE" "$backend/LICENSE"
mkdir -p "$backend/docs"
cp "$tmp/docs/"* "$tmp/README.md" "$backend/docs/"
chmod 0755 "$backend/vendor/bin/"*
mkdir -p /usr/local/emhttp/plugins/intel.gpu.firmware
cp "$tmp/web/"* /usr/local/emhttp/plugins/intel.gpu.firmware/
chmod -R go-w "$backend" /usr/local/emhttp/plugins/intel.gpu.firmware
# Static assets must be readable by the web server; private state stays root-only.
chmod 0755 /usr/local/emhttp/plugins/intel.gpu.firmware
chmod 0644 /usr/local/emhttp/plugins/intel.gpu.firmware/*
php "$backend/maintenance-boot.php"
echo "Intel GPU Firmware installed. Open Tools > Intel GPU Firmware. No GPU was flashed."
