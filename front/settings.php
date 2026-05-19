<?php

use Glpi\Application\View\TemplateRenderer;

/**
 * engage plugin — Global settings page
 * Copyright (C) 2024 Imagunet S.A.S.
 */

if (!defined('GLPI_ROOT')) {
   require_once '/usr/share/glpi/inc/includes.php';
}

if (!PluginEngageProfile::canReadEngage()) {
   Html::displayRightError();
}

// Handle form save
if (isset($_POST['update_settings'])) {
   if (!PluginEngageProfile::canUpdateEngage()) {
      Html::displayRightError();
   }
   // CSRF already validated by GLPI 11's CheckCsrfListener before reaching here.
   PluginEngageSettings::handlePost($_POST);
   Html::back();
}

Html::header(
   __('Engage — Global Settings', 'engage'),
   $_SERVER['PHP_SELF'],
   'config',
   'plugins'
);

PluginEngageSettings::showSettingsForm();

Html::footer();
