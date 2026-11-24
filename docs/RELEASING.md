# Releasing the non-writing alpha

Repository: `centauri/unraid-intel-gpu-firmware`. Source branch: `main`. Dedicated update branch: `alpha`. Its `intel.gpu.firmware.plg` URL is fixed in `release.json`. The developer candidate has no update URL and must never be uploaded to that branch or a public alpha release.

1. Increment `Core.php` VERSION, changelog and current release/validation records. Unraid compares version strings lexically, so verify that the new version sorts after the existing release. Never replace a published artifact under the same version.
2. Run the PHP suite, build/check the default package, then build/check the candidate using fake devices only. The candidate check does not install or flash.
3. Run `python3 tools/prepare_release.py`. It verifies the compile-time write boundary, checksums, update URL and private-host exclusion. Only `release/` contents are public release assets. Do not upload the entire working directory, `dist/`, `vendor/`, host audit reports or screenshots.
4. Require the GitHub validation workflow to pass. Keep a draft prerelease with the exact tested assets and version tag. Review its checksum file, release notes and source tree before making it public.
5. Publish the prerelease. Publish only the checked default PLG and checksum to the `alpha` branch; fetch the public URL and verify identical bytes. Update checks must stay on this channel and never point to the candidate.
6. Community Applications submission is a separate step after alpha feedback; do not label this stable or universal Intel flash support.

## Reviewing a new firmware catalog

Use an official Intel package and independently obtain the published SHA-512. `tools/make_catalog.py --help` describes the generator. It checks the whole archive, extracts supported component paths and records image hashes/sizes. Review changes in component paths, image projects, Intel tool support, code/data dependencies and update ordering. Run compatibility checks against sanitized real output where available. Detection or a filename is not physical update evidence.

`Check Intel releases` only reports a newer/changed package; it cannot update the trusted catalog. A catalog change requires a reviewed plugin release and a new version. No arbitrary upload or unreviewed URL is accepted.

## Public evidence

Add the exact PCI vendor/device/subsystem/revision, driver, Unraid version, source artifact hash and observed steps. Keep detection, readable interface, compatible image, physical write, post-reboot verification and workload validation separate. No physical flash is required to contribute alpha feedback.
