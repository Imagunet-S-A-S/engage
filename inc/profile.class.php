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

class PluginEngageProfile extends Profile {

   public const RIGHT_CONFIG = 'plugin_engage_config';

   public static function getAllRights(): array {
      return [[
         'rights' => [
            READ   => __('Read'),
            UPDATE => __('Update'),
         ],
         'label'  => __('Engage configuration', 'engage'),
         'field'  => self::RIGHT_CONFIG,
      ]];
   }

   public static function addDefaultProfileInfos(int $profiles_id, array $rights): void {
      global $DB;

      $profileRight = new ProfileRight();

      foreach ($rights as $right => $value) {
         $criteria = [
            'profiles_id' => $profiles_id,
            'name'        => $right,
         ];

         if (countElementsInTable('glpi_profilerights', $criteria)) {
            $DB->update('glpi_profilerights', ['rights' => $value], $criteria);
         } else {
            $profileRight->add([
               'profiles_id' => $profiles_id,
               'name'        => $right,
               'rights'      => $value,
            ]);
         }

         if (isset($_SESSION['glpiactiveprofile']['id'])
               && (int)$_SESSION['glpiactiveprofile']['id'] === $profiles_id) {
            $_SESSION['glpiactiveprofile'][$right] = $value;
         }
      }
   }

   public static function createFirstAccess(int $profiles_id): void {
      if ($profiles_id <= 0) {
         return;
      }

      self::addDefaultProfileInfos($profiles_id, [
         self::RIGHT_CONFIG => READ | UPDATE,
      ]);
   }

   /**
    * Seed Engage rights for profiles that already have GLPI setup/config update rights.
    * This keeps existing super-admin/configuration profiles working after upgrades.
    */
   public static function ensureDefaultRights(): void {
      global $DB;

      $profiles = $DB->request([
         'SELECT' => ['profiles_id', 'rights'],
         'FROM'   => 'glpi_profilerights',
         'WHERE'  => ['name' => 'config'],
      ]);

      foreach ($profiles as $profile) {
         if (((int)$profile['rights'] & UPDATE) === UPDATE) {
            self::addDefaultProfileInfos((int)$profile['profiles_id'], [
               self::RIGHT_CONFIG => READ | UPDATE,
            ]);
         }
      }
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if ($item->getType() !== 'Profile') {
         return '';
      }
      return self::createTabEntry(__('Engage management', 'engage'));
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      $engageprofile = new self();
      $engageprofile->showForm($item->getID());
      return true;
   }

   public function showForm($ID, array $options = []) {

      if (!self::canView()) {
         return false;
      }

      echo "<div class='spaced'>";
      $profile = new Profile();
      $profile->getFromDB($ID);
      if ($canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE])) {
         echo "<form method='post' action='".$profile->getFormURL()."'>";
      }

      $rights = self::getAllRights();

      $profile->displayRightsChoiceMatrix($rights, [  
         'canedit'       => $canedit,
         'default_class' => 'tab_bg_2',
         'title'         => __('General', 'engage')]);

      if ($canedit) {
         echo "<div class='center'>";
         echo Html::hidden('id', ['value' => $ID]);
         echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
         echo "</div>\n";
         Html::closeForm();
      }
      echo "</div>";
   }
}
