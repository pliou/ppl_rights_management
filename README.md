# PPL Rights Management

TYPO3 CMS backend module for delegated management of backend users, backend groups, module permissions, DB mounts, page tree access and file mounts.

The extension is built for TYPO3 12.4 LTS and follows TYPO3 extension packaging conventions so it can be installed through Composer and published on Packagist.

## Package

- Extension key: `ppl_rights_management`
- Composer package: `ppl/ppl_rights_management`
- Current release: `12.4.0`
- TYPO3 compatibility: `12.4.0-12.4.99`
- PHP compatibility: `>=8.2`
- License: `GPL-2.0-or-later`

## Features

- Overview of effective backend permissions for selected users or groups
- Backend group management
- Record permissions for page types and database tables
- Backend module permissions
- Group inheritance management
- Backend user group assignment
- Direct backend user rights management
- DB mount and file mount management
- History log for rights management changes
- Delegated write mode for non-admin backend users

Delegated users can only assign rights they effectively have themselves. Admin users can always save.

## Installation

Install the extension with Composer:

```bash
composer require ppl/ppl_rights_management:^12.4
```

Activate the extension in TYPO3 if your setup does not do this automatically:

```bash
vendor/bin/typo3 extension:setup
```

After installation, run the TYPO3 database schema analyzer or deployment command so the history table `tx_pplrightsmanagement_history` is created.

## Backend Module

The module is registered below **System** as **PPL Rights Management**.

TYPO3 backend access is still controlled by backend groups. Add the module to the allowed modules of the backend groups that should open it.

## Extension Configuration

The extension provides these configuration options under `ppl_rights_management`:

- `enableDelegatedWrites`: Enables write access for configured delegated backend groups. When disabled, delegated groups can open the module read-only.
- `moduleAccessGroupIds`: Optional comma-separated list of backend group UIDs or exact group titles that may access the module through the automatic group resolver.
- `enforceUserPermissions`: Restricts the displayed rights to rights the current backend user effectively has. When disabled, unavailable rights remain visible but read-only for delegated users.

## History

Successful save operations are written to `tx_pplrightsmanagement_history`.

The history stores:

- backend user UID and name
- optional impersonation source
- scope
- action
- summary
- payload before and after the change

## Versioning

This package uses TYPO3-aligned semantic versioning.

For TYPO3 12.4 LTS, releases use the `12.4.x` line. The Composer package should be released from Git tags, for example:

```bash
git tag 12.4.0
git push origin 12.4.0
```

Do not add a `version` field to `composer.json`; Packagist reads the version from Git tags.

When publishing from a monorepo, publish this package directory through a subtree split or a dedicated repository. Packagist expects the package `composer.json` at the repository root.

## Development

The extension is PSR-4 autoloaded from `Classes/`:

```json
"Ppl\\PplRightsManagement\\": "Classes/"
```

Relevant files:

- `Configuration/Backend/Modules.php`: backend module registration
- `Configuration/Icons.php`: backend icon registration
- `ext_conf_template.txt`: extension configuration
- `ext_tables.sql`: database schema
- `Resources/Private/Templates/`: backend Fluid templates
- `Resources/Public/`: CSS, JavaScript and icons

## License

This TYPO3 extension is licensed under the GNU General Public License, version 2 or later. See `LICENSE`.
