# Public alpha validation — 24 November 2026

Artifact: `2026.11.24h-alpha1`. Public mode: `read_only_test`; physical writes disabled. The developer candidate is checked separately and is excluded from public assets.

## Automated evidence

- 94 PHP tests in both build profiles, using fake GPUs/Intel executors. No native firmware writer is run.
- Real process death before and after the durable write-intent boundary. Preflight is recoverable; ambiguous write intent stays blocked.
- Real Unix-socket authorization server: repeated requests, malformed requests, helper death and restart from persistent state.
- Simulated reboot, node renumbering, missing GPU identity, partial restoration and guard/stop failures. These exercise the maintenance state machine, not a real server reboot.
- Default/candidate package checks: payload/source consistency, hashes, PHP/shell/JavaScript syntax and catalog structure.
- Release preparation only includes the non-writing default package, source archive, notes and checksums. Private host data, downloaded tools and firmware images are excluded.

## Native alpha checks

The 94-test default suite also passed on Unraid 7.4.1 with PHP 8.4.28. It used fake GPU executors and real local helper processes, without replacing the installed plugin or invoking IGSC writes. The existing physical-update journal was byte-identical before and after the run. A browser fixture displayed the public-alpha boundary, distinct hardware-evidence labels and a disabled firmware-write button.

## Existing physical evidence

An earlier candidate `2026.11.24.8-candidate` updated one ASRock A380 on Unraid 7.4.1 with i915, from DG02_2.2353 to DG02_2.2359. It passed a real reboot verification and a short H.264 encode. This does not physically validate the alpha code or other cards. [Exact record](A380-FIRST-FLASH-20261124.md).

The prior targeted-maintenance build `2026.11.24g-candidate` completed a live no-flash rehearsal: seven affected containers had start attempts rejected; the five previously running ones were restored; two previously stopped ones stayed stopped. Sixty-four unrelated containers, six VMs and two LXC containers retained their state. The original Docker configuration, libvirt hook and flash journal were unchanged after restoration. A device-free transient VM proved real libvirt hook enforcement. No GPU-using VM was available for a physical passthrough test.

## Limits

The new boot/recovery logic has not yet undergone an actual Unraid reboot with an active maintenance session. Interrupted physical-write recovery, other GPUs/generations/OEMs, multi-GPU hardware and GPU passthrough restoration remain unvalidated. Public alpha is for discovery, compatibility checks and optional acknowledged maintenance tests, not flashing. A UPS is optional advice, not a prerequisite.

Older test records are retained in [VALIDATION-HISTORY.md](VALIDATION-HISTORY.md) and describe only their named historical builds.
