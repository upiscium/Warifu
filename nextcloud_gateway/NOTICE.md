# License and provenance

Nextcloud Gateway — Copyright (C) 2026 upiscium.

The project source, tests and tooling are licensed under the GNU Affero General
Public License, version 3 or (at your option) any later version
(`AGPL-3.0-or-later`). See [LICENSE](LICENSE). There is no warranty.

The lock handoff design in `MutationGuard.php` (extended for two-path transfers
in `TransferGuard.php`) and the local-home/path policy in
`Scope.php` are adapted from **ObsidianCore-Sync's VaultSync Bridge**:

- Source: <https://github.com/upiscium/ObsidianCore-Sync/tree/6882c3799c8a0edd50a283efba114985972493fc/nextcloud/vaultsync_bridge>
- Original copyright: 2026 upiscium; SPDX license: `AGPL-3.0-or-later`.
- Adapted on 2026-11-15 for native DAV interception rather than a proprietary
  bridge endpoint; adds runtime fingerprints, content validators, conditional
  request handling, native-handler wrappers and read snapshot locks.

Behavioral research also used
[ObsidianAutomation@43e5a2f27be047f4ea749871fa82a5f320733161](https://github.com/upiscium/ObsidianAutomation/tree/43e5a2f27be047f4ea749871fa82a5f320733161)
and its issues [#302](https://github.com/upiscium/ObsidianAutomation/issues/302),
[#308](https://github.com/upiscium/ObsidianAutomation/issues/308),
[#318](https://github.com/upiscium/ObsidianAutomation/issues/318).
This is a research reference, not a claim that their implementation is included.

Nextcloud 35.0.3 and its bundled Sabre libraries are external dependencies, not
vendored source in this project. They retain their respective copyright and
license notices (Nextcloud core predominantly AGPL-3.0-only; SabreDAV BSD).
The license text in `LICENSE` is reproduced from `COPYING` in the pinned official
Nextcloud image identified in `appinfo/runtime.json`; it is the FSF's AGPL v3
license document, not a relicensing of Nextcloud itself.

Before offering a modified version over a network, make its corresponding source
available to users as required by AGPL section 13. This local prototype has not
been published or deployed, and does not yet provide a public source-download UI.
