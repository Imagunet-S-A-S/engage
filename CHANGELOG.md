# Changelog

All notable changes to this project will be documented in this file.

## [2.2.5] - 2026-05-19

### Fixed

- Override `canUpdate()`, `canCreateItem()`, `canUpdateItem()`, and `canViewItem()` in `PluginEngageConfig`, `PluginEngageSettings`, and `PluginEngageQueue` so that GLPI's internal `CommonDBTM` permission checks honour the same logic as the explicit controller guards — resolves "no permission" errors when saving entity configuration.
- Add a DB fallback (`activeProfileHasRight`) to `canReadEngage()` and `canUpdateEngage()` so profiles whose session rights have not been refreshed after plugin install still pass the permission check.
- Fix the Engage entity tab visibility check to use `canReadEngage()` instead of the raw `Session::haveRight()` call.

### Added

- PHPUnit test suite (`tests/Unit/`) covering time-slot range logic, priority-filter parsing, and profile rights constants; runs without a GLPI installation via stubs in `tests/bootstrap.php`.
- `phpunit.xml` configuration and `composer test` script.

### Changed

- Updated `composer.json`: description, `phpunit/phpunit ^10.5` dev dependency, `vendor/` added to `.gitignore`.
- Standardised file headers to the short form across `inc/profile.class.php`, `front/config.php`, and the Twig base templates.
- Rewrote `README.md` with badges, permissions section, configuration table, and development instructions.

## [2.2.4] - 2026-05-19

### Changed

- Publish GitHub releases with `softprops/action-gh-release@v2` and attach the generated package as a release asset.

## [2.2.3] - 2026-05-19

### Fixed

- Fix Engage front controllers when the plugin is installed in `/var/lib/glpi/plugins` instead of under the GLPI source tree.
- Allow profiles with GLPI setup/config rights to access Engage even if their active session does not yet include the new Engage-specific right.

## [2.2.2] - 2026-05-19

### Fixed

- Make the Engage profile right (`plugin_engage_config`) the actual permission used by entity configuration, settings, and queue pages.
- Rename the profile permission row from "Technician" to "Engage configuration" to match what it controls.
- Seed Engage read/update rights for profiles that already have GLPI setup/config update rights, keeping super-admin/configuration profiles functional after upgrades.

## [2.2.1] - 2026-04-24

### Fixed

- Enforce GLPI 11 and PHP 8.2 requirements in plugin metadata and prerequisite checks.
- Add CSRF and permission validation to the entity configuration form handler.
- Respect the entity recursive flag when resolving inherited Engage configuration.
- Validate the configured technician before queueing delayed followups.
- Queue outside-calendar followups to the next available working window when no time slot is configured.
- Make next-slot scheduling honor the calendar override option.
- Escape direct HTML output in technician and priority labels.
- Redirect the legacy `front/config.php` entry point to the supported settings page.
- Ignore Codex workspace files in Git.

### Changed

- Standardized plugin authorship and repository metadata under Imagunet S.A.S.
- Updated package metadata, marketplace XML, release workflow MIME type, and lint workflow PHP version.
- Updated README and changelog to reflect the current non-round-robin behavior.

## [2.2.0] - 2026-03-03

### Breaking Changes

- **Requires GLPI 11.0.0+** — drops support for GLPI 10.x
- Plugin version jump from `1.0.12` to `2.2.0`

### Added

- **Queue system** (`PluginEngageQueue`): asynchronous followup delivery with cron processor, status tracking (pending/sent/error) and queue dashboard UI
- **Time slot support** (`PluginEngageTimeSlot`): schedule-based template selection with UTC/timezone handling, slot range resolution and next-slot calculation
- **Settings module** (`PluginEngageSettings`): global plugin settings with log retention policy and cron purge task
- **Ticket log** (`PluginEngageLog`): per-ticket traceability tab showing engagement outcomes and status labels
- `front/queue.php` — queue dashboard front controller
- `front/settings.php` — settings front controller
- `inc/log.class.php`, `inc/queue.class.php`, `inc/settings.class.php`, `inc/timeslot.class.php` — new classes
- `templates/pages/queue_dashboard.html.twig` — queue monitoring view
- `templates/pages/settings_form.html.twig` — global settings form
- `templates/pages/ticket_log.html.twig` — per-ticket log view

### Changed

- Technician assignment now uses the configured fallback technician with rights validation; round-robin assignment was removed
- **Config class** (`PluginEngageConfig`) extended with calendar/delay scheduling, priority filtering, inline slot editor and Bootstrap 5 rendering
- `hook.php` updated with new hooks for queue processing and cron registration
- `front/config.form.php` and `front/config.php` updated for GLPI 11 routing
- `templates/base_form.html.twig` and `templates/footer_form.html.twig` migrated to Bootstrap 5 / Twig conventions
- `templates/pages/entity_setup.html.twig` simplified and updated for GLPI 11 entity API
- `inc/profile.class.php` updated for GLPI 11 rights management
- README rewritten for v2.2.0 with updated features and installation instructions

### Removed

- Support for GLPI 10.0.x
- Round-robin technician assignment and related tables/fields
- Legacy inline PHP rendering replaced by Twig templates throughout

---

## [1.0.12] - 2024

### Initial stable release

- Basic technician assignment on ticket creation
- Welcome followup via impersonation
- Single entity configuration tab
- GLPI 10.0.x support
