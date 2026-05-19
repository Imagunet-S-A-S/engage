<?php

/**
 * -------------------------------------------------------------------------
 * engage plugin for GLPI
 * Copyright (C) 2024 Imagunet S.A.S.
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+
 * @link https://github.com/Imagunet-S-A-S/engage/
 * --------------------------------------------------------------------------
 */

class PluginEngageQueue extends CommonDBTM {

   static $rightname = PluginEngageProfile::RIGHT_CONFIG;

   const STATUS_PENDING   = 0;
   const STATUS_SENT      = 1;
   const STATUS_ERROR     = 2;
   const STATUS_CANCELLED = 3;

   public static function getTypeName($nb = 0): string {
      return __('Engage Queue', 'engage');
   }

   public static function canCreate(): bool { return true; }
   public static function canUpdate(): bool { return true; }
   public static function canView(): bool   { return PluginEngageProfile::canReadEngage(); }

   public static function enqueue(
      int $tickets_id, int $entities_id,
      int $technician_id, int $template_id,
      string $send_after
   ) {
      $queue = new self();
      $id = $queue->add([
         'tickets_id'    => $tickets_id,
         'entities_id'   => $entities_id,
         'technician_id' => $technician_id,
         'template_id'   => $template_id,
         'send_after'    => $send_after,
         'status'        => self::STATUS_PENDING,
         'created_at'    => gmdate('Y-m-d H:i:s'),
      ]);
      Toolbox::logDebug('[Engage] Queued ticket #' . $tickets_id . ' → send at ' . $send_after);
      return $id;
   }

   /**
    * CronTask entry point — processes pending jobs whose send_after <= NOW.
    * Updates PluginEngageLog on completion/failure.
    */
   public static function cronProcessQueue(CronTask $task) {
      global $DB;

      $table = getTableForItemtype('PluginEngageQueue');
      $now   = gmdate('Y-m-d H:i:s'); // UTC — matches send_after stored in UTC

      $iterator = $DB->request([
         'FROM'  => $table,
         'WHERE' => ['status' => self::STATUS_PENDING, 'send_after' => ['<=', $now]],
         'ORDER' => ['send_after ASC'],
         'LIMIT' => 50,
      ]);

      if ($iterator->count() === 0) return 0;

      $processed = 0;
      foreach ($iterator as $row) {
         $queue_id      = (int)$row['id'];
         $tickets_id    = (int)$row['tickets_id'];
         $entities_id   = (int)$row['entities_id'];
         $technician_id = (int)$row['technician_id'];
         $template_id   = (int)$row['template_id'];

         $ticket = new Ticket();
         if (!$ticket->getFromDB($tickets_id)) {
            self::markStatus($queue_id, self::STATUS_CANCELLED, 'Ticket not found');
            // Update log entry to reflect cancellation
            self::updateLogForQueue($queue_id, $tickets_id, $entities_id,
               PluginEngageLog::OUTCOME_SKIPPED,
               __('Cancelled: ticket was deleted before delivery', 'engage'));
            continue;
         }

         if (in_array($ticket->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED])) {
            self::markStatus($queue_id, self::STATUS_CANCELLED, 'Ticket solved/closed');
            self::updateLogForQueue($queue_id, $tickets_id, $entities_id,
               PluginEngageLog::OUTCOME_SKIPPED,
               __('Cancelled: ticket was solved or closed before delivery', 'engage'));
            continue;
         }

         // Re-resolve template at delivery time — the slot active NOW may differ
         // from the one active when the ticket was created (e.g. ticket arrived at
         // 14:59 with default template queued for 15:02, but slot starts at 15:00).
         $config = PluginEngageConfig::getConfigForEntity($entities_id);
         if ($config instanceof PluginEngageConfig && !$config->isNewItem()) {
            $resolved = PluginEngageTimeSlot::resolveTemplate($config);
            if ($resolved > 0) {
               $template_id = $resolved; // use slot-matched template at send time
            }
         }

         $result = PluginEngageTicket::sendFollowupFromQueue(
            $ticket, $technician_id, $template_id, $entities_id
         );

         if ($result) {
            self::markStatus($queue_id, self::STATUS_SENT);
            self::updateLogForQueue($queue_id, $tickets_id, $entities_id,
               PluginEngageLog::OUTCOME_SENT,
               __('Delayed followup delivered by cron', 'engage'));
            $processed++;
         } else {
            self::markStatus($queue_id, self::STATUS_ERROR, 'sendFollowupFromQueue returned false');
            self::updateLogForQueue($queue_id, $tickets_id, $entities_id,
               PluginEngageLog::OUTCOME_ERROR,
               __('Cron delivery failed — check PHP error log', 'engage'));
         }

         $task->log("Engage: processed queue ID $queue_id for ticket #$tickets_id — " . ($result ? 'SENT' : 'ERROR'));
      }

