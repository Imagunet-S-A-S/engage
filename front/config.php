<?php

/**
 * -------------------------------------------------------------------------
 * engage plugin for GLPI is a tool designed to facilitate user assignment 
 * and SLA compliance.
 * Copyright (C) 2024 Imagunet S.A.S.
 * -------------------------------------------------------------------------
 * 
 * LICENSE
 *
 * This file is part of Engage.
 *
 * Engage is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Engage is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Engage. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 * @package     Engage
 * @author      Imagunet S.A.S.
 * @copyright   Copyright (C) 2024 Imagunet S.A.S.
 * @license     https://www.gnu.org/licenses/gpl-3.0.txt GPLv3+   
 * @link        https://github.com/Imagunet-S-A-S/engage
 * --------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   require_once '/usr/share/glpi/inc/includes.php';
}

if (!PluginEngageProfile::canReadEngage()) {
   Html::displayRightError();
}

Html::redirect($CFG_GLPI['root_doc'] . '/plugins/engage/front/settings.php');
