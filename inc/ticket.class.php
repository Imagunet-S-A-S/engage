<?php

/**
 * -------------------------------------------------------------------------
 * engage plugin for GLPI
 * Copyright (C) 2024 Imagunet S.A.S. - Juan Gallego, Santiago Gomez, Giovanny Rodriguez
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+
 * @link https://github.com/imagunet/engage
 * --------------------------------------------------------------------------
 *
 * v2.2.0: Removed round-robin logic. Technician resolution is now:
 *   1. users_id_tech (configured fallback tech)
 *   2. Validation of ASSIGN/STEAL rights
 *
 * New feature: priority_filter — if set, only process tickets whose
 * priority matches one of the selected values.
 *
 * Time slot resolution now delegates fully to PluginEngageTimeSlot::resolveTemplate()
 * which returns 0 (silent skip) when no slot matches and use_default_outside_slots=0.
 */
class PluginEngageTicket extends CommonDBTM
{
   static $rightname = 'ticket';

   public static function canCreate(): bool { return Session::haveRight('ticket', UPDATE); }
   public static function canView(): bool   { return Session::haveRight('ticket', READ); }
   public static function getTypeName($nb = 0): string { return __('Ticket'); }
   public function getName($with_comment = 0): string  { return __('Ticket', 'ticket'); }

   private static function getEntityRestrictProfile(int $userID, int $entityID)
   {
      global $DB;
      foreach ([Ticket::ASSIGN, Ticket::STEAL] as $right_flag) {
         $query = $DB->request([
            'SELECT'     => 'glpi_profilerights.profiles_id',
            'FROM'       => 'glpi_profilerights',
            'INNER JOIN' => [
               'glpi_profiles' => [
                  'FKEY' => ['glpi_profilerights' => 'profiles_id', 'glpi_profiles' => 'id']
               ],
               'glpi_profiles_users' => [
                  'FKEY' => [
                     'glpi_profiles_users' => 'profiles_id',
                     'glpi_profiles'       => 'id',
                     ['AND' => ['glpi_profiles_users.users_id' => $userID]],
                  ]
               ],
            ],
            'WHERE' => [
               'glpi_profilerights.name'   => Ticket::$rightname,
               'glpi_profilerights.rights' => ['&', $right_flag],
            ] + getEntitiesRestrictCriteria('glpi_profiles_users', '', $entityID, true),
         ]);
         if ($profiles = $query->current()) {
            $profile_id = array_values($profiles)[0];
            $rights     = ProfileRight::getProfileRights($profile_id, ['ticket']);
            return $rights['ticket'];
         }
      }
      return null;
   }

   /**
    * Check if technician has ASSIGN or STEAL right in the given entity.
    */
   private static function hasRequiredRights(int $userID, int $entityID): bool
   {
      global $DB;
      foreach ([Ticket::ASSIGN, Ticket::STEAL] as $right_flag) {
         $query = $DB->request([
            'COUNT'      => 'id',
            'FROM'       => 'glpi_profilerights',
            'INNER JOIN' => [
               'glpi_profiles_users' => [
                  'FKEY' => [
                     'glpi_profilerights' => 'profiles_id',
                     'glpi_profiles_users' => 'profiles_id',
                     ['AND' => ['glpi_profiles_users.users_id' => $userID]],
                  ]
               ],
            ],
            'WHERE' => [
               'glpi_profilerights.name'   => Ticket::$rightname,
               'glpi_profilerights.rights' => ['&', $right_flag],
            ] + getEntitiesRestrictCriteria('glpi_profiles_users', '', $entityID, true),
         ]);
         if ($query->current()['id'] > 0) return true;
      }
      return false;
   }

