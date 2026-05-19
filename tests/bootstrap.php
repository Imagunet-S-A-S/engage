<?php

/**
 * Bootstrap for Engage plugin unit tests.
 *
 * Provides the minimum GLPI stubs needed to load plugin classes in isolation
 * so pure-logic methods can be exercised without a full GLPI installation.
 */

if (!defined('GLPI_ROOT')) {
   define('GLPI_ROOT', '/dev/null');
}

// GLPI bitfield permission constants
if (!defined('READ'))   define('READ',   1);
if (!defined('UPDATE')) define('UPDATE', 2);
if (!defined('CREATE')) define('CREATE', 4);

// ── Minimal GLPI class stubs ──────────────────────────────────────────────────

class CommonDBTM
{
   public static $rightname = '';
   public $fields           = [];

   public function isNewItem(): bool
   {
      return empty($this->fields['id']) || (int)$this->fields['id'] <= 0;
   }

   public function getEmpty(): void
   {
      $this->fields = ['id' => 0];
   }
}

class Profile extends CommonDBTM {}

// ── GLPI translation/utility stubs ───────────────────────────────────────────

if (!function_exists('__')) {
   function __(string $str, string $domain = 'glpi'): string { return $str; }
}
if (!function_exists('_n')) {
   function _n(string $sing, string $plur, int $n, string $domain = 'glpi'): string {
      return $n === 1 ? $sing : $plur;
   }
}
if (!function_exists('_sx')) {
   function _sx(string $ctx, string $str, string $domain = 'glpi'): string { return $str; }
}

// ── Plugin-level stubs ────────────────────────────────────────────────────────

require_once dirname(__DIR__) . '/inc/profile.class.php';
