# Public alpha 2 — 2026.11.24i-alpha2

Intel GPU Firmware for Unraid 7.4.1 / x86_64. **This alpha can flash compatible newer GSC firmware using official Intel IGSC.** It replaces alpha 1's inspection-only public build. Installing or updating the plugin never flashes automatically.

Review the selected GPU and its workloads, prepare targeted maintenance, check compatibility again, then review and explicitly confirm the exact GPU before starting an update. After a successful write/read-back, reboot, verify the firmware and restore workloads. Equal/older firmware, wrong images, missing maintenance guards, open GPU users, expired plans and uncertain prior writes remain blocked. There is no force option, arbitrary upload or automatic retry. A UPS is recommended, not required.

Detection covers Intel display controllers regardless of board vendor; actual update eligibility depends on Intel's interface, identity, project/version and hardware checks. Only GSC is written. OPROM, firmware data, board BIOS and HDMI components remain read-only. Firmware is downloaded on request from the pinned reviewed Intel package and checked against its hashes.

Only one ASRock A380 has historical physical update evidence from an earlier developer build. This release was tested without flashing that card again. The automated suite exercises the packaged updater, confirmation gates, actual worker/helper death, restoration and simulated reboot/device remapping with fake GPUs. A live reboot of the new boot-guard integration remains pending; interrupted physical-write recovery, other GPUs/OEMs and passthrough restoration are not certified.

The fixed alpha plugin URL now delivers this functional updater. Versioned alpha 1 assets remain unchanged. Developer read-only/candidate packages are not release assets. Review `SHA256SUMS` and the README's update workflow before installing. Compatibility reports can be submitted without flashing; review exported JSON before sharing.
