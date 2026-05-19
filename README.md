# Engage — Automatic Followup for GLPI

[![Latest Release](https://img.shields.io/github/v/release/Imagunet-S-A-S/engage)](https://github.com/Imagunet-S-A-S/engage/releases/latest)
[![GLPI](https://img.shields.io/badge/GLPI-11.0-informational)](https://glpi-project.org/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-informational)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--3.0%2B-success)](LICENSE)

<p align="center">
  <img src="https://raw.githubusercontent.com/Imagunet-S-A-S/engage/development/engage.svg" alt="Engage logo" width="160"/>
</p>

Engage automatically sends a welcome followup when a ticket is created and assigns the configured technician, respecting your business-hours calendar, priority rules, and time-slot schedules.

---

## Requirements

| Component | Minimum |
|-----------|---------|
| GLPI      | 11.0.0  |
| PHP       | 8.2     |

---

## Features

- **Automatic followup** on ticket creation using any ITIL followup template
- **Per-entity configuration** with parent/child inheritance via the recursive flag
- **Fallback technician** with ASSIGN/STEAL rights validation
- **Ticket type filter** — process Incidents, Requests, or both
- **Priority filter** — restrict notifications to selected priority levels
- **Dynamic time slots** — different followup templates per time window, drag-and-drop ordering
- **Business-hours calendar** — queue followups for the next working period
- **24/7 override** — bypass the calendar and always send
- **Configurable delay** — queue the followup for later delivery (0–10,080 minutes)
- **Asynchronous queue** — background processing via GLPI cron tasks
- **Activity log** — per-ticket traceability tab with outcome tracking
- **Retention policy** — configurable purge cron for logs and queue rows

---

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/Imagunet-S-A-S/engage/releases/latest).
2. Extract the archive to your GLPI plugins directory:

   ```sh
   # Standard layout
   tar xvf glpi-engage-v<version>.tar.gz -C /var/www/glpi/plugins/

   # External data directory layout (GLPI >= 10.0.5)
   tar xvf glpi-engage-v<version>.tar.gz -C /var/lib/glpi/plugins/
   ```

3. In GLPI, go to **Setup → Plugins**, click **Install**, then **Activate**.

---

## Permissions

Engage introduces one permission: **Engage configuration** (`plugin_engage_config`).

- Visible under **Administration → Profiles → _Profile_ → Engage management**.
- Profiles that already have the GLPI **Setup/Config** update right receive this permission automatically on install and upgrade.
- Grant or revoke it manually per profile as needed.

---

## Configuration

Navigate to **Administration → Entities → _Entity_ → Engage** to configure the plugin for an entity.

| Field | Description |
|-------|-------------|
| Enable automatic followup | Activate/deactivate Engage for this entity |
| Ticket type | Incident, Request, or both |
| Recursive | Propagate this configuration to child entities |
| Fallback technician | Assigned to tickets; used as followup author |
| Notify on priorities | Leave empty to act on all priorities |
| Default template | Followup template used when no time slot matches |
| Send default outside slots | If unchecked, no followup is sent outside defined slot windows |
| Time slots | Ordered time windows, each with its own template (drag to reorder) |
| Business hours calendar | Restrict delivery to working hours |
| Override calendar (24/7) | Ignore the calendar and always send |
| Delay before sending | Queue the followup for N minutes after creation (0 = immediate) |

Global settings (log and queue retention days) are available at **Setup → Plugins → Engage**.

---

## Automatic Actions

| Action | Interval | Description |
|--------|----------|-------------|
| `PluginEngageQueue` | 1 min | Delivers pending followups whose scheduled time has arrived |
| `PluginEngageSettings` | 24 h | Purges old log and queue rows per the configured retention policy |

---

## Database Tables

| Table | Contents |
|-------|----------|
| `glpi_plugin_engage_configs` | Per-entity configuration rows |
| `glpi_plugin_engage_timeslots` | Dynamic time-slot definitions |
| `glpi_plugin_engage_queues` | Asynchronous followup delivery queue |
| `glpi_plugin_engage_logs` | Per-ticket engagement activity log |
| `glpi_plugin_engage_settings` | Global plugin settings (single row) |

---

## Development

### Running the unit tests

```sh
composer install
composer test
```

Tests live in `tests/Unit/` and cover pure-logic methods (time-slot range matching, priority filter parsing, rights constants). They run without a GLPI installation using the stubs in `tests/bootstrap.php`.

### Code quality

```sh
# Syntax check all PHP source files
composer lint
```

### Contributing

1. Open an issue for each bug or feature request.
2. Follow the [GLPI plugin development guidelines](https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/index.html).
3. Fork the repository and branch from `development`.
4. Open a pull request against `development`.

---

## License

[GPL-3.0-or-later](LICENSE) — Copyright (C) 2024 [Imagunet S.A.S.](https://www.imagunet.com)
