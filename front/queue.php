<?php

use Glpi\Application\View\TemplateRenderer;

/**
 * -------------------------------------------------------------------------
 * engage plugin — Admin queue dashboard
 * Copyright (C) 2024 Imagunet S.A.S.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkRight('config', READ);

Html::header(
   __('Engage — Queue & Activity', 'engage'),
   $_SERVER['PHP_SELF'],
   'config',
   'plugins'
);

$status_filter = isset($_GET['status']) ? (int)$_GET['status'] : -1;

$counts = PluginEngageQueue::countByStatus();
$rows   = PluginEngageQueue::getForDashboard($status_filter, 200);

TemplateRenderer::getInstance()->display('@engage/pages/queue_dashboard.html.twig', [
   'rows'          => $rows,
   'counts'        => $counts,
   'status_filter' => $status_filter,
   'STATUS_PENDING'   => PluginEngageQueue::STATUS_PENDING,
   'STATUS_SENT'      => PluginEngageQueue::STATUS_SENT,
   'STATUS_ERROR'     => PluginEngageQueue::STATUS_ERROR,
   'STATUS_CANCELLED' => PluginEngageQueue::STATUS_CANCELLED,
]);

Html::footer();
