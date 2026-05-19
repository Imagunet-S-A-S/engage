<?php

require_once dirname(__DIR__, 2) . '/inc/config.class.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PluginEngageConfig priority-filter helpers.
 *
 * These methods parse the JSON-encoded priority_filter field and are
 * exercised without any database or GLPI session context.
 */
class ConfigPriorityTest extends TestCase
{
   public function testEmptyStringReturnsEmptyArray(): void
   {
      $this->assertSame([], PluginEngageConfig::getSelectedPriorities(''));
   }

   public function testValidJsonReturnsIntegerArray(): void
   {
      $result = PluginEngageConfig::getSelectedPriorities('[1,3,5]');
      $this->assertSame([1, 3, 5], $result);
   }

   public function testStringValuesAreCastToInt(): void
   {
      $result = PluginEngageConfig::getSelectedPriorities('["1","3"]');
      $this->assertSame([1, 3], $result);
   }

   public function testInvalidJsonReturnsEmptyArray(): void
   {
      $this->assertSame([], PluginEngageConfig::getSelectedPriorities('not-json'));
   }

   public function testNullJsonValueReturnsEmptyArray(): void
   {
      $this->assertSame([], PluginEngageConfig::getSelectedPriorities('null'));
   }

   public function testPriorityConstantsAreCorrect(): void
   {
      $this->assertSame(1, PluginEngageConfig::PRIORITY_VERY_LOW);
      $this->assertSame(2, PluginEngageConfig::PRIORITY_LOW);
      $this->assertSame(3, PluginEngageConfig::PRIORITY_MEDIUM);
      $this->assertSame(4, PluginEngageConfig::PRIORITY_HIGH);
      $this->assertSame(5, PluginEngageConfig::PRIORITY_VERY_HIGH);
   }
}
