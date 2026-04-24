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
 * PluginEngageLog — Activity log for every ticket Engage processed.
 *
 * Outcome codes:
 *   sent       — followup created immediately
 *   queued     — followup enqueued for delayed delivery
 *   skipped    — intentionally not sent (calendar, type filter, disabled)
 *   error      — unexpected failure
 */

use Glpi\Application\View\TemplateRenderer;

class PluginEngageLog extends CommonDBTM {

   static $rightname = 'ticket';

   const OUTCOME_SENT    = 'sent';
   const OUTCOME_QUEUED  = 'queued';
   const OUTCOME_SKIPPED = 'skipped';
   const OUTCOME_ERROR   = 'error';

   public static function getTypeName($nb = 0): string {
      return __('Engage Activity', 'engage');
   }

   // ── Tab registration on Ticket ────────────────────────────────────────

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if ($item->getType() !== 'Ticket') return '';
      // Hide from Self-Service interface
      if (Session::getCurrentInterface() === 'helpdesk') return '';
      $count = countElementsInTable(
         getTableForItemtype('PluginEngageLog'),
         ['tickets_id' => $item->getID()]
      );
      return self::createTabEntry(__('Engage', 'engage'), $count);
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if ($item->getType() !== 'Ticket') {
         return false;
      }
      self::showForTicket($item);
      return true;
   }

   // ── Write helpers ─────────────────────────────────────────────────────

   /**
    * Record an engage event for a ticket.
    *
    * @param int    $tickets_id
    * @param int    $entities_id
    * @param string $outcome      OUTCOME_* constant
    * @param string $reason       Human-readable reason / detail
    * @param int    $technician_id  0 if none
    * @param int    $template_id    0 if none
    * @param int    $queue_id       0 if not queued
    * @param string $send_after     ISO datetime if queued, empty otherwise
    */
   public static function record(
      int    $tickets_id,
      int    $entities_id,
      string $outcome,
      string $reason       = '',
      int    $technician_id = 0,
      int    $template_id   = 0,
      int    $queue_id      = 0,
      string $send_after    = ''
   ): void {
      $log = new self();
      $log->add([
         'tickets_id'     => $tickets_id,
         'entities_id'    => $entities_id,
         'outcome'        => $outcome,
         'reason'         => $reason,
         'technician_id'  => $technician_id,
         'template_id'    => $template_id,
         'queue_id'       => $queue_id,
         'send_after'     => $send_after ?: null,
         'created_at'     => date('Y-m-d H:i:s'),
      ]);
   }

   // ── Display helpers ───────────────────────────────────────────────────

   public static function showForTicket(Ticket $ticket): void {
      global $DB;

      $ticket_id  = $ticket->getID();
      $log_table  = getTableForItemtype('PluginEngageLog');
      $queue_table = getTableForItemtype('PluginEngageQueue');

      // Fetch log entries
      $logs = [];
      $iterator = $DB->request([
         'FROM'  => $log_table,
         'WHERE' => ['tickets_id' => $ticket_id],
         'ORDER' => ['created_at DESC'],
      ]);
      foreach ($iterator as $row) {
         $logs[] = $row;
      }

      // Fetch queue entry if any
      $queue_row = null;
      $queue_iterator = $DB->request([
         'FROM'  => $queue_table,
         'WHERE' => ['tickets_id' => $ticket_id],
         'ORDER' => ['id DESC'],
         'LIMIT' => 1,
      ]);
      if ($queue_iterator->count() > 0) {
         $queue_row = $queue_iterator->current();
      }

      // Resolve technician and template names for display
      foreach ($logs as &$entry) {
         $entry['technician_name'] = '';
         if ($entry['technician_id'] > 0) {
            $entry['technician_name'] = User::getNameForLog($entry['technician_id']);
         }
         $entry['template_name'] = '';
         if ($entry['template_id'] > 0) {
            $tmpl = new ITILFollowupTemplate();
            if ($tmpl->getFromDB($entry['template_id'])) {
               $entry['template_name'] = $tmpl->fields['name'];
            }
         }
         $entry['outcome_label'] = self::getOutcomeLabel($entry['outcome']);
         $entry['outcome_class'] = self::getOutcomeClass($entry['outcome']);
      }
      unset($entry);

      // Remaining time for queued items
      $remaining = null;
      if ($queue_row && $queue_row['status'] == PluginEngageQueue::STATUS_PENDING) {
         $diff = strtotime($queue_row['send_after']) - time();
         if ($diff > 0) {
            $mins = (int)floor($diff / 60);
            $secs = $diff % 60;
            $remaining = $mins . 'min ' . $secs . 's';
         }
      }

      TemplateRenderer::getInstance()->display('@engage/pages/ticket_log.html.twig', [
         'ticket'    => $ticket,
         'logs'      => $logs,
         'queue_row' => $queue_row,
         'remaining' => $remaining,
         'now'       => date('Y-m-d H:i:s'),
      ]);
   }

   public static function getOutcomeLabel(string $outcome): string {
      return match ($outcome) {
         self::OUTCOME_SENT    => __('Sent immediately', 'engage'),
         self::OUTCOME_QUEUED  => __('Queued (delayed)', 'engage'),
         self::OUTCOME_SKIPPED => __('Skipped', 'engage'),
         self::OUTCOME_ERROR   => __('Error', 'engage'),
         default               => $outcome,
      };
   }

   public static function getOutcomeClass(string $outcome): string {
      return match ($outcome) {
         self::OUTCOME_SENT    => 'success',
         self::OUTCOME_QUEUED  => 'info',
         self::OUTCOME_SKIPPED => 'secondary',
         self::OUTCOME_ERROR   => 'danger',
         default               => 'secondary',
      };
   }
}
