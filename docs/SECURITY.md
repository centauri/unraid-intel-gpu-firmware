# Security and failure boundaries

The authenticated Unraid webGUI is the security boundary; do not expose this endpoint independently. It runs with the host privileges normally used by Unraid plugins. POST operations require the existing Unraid CSRF token, a fixed action allowlist and strictly typed/limited arguments. Firmware and device paths are not accepted from the browser.

Commands use argument arrays with a fixed executable/environment. The only shell launch consists of fixed worker paths and a generated hexadecimal job ID. Read operations have a separate Intel command allowlist. `fw update` has one call site, explicit device selection, no force/downgrade flags and no process-killing timeout.

The plugin package/catalog is a trust root. Hashes protect downloads relative to that catalog; they cannot protect against a maliciously replaced plugin or compromised root account. Concurrent root administration is outside the model; use an exclusive maintenance window.

The target must match vendor/device/subsystem IDs, PCI address and MEI sysfs ancestry. A fresh fingerprint also covers revision, driver, firmware and component results. Intel checks image project/SKU compatibility. This does not establish OEM certification, inter-component update ordering, recovery support or correctness of the firmware itself.

One worker or installer/remover holds the operation lock. Schema-2 preflight is persisted first and cannot invoke the writer. A separate flashing intent is written atomically, fsynced, renamed, verified and the containing filesystem flushed before entering the flash tool. A worker death in preflight can be recovered as not_started; death after flashing intent remains uncertain. Known exceptions before entering the writer consume the old plan and record not_started. An unfinished/unknown journal blocks another write. Ambiguous completion is not treated as failure safe to retry. Read access is also blocked during an unresolved same-boot write.

Read timeouts may terminate read/download/extraction operations. Firmware writes never receive that timeout. Browser disconnection does not terminate the worker. A dead/reused worker PID is checked by command line and job ID. Status polling only reads cached files.

The default build additionally blocks physical writes in the HTTP queue, production engine, Intel adapter and native process executor. Unit-test fake executors may simulate write transitions; they cannot execute commands or reach hardware.

Native Unraid integration revealed that the security prepend validates and removes the form CSRF token. The endpoint therefore independently parses the bounded original form and verifies both its token and the payload token against the server token. No authenticated token is written to the job record or logs.

UPS status is advisory, not an authorization or write prerequisite. Missing, ambiguous, unhealthy or failed monitoring cannot block or abort an update. Reporting is not proof of cabling or battery health. Targeted Docker authorization and libvirt startup hooks protect normal managed starts during maintenance. Direct host access, libvirt PCI hot-plug and concurrent root changes remain outside this boundary; OS/power failure can interrupt a write. One ASRock A380 update was verified after reboot; interrupted-write recovery remains untested.

## Candidate review — 24 November 2026

The optional candidate build changes only the version, mode and compile-time write flag in Core.php. There is no HTTP/config/environment switch. Its native writer constructs one exact IGSC command using a fixed executable/library path, a canonical staged fwcode path and a strictly formed MEI node. The general process runner remains unable to launch write commands or use an unlimited timeout. The engine performs full checks before reaching that dedicated entry point.

Reviewed Intel IGSC V1.3.2 at commit `06273c4defa44275c5ab93332499506b5a078760`. In `src/igsc_cli.c`, `firmware_update` logs an update failure but then overwrites `ret` with a subsequent device-version query. Therefore exit zero alone is insufficient. The plugin rejects nonempty stderr or error/failure diagnostics even at exit zero, checks the exact image/before/after version transcript and independently reads the version again. Ambiguous outcomes become `needs_attention`, not success or an automatic retry.

Intel's library contains a bounded transport retry inside one invocation (`lib/igsc_lib.c`, `retry_update`) and firmware-operation timeouts. The plugin neither disables these upstream mechanics nor launches another invocation after an uncertain result. The plugin's lack of a process-killing write timeout is not a claim that Intel has no internal timeouts.

Every compatibility check clears the old plan first. Plans bind the plugin version and mode as well as the boot/device/image. Maintenance and plan lifetime are rechecked after the intent journal flush. Same-boot `needs_attention` blocks scans and plugin replacement, covering a possibly orphaned updater. A dangling journal symlink is an error rather than an absent journal. Root tampering, concurrent administrators and power/hardware failure remain outside these guarantees.

References: [Intel CLI](https://github.com/intel/igsc/blob/06273c4defa44275c5ab93332499506b5a078760/src/igsc_cli.c#L985), [Intel library](https://github.com/intel/igsc/blob/06273c4defa44275c5ab93332499506b5a078760/lib/igsc_lib.c#L2186). Intel's [B60 management guide](https://cdrdv2-public.intel.com/860316/860316_IntelArcProB60GPUManagementUserGuide_IntelXeonPlatform_1.4.pdf), section 5.2, also distinguishes code/data images and requires the correct AIC-vendor images; it is not evidence certifying an A380 or a generic B60 update with this catalog.

## Public alpha recovery tests

Worker death is tested on both sides of the durable write boundary with real child processes and fake hardware. Authorization helper death/restart uses a real Unix socket. Boot IDs, node remapping and partial restoration are simulated; live reboot recovery of this maintenance implementation is not certified. The alpha update channel and prepared release artifacts are checked to contain WRITES_ENABLED=false. A developer candidate is never automatically installed or uploaded.
