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

if (!defined('GLPI_ROOT')) {
   require_once '/usr/share/glpi/inc/includes.php';
}

if (!PluginEngageProfile::canReadEngage()) {
   Html::displayRightError();
}

Html::redirect($CFG_GLPI['root_doc'] . '/plugins/engage/front/settings.php');
