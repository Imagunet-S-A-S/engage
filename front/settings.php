<?php

use Glpi\Application\View\TemplateRenderer;

/**
 * engage plugin — Global settings page
 * Copyright (C) 2024 Imagunet S.A.S.
 */

include('../../../inc/includes.php');

Session::checkRight('config', READ);

// Handle form save
if (isset($_POST['update_settings'])) {
   Session::checkRight('config', UPDATE);
   Session::checkCSRF($_POST);
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