   /**
    * Hook: ITEM_ADD on Ticket.
    *
    * Processing order:
    *   1. Load entity config
    *   2. Check ticket type filter
    *   3. Check priority filter (v2.2.0)
    *   4. Check calendar (business hours)
    *   5. Resolve technician (fallback tech only, v2.2.0 — no round robin)
    *   6. Resolve template via dynamic time slots
    *   7. Delay or send immediately
    */
   public static function createFollowup($item)
   {
      $entities_id = (int)$item->getEntityID();
      $ticket_id   = (int)$item->fields['id'];

      $config = PluginEngageConfig::getConfigForEntity($entities_id);

      if (!($config instanceof PluginEngageConfig) || $config->isNewItem()) {
         return;
      }

      $technician    = (int)$config->fields['users_id_tech'];
      $delay_minutes = (int)($config->fields['delay_minutes'] ?? 0);
      $calendars_id  = (int)($config->fields['calendars_id']  ?? 0);

      // ── Ticket type filter ─────────────────────────────────────────────
      if ($config->fields['ticket_type'] != PluginEngageConfig::MIXED
         && $item->fields['type'] != $config->fields['ticket_type']) {
         $type_label = $item->fields['type'] == Ticket::INCIDENT_TYPE
            ? __('Incident') : __('Request');
         PluginEngageLog::record($ticket_id, $entities_id,
            PluginEngageLog::OUTCOME_SKIPPED,
            sprintf(__('Ticket type "%s" excluded by configuration', 'engage'), $type_label));
         return;
      }

      // ── Priority filter (v2.2.0) ───────────────────────────────────────
      $priority_filter = trim($config->fields['priority_filter'] ?? '');
      if ($priority_filter !== '') {
         $allowed = json_decode($priority_filter, true) ?: [];
         if (!empty($allowed)) {
            $ticket_priority = (int)($item->fields['priority'] ?? 0);
            if (!in_array($ticket_priority, $allowed, true)) {
               $priority_label = Ticket::getPriorityName($ticket_priority);
               PluginEngageLog::record($ticket_id, $entities_id,
                  PluginEngageLog::OUTCOME_SKIPPED,
                  sprintf(__('Priority "%s" not in notification filter', 'engage'), $priority_label));
               return;
            }
         }
      }

      // ── Resolve technician ─────────────────────────────────────────────
      if ($technician === 0) {
         PluginEngageLog::record($ticket_id, $entities_id,
            PluginEngageLog::OUTCOME_SKIPPED,
            __('No technician configured', 'engage'));
         return;
      }
      if (!self::hasRequiredRights($technician, $entities_id)) {
         PluginEngageLog::record($ticket_id, $entities_id,
            PluginEngageLog::OUTCOME_SKIPPED,
            sprintf(__('Technician #%d lacks ASSIGN/STEAL rights in this entity', 'engage'), $technician),
            $technician);
         return;
      }

      // ── Calendar check ─────────────────────────────────────────────────
      $override_cal = !empty($config->fields['override_calendar']);
      if (!$override_cal && $calendars_id > 0 && !PluginEngageConfig::isWithinCalendar($config)) {
         // Outside business hours — enqueue for next working period
         $next_working = PluginEngageTimeSlot::nextSlotStart($config);
         if ($next_working === null) {
            $next_working = PluginEngageTimeSlot::nextCalendarWorkingDate($calendars_id);
         }
         $fallback_tpl = (int)($config->fields['itil_followup'] ?? 0);
         if ($next_working === null || $fallback_tpl === 0) {
            $cal      = new Calendar();
            $cal_name = $cal->getFromDB($calendars_id) ? $cal->fields['name'] : '#' . $calendars_id;
            PluginEngageLog::record($ticket_id, $entities_id,
               PluginEngageLog::OUTCOME_SKIPPED,
               sprintf(__('Outside business hours (calendar: %s) and no next delivery window or default template configured', 'engage'), $cal_name),
               $technician, $fallback_tpl);
            return;
         }
         $queue_id = PluginEngageQueue::enqueue(
            $ticket_id, $entities_id, $technician, $fallback_tpl,
            PluginEngageConfig::applyDelay($config, $next_working)
         );
         $cal      = new Calendar();
         $cal_name = $cal->getFromDB($calendars_id) ? $cal->fields['name'] : '#' . $calendars_id;
         $send_at  = PluginEngageConfig::applyDelay($config, $next_working);
         PluginEngageLog::record($ticket_id, $entities_id,
            PluginEngageLog::OUTCOME_QUEUED,
            sprintf(__('Outside business hours (calendar: %s) — queued for: %s', 'engage'), $cal_name, $send_at),
            $technician, $fallback_tpl, (int)$queue_id, $send_at);
         return;
      }

      // ── Resolve template via dynamic time slots ────────────────────────
      $template_id = PluginEngageTimeSlot::resolveTemplate($config);

      if ($template_id === 0) {
         // No slot matches now and use_default_outside_slots = 0.
         // If there ARE slots configured, enqueue for the next slot start
         // instead of skipping — ticket will be delivered when the slot opens.
         // Only truly Skip if there are no slots at all (genuine edge case).
         $next_start = PluginEngageTimeSlot::nextSlotStart($config);

         if ($next_start !== null) {
            // Pick the default template for delivery at slot time
            // (resolveTemplate will return the correct slot template when cron runs)
            // We store the default now; the cron will re-resolve the template at send time
            // using the queue's stored template_id. Use default as placeholder.
            $fallback_tpl = (int)($config->fields['itil_followup'] ?? 0);
            if ($fallback_tpl === 0) {
               PluginEngageLog::record($ticket_id, $entities_id,
                  PluginEngageLog::OUTCOME_SKIPPED,
                  sprintf(__('No time slot active at %s and no default template configured', 'engage'), PluginEngageTimeSlot::nowInGlpiTz()->format('H:i')),
                  $technician);
               return;
            }

            $send_at  = PluginEngageConfig::applyDelay($config, $next_start);
            $queue_id = PluginEngageQueue::enqueue(
               $ticket_id, $entities_id, $technician, $fallback_tpl, $send_at
            );
            PluginEngageLog::record($ticket_id, $entities_id,
               PluginEngageLog::OUTCOME_QUEUED,
               sprintf(__('Outside active slots at %s — queued for next slot start: %s', 'engage'),
                  PluginEngageTimeSlot::nowInGlpiTz()->format('H:i'), $send_at),
               $technician, $fallback_tpl, (int)$queue_id, $send_at);
            return;
         }

         // No slots configured at all — genuine skip
         PluginEngageLog::record($ticket_id, $entities_id,
            PluginEngageLog::OUTCOME_SKIPPED,
            sprintf(__('No time slot active at %s and default template disabled', 'engage'), PluginEngageTimeSlot::nowInGlpiTz()->format('H:i')),
            $technician);
         return;
      }

      // ── Delay or send immediately ──────────────────────────────────────
      if ($delay_minutes > 0) {
         $send_after = PluginEngageConfig::getScheduledDateTime($config);
         if ($send_after && $send_after > gmdate('Y-m-d H:i:s')) {
            $queue_id = PluginEngageQueue::enqueue(
               $ticket_id, $entities_id, $technician, $template_id, $send_after
            );
            $slot = PluginEngageTimeSlot::currentSlotName($config);
            PluginEngageLog::record($ticket_id, $entities_id,
               PluginEngageLog::OUTCOME_QUEUED,
               sprintf(__('Queued %dmin delay — scheduled: %s (slot: %s)', 'engage'),
                  $delay_minutes, $send_after, $slot),
               $technician, $template_id, (int)$queue_id, $send_after);
            return;
         }
      }

      // Immediate send
      $slot = PluginEngageTimeSlot::currentSlotName($config);
      $ok   = self::sendFollowupFromQueue($item, $technician, $template_id, $entities_id);

      PluginEngageLog::record($ticket_id, $entities_id,
         $ok ? PluginEngageLog::OUTCOME_SENT : PluginEngageLog::OUTCOME_ERROR,
         $ok
            ? sprintf(__('Sent immediately (slot: %s, tech: %s)', 'engage'),
                  $slot, User::getNameForLog($technician))
            : __('Failed to create followup — check PHP error log', 'engage'),
         $technician, $template_id);
   }

