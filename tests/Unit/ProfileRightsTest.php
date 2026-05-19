<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PluginEngageProfile permission constants and right definitions.
 *
 * Validates the contract between the profile class and the GLPI rights system:
 * the right name must remain stable (renaming it breaks existing installs).
 */
class ProfileRightsTest extends TestCase
{
   public function testRightConfigConstantIsStable(): void
   {
      $this->assertSame('plugin_engage_config', PluginEngageProfile::RIGHT_CONFIG);
   }

   public function testGetAllRightsReturnsExpectedStructure(): void
   {
      $rights = PluginEngageProfile::getAllRights();

      $this->assertIsArray($rights);
      $this->assertCount(1, $rights, 'Engage exposes exactly one rights group');

      $group = $rights[0];
      $this->assertArrayHasKey('rights', $group);
      $this->assertArrayHasKey('label', $group);
      $this->assertArrayHasKey('field', $group);

      $this->assertSame(PluginEngageProfile::RIGHT_CONFIG, $group['field']);
      $this->assertArrayHasKey(READ,   $group['rights']);
      $this->assertArrayHasKey(UPDATE, $group['rights']);
   }
}
