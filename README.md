# Simple Engage Service — GLPI Plugin

##### _Make it easy, simple and imagine_

<p align="center">
  <img src="https://raw.githubusercontent.com/Imagunet-S-A-S/engage/development/engage.svg" alt="Simple Engage Service" width="180"/>
</p>

## Requirements

- GLPI >= 11.0.0

## Features

- Round-robin technician assignment per entity
- Automatic welcome followup on ticket creation
- Time-slot-based template selection
- Queue processor with cron delivery
- UTC/timezone-aware scheduling
- Per-entity configuration with entity selector
- Ticket log for traceability

## Installation
```sh
cd /var/www/glpi/plugins
wget https://github.com/Imagunet-S-A-S/engage/releases/download/v2.2.0/glpi-engage-v2.2.0.tar.gz
tar xvf glpi-engage-v2.2.0.tar.gz
rm glpi-engage-v2.2.0.tar.gz
```

Go to **Setup → Plugins**, then click **Install** and **Activate**.

## Contributing

- Open an issue for each bug or feature request
- Follow [GLPI development guidelines](http://glpi-developer-documentation.readthedocs.io/en/latest/plugins/index.html)
- Work on a branch from `development` on your own fork
- Open a PR against `development`

## License

GPLv3+