   /**
    * Create the ITILFollowup and assign technician.
    * Called directly (immediate) or by PluginEngageQueue cron.
    */
   public static function sendFollowupFromQueue(
      $ticket_or_item,
      int $technician_id,
      int $template_id,
      int $entities_id
   ): bool {
      global $DB;

      $ticket_id = (int)$ticket_or_item->fields['id'];

      $original_session = [
         'glpiID'            => $_SESSION['glpiID']            ?? 0,
         'glpiactiveprofile' => $_SESSION['glpiactiveprofile'] ?? [],
         'glpiactive_entity' => $_SESSION['glpiactive_entity'] ?? 0,
      ];

      try {
         $template = new ITILFollowupTemplate();
         if (!$template->getFromDB($template_id)) {
            Toolbox::logWarning('[Engage] Cannot load template #' . $template_id);
            return false;
         }

         $parent_ticket = new Ticket();
         $parent_ticket->getFromDB($ticket_id);
         $template->fields['content'] = $template->getRenderedContent($parent_ticket);

         $right = self::getEntityRestrictProfile($technician_id, $entities_id);
         $_SESSION['glpiID'] = $technician_id;
         if (isset($_SESSION['glpiactiveprofile'])) {
            $_SESSION['glpiactiveprofile']['ticket'] = $right;
         }

         $f_up = new ITILFollowup();
         $f_up->add($f_up->prepareInputForAdd([
            'itemtype'                    => 'Ticket',
            'items_id'                    => $ticket_id,
            'content'                     => $template->fields['content'],
            'users_id'                    => $technician_id,
            'requesttypes_id'             => $template->fields['requesttypes_id'] ?? 0,
            'is_private'                  => '0',
            '_fup_to_kb'                  => '0',
            'add'                         => '',
            'pending'                     => '0',
            'followup_frequency'          => '0',
            'followups_before_resolution' => '0',
         ]));

         $_SESSION['glpiID']            = $original_session['glpiID'];
         $_SESSION['glpiactiveprofile'] = $original_session['glpiactiveprofile'];

         $already = countElementsInTable('glpi_tickets_users', [
            'tickets_id' => $ticket_id,
            'users_id'   => $technician_id,
            'type'       => CommonITILActor::ASSIGN,
         ]);

         if (!$already) {
            $DB->insert('glpi_tickets_users', [
               'tickets_id'        => $ticket_id,
               'users_id'          => $technician_id,
               'type'              => CommonITILActor::ASSIGN,
               'use_notification'  => 1,
               'alternative_email' => '',
            ]);
         }

         $DB->update('glpi_tickets', [
            'status' => CommonITILObject::ASSIGNED,
         ], ['id' => $ticket_id]);

         return true;

      } catch (\Throwable $e) {
         Toolbox::logError('[Engage] Exception in sendFollowupFromQueue: ' . $e->getMessage());
         return false;
      } finally {
         $_SESSION['glpiID']            = $original_session['glpiID'];
         $_SESSION['glpiactiveprofile'] = $original_session['glpiactiveprofile'];
         $_SESSION['glpiactive_entity'] = $original_session['glpiactive_entity'];
      }
   }
}