      $task->setVolume($processed);
      return $processed > 0 ? 1 : 0;
   }

   /**
    * Update the log entry that was originally created for this queue job.
    */
   private static function updateLogForQueue(
      int $queue_id, int $tickets_id, int $entities_id,
      string $outcome, string $reason
   ): void {
      global $DB;
      $log_table = getTableForItemtype('PluginEngageLog');

      // Find the matching log entry (queued outcome with same queue_id)
      $existing = $DB->request([
         'FROM'  => $log_table,
         'WHERE' => ['tickets_id' => $tickets_id, 'queue_id' => $queue_id],
         'LIMIT' => 1,
      ]);

      if ($existing->count() > 0) {
         $row = $existing->current();
         $DB->update($log_table, [
            'outcome'    => $outcome,
            'reason'     => $reason,
            'created_at' => gmdate('Y-m-d H:i:s'), // update timestamp to delivery time
         ], ['id' => $row['id']]);
      } else {
         // No prior log entry (edge case) — create one
         PluginEngageLog::record($tickets_id, $entities_id, $outcome, $reason, 0, 0, $queue_id);
      }
   }

   private static function markStatus(int $queue_id, int $status, string $error = ''): void {
      global $DB;
      $DB->update(getTableForItemtype('PluginEngageQueue'), [
         'status'       => $status,
         'processed_at' => gmdate('Y-m-d H:i:s'),
         'error_msg'    => $error ?: null,
      ], ['id' => $queue_id]);
   }

   // ── Admin display helpers ─────────────────────────────────────────────

   public static function getStatusLabel(int $status): string {
      return match ($status) {
         self::STATUS_PENDING   => __('Pending', 'engage'),
         self::STATUS_SENT      => __('Sent', 'engage'),
         self::STATUS_ERROR     => __('Error', 'engage'),
         self::STATUS_CANCELLED => __('Cancelled', 'engage'),
         default                => __('Unknown', 'engage'),
      };
   }

   public static function getStatusClass(int $status): string {
      return match ($status) {
         self::STATUS_PENDING   => 'warning',
         self::STATUS_SENT      => 'success',
         self::STATUS_ERROR     => 'danger',
         self::STATUS_CANCELLED => 'secondary',
         default                => 'secondary',
      };
   }

   /**
    * Load paginated queue rows with resolved names for admin dashboard.
    */
   public static function getForDashboard(int $status_filter = -1, int $limit = 100, int $offset = 0): array {
      global $DB;

      $table = getTableForItemtype('PluginEngageQueue');
      $where = $status_filter >= 0 ? ['status' => $status_filter] : [];

      $iterator = $DB->request([
         'FROM'   => $table,
         'WHERE'  => $where,
         'ORDER'  => ['send_after DESC'],
         'LIMIT'  => $limit,
         'START'  => $offset,
      ]);

      $rows = [];
      foreach ($iterator as $row) {
         // Ticket name
         $ticket = new Ticket();
         $row['ticket_name'] = $ticket->getFromDB($row['tickets_id'])
            ? '#' . $row['tickets_id'] . ' — ' . $ticket->fields['name']
            : '#' . $row['tickets_id'] . ' (deleted)';
         $row['ticket_url'] = Ticket::getFormURLWithID($row['tickets_id']);

         // Technician
         $row['technician_name'] = $row['technician_id'] > 0
            ? User::getNameForLog($row['technician_id'])
            : '—';

         // Template
         $tmpl = new ITILFollowupTemplate();
         $row['template_name'] = ($row['template_id'] > 0 && $tmpl->getFromDB($row['template_id']))
            ? $tmpl->fields['name']
            : '—';

         // Convert UTC datetimes to GLPI timezone for display
         $tz = PluginEngageTimeSlot::getGlpiTimezone();

         $send_after_display = '';
         if (!empty($row['send_after'])) {
            $dt = new DateTime($row['send_after'], new DateTimeZone('UTC'));
            $dt->setTimezone($tz);
            $send_after_display = $dt->format('Y-m-d H:i:s');
         }
         $row['send_after_display'] = $send_after_display;

         $processed_display = '';
         if (!empty($row['processed_at'])) {
            $dt = new DateTime($row['processed_at'], new DateTimeZone('UTC'));
            $dt->setTimezone($tz);
            $processed_display = $dt->format('Y-m-d H:i:s');
         }
         $row['processed_at_display'] = $processed_display;

         // Time remaining — compare UTC send_after vs UTC now
         $row['remaining'] = '';
         if ($row['status'] == self::STATUS_PENDING) {
            $now_utc  = new DateTime('now', new DateTimeZone('UTC'));
            $send_utc = new DateTime($row['send_after'], new DateTimeZone('UTC'));
            $diff     = $send_utc->getTimestamp() - $now_utc->getTimestamp();
            if ($diff > 0) {
               $row['remaining'] = floor($diff / 60) . 'min ' . ($diff % 60) . 's';
            } else {
               $row['remaining'] = __('Processing…', 'engage');
            }
         }

         $row['status_label'] = self::getStatusLabel((int)$row['status']);
         $row['status_class'] = self::getStatusClass((int)$row['status']);
         $rows[] = $row;
      }

      return $rows;
   }

   public static function countByStatus(): array {
      global $DB;
      $table  = getTableForItemtype('PluginEngageQueue');
      $counts = [
         self::STATUS_PENDING   => 0,
         self::STATUS_SENT      => 0,
         self::STATUS_ERROR     => 0,
         self::STATUS_CANCELLED => 0,
      ];
      $iterator = $DB->request([
         'SELECT' => ['status', new \QueryExpression('COUNT(*) AS cnt')],
         'FROM'   => $table,
         'GROUPBY' => ['status'],
      ]);
      foreach ($iterator as $row) {
         $counts[(int)$row['status']] = (int)$row['cnt'];
      }
      return $counts;
   }
}
