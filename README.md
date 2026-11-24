# Intel GPU Firmware for Unraid

**Public alpha 1 · 2026.11.24h-alpha1 · physical firmware writes disabled.**

Discover Intel GPUs, read supported firmware versions, check an official Intel GSC image, keep local version history and check Intel release metadata. Unraid 7.4.1 / x86_64 is the tested host platform. This independent project is not affiliated with Intel, Unraid or board manufacturers.

## What the alpha does

- Enumerates host-visible Intel display controllers, regardless of board vendor.
- Uses Intel IGSC 1.3.2 for supported firmware reads and compatibility checks.
- Downloads one reviewed Intel package on request. Verifies its pinned SHA-512 and every extracted image's size and SHA-256.
- Stores version observations and update history on the Unraid flash drive.
- Checks Intel's package page on request. New packages require a reviewed catalog release; they never silently replace trusted images.
- Lists workloads with access to the selected GPU. An optional, separately acknowledged rehearsal stops affected workloads, tests start guards and restores their original policies and running states.

**Installation, scans and dry tests do not stop workloads. A maintenance rehearsal does.** Privileged containers are included because they can access host devices. Existing unrelated containers and VMs continue; container creation and some orchestration mutations are temporarily blocked during a rehearsal. Start guards cover normal Docker/libvirt APIs, not concurrent root administration or GPU hot-plug. Native LXC GPU access and unsupported configurations block maintenance.

Physical writes are blocked independently in the HTTP queue, production engine, Intel adapter and process executor. There is no UI, environment or configuration switch to enable them. The developer-only candidate is not distributed through the alpha channel and has no automatic update URL.

## Install

After the alpha is published, paste this URL into **Plugins → Install Plugin**:

```text
https://raw.githubusercontent.com/centauri/unraid-intel-gpu-firmware/alpha/intel.gpu.firmware.plg
```

The `alpha` branch is a dedicated **non-writing** update channel. Updates require the normal Unraid plugin update flow. Immutable versioned assets and checksums are supplied with each [GitHub prerelease](https://github.com/centauri/unraid-intel-gpu-firmware/releases). This is not yet a Community Applications listing.

For a downloaded package, check `SHA256SUMS`, then run:

```sh
plugin install /path/to/intel.gpu.firmware.plg
```

Open **Tools → System Information → Intel GPU Firmware**. First scan the GPUs, then optionally download the reviewed firmware and check compatibility. Firmware history and Intel release checks are separate actions. A GPU with no supported Intel update interface stays visible with an explanation.

## Requirements and downloads

- Unraid 7.4.1+, x86_64. Later Unraid releases are not yet validated.
- PHP 8.1+ with pcntl, POSIX, SimpleXML and fsync; curl, bsdtar, xz/tar, flock, sync, fuser, libudev and glibc 2.38+. No system libraries are replaced.
- Intel host driver `i915` or `xe` and a usable MEI interface for inspection. The plugin never rebinds PCI devices or loads/unloads kernel modules.
- About 2.1 GB free temporary storage for the optional 904 MB firmware download, usually RAM on Unraid. The archive is removed after extraction; verified images total about 19 MB and are cleared on reboot.
- Internet for first-time helper downloads and explicit firmware/release checks. Pinned helpers are cached on the flash drive for offline reboot installation.

The catalog is pinned to Intel **32.0.101.9175**: 70 component records, including five GSC images. Only GSC is a candidate update scope. OPROM, board data, HDMI/PCON, BIOS and driver-loaded GuC/HuC/DMC are not written. There is no arbitrary firmware upload or custom download URL.

## What hardware support means

| Evidence | Meaning |
| --- | --- |
| Detected | Intel PCI display controller is visible |
| Inspectable | Intel's firmware interface responds and matches the PCI identity |
| Compatible image | Exactly one newer, same-project image passes Intel's hardware check |
| One matching configuration tested | Published evidence for that exact PCI/subsystem/revision/driver combination, not certification of your individual card |
| Verified after reboot | A particular local update was checked after a real boot change; shown separately in its history |

One **ASRock Arc A380** (`8086:56a5`, subsystem `1849:6004`, rev `05`, i915) was physically updated using an earlier developer build, on Unraid 7.4.1: `DG02_2.2353 → DG02_2.2359`. It passed post-reboot verification and a short H.264 encode. [Exact historical record](docs/A380-FIRST-FLASH-20261124.md).

Other Alchemist, Battlemage, Arc Pro, OEM and future configurations are **unvalidated** unless a matching record is added. They may be detected or inspectable without any physical update evidence. Integrated/older GPUs may require platform BIOS or runtime firmware instead. No recovery, rollback or OEM-wide support is promised.

## Maintenance and recovery

A dry test writes no firmware and stops no services. **Rehearse stop and restore · no flash** is a real, optional maintenance test: allow downtime for the listed workloads. Previously stopped workloads stay stopped. Failed preparation is followed by restoration; partial restoration can be retried using **Restore GPU workloads**.

A durable `preflight` record guarantees the writer was not invoked. After worker death it can be finalized as `not_started` during restoration. Once the durable `flashing` boundary is crossed, a crash is treated as uncertain and never automatically retried or unlocked. [Recovery guide](docs/RECOVERY.md).

Boot recovery re-arms an unfinished session's guards and does not automatically start workloads. This alpha has simulated reboot/mapping tests and real helper crash/restart tests; an actual server reboot with the new maintenance code remains an experimental validation item. Do not reboot during a rehearsal. The public alpha cannot perform a firmware update that requires reboot.

## Reports and privacy

Use **Export support report**, review the JSON, then submit a [compatibility report](https://github.com/centauri/unraid-intel-gpu-firmware/issues/new/choose). The report excludes hostname, IP, serials, PCI location, container names, raw logs and private history. Nothing is uploaded automatically. Do not share raw Unraid diagnostics or credentials.

Persistent state: `/boot/config/plugins/intel.gpu.firmware/state/`. Runtime files: `/var/lib/intel-gpu-firmware/`. Tools: `/usr/local/libexec/intel-gpu-firmware/`. Removal retains persistent history/cache and refuses active maintenance or unresolved writes. History is keyed by PCI slot plus hardware IDs, not a unique board serial, and retains the latest 100 events per identity.

## Development

Run separately:

```sh
php tests/run.php
```

```sh
python3 tools/build.py
```

```sh
python3 tests/package_check.py
```

```sh
python3 tools/prepare_release.py
```

The candidate can be built with `python3 tools/build.py --candidate` and checked with `python3 tests/candidate_check.py`. These tests use fake hardware; never invoke the candidate installer or a native writer in CI. CI uploads only the default alpha artifacts. [Validation](docs/VALIDATION.md), [security boundaries](docs/SECURITY.md), [release procedure](docs/RELEASING.md), [contributing](docs/CONTRIBUTING.md).

## Upstream and licenses

- [Intel IGSC](https://github.com/intel/igsc), CLI/library 1.3.2, Debian revision `1.3.2+~metee6.2.6-5`.
- [Debian packages](https://deb.debian.org/debian/pool/main/i/igsc/), exact hashes in the installer.
- [Intel graphics package](https://www.intel.com/content/www/us/en/download/785597/intel-arc-graphics-windows.html), publisher URL and SHA-512 in the catalog.
- [7-Zip 26.06](https://github.com/ip7z/7zip/releases/tag/26.06), private Linux x64 extractor with upstream license.

Original code is MIT licensed. Dependencies and firmware retain their upstream licenses. Intel firmware and downloaded executables are not redistributed in the source or plugin packages.
