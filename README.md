# Simple Engage Service — GLPI Plugin

##### _Make it easy, simple and imagine_

<p align="center">
  <img src="https://raw.githubusercontent.com/Imagunet-S-A-S/engage/blob/development/engage.svg)" alt="Simple Engage Service" style="max-width: 250px;"/>
</p>

## Overview

**Engage** automatically sends a personalized welcome followup the moment a ticket is created in GLPI. The followup appears as sent by a real technician — not the system — and can be customized per entity, time slot, and ticket type.

Designed for enterprise telecom environments with multiple entities, business hours restrictions, and load-balanced technician teams.

Currently only available for **Tickets**. Requires **GLPI 10.0.x**.

---

## Features

| Feature | Since |
|---|---|
| Automatic welcome followup on ticket creation | v1.0 |
| Technician impersonation (followup appears from real tech) | v1.0 |
| Per-entity configuration with parent inheritance | v1.0 |
| Ticket type filter (Incident / Request / Both) | v1.0 |
| Business hours calendar restriction | v1.1 |
| Configurable send delay (calendar-aware working minutes) | v1.1 |
| Async delivery queue with CronTask | v1.1.1 |
| Activity log tab on each ticket | v1.2 |
| Admin Queue Monitor dashboard | v1.2 |
| Global data retention & nightly purge | v1.3 |
| **Group-based round-robin technician assignment** | **v2.0** |
| **Per-slot dynamic templates (Morning/Afternoon/Night/Weekend)** | **v2.0** |
| **Configurable slot time boundaries per entity** | **v2.0** |

---

## Installation

```sh
cd /var/www/glpi/plugins
tar xvf engage-v2.0.3.tar.bz2
```

Go to **Setup → Plugins**, click **Install**, then **Activate**.

> ⚠️ If upgrading from v1.x, reinstall the plugin to apply the database migrations for the new `rr_members` table and the new columns in `glpi_plugin_engage_configs`.

---

## Configuration

### Per-entity setup

Go to **Administration → Entities → [Entity] → Engage tab**.

#### General

| Field | Description |
|---|---|
| Enable automatic followup | Activate / deactivate for this entity |
| Ticket type | Incident only / Request only / Both |
| Recursive | Inherit this config to child entities |

#### Technician Assignment

| Field | Description |
|---|---|
| Round-Robin group | GLPI group whose members form the rotation pool |
| Active in round-robin | Checkboxes — select which group members participate |
| Fallback technician | Used only when no group is configured |

**How round-robin works:**
1. When a ticket is created, Engage picks the next active member in rotation order.
2. It checks that the selected member has `ASSIGN` + `STEAL` rights on Ticket in the ticket's entity.
3. If the member lacks rights, it skips to the next one.
4. If no valid member is found, it falls back to the individual technician.
5. If that also fails, the ticket is skipped and the reason is logged.
6. The rotation index is updated atomically after each assignment.

> Members who are disabled or deleted in GLPI are automatically excluded from the rotation without requiring any change to the Engage config.

#### Followup Templates

| Field | Description |
|---|---|
| Default template ★ | **Required.** Used when no slot-specific template matches. |
| Morning template | Optional. Applied during the configured morning hours. |
| Afternoon template | Optional. Applied during the configured afternoon hours. |
| Night template | Optional. Applied during the configured night hours (supports midnight-crossing ranges). |
| Weekend template | Optional. Applied on Saturday and Sunday (all hours). |

Each slot has configurable start/end times (HH:MM). Leave a slot's template blank to fall back to the default for that period.

**Resolution order:**
1. Weekend (Saturday or Sunday) → `tpl_weekend`
2. Morning range → `tpl_morning`
3. Afternoon range → `tpl_afternoon`
4. Night range → `tpl_night` (handles midnight-crossing, e.g. 22:00–06:00)
5. No match → `itil_followup` (default)

#### Business Hours & Delay

| Field | Description |
|---|---|
| Business hours calendar | If set, followups are only sent during working periods of this calendar. Tickets outside hours are skipped. |
| Delay before sending (minutes) | `0` = immediate. If a calendar is set, delay counts working minutes only. Max 10080 (7 days). |

---

### Global settings

Go to **Setup → Engage → Settings & Retention**.

| Field | Default | Description |
|---|---|---|
| Activity log retention | 90 days | How long to keep rows in `glpi_plugin_engage_logs` |
| Queue Sent/Cancelled retention | 30 days | How long to keep processed queue entries |
| Queue Error retention | 90 days | How long to keep failed queue entries (longer for diagnosis) |

Set any value to `0` to keep forever. **PENDING queue entries are never auto-purged.**

---

### Automatic Actions (CronTasks)

| Task | Frequency | Purpose |
|---|---|---|
| `PluginEngageQueue :: processQueue` | Every 1 min | Delivers delayed followups when their scheduled time arrives |
| `PluginEngageSettings :: purge` | Daily | Deletes log and queue rows older than configured retention thresholds |

> **Recommended:** Run cron in **CLI mode** for `processQueue`. CLI mode runs independently of web traffic and guarantees 1-minute precision.
>
> ```cron
> * * * * * www-data /usr/bin/php /var/www/glpi/front/cron.php --force 2>/dev/null
> ```

---

## Admin interfaces

### Queue Monitor
**Setup → Plugins → Engage → Queue Monitor**

Shows all queued, sent, errored, and cancelled followup jobs with:
- Summary cards (Pending / Sent / Errors / Cancelled)
- Tab filter per status
- Ticket link, technician, template, scheduled time, processed time, remaining ETA

