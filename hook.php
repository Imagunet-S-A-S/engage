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
 * v2.2.0 changes:
 * - Removed Round-Robin (groups_id, rr_last_index, glpi_plugin_engage_roundrobin table)
 * - Added dynamic time slots table (glpi_plugin_engage_timeslots)
 * - Added priority_filter field to config table (JSON array)
 * - Added use_default_outside_slots field (send default when no slot matches)
 * - Automatic migration from old morning/afternoon/night/weekend slots
 */

function plugin_engage_install()
{
   global $DB;

   $default_charset   = DBConnection::getDefaultCharset();
   $default_collation = DBConnection::getDefaultCollation();
   $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
   $migration         = new Migration(PLUGIN_ENGAGE_VERSION);

   // ── Config table (per-entity) ─────────────────────────────────────────
   $config_table = getTableForItemtype('PluginEngageConfig');
   if (!$DB->tableExists($config_table)) {
      $DB->doQuery("CREATE TABLE IF NOT EXISTS `{$config_table}` (
         `id`                        INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
         `users_id_tech`             INT UNSIGNED DEFAULT 0,
         `itil_followup`             INT UNSIGNED DEFAULT 0,
         `entities_id`               INT UNSIGNED DEFAULT NULL,
         `is_recursive`              TINYINT(1)   DEFAULT 1,
         `is_active`                 TINYINT(1)   DEFAULT 1,
         `ticket_type`               TINYINT      DEFAULT 0,
         `delay_minutes`             INT UNSIGNED DEFAULT 0,
         `calendars_id`              INT UNSIGNED DEFAULT 0,
         `priority_filter`           VARCHAR(255) DEFAULT '',
         `use_default_outside_slots` TINYINT(1)   DEFAULT 1,
         `override_calendar`         TINYINT(1)   DEFAULT 0,
         PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC");
   } else {
      // Migrate existing installs — addField is idempotent
      $migration->addField($config_table, 'is_active',                 'bool',    ['value' => 1]);
      $migration->addField($config_table, 'ticket_type',               'tinyint', ['value' => 0]);
      $migration->addField($config_table, 'delay_minutes',             'integer', ['value' => 0]);
      $migration->addField($config_table, 'calendars_id',              'fkey',    ['value' => 0]);
      $migration->addField($config_table, 'priority_filter',           'string',  ['value' => '']);
      $migration->addField($config_table, 'use_default_outside_slots', 'bool',    ['value' => 1]);
      $migration->addField($config_table, 'override_calendar',         'bool',    ['value' => 0]);
   }

   // ── Dynamic time slots table ──────────────────────────────────────────
   $slots_table = getTableForItemtype('PluginEngageTimeSlot');
   if (!$DB->tableExists($slots_table)) {
      $DB->doQuery("CREATE TABLE IF NOT EXISTS `{$slots_table}` (
         `id`          INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
         `configs_id`  INT UNSIGNED NOT NULL DEFAULT 0,
         `name`        VARCHAR(100) NOT NULL DEFAULT '',
         `start_time`  VARCHAR(5)   NOT NULL DEFAULT '00:00',
         `end_time`    VARCHAR(5)   NOT NULL DEFAULT '23:59',
         `template_id` INT UNSIGNED NOT NULL DEFAULT 0,
         `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
         PRIMARY KEY (`id`),
         KEY `idx_configs_id` (`configs_id`, `sort_order`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC");
   }

   // ── Global settings table ─────────────────────────────────────────────
   $settings_table  = getTableForItemtype('PluginEngageSettings');
   $settings_is_new = !$DB->tableExists($settings_table);
   if ($settings_is_new) {
      $DB->doQuery("CREATE TABLE IF NOT EXISTS `{$settings_table}` (
         `id`                          INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
         `log_retention_days`          INT UNSIGNED NOT NULL DEFAULT 90,
         `queue_sent_retention_days`   INT UNSIGNED NOT NULL DEFAULT 30,
         `queue_error_retention_days`  INT UNSIGNED NOT NULL DEFAULT 90,
         PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC");
   } else {
      $migration->addField($settings_table, 'log_retention_days',         'integer', ['value' => 90]);
      $migration->addField($settings_table, 'queue_sent_retention_days',  'integer', ['value' => 30]);
      $migration->addField($settings_table, 'queue_error_retention_days', 'integer', ['value' => 90]);
   }

   // ── Queue table ───────────────────────────────────────────────────────
   $queue_table = getTableForItemtype('PluginEngageQueue');
   if (!$DB->tableExists($queue_table)) {
      $DB->doQuery("CREATE TABLE IF NOT EXISTS `{$queue_table}` (
         `id`              INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
         `tickets_id`      INT UNSIGNED NOT NULL DEFAULT 0,
         `entities_id`     INT UNSIGNED NOT NULL DEFAULT 0,
         `technician_id`   INT UNSIGNED NOT NULL DEFAULT 0,
         `template_id`     INT UNSIGNED NOT NULL DEFAULT 0,
         `send_after`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `status`          TINYINT      NOT NULL DEFAULT 0,
         `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `processed_at`    TIMESTAMP    NULL DEFAULT NULL,
         `error_msg`       TEXT         DEFAULT NULL,
         PRIMARY KEY (`id`),
         KEY `idx_send_after_status` (`send_after`, `status`),
         KEY `idx_tickets_id`        (`tickets_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC");
   }

   // ── Log table ─────────────────────────────────────────────────────────
   $log_table = getTableForItemtype('PluginEngageLog');
   if (!$DB->tableExists($log_table)) {
      $DB->doQuery("CREATE TABLE IF NOT EXISTS `{$log_table}` (
         `id`              INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
         `tickets_id`      INT UNSIGNED NOT NULL DEFAULT 0,
         `entities_id`     INT UNSIGNED NOT NULL DEFAULT 0,
         `outcome`         VARCHAR(20)  NOT NULL DEFAULT 'sent',
         `reason`          TEXT         DEFAULT NULL,
         `technician_id`   INT UNSIGNED DEFAULT 0,
         `template_id`     INT UNSIGNED DEFAULT 0,
         `queue_id`        INT UNSIGNED DEFAULT 0,
         `send_after`      TIMESTAMP    NULL DEFAULT NULL,
         `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `idx_tickets_id`  (`tickets_id`),
         KEY `idx_outcome`     (`outcome`),
         KEY `idx_created_at`  (`created_at`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC");
   }

   $migration->executeMigration();

   // Migrate old fixed slots to dynamic table
   _plugin_engage_migrate_legacy_slots($DB, $config_table, $slots_table);

   if ($settings_is_new && !countElementsInTable($settings_table, ['id' => 1])) {
      $DB->insertOrDie($settings_table, [
         'id'                         => 1,
         'log_retention_days'         => 90,
         'queue_sent_retention_days'  => 30,
         'queue_error_retention_days' => 90,
      ], 'Engage: insert default settings');
   }

   CronTask::Register('PluginEngageQueue', 'processQueue', 60, [
      'comment' => 'Engage: deliver pending welcome followups',
      'mode'    => CronTask::MODE_INTERNAL,
   ]);
   CronTask::Register('PluginEngageSettings', 'purge', 86400, [
      'comment' => 'Engage: purge old log and queue entries per retention policy',
      'mode'    => CronTask::MODE_INTERNAL,
   ]);

   $migration->displayMessage("Engage v" . PLUGIN_ENGAGE_VERSION . " installation complete.");
   return true;
}

/**
 * Migrate legacy fixed slots (morning/afternoon/night/weekend) to dynamic slots table.
 * Only migrates configs that have at least one legacy template set and no dynamic slots yet.
 */
function _plugin_engage_migrate_legacy_slots($DB, $config_table, $slots_table)
{
   $cols = $DB->listFields($config_table);
   if (!isset($cols['tpl_morning'])) {
      return; // Fresh install — no legacy columns
   }

   $configs = $DB->request(['FROM' => $config_table]);
   foreach ($configs as $config) {
      $configs_id = (int)$config['id'];

      if (countElementsInTable($slots_table, ['configs_id' => $configs_id]) > 0) {
         continue; // Already has dynamic slots
      }

      $order  = 0;
      $legacy = [
         ['name' => 'Mañana',        'start' => $config['morning_start']   ?? '06:00', 'end' => $config['morning_end']   ?? '12:00', 'tpl' => (int)($config['tpl_morning']   ?? 0)],
         ['name' => 'Tarde',         'start' => $config['afternoon_start'] ?? '12:00', 'end' => $config['afternoon_end'] ?? '18:00', 'tpl' => (int)($config['tpl_afternoon'] ?? 0)],
         ['name' => 'Noche',         'start' => $config['night_start']     ?? '18:00', 'end' => $config['night_end']     ?? '06:00', 'tpl' => (int)($config['tpl_night']     ?? 0)],
         ['name' => 'Fin de semana', 'start' => '00:00',                              'end' => '23:59',                              'tpl' => (int)($config['tpl_weekend']   ?? 0)],
      ];

      foreach ($legacy as $slot) {
         if ($slot['tpl'] > 0) {
            $DB->insert($slots_table, [
               'configs_id'  => $configs_id,
               'name'        => $slot['name'],
               'start_time'  => $slot['start'],
               'end_time'    => $slot['end'],
               'template_id' => $slot['tpl'],
               'sort_order'  => $order++,
            ]);
         }
      }

      Toolbox::logInfo("[Engage] Migrated legacy time slots for config #{$configs_id}");
   }
}

function plugin_engage_uninstall()
{
   global $DB;

   foreach ([
      getTableForItemtype('PluginEngageConfig'),
      getTableForItemtype('PluginEngageTimeSlot'),
      getTableForItemtype('PluginEngageSettings'),
      getTableForItemtype('PluginEngageQueue'),
      getTableForItemtype('PluginEngageLog'),
      'glpi_plugin_engage_roundrobins', // Legacy RR table — drop if exists
   ] as $table) {
      if ($DB->tableExists($table)) {
         $DB->doQuery("DROP TABLE `{$table}`");
      }
   }

   CronTask::unregister('PluginEngageQueue');
   CronTask::unregister('PluginEngageSettings');
   return true;
}
