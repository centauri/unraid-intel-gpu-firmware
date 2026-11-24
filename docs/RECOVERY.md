# Maintenance recovery

Do not delete journals to re-enable controls. No action below launches a firmware writer.

| State | Meaning and next action |
| --- | --- |
| Maintenance `preparing` / `needs_attention`, no unresolved flash | Wait for the worker to finish. Use Restore GPU workloads. It restores saved policies and starts only originally running workloads. |
| Maintenance `restoring` | Restoration partially completed. Read the error, resolve that workload's problem, then retry Restore. Completed items are not restarted again. |
| Flash `preflight` with schema 2 | The worker stopped before the durable write boundary. Restore finalizes it as `not_started` and invalidates the old plan. The operation lock excludes a still-running worker. |
| Flash `not_started` | This invocation did not enter the writer. Restore workloads. A later deliberate attempt requires a fresh compatibility plan. |
| Flash `flashing`, `verifying` or `needs_attention` | Treat the write as uncertain. Keep power stable while an updater may run. Do not retry, reboot to clear the error or remove the plugin. Ask a maintainer to inspect the journal and device state. |
| Flash `reboot_required` | Earlier developer update passed read-back. Reboot only after that updater has exited, verify after reboot, then restore workloads. The public alpha cannot create this state through a physical update. |
| Flash `complete` | The recorded update passed post-reboot verification. Restore any remaining maintenance session. |

If a Docker guard helper dies, Docker's authorization call fails rather than authorizing GPU starts. Existing workloads continue, but API management may be unavailable until the guard is restored or safely unloaded. Restore unloads authorization before terminating the helper. On an unfinished reboot session, old and newly resolved GPU node names stay reserved; an unverifiable mapping blocks all managed starts until reviewed/restored. Direct root commands and internal daemon startup behavior are outside API authorization, so do not restart management daemons during maintenance.

The current alpha tests boot transitions with fixtures; it does not certify recovery from power loss or an interrupted physical flash. No recovery firmware, backup of GPU contents, forced flash or downgrade is supplied.

Persistent records are in `/boot/config/plugins/intel.gpu.firmware/state/`: `flash.json` (authoritative update boundary), `maintenance.json` (original workload policies/states), `guard-config.json` (Docker configuration backup), `history.json` (informational history). Do not post these unredacted. Use the public support report first.