### Activity log (per ticket)
**Ticket → Engage tab**

Shows every decision Engage made for that ticket:
- Outcome badge: `sent` / `queued` / `skipped` / `error`
- Reason text (e.g. *"Outside business hours"*, *"Queued 5min delay — slot: Morning"*)
- Technician and template used
- Scheduled delivery time with live countdown for pending jobs

---

## Database tables

| Table | Description |
|---|---|
| `glpi_plugin_engage_configs` | Per-entity configuration (one row per entity) |
| `glpi_plugin_engage_rr_members` | Round-robin member list per config |
| `glpi_plugin_engage_queues` | Async delivery queue |
| `glpi_plugin_engage_logs` | Activity log (one row per ticket event) |
| `glpi_plugin_engage_settings` | Global plugin settings (single row) |

---

## Testing guide

### Test 1 — Round-robin rotation

1. Configure a group with 2+ members, all checkboxes active.
2. Set delay to `0` (immediate).
3. Create 4 tickets in rapid succession.
4. Open **Queue Monitor** → verify technician alternates: A → B → A → B.
5. Open each ticket's **Engage tab** → verify log shows *"Sent immediately (slot: ..., tech: ...)"*.

### Test 2 — Time-slot templates

1. Create 3 ITILFollowupTemplates: `TPL-Morning`, `TPL-Afternoon`, `TPL-Night`.
2. In the entity config, assign each to the corresponding slot.
3. Temporarily adjust a slot's time range to include the current time.
4. Create a ticket → open Engage tab → verify the correct template name appears in the log.
5. Restore the original time ranges.

### Test 3 — Fallback when round-robin exhausted

1. In the entity config, uncheck all round-robin members (keep the group selected).
2. Set a valid individual fallback technician.
3. Create a ticket → Engage tab should show the fallback tech was used.
4. Now clear the fallback tech too.
5. Create another ticket → Engage tab should show `skipped` with reason *"Round-robin: no active member..."*.

### Test 4 — Business hours skip

1. Set a calendar that excludes the current time (e.g. a weekday-only calendar on a weekend).
2. Create a ticket → Engage tab should show `skipped` with *"Outside business hours"*.

### Test 5 — Delayed delivery

1. Set delay to `5` minutes, CronTask in CLI mode running every minute.
2. Create a ticket → Queue Monitor shows `Pending` with ETA countdown.
3. Wait 5 minutes → status flips to `Sent` with processed timestamp.
4. Open the ticket → followup is visible from the technician.

---

## Authors

- **Juan Gallego** — juan.gallego@imagunet.com
- **Santiago Gomez** — santiago.gomez@imagunet.com
- **Giovanny Rodriguez** — giovanny.rodriguez@imagunet.com

**Imagunet S.A.S.** — https://www.imagunet.com

---

## Changelog

### v2.0.3
- **Fix:** `is_deleted` undefined warning on `glpi_groups` — groups table has no soft-delete; replaced with `countElementsInTable()` existence check

### v2.0.2
- **Fix:** Round-robin member checkboxes not persisting — `config.form.php` now processes `rr_members[]` POST array and calls `syncMembers()` after config save
- **Fix:** `CronTask::unregister()` called with plugin shortname instead of class names
- **Fix:** `DATETIME` columns changed to `TIMESTAMP` per GLPI 10 recommendations
- **Fix:** Fallback technician dropdown width — missing `col-xxl-8 field-container` wrapper

### v2.0.0
- **New:** Group-based round-robin technician assignment
  - GLPI group defines the candidate pool
  - Per-member checkbox activation within the group
  - Automatic skip of members without ASSIGN+STEAL rights
  - Fallback chain: round-robin → individual tech → skip + log
  - Inactive/deleted GLPI users excluded via JOIN filter on `glpi_users`
  - Graceful handling of deleted groups via `countElementsInTable()`
  - Inherited config warning in entity UI
- **New:** Per-slot dynamic templates (Morning / Afternoon / Night / Weekend)
  - Each slot has configurable HH:MM boundaries per entity
  - Night slot supports midnight-crossing ranges (e.g. 22:00–06:00)
  - Slot name recorded in activity log for full traceability
- **New:** `PluginEngageRoundRobin` — member management, selection algorithm, UI
- **New:** `PluginEngageTimeSlot` — slot resolution with midnight-crossing support

### v1.3.0
- **New:** Global Settings page (Setup → Engage)
- **New:** Configurable retention per data type (logs / sent queue / error queue)
- **New:** `cronPurge` nightly CronTask
- PENDING queue entries protected from auto-purge

### v1.2.0
- **New:** Activity log per ticket (Engage tab)
- **New:** Admin Queue Monitor dashboard
- **New:** All Engage decisions logged with outcome, reason, technician, template

### v1.1.1
- **New:** True async delivery queue via CronTask (every 60s)
- **New:** `glpi_plugin_engage_queues` table
- **Fix:** `calendars_id` migration type changed to `fkey` (unsigned)

### v1.1.0
- **New:** Business hours calendar restriction
- **New:** Configurable send delay (calendar-aware working minutes)
- **Fix:** Fatal `isNewItem() on bool` when no config exists
- **Fix:** Boolean precedence in entity inheritance logic
- **Fix:** Template rendered with wrong object
- **Fix:** Session impersonation uses try/finally
- **Fix:** Singleton cache resets across entities

### v1.0.12
- Initial stable release

---

## Contributing

- Open a ticket for each bug/feature
- Follow [GLPI plugin development guidelines](https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/index.html)
- Use [GitFlow](http://git-flow.readthedocs.io/) branching
