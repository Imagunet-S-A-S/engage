<?php

/**
 * -------------------------------------------------------------------------
 * engage plugin for GLPI
 * Copyright (C) 2024 Imagunet S.A.S.
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+
 * @link https://github.com/Imagunet-S-A-S/engage/
 * --------------------------------------------------------------------------
 *
 * PluginEngageTimeSlot — Dynamic time-slot management.
 *
 * TIME ZONE STRATEGY:
 * - All HH:MM values in the DB represent LOCAL time in the GLPI timezone
 *   ($_SESSION['glpitimezone'] or PHP default).
 * - All DateTime comparisons use the GLPI timezone so that "08:00" means
 *   08:00 in Colombia (or wherever GLPI is configured), not UTC.
 * - The queue send_after is stored in UTC (MySQL standard).
 *
 * Slots are stored in glpi_plugin_engage_timeslots (one row per period).
 * Resolution order:
 *   1. Load all slots for the config, ordered by sort_order ASC
 *   2. Return the FIRST slot whose [start_time, end_time) range contains now
 *      (evaluated in GLPI timezone)
 *   3. If no slot matches:
 *      - If use_default_outside_slots = 1 → use default template (itil_followup)
 *      - If use_default_outside_slots = 0 → return 0 (no followup sent)
 */
class PluginEngageTimeSlot extends CommonDBTM
{
   static $rightname = PluginEngageProfile::RIGHT_CONFIG;

   public static function getTypeName($nb = 0): string { return __('Time Slot', 'engage'); }

   // ── Timezone helper ───────────────────────────────────────────────────

   /**
    * Return the GLPI-configured timezone as a DateTimeZone object.
    * Falls back to PHP's default timezone if GLPI session is not available.
    */
   public static function getGlpiTimezone(): DateTimeZone
   {
      // GLPI 11 stores the active timezone in the session
      $tz_string = $_SESSION['glpitimezone'] ?? date_default_timezone_get();
      if (empty($tz_string)) {
         $tz_string = 'UTC';
      }
      try {
         return new DateTimeZone($tz_string);
      } catch (\Exception $e) {
         return new DateTimeZone('UTC');
      }
   }

   /**
    * Return "now" in the GLPI timezone.
    */
   public static function nowInGlpiTz(): DateTime
   {
      return new DateTime('now', self::getGlpiTimezone());
   }

   // ── CRUD helpers ──────────────────────────────────────────────────────

   /**
    * Return all slots for a given configs_id, ordered by sort_order.
    */
   public static function getSlotsForConfig(int $configs_id): array
   {
      global $DB;
      if ($configs_id <= 0) return [];

      $rows = $DB->request([
         'FROM'  => self::getTable(),
         'WHERE' => ['configs_id' => $configs_id],
         'ORDER' => ['sort_order ASC', 'id ASC'],
      ]);

      $slots = [];
      foreach ($rows as $row) {
         $slots[] = $row;
      }
      return $slots;
   }

   /**
    * Save slots from POST data.
    * HH:MM values from the form are in GLPI local time — stored as-is
    * since we always compare in GLPI timezone too.
    */
   public static function saveFromPost(int $configs_id, array $post): void
   {
      global $DB;

      $ids       = array_map('intval', $post['engage_slot_ids']       ?? []);
      $names     = $post['engage_slot_names']      ?? [];
      $starts    = $post['engage_slot_starts']     ?? [];
      $ends      = $post['engage_slot_ends']       ?? [];
      $templates = array_map('intval', $post['engage_slot_templates'] ?? []);

      $submitted_ids = array_filter($ids, fn($id) => $id > 0);

      // Delete slots not in POST
      $existing = $DB->request([
         'SELECT' => ['id'],
         'FROM'   => self::getTable(),
         'WHERE'  => ['configs_id' => $configs_id],
      ]);
      foreach ($existing as $row) {
         if (!in_array((int)$row['id'], $submitted_ids)) {
            $DB->delete(self::getTable(), ['id' => (int)$row['id']]);
         }
      }

      // Upsert submitted slots
      foreach ($ids as $order => $slot_id) {
         $name   = trim($names[$order]     ?? '');
         $start  = trim($starts[$order]    ?? '00:00');
         $end    = trim($ends[$order]      ?? '23:59');
         $tpl_id = (int)($templates[$order] ?? 0);

         if ($name === '' || $tpl_id === 0) continue;

         $data = [
            'configs_id'  => $configs_id,
            'name'        => $name,
            'start_time'  => $start,
            'end_time'    => $end,
            'template_id' => $tpl_id,
            'sort_order'  => $order,
         ];

         if ($slot_id > 0) {
            $DB->update(self::getTable(), $data, ['id' => $slot_id]);
         } else {
            $DB->insert(self::getTable(), $data);
         }
      }
   }

