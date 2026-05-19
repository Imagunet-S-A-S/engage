<?php

require_once dirname(__DIR__, 2) . '/inc/timeslot.class.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PluginEngageTimeSlot::inRange().
 *
 * These tests cover the core scheduling logic without requiring a GLPI
 * installation. Any change to the range-matching algorithm must keep all
 * cases green.
 */
class TimeSlotRangeTest extends TestCase
{
   /** @dataProvider provideInRange */
   public function testInRange(string $hhmm, string $start, string $end, bool $expected): void
   {
      $this->assertSame(
         $expected,
         PluginEngageTimeSlot::inRange($hhmm, $start, $end),
         "inRange('{$hhmm}', '{$start}', '{$end}') should be " . ($expected ? 'true' : 'false')
      );
   }

   public static function provideInRange(): array
   {
      return [
         // ── Normal (non-wrapping) ranges ─────────────────────────────────
         'inside range'                 => ['09:00', '08:00', '18:00', true],
         'at range start (inclusive)'   => ['08:00', '08:00', '18:00', true],
         'at range end (exclusive)'     => ['18:00', '08:00', '18:00', false],
         'one minute before start'      => ['07:59', '08:00', '18:00', false],
         'one minute after end'         => ['18:01', '08:00', '18:00', false],
         'well before start'            => ['00:00', '08:00', '18:00', false],
         'well after end'               => ['23:59', '08:00', '18:00', false],

         // ── Midnight-crossing ranges ──────────────────────────────────────
         'midnight-crossing: before midnight'   => ['23:00', '22:00', '06:00', true],
         'midnight-crossing: at start'          => ['22:00', '22:00', '06:00', true],
         'midnight-crossing: after midnight'    => ['02:00', '22:00', '06:00', true],
         'midnight-crossing: at end (exclusive)' => ['06:00', '22:00', '06:00', false],
         'midnight-crossing: midday outside'    => ['12:00', '22:00', '06:00', false],

         // ── Edge cases ────────────────────────────────────────────────────
         'start equals end (degenerate)' => ['08:00', '08:00', '08:00', false],
         'full day slot'                 => ['12:00', '00:00', '23:59', true],
         'midnight itself in normal range' => ['00:00', '23:00', '01:00', true],
      ];
   }
}
