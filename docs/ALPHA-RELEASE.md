# Public alpha 1 — 2026.11.24h-alpha1

Intel GPU Firmware for Unraid 7.4.1 / x86_64. **Physical firmware writing is disabled; no setting enables it.**

Scan Intel GPUs, read supported firmware versions, download/check the reviewed Intel GSC package, retain local version history and check Intel releases. Optional acknowledged maintenance rehearsals stop only affected workloads and test restoration. They cause temporary downtime for those workloads.

This alpha distinguishes detected, inspectable, image-compatible and physically tested configurations. Only one ASRock A380 has historical physical update evidence from an earlier developer build. Other boards and generations remain unvalidated.

Recovery now distinguishes a writer that was never invoked from an uncertain write. Failed rehearsals restore original policies; partial restoration is retryable. Tests cover actual worker/helper death, helper restart and simulated reboot/node renumbering. Actual reboot recovery of the new maintenance code and interrupted physical-write recovery are not certified.

The public update channel remains non-writing. Developer candidate binaries are excluded from this release. Firmware images and dependency executables are downloaded from their publishers with pinned hashes, not redistributed here.

Review `SHA256SUMS` before installing. Submit compatibility reports without flashing; review exported JSON before sharing. See README and docs/VALIDATION.md for scope and evidence.