   // ── Template resolution ───────────────────────────────────────────────

   /**
    * Resolve the template ID to use for the given config and moment.
    * All comparisons done in GLPI timezone.
    *
    * @param PluginEngageConfig $config
    * @param string|null        $datetime  ISO datetime (default: now in GLPI tz)
    * @return int  ITILFollowupTemplate ID, or 0 if no slot matches and default disabled
    */
   public static function resolveTemplate(PluginEngageConfig $config, ?string $datetime = null): int
   {
      $configs_id  = (int)($config->fields['id']           ?? 0);
      $default_tpl = (int)($config->fields['itil_followup'] ?? 0);
      $use_default = (int)($config->fields['use_default_outside_slots'] ?? 1);

      // Evaluate "now" in GLPI timezone
      $tz   = self::getGlpiTimezone();
      $dt   = $datetime ? new DateTime($datetime, $tz) : new DateTime('now', $tz);
      $hhmm = $dt->format('H:i');

      $slots = self::getSlotsForConfig($configs_id);

      foreach ($slots as $slot) {
         if (self::inRange($hhmm, $slot['start_time'], $slot['end_time'])) {
            $tpl = (int)$slot['template_id'];
            Toolbox::logDebug('[Engage TimeSlot] Matched slot "' . $slot['name'] . '" → template #' . $tpl);
            return $tpl > 0 ? $tpl : $default_tpl;
         }
      }

      if ($use_default && $default_tpl > 0) {
         Toolbox::logDebug('[Engage TimeSlot] No slot matched → using default template #' . $default_tpl);
         return $default_tpl;
      }

      Toolbox::logDebug('[Engage TimeSlot] No slot matched and use_default_outside_slots=0 → silent skip');
      return 0;
   }

   /**
    * Return the name of the currently matching slot (in GLPI timezone).
    */
   public static function currentSlotName(PluginEngageConfig $config): string
   {
      $configs_id  = (int)($config->fields['id'] ?? 0);
      $use_default = (int)($config->fields['use_default_outside_slots'] ?? 1);
      $hhmm        = self::nowInGlpiTz()->format('H:i');

      foreach (self::getSlotsForConfig($configs_id) as $slot) {
         if (self::inRange($hhmm, $slot['start_time'], $slot['end_time'])) {
            return $slot['name'];
         }
      }

      return $use_default ? __('Default', 'engage') : __('None (outside slots)', 'engage');
   }

