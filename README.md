# Simple Engage Service - GLPI Plugin

##### _Make it easy, simple and imagine_

<p align="center">
  <img src="https://raw.githubusercontent.com/Imagunet-S-A-S/engage/development/engage.svg" alt="Simple Engage Service" width="180"/>
</p>

## Requirements

- GLPI >= 11.0.0 and <= 11.0.99
- PHP >= 8.2

## Features

- Automatic welcome followup on ticket creation
- Per-entity configuration with parent inheritance controlled by the recursive flag
- Fallback technician assignment with ticket rights validation
- Ticket type and priority filters
- Dynamic time slots with per-slot followup templates
- Business-hours calendar restriction with optional 24/7 override
- Queue processor with cron delivery for delayed or future-slot followups
- UTC/timezone-aware scheduling
- Ticket activity log for traceability
- Retention settings and purge cron for logs and queue rows

## Installation

```sh
cd /var/www/glpi/plugins
wget https://github.com/Imagunet-S-A-S/engage/releases/download/v2.2.0/glpi-engage-v2.2.0.tar.gz
tar xvf glpi-engage-v2.2.0.tar.gz
rm glpi-engage-v2.2.0.tar.gz
```

Go to **Setup -> Plugins**, then click **Install** and **Activate**.

## Configuration

Configure Engage from **Administration -> Entities -> Entity -> Engage**.

Key fields:

- **Fallback technician**: technician assigned to tickets and used as followup author.
- **Ticket type**: process incidents, requests, or both.
- **Priority filter**: when empty, all priorities are processed.
- **Default template**: fallback followup template.
- **Dynamic time slots**: ordered local-time ranges with a specific template.
- **Business hours calendar**: restrict sending to working periods.
- **Override calendar**: ignore calendar limits and send 24/7.
- **Delay before sending**: queue the followup for later delivery.

## Automatic Actions

Engage registers these GLPI automatic actions:

- `PluginEngageQueue::processQueue`: delivers pending followups.
- `PluginEngageSettings::purge`: removes old log and queue rows according to retention settings.

## Database Tables

- `glpi_plugin_engage_configs`
- `glpi_plugin_engage_timeslots`
- `glpi_plugin_engage_queues`
- `glpi_plugin_engage_logs`
- `glpi_plugin_engage_settings`

## Contributing

- Open an issue for each bug or feature request.
- Follow [GLPI development guidelines](https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/index.html).
- Work on a branch from `development` on your own fork.
- Open a PR against `development`.

## License

GPLv3+
