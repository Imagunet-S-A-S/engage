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
 * v2.2.0: Removed round-robin member sync. Added dynamic time slots save.
 */

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('engage') || !$plugin->isActivated('engage')) {
   Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

$config = new PluginEngageConfig();

if (isset($_POST['add']) || isset($_POST['update'])) {
   Session::checkCSRF($_POST);

   // Normalize priority_filter: empty string if no priorities selected
   if (!isset($_POST['priority_filter']) || $_POST['priority_filter'] === '') {
      $_POST['priority_filter'] = '';
   }

   // Normalize use_default_outside_slots checkbox (unchecked = not present in POST)
   $_POST['use_default_outside_slots'] = isset($_POST['use_default_outside_slots']) ? 1 : 0;
   $_POST['override_calendar']         = isset($_POST['override_calendar']) ? 1 : 0;

   // Strip slot arrays before passing to CommonDBTM (not DB columns)
   $slot_post = [];
   foreach (['engage_slot_ids', 'engage_slot_names', 'engage_slot_starts', 'engage_slot_ends', 'engage_slot_templates'] as $key) {
      $slot_post[$key] = (array)($_POST[$key] ?? []);
      unset($_POST[$key]);
   }

   if (isset($_POST['add'])) {
      $config_id = $config->add($_POST);
   } else {
      $config->update($_POST);
      $config_id = (int)$_POST['id'];
   }

   // Save dynamic time slots
   if ($config_id > 0) {
      PluginEngageTimeSlot::saveFromPost($config_id, $slot_post);
   }

   Html::back();
}

$id = Session::getActiveEntity();
Html::redirect($CFG_GLPI['root_doc'] . '/front/entity.form.php?forcetab=PluginEngageConfig$1&id=' . $id);
