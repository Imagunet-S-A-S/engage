# Changelog

All notable changes to this project will be documented in this file.

## [2.2.0] - 2026-03-03

### Breaking Changes

- **Requires GLPI 11.0.0+** — drops support for GLPI 10.x
- Plugin version jump from `1.0.12` to `2.2.0`

### Added

- **Queue system** (`PluginEngageQueue`): asynchronous followup delivery with cron processor, status tracking (pending/sent/error) and queue dashboard UI
- **Time slot support** (`PluginEngageTimeslot`): schedule-based template selection with UTC/Bogotá timezone handling, slot range resolution and next-slot calculation
- **Settings module** (`PluginEngageSettings`): global plugin settings with log retention policy and cron purge task
- **Ticket log** (`PluginEngageLog`): per-ticket traceability tab showing engagement outcomes and status labels
- `front/queue.php` — queue dashboard front controller
- `front/settings.php` — settings front controller
- `inc/log.class.php`, `inc/queue.class.php`, `inc/settings.class.php`, `inc/timeslot.class.php` — new classes
- `templates/pages/queue_dashboard.html.twig` — queue monitoring view
- `templates/pages/settings_form.html.twig` — global settings form
- `templates/pages/ticket_log.html.twig` — per-ticket log view

### Changed

- **Round-robin technician assignment** now supports per-entity rotation (`PluginEngageTicket`)
- **Config class** (`PluginEngageConfig`) extended with calendar/delay scheduling, priority filtering, inline slot editor and Bootstrap 5 rendering
- `hook.php` updated with new hooks for queue processing and cron registration
- `front/config.form.php` and `front/config.php` updated for GLPI 11 routing
- `templates/base_form.html.twig` and `templates/footer_form.html.twig` migrated to Bootstrap 5 / Twig conventions
- `templates/pages/entity_setup.html.twig` simplified and updated for GLPI 11 entity API
- `inc/profile.class.php` updated for GLPI 11 rights management
- README rewritten for v2.2.0 with updated features and installation instructions

### Removed

- Support for GLPI 10.0.x
- Legacy inline PHP rendering replaced by Twig templates throughout

---

## [1.0.12] - 2024

### Initial stable release

- Basic technician assignment on ticket creation
- Welcome followup via impersonation
- Single entity configuration tab
- GLPI 10.0.x support
