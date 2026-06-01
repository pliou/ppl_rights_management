# Changelog

All notable changes to this extension are documented here.

## Unreleased

- Registered the backend group module access listener through `Configuration/Services.yaml` instead of a PHP event-listener attribute.
- Switched backend module registration to the configured icon identifier.
- Restricted the read-only history route to GET.
- Logged failed history writes without blocking the original rights change.
- Updated package documentation for the TYPO3 14 release line.

## 14.0.0

Initial TYPO3 14 release.

- Added backend module for rights management
- Added delegated write mode for non-admin backend users
- Added backend user and backend group management views
- Added page type, table, module, DB mount and file mount permission management
- Added group inheritance management
- Added history logging in `tx_pplrightsmanagement_history`
- Added TYPO3 and Packagist package metadata
