# Historical validation records

These records describe earlier artifacts. See VALIDATION.md for the public alpha.

# Validation record — 24 November 2026

**Latest physical result:** one ASRock A380 completed the candidate's GSC update,
verification after a real reboot, workload restoration and a short Plex VAAPI
encode. See [the exact artifact and evidence](A380-FIRST-FLASH-20261124.md).
The sections below describe the earlier read-only and simulated tests; their
then-unproven physical steps are superseded only for this one recorded card.

- **43 PHP tests**, passed locally and under Unraid's PHP 8.4.28. Fake executors and temporary sysfs/process fixtures exercise selection/policy/state transitions without hardware writes.
- Tests cover version selection, unknown brands/future project identifiers, MEI mapping, PCI mismatches, tampered images/symlinks, ambiguous images, maintenance changes, stale plans, explicit confirmation, failed writes/read-back and retry suppression.
- Actual maintenance policy is tested against synthetic Docker/QEMU processes, driver/display/VF state and UPS input. Request/CSRF validation, stale workers, atomic local state and Intel PCI enumeration are also tested.
- The complete official `gfx_win_101.9175.exe` was re-downloaded and matched the catalog's **904495280 bytes and SHA-512**. Extraction with upstream macOS 7-Zip succeeded; **all 70 selected files** matched their catalog hashes/sizes, totaling **18960384 bytes**. Linux uses the same pinned upstream release but is not proven by that macOS run.
- The standalone catalog-generation tool reproduced every catalog image/hash and package identity from that verified archive.
- PLG XML/base64 payload, embedded files, source ZIP, checksums and PHP/shell/JavaScript syntax passed local validation.
- Browser fixture: light/dark layout, a 390 px frame, blocked review, persistent expanded details during polling, wrong-card confirmation refusal, correct confirmation enablement and visible backend rejection in the modal. The preview API rejects all flash requests and has no device access.

Earlier read-only evidence on Unraid 7.4.1: IGSC 1.3.2 read an ASRock Arc A380 (`8086:56a5`, subsystem `1849:6004`, revision `05`) running `DG02_2.2353`; Intel's check accepted SOC2 image `DG02_2.2359`. This demonstrates inspection/compatibility on one board, not a flash test of this plugin.

## Native Unraid dry testing

Test host: Unraid 7.4.1, kernel 6.18.64-Unraid, PHP 8.4.28, ASRock A380 with the IDs above. The installed read-only build has no configuration switch to enable physical writing.

- Authenticated webGUI scan, firmware-package staging and compatibility check executed through the actual background worker.
- Linux extraction and all **70 image hashes/sizes** passed on the real host. Candidate `DG02_2.2359` matched; current firmware remained `DG02_2.2353`.
- **24 additional live assertions** passed: exact identity, native write guards before process creation, no flash journal mutation, all staged images, six dry-run checks, active-workload gates, repeated stable firmware reads, persistent result read-back, competing worker lock rejection and dead-worker recognition.
- A connected UPS passed the readiness policy; Docker/VM processes correctly blocked readiness. No workload was stopped.
- Five actual authenticated HTTP requests were rejected: bad CSRF, malformed PCI address, blocked flash action, unexpected field and oversized body. The flash test used no valid plan.
- Anonymous API access redirected to the Unraid login instead of returning status.
- Removal and reinstallation completed successfully. Plugin UI/tools were removed, persistent dry-test state retained its exact SHA-256, cached dependencies were reused, and the plugin was registered under the expected boot filename. Plex stayed running and the GPU's internal firmware version remained `101.0.10.2177`. No server reboot was performed.

Bugs found and corrected: JSON-only CSRF transport was incompatible with Unraid's prepend; that prepend also removes the validated token from POST, requiring bounded decoding of the original form. Static assets now use the build version to avoid stale scripts after upgrades. GPU facts override inherited Unraid definition-list alignment.

**Not established:** physical firmware writes, actual reboot/post-flash activation, transcoding/performance after a flash, OEM-wide compatibility or recovery. Passing dry tests does not certify a physical flash. Writing remains disabled in this release.

## Preview 0.3 update-path review

- 58 tests passed in the default profile and 58 in the separately extracted write-candidate profile, using fake devices/executors. The candidate installer and native writer were not invoked by these tests.
- Added regression coverage for the IGSC exit-zero/error diagnostic case, incomplete transcripts, old-plan invalidation, cross-build plans, maintenance changes during journal flush, dangling journals, forbidden native writer paths, queue duplication, worker locks/replay, uncertain results and unflushed completion.
- Shared worker orchestration now runs in tests with the same locking/dispatch/job code used by the native CLI wrapper. Simulated reboot verification uses a changed boot ID; it is not an actual server reboot.
- The separate candidate contains a native write implementation, but its hardware execution/recovery remains unvalidated. Candidate availability is not a recommendation to flash all Intel cards.

Native Unraid follow-up: the shared worker completed an authenticated dry run with six successful checks. The default profile remained write-disabled and its flash journal remained absent. The confirmation UI was exercised in a localhost fixture that always rejects physical writes: wrong card disabled, exact card plus acknowledgement enabled, server rejection visible.
