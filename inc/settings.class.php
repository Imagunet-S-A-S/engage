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
 * PluginEngageSettings — Global plugin settings (single row, id = 1).
 *
 * Manages:
 *  - Log retention (days to keep glpi_plugin_engage_logs rows)
 *  - Queue SENT/CANCELLED retention (days to keep processed queue rows)
 *  - Queue ERROR retention (longer — useful for diagnosis)
 *
 * Purge CronTask runs nightly and deletes rows older than configured thresholds.
 * PENDING rows are NEVER purged automatically.
 */

use Glpi\Application\View\TemplateRenderer;

class PluginEngageSettings extends CommonDBTM {

   static $rightname = 'config';

   // Defaults (days)
   const DEFAULT_LOG_RETENTION            = 90;
   const DEFAULT_QUEUE_SENT_RETENTION     = 30;
   const DEFAULT_QUEUE_ERROR_RETENTION    = 90;

   // Sentinel: 0 = keep forever
   const RETAIN_FOREVER = 0;

   public static function getTypeName($nb = 0): string {
      return __('Engage Global Settings', 'engage');
   }

   // ── Singleton ─────────────────────────────────────────────────────────

   /**
    * Load (or create default) global settings row.
    * Always returns a PluginEngageSettings object with fields populated.
    *
    * @return PluginEngageSettings
    */
   public static function getInstance(): self {
      $instance = new self();
      if (!$instance->getFromDB(1)) {
         // First run — create defaults
         $instance->add([
            'id'                          => 1,
            'log_retention_days'          => self::DEFAULT_LOG_RETENTION,
            'queue_sent_retention_days'   => self::DEFAULT_QUEUE_SENT_RETENTION,
            'queue_error_retention_days'  => self::DEFAULT_QUEUE_ERROR_RETENTION,
         ]);
         $instance->getFromDB(1);
      }
      return $instance;
   }

   public function post_getEmpty() {
      $this->fields['id']                          = 1;
      $this->fields['log_retention_days']          = self::DEFAULT_LOG_RETENTION;
      $this->fields['queue_sent_retention_days']   = self::DEFAULT_QUEUE_SENT_RETENTION;
      $this->fields['queue_error_retention_days']  = self::DEFAULT_QUEUE_ERROR_RETENTION;
   }

   // ── CronTask: purge ───────────────────────────────────────────────────

   /**
    * CronTask entry point — called nightly by GLPI cron runner.
    * Purges log and queue rows older than configured thresholds.
    *
    * PENDING queue rows are NEVER deleted automatically.
    *
    * @param CronTask $task
    * @return int  1 if any rows deleted, 0 otherwise
    */
   public static function cronPurge(CronTask $task): int {
      global $DB;

      $settings   = self::getInstance();
      $log_table  = getTableForItemtype('PluginEngageLog');
      $queue_table = getTableForItemtype('PluginEngageQueue');
      $total_deleted = 0;

      // ── Purge log entries ─────────────────────────────────────────────
      $log_days = (int)$settings->fields['log_retention_days'];
      if ($log_days > self::RETAIN_FOREVER) {
         $cutoff = date('Y-m-d H:i:s', strtotime("-{$log_days} days"));
         $result = $DB->delete($log_table, ['created_at' => ['<', $cutoff]]);
         $deleted = $DB->affectedRows();
         $total_deleted += $deleted;
         $task->log("Engage Purge: deleted $deleted log entries older than $log_days days (cutoff: $cutoff)");
      } else {
         $task->log("Engage Purge: log retention = forever, skipping log purge.");
      }

      // ── Purge queue SENT + CANCELLED entries ──────────────────────────
      $sent_days = (int)$settings->fields['queue_sent_retention_days'];
      if ($sent_days > self::RETAIN_FOREVER) {
         $cutoff = date('Y-m-d H:i:s', strtotime("-{$sent_days} days"));
         $result = $DB->delete($queue_table, [
            'status'       => [
               PluginEngageQueue::STATUS_SENT,
               PluginEngageQueue::STATUS_CANCELLED,
            ],
            'processed_at' => ['<', $cutoff],
         ]);
         $deleted = $DB->affectedRows();
         $total_deleted += $deleted;
         $task->log("Engage Purge: deleted $deleted sent/cancelled queue entries older than $sent_days days.");
      }

      // ── Purge queue ERROR entries ─────────────────────────────────────
      $error_days = (int)$settings->fields['queue_error_retention_days'];
      if ($error_days > self::RETAIN_FOREVER) {
         $cutoff = date('Y-m-d H:i:s', strtotime("-{$error_days} days"));
         $result = $DB->delete($queue_table, [
            'status'       => PluginEngageQueue::STATUS_ERROR,
            'processed_at' => ['<', $cutoff],
         ]);
         $deleted = $DB->affectedRows();
         $total_deleted += $deleted;
         $task->log("Engage Purge: deleted $deleted error queue entries older than $error_days days.");
      }

      // PENDING rows: never auto-deleted
      $pending_count = countElementsInTable($queue_table, [
         'status' => PluginEngageQueue::STATUS_PENDING
      ]);
      if ($pending_count > 0) {
         $task->log("Engage Purge: $pending_count PENDING entries preserved (never auto-purged).");
      }

      $task->setVolume($total_deleted);
      return $total_deleted > 0 ? 1 : 0;
   }

   // ── Display ───────────────────────────────────────────────────────────

   public static function showSettingsForm(): void {
      $settings = self::getInstance();
      $canedit  = Session::haveRight(self::$rightname, UPDATE);

      TemplateRenderer::getInstance()->display('@engage/pages/settings_form.html.twig', [
         'settings' => $settings,
         'canedit'  => $canedit,
         'defaults' => [
            'log_days'   => self::DEFAULT_LOG_RETENTION,
            'sent_days'  => self::DEFAULT_QUEUE_SENT_RETENTION,
            'error_days' => self::DEFAULT_QUEUE_ERROR_RETENTION,
         ],
      ]);
   }

   /**
    * Handle POST from settings form.
    */
   public static function handlePost(array $post): void {
      if (!Session::haveRight(self::$rightname, UPDATE)) {
         return;
      }

      $settings = self::getInstance();

      $log_days   = max(0, (int)($post['log_retention_days']         ?? self::DEFAULT_LOG_RETENTION));
      $sent_days  = max(0, (int)($post['queue_sent_retention_days']  ?? self::DEFAULT_QUEUE_SENT_RETENTION));
      $error_days = max(0, (int)($post['queue_error_retention_days'] ?? self::DEFAULT_QUEUE_ERROR_RETENTION));

      $settings->update([
         'id'                         => 1,
         'log_retention_days'         => $log_days,
         'queue_sent_retention_days'  => $sent_days,
         'queue_error_retention_days' => $error_days,
      ]);
   }

   /**
    * Human-readable retention label.
    */
   public static function retentionLabel(int $days): string {
      if ($days === self::RETAIN_FOREVER) {
         return __('Forever (never purged)', 'engage');
      }
      return sprintf(_n('%d day', '%d days', $days, 'engage'), $days);
   }
}
