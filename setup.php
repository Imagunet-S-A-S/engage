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

use Glpi\Plugin\Hooks;

define('PLUGIN_ENGAGE_VERSION',          '2.2.5');
define('PLUGIN_ENGAGE_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_ENGAGE_MAX_GLPI_VERSION', '11.0.99');
define('PLUGIN_ENGAGE_MIN_PHP_VERSION',  '8.2.0');

function plugin_init_engage()
{
    global $PLUGIN_HOOKS;

    $Plugin = new Plugin();
    $PLUGIN_HOOKS['csrf_compliant']['engage'] = true;

    if ($Plugin->isActivated('engage')) {

        Plugin::registerClass('PluginEngageConfig',   ['addtabon' => ['Entity']]);
        Plugin::registerClass('PluginEngageProfile',  ['addtabon' => ['Profile']]);
        Plugin::registerClass('PluginEngageQueue');
        Plugin::registerClass('PluginEngageLog',      ['addtabon' => ['Ticket']]);
        Plugin::registerClass('PluginEngageSettings');
        Plugin::registerClass('PluginEngageTimeSlot');

        $PLUGIN_HOOKS[Hooks::PRE_ITEM_FORM]['engage'] = ['PluginEngageConfig',  'displayTechnician'];
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['engage']       = ['Ticket' => ['PluginEngageTicket', 'createFollowup']];

        $PLUGIN_HOOKS['config_page']['engage'] = 'front/settings.php';
    }
}

function plugin_version_engage()
{
    return [
        'name'         => 'Simple Engage Service',
        'shortname'    => 'engage',
        'version'      => PLUGIN_ENGAGE_VERSION,
        'author'       => '<a href="https://www.imagunet.com">Imagunet S.A.S.</a>',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/Imagunet-S-A-S/engage/',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ENGAGE_MIN_GLPI_VERSION,
                'max' => PLUGIN_ENGAGE_MAX_GLPI_VERSION,
                'dev' => false,
            ],
            'php' => [
                'min' => PLUGIN_ENGAGE_MIN_PHP_VERSION,
            ],
        ]
    ];
}

function plugin_engage_check_prerequisites()
{
    if (version_compare(PHP_VERSION, PLUGIN_ENGAGE_MIN_PHP_VERSION, 'lt')) {
        Plugin::messageIncompatible('php', PLUGIN_ENGAGE_MIN_PHP_VERSION);
        return false;
    }

    if (
        version_compare(GLPI_VERSION, PLUGIN_ENGAGE_MIN_GLPI_VERSION, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_ENGAGE_MAX_GLPI_VERSION, 'gt')
    ) {
        Plugin::messageIncompatible(
            'core',
            PLUGIN_ENGAGE_MIN_GLPI_VERSION,
            PLUGIN_ENGAGE_MAX_GLPI_VERSION
        );
        return false;
    }

    return true;
}

function plugin_engage_check_config($verbose = false) { return true; }
function plugin_engage_options() { return [Plugin::OPTION_AUTOINSTALL_DISABLED => true]; }
