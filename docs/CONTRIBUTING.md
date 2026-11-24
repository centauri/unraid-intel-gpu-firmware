# Add evidence without guessing support

Start with an exported support report. Record the board model, Unraid/kernel and driver versions, scan/check result and relevant error. Never label a device flash-tested from an inventory or mock test. Reports are not uploaded automatically.

Keep these evidence levels separate: **detected**, **Intel interface readable**, **image compatibility checked**, **physical update completed**, **verified after reboot**, **workload checked**. Claim only observed levels. Another board brand normally needs no code change; identification uses PCI IDs and the Intel interface.

For a new catalog, obtain an official Intel archive and independently obtain its published SHA-512. Run `python3 tools/make_catalog.py --help`. Supply archive, digest, release version and official URLs. It verifies the complete archive before extraction and generates metadata only. Review all path/component changes and Intel's update dependencies. Never infer compatibility from a filename.

New directory families can be cataloged, but unsupported version/output formats fail closed until reviewed. Update CLI/library together, verify pinned downloads, test on the supported Unraid version and adapt fixtures to real sanitized output. Do not silently broaden command allowlists.

Before a stable public release, test install/reinstall/remove and boot restoration on disposable Unraid, native webGUI/CSRF integration, browser loss and worker crashes, multiple cards/MEI mapping, and controlled physical updates with recovery arrangements. Test independent boards/generations before calling them validated. CI must never write real firmware.

OPROM/board-data writing needs separate review of exact supported IDs, code/data dependencies, update order and recovery. Their catalog entries do not enable writing.

Local UI fixture: `php -S 127.0.0.1:8766 tests/preview.php`. Open `/`, `/ready`, `/dark` or `/mobile`. It never loads the production engine and rejects every flash request.
