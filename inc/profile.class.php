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

   private static function activeProfileHasRight(string $rightname, int $right): bool {
      global $DB;

      $profiles_id = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
      if ($profiles_id <= 0) {
         return false;
      }

      $iterator = $DB->request([
         'SELECT' => ['rights'],
         'FROM'   => 'glpi_profilerights',
         'WHERE'  => [
            'profiles_id' => $profiles_id,
            'name'        => $rightname,
         ],
         'LIMIT'  => 1,
      ]);

      foreach ($iterator as $row) {
         return (((int)$row['rights'] & $right) === $right);
      }

      return false;
   }

   public static function canReadEngage(): bool {
      return Session::haveRight(self::RIGHT_CONFIG, READ)
         || Session::haveRight('config', READ)
         || self::activeProfileHasRight(self::RIGHT_CONFIG, READ)
         || self::activeProfileHasRight('config', READ);
   }

   public static function canUpdateEngage(): bool {
      return Session::haveRight(self::RIGHT_CONFIG, UPDATE)
         || Session::haveRight('config', UPDATE)
         || self::activeProfileHasRight(self::RIGHT_CONFIG, UPDATE)
         || self::activeProfileHasRight('config', UPDATE);
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