   /**
    * Calculate the next datetime (UTC, for queue storage) when any configured
    * slot begins, evaluated in GLPI timezone.
    *
    * Returns null if no slots configured.
    */
   public static function nextSlotStart(PluginEngageConfig $config): ?string
   {
      $configs_id   = (int)($config->fields['id'] ?? 0);
      $calendars_id = !empty($config->fields['override_calendar'])
         ? 0
         : (int)($config->fields['calendars_id'] ?? 0);
      $slots        = self::getSlotsForConfig($configs_id);

      if (empty($slots)) return null;

      $tz          = self::getGlpiTimezone();
      $now         = new DateTime('now', $tz);
      $now_minutes = self::toMinutes($now->format('H:i'));

      $best_diff       = null;
      $best_start_time = null;

      foreach ($slots as $slot) {
         $start_minutes = self::toMinutes($slot['start_time']);
         $diff = $start_minutes - $now_minutes;
         if ($diff <= 0) {
            $diff += 1440; // wrap to next day
         }
         if ($best_diff === null || $diff < $best_diff) {
            $best_diff       = $diff;
            $best_start_time = $slot['start_time'];
         }
      }

      if ($best_start_time === null) return null;

      // Build candidate in GLPI timezone at exact slot start_time
      [$sh, $sm] = explode(':', $best_start_time);
      $candidate = clone $now;

      // If that time already passed today, move to tomorrow
      if (self::toMinutes($best_start_time) <= $now_minutes) {
         $candidate->modify('+1 day');
      }
      $candidate->setTime((int)$sh, (int)$sm, 0);

      // If no calendar restriction, convert to UTC for queue storage
      if ($calendars_id === 0) {
         $candidate->setTimezone(new DateTimeZone('UTC'));
         return $candidate->format('Y-m-d H:i:s');
      }

      // With calendar: advance day-by-day (in GLPI tz) until working hour
      $calendar = new Calendar();
      if (!$calendar->getFromDB($calendars_id)) {
         $candidate->setTimezone(new DateTimeZone('UTC'));
         return $candidate->format('Y-m-d H:i:s');
      }

      $max_attempts = 14;
      for ($i = 0; $i < $max_attempts; $i++) {
         // isAWorkingHour expects UTC or server time — convert candidate to UTC for check
         $check_utc = clone $candidate;
         $check_utc->setTimezone(new DateTimeZone('UTC'));
         if ($calendar->isAWorkingHour($check_utc->format('Y-m-d H:i:s'))) {
            return $check_utc->format('Y-m-d H:i:s');
         }
         $candidate->modify('+1 day');
      }

      Toolbox::logWarning('[Engage TimeSlot] nextSlotStart: could not find working hour in 14 days for calendar #' . $calendars_id);
      $candidate->setTimezone(new DateTimeZone('UTC'));
      return $candidate->format('Y-m-d H:i:s');
   }

   /**
    * Return the next working minute for a calendar, in UTC for queue storage.
    */
   public static function nextCalendarWorkingDate(int $calendars_id): ?string
   {
      if ($calendars_id <= 0) return null;

      $calendar = new Calendar();
      if (!$calendar->getFromDB($calendars_id)) return null;

      $candidate = new DateTime('now', new DateTimeZone('UTC'));
      $candidate->modify('+1 minute');
      $candidate->setTime(
         (int)$candidate->format('H'),
         (int)$candidate->format('i'),
         0
      );

      $max_attempts = 14 * 24 * 60;
      for ($i = 0; $i < $max_attempts; $i++) {
         $datetime = $candidate->format('Y-m-d H:i:s');
         if ($calendar->isAWorkingHour($datetime)) {
            return $datetime;
         }
         $candidate->modify('+1 minute');
      }

      Toolbox::logWarning('[Engage TimeSlot] nextCalendarWorkingDate: could not find working hour in 14 days for calendar #' . $calendars_id);
      return null;
   }

   // ── Range helpers ─────────────────────────────────────────────────────

   /**
    * Check if $hhmm falls within [$start, $end).
    * Handles midnight-crossing ranges (e.g. 22:00–06:00).
    */
   public static function inRange(string $hhmm, string $start, string $end): bool
   {
      $cur = self::toMinutes($hhmm);
      $s   = self::toMinutes($start);
      $e   = self::toMinutes($end);

      if ($s === $e) return false;

      if ($s < $e) {
         return $cur >= $s && $cur < $e;
      }
      // Wraps midnight
      return $cur >= $s || $cur < $e;
   }

   private static function toMinutes(string $hhmm): int
   {
      [$h, $m] = explode(':', $hhmm);
      return ((int)$h * 60) + (int)$m;
   }
}
