<?php

/**
 * -------------------------------------------------------------------------
 * engage plugin for GLPI
 * Copyright (C) 2024 Imagunet S.A.S.
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+
 * @link https://github.com/Imagunet-S-A-S/engage/
 * --------------------------------------------------------------------------
 *
 * v2.2.0:
 * - Removed Round-Robin
 * - Dynamic time slots with drag-and-drop
 * - Priority filter (multi-checkbox)
 * - use_default_outside_slots toggle
 *
 * Bug fixes v2.2.1:
 * - Slot template uses native <select> instead of GLPI Dropdown::show()
 *   to avoid the hidden-field offset bug with array names
 * - Removed + button from slot template dropdown (not applicable there)
 * - priority_filter JSON serialized on Save button click, not form submit
 */

use Glpi\Application\View\TemplateRenderer;

class PluginEngageConfig extends CommonDBTM
{
   static private $_instance           = NULL;
   static private $_instance_entity_id = NULL;
   static $rightname                   = 'config';

   const CONFIG_PARENT = 0;
   const ENABLED       = 1;
   const DISABLED      = 0;
   const MIXED         = 0;
   const INCIDENT      = 1;
   const REQUEST       = 2;

   const PRIORITY_VERY_LOW  = 1;
   const PRIORITY_LOW       = 2;
   const PRIORITY_MEDIUM    = 3;
   const PRIORITY_HIGH      = 4;
   const PRIORITY_VERY_HIGH = 5;

   public static function canCreate(): bool { return Session::haveRight('config', UPDATE); }
   public static function canView(): bool   { return Session::haveRight('config', READ); }
   public static function getTypeName($nb = 0): string { return __('Setup'); }
   public function getName($with_comment = 0): string  { return __('Engage', 'engage'); }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if ($item->getType() !== 'Entity') return '';
      // Hide from Self-Service interface and users without config READ right
      if (Session::getCurrentInterface() === 'helpdesk') return '';
      if (!Session::haveRight(self::$rightname, READ)) return '';
      return self::getName();
   }
   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if ($item->getType() == 'Entity') {
         self::showConfigForm($item);
      }
      return true;
   }

   public static function canItemtype($itemtype = '') {
      return (!class_exists($itemtype) || $itemtype == 'Ticket');
   }

   static function getInstance($ID) {
      if (!isset(self::$_instance) || self::$_instance_entity_id !== $ID) {
         self::$_instance = new self();
         self::$_instance_entity_id = $ID;
         if (!self::$_instance->getFromDBByCrit(['entities_id' => $ID])) {
            self::$_instance->getEmpty();
         }
      }
      return self::$_instance;
   }

   public function post_getEmpty() {
      $this->fields['id']                        = 0;
      $this->fields['users_id_tech']             = 0;
      $this->fields['itil_followup']             = 0;
      $this->fields['entities_id']               = NULL;
      $this->fields['is_recursive']              = 1;
      $this->fields['is_active']                 = 1;
      $this->fields['ticket_type']               = self::MIXED;
      $this->fields['delay_minutes']             = 0;
      $this->fields['calendars_id']              = 0;
      $this->fields['override_calendar']         = 0;
      $this->fields['priority_filter']           = '';
      $this->fields['use_default_outside_slots'] = 1;
   }

   // ── Entity ancestor walk ──────────────────────────────────────────────

   public static function getConfigForEntity($value = '') {
      $dbu = new DbUtils();
      $requested_entity = is_array($value) ? null : (int)$value;
      $ancestors = [];
      if (is_array($value)) {
         $entities = array_map('intval', $value);
         $ancestors = $dbu->getAncestorsOf("glpi_entities", $entities);
         $ancestors = array_diff($ancestors, $entities);
      } elseif (strlen((string)$value) == 0) {
         $entities = [];
         $ancestors = $_SESSION['glpiparententities'] ?? [];
      } else {
         $entities = [(int)$value];
         $ancestors = $dbu->getAncestorsOf('glpi_entities', $value);
      }
      $ancestors = array_merge($ancestors, $entities);
      $ancestors = array_reverse($ancestors);

      $config = new self();
      foreach ($ancestors as $entity_id) {
         $entity_id = (int)$entity_id;
         $found = $config->getFromDBByCrit(['entities_id' => $entity_id]);
         if (!$found) { $config->getEmpty(); continue; }
         if ($requested_entity !== null
               && $entity_id !== $requested_entity
               && empty($config->fields['is_recursive'])) {
            $config->getEmpty();
            continue;
         }
         if ($config->fields['users_id_tech'] == self::CONFIG_PARENT
               && $config->fields['is_active'] != self::DISABLED) {
            continue;
         }
         if (!isset($config->fields['is_active'])
               || $config->fields['is_active'] == self::DISABLED) {
            $config->getEmpty();
            return $config;
         }
         return $config;
      }
      $config->getEmpty();
      return $config;
   }

   // ── Calendar helpers ──────────────────────────────────────────────────

   public static function isWithinCalendar(PluginEngageConfig $config): bool {
      // override_calendar = 1 means send 24/7, ignore calendar completely
      if (!empty($config->fields['override_calendar'])) return true;
      $calendars_id = (int)($config->fields['calendars_id'] ?? 0);
      if ($calendars_id === 0) return true;
      $calendar = new Calendar();
      if (!$calendar->getFromDB($calendars_id)) return true;
      return $calendar->isAWorkingHour(gmdate('Y-m-d H:i:s'));
   }

   /**
    * Apply delay_minutes offset to a given UTC datetime string.
    * Used when a ticket is queued for a future slot/calendar window —
    * the delay is added ON TOP of that window's start time.
    * Returns the original datetime if delay is 0.
    */
   public static function applyDelay(PluginEngageConfig $config, string $utc_datetime): string
   {
      $delay_minutes = (int)($config->fields['delay_minutes'] ?? 0);
      if ($delay_minutes === 0) return $utc_datetime;

      $dt = new DateTime($utc_datetime, new DateTimeZone('UTC'));
      $dt->modify('+' . $delay_minutes . ' minutes');
      return $dt->format('Y-m-d H:i:s');
   }

   public static function getScheduledDateTime(PluginEngageConfig $config): ?string {
      $delay_minutes  = (int)($config->fields['delay_minutes']    ?? 0);
      $calendars_id   = (int)($config->fields['calendars_id']     ?? 0);
      $override_cal   = !empty($config->fields['override_calendar']);

      if ($delay_minutes === 0) return null;

      // Always work in UTC — send_after is stored and compared in UTC
      $now_utc = gmdate('Y-m-d H:i:s');

      if ($calendars_id > 0 && !$override_cal) {
         $calendar = new Calendar();
         if ($calendar->getFromDB($calendars_id)) {
            // computeEndDate works with server time — pass UTC and get UTC back
            return $calendar->computeEndDate($now_utc, $delay_minutes * 60, 0, true);
         }
      }

      // No calendar — simple UTC offset
      $dt = new DateTime('now', new DateTimeZone('UTC'));
      $dt->modify('+' . $delay_minutes . ' minutes');
      return $dt->format('Y-m-d H:i:s');
   }

   // ── Priority helpers ──────────────────────────────────────────────────

   public static function getPriorityOptions(): array {
      return [
         self::PRIORITY_VERY_HIGH => __('Very high'),
         self::PRIORITY_HIGH      => __('High'),
         self::PRIORITY_MEDIUM    => __('Medium'),
         self::PRIORITY_LOW       => __('Low'),
         self::PRIORITY_VERY_LOW  => __('Very low'),
      ];
   }

   public static function getSelectedPriorities(string $filter): array {
      if ($filter === '') return [];
      $decoded = json_decode($filter, true);
      return is_array($decoded) ? array_map('intval', $decoded) : [];
   }

   // ── Template list helper (for native <select>) ────────────────────────

   /**
    * Returns all active ITILFollowupTemplates as [id => name].
    */
   private static function getFollowupTemplates(): array
   {
      global $DB;
      $result = [];
      $rows = $DB->request([
         'SELECT' => ['id', 'name'],
         'FROM'   => 'glpi_itilfollowuptemplates',
         'ORDER'  => 'name ASC',
      ]);
      foreach ($rows as $row) {
         $result[(int)$row['id']] = $row['name'];
      }
      return $result;
   }

   // ── Display helpers ───────────────────────────────────────────────────

   public static function displayTechnician($item = []) {
      if (isset($item['item']) && $item['item'] instanceof CommonDBTM) {
         self::showTechnicianLabel($item);
      }
      return true;
   }

   static function showTechnicianLabel($item) {
      if (!self::canView()) return false;
      $itemtype = get_class($item['item']);
      if (self::canItemtype($itemtype) && $item['item']->fields['entities_id'] >= 0) {
         $config = self::getConfigForEntity($item['item']->fields['entities_id']);
         if (isset($config->fields['users_id_tech']) && is_array($config->fields) && $config->fields['is_active']) {
            $whoare = $config->fields['users_id_tech'] > 0
               ? User::getNameForLog($config->fields['users_id_tech'])
               : __('Not assigned or disabled');
         } else {
            $whoare = __('Not assigned or disabled');
         }
         echo "<div class='form-field row col-12 d-flex align-items-center mb-2'>";
         echo "<label class='col-form-label col-xxl-4 text-xxl-end'>".__('Engage', 'engage')."</label>";
         echo "<div class='col-xxl-8 field-container'>";
         echo "<span class='entity-badge'><span class='text-nowrap'>".htmlspecialchars($whoare)."</span></span>";
         echo "</div></div>";
      }
   }

   // ── Main config form ──────────────────────────────────────────────────

   static function showConfigForm(Entity $entity) {
      $config  = self::getInstance($entity->getEntityID());
      $canedit = Session::haveRight(self::$rightname, UPDATE);

      TemplateRenderer::getInstance()->display('@engage/pages/entity_setup.html.twig', [
         'canedit' => $canedit,
         'config'  => $config,
      ]);

      if (!$config->fields['is_active']) {
         TemplateRenderer::getInstance()->display('@engage/footer_form.html.twig', [
            'canedit' => $canedit,
            'entity'  => $entity->getEntityID(),
            'config'  => $config,
         ]);
         return true;
      }

      $configs_id     = (int)($config->fields['id'] ?? 0);
      $config_entity  = (int)($config->fields['entities_id'] ?? -1);
      $current_entity = (int)$entity->getEntityID();
      $is_inherited   = ($configs_id > 0 && $config_entity !== $current_entity);
      $dis            = $canedit ? '' : ' disabled';

      // ── Section: Technician ───────────────────────────────────────────
      echo "<div class='hr-text'><i class='ti ti-user'></i><span>"
         . __('Technician', 'engage') . "</span></div>";

      if ($is_inherited) {
         echo "<div class='row ps-4'><div class='alert alert-warning col-11 ms-1 py-2 px-3 mb-2' style='font-size:0.85rem;'>";
         echo "<i class='ti ti-alert-triangle me-1'></i>";
         echo "<strong>" . __('Inherited configuration', 'engage') . "</strong> — ";
         echo __('This entity inherits its Engage config from a parent entity. Changes here will affect ALL entities sharing this configuration.', 'engage');
         echo "</div></div>";
      }

      echo "<div class='row ps-4'><div class='form-field row col-12 mb-2'>";
      echo "<label class='col-form-label col-xxl-4 text-xxl-end'>"
         . __('Fallback technician', 'engage') . "</label>";
      echo "<div class='col-xxl-8 field-container'>";
      User::dropdown([
         'name'       => 'users_id_tech',
         'right'      => 'interface',
         'value'      => $config->fields['users_id_tech'],
         'emptylabel' => $entity->getEntityID() ? __('Inherit from parent') : Dropdown::EMPTY_VALUE,
         'width'      => '250px',
      ]);
      echo "<span class='form-text text-muted ms-2'>"
         . __('Technician assigned to tickets and used as followup author.', 'engage')
         . "</span></div></div></div>";

      // ── Section: Priority filter ──────────────────────────────────────
      echo "<div class='hr-text'><i class='ti ti-filter'></i><span>"
         . __('Priority Filter', 'engage') . "</span></div>";

      echo "<div class='row ps-4'><div class='form-field row col-12 mb-2'>";
      echo "<label class='col-form-label col-xxl-4 text-xxl-end'>"
         . __('Notify on priorities', 'engage') . "</label>";
      echo "<div class='col-xxl-8 field-container'>";

      $selected_priorities = self::getSelectedPriorities($config->fields['priority_filter'] ?? '');

      echo "<div class='d-flex flex-wrap gap-3 align-items-center'>";
      foreach (self::getPriorityOptions() as $value => $label) {
         $is_checked  = (!empty($selected_priorities) && in_array($value, $selected_priorities)) ? ' checked' : '';
         $color_class = match($value) {
            self::PRIORITY_VERY_HIGH => 'text-danger fw-bold',
            self::PRIORITY_HIGH      => 'text-warning fw-semibold',
            self::PRIORITY_MEDIUM    => 'text-info',
            self::PRIORITY_LOW       => 'text-success',
            self::PRIORITY_VERY_LOW  => 'text-muted',
            default => '',
         };
         echo "<div class='form-check'>";
         echo "<input class='form-check-input engage-priority-cb' type='checkbox'"
            . " name='priority_filter_values[]' value='{$value}'"
            . $is_checked . $dis . " id='prio_{$value}'>";
         echo "<label class='form-check-label {$color_class}' for='prio_{$value}'>" . htmlspecialchars($label) . "</label>";
         echo "</div>";
      }
      echo "</div>";
      echo "<div class='form-text text-muted mt-1'>"
         . __('If no priority is checked, Engage will act on ALL priorities.', 'engage')
         . "</div>";
      // Hidden field — populated by JS before submit
      echo "<input type='hidden' name='priority_filter' id='priority_filter_json' value='"
         . htmlspecialchars($config->fields['priority_filter'] ?? '') . "'>";
      echo "</div></div></div>";

      // ── Section: Followup Templates ───────────────────────────────────
      echo "<div class='hr-text'><i class='ti ti-template'></i><span>"
         . __('Followup Templates', 'engage') . "</span></div>";

      echo "<div class='row ps-4'><div class='form-field row col-12 mb-2'>";
      echo "<label class='col-form-label col-xxl-4 text-xxl-end'><strong>"
         . __('Default template', 'engage') . " *</strong></label>";
      echo "<div class='col-xxl-8 field-container'>";
      ITILFollowupTemplate::dropdown([
         'name'     => 'itil_followup',
         'value'    => $config->fields['itil_followup'],
         'width'    => '280px',
         'comments' => false,
      ]);
      echo "<span class='form-text text-muted ms-2'>"
         . __('Used when no time slot matches (if enabled below).', 'engage')
         . "</span></div></div></div>";

      // use_default_outside_slots
      $checked_default = ($config->fields['use_default_outside_slots'] ?? 1) ? ' checked' : '';
      echo "<div class='row ps-4'><div class='form-field row col-12 mb-3'>";
      echo "<label class='col-form-label col-xxl-4 text-xxl-end'>"
         . __('Send default outside slots', 'engage') . "</label>";
      echo "<div class='col-xxl-8 field-container d-flex align-items-center gap-2'>";
      echo "<input type='checkbox' class='form-check-input' name='use_default_outside_slots'"
         . " value='1'{$checked_default}{$dis} id='use_default_chk'>";
      echo "<label class='form-check-label' for='use_default_chk'>"
         . __('When no time slot is active, send the default template above. If unchecked, no followup is sent outside defined slots.', 'engage')
         . "</label></div></div></div>";

      // ── Section: Time Slots ───────────────────────────────────────────
      echo "<div class='hr-text'><i class='ti ti-clock'></i><span>"
         . __('Time Slots', 'engage') . "</span></div>";

      echo "<div class='row ps-4'>";
      echo "<div class='col-11 ms-1 mb-3 p-3 rounded' style='background:#f0f4ff;border:1px solid #d0d9f0;font-size:0.85rem;'>";
      echo "<i class='ti ti-info-circle me-1 text-primary'></i>";
      echo "<strong>" . __('How time slots work:', 'engage') . "</strong><br>";
      echo __('Each slot defines a time range (e.g. 08:00–18:00) and a followup template to use. When a ticket arrives, Engage checks the slots <strong>in order from top to bottom</strong> and uses the <em>first matching slot</em>. Drag the ⠿ handle to reorder.', 'engage');
      echo "<br><span class='text-muted'>"
         . __('The active days are controlled by the Business Hours Calendar — e.g. an 8×5 calendar will automatically skip weekends and holidays.', 'engage')
         . "</span></div></div>";

      // Load available templates once for the native selects
      $all_templates = self::getFollowupTemplates();
      $slots         = PluginEngageTimeSlot::getSlotsForConfig($configs_id);

      self::_renderSlotsEditor($slots, $all_templates, $canedit);

      // ── Section: Business Hours & Delay ───────────────────────────────
      echo "<div class='hr-text'><i class='ti ti-building'></i><span>"
         . __('Business Hours & Delay', 'engage') . "</span></div>";

      // Explanation of calendar vs slots logic
      echo "<div class='row ps-4'>";
      echo "<div class='col-11 ms-1 mb-3 p-3 rounded' style='background:#fff8e1;border:1px solid #ffe082;font-size:0.85rem;'>";
      echo "<i class='ti ti-info-circle me-1 text-warning'></i>";
      echo "<strong>" . __('Calendar vs Slots logic:', 'engage') . "</strong><br>";
      echo __('The <strong>Business Hours Calendar</strong> defines when Engage is active (e.g. Mon–Fri 8–18h). Tickets outside this window are queued for the next working period. <strong>Time Slots</strong> (above) act as overrides within that window — use them to send different templates at specific hours. If <strong>Override Calendar</strong> is checked, Engage ignores the calendar entirely and sends 24/7.', 'engage');
      echo "</div></div>";

      // Override calendar checkbox
      $checked_override = !empty($config->fields['override_calendar']) ? ' checked' : '';
      echo "<div class='row ps-4'><div class='form-field row col-12 mb-2'>";
      echo "<label class='col-form-label col-xxl-4 text-xxl-end'>"
         . __('Override calendar (24/7)', 'engage') . "</label>";
      echo "<div class='col-xxl-8 field-container d-flex align-items-center gap-2'>";
      echo "<input type='checkbox' class='form-check-input' name='override_calendar'"
         . " value='1'{$checked_override}{$dis} id='override_calendar_chk'>";
      echo "<label class='form-check-label' for='override_calendar_chk'>"
         . __('Ignore business hours calendar and send at any time.', 'engage')
         . "</label></div></div></div>";

      // Calendar dropdown (disabled visually when override is checked)
      echo "<div class='row ps-4' id='engage-calendar-row'><div class='form-field row col-12 mb-2'>";
      echo "<label class='col-form-label col-xxl-4 text-xxl-end'>"
         . __('Business hours calendar', 'engage') . "</label>";
      echo "<div class='col-xxl-8 field-container'>";
      Calendar::dropdown([
         'name'       => 'calendars_id',
         'value'      => $config->fields['calendars_id'],
         'emptylabel' => __('No restriction (24/7)', 'engage'),
         'width'      => '250px',
         'comments'   => false,
      ]);
      echo "</div></div></div>";

      echo "<div class='row ps-4'><div class='form-field row col-12 mb-2'>";
      echo "<label class='col-form-label col-xxl-4 text-xxl-end'>"
         . __('Delay before sending (minutes)', 'engage') . "</label>";
      echo "<div class='col-xxl-8 field-container'>";
      echo "<input type='number' name='delay_minutes' min='0' max='10080' step='1'"
         . " value='" . (int)$config->fields['delay_minutes'] . "'"
         . " class='form-control' style='width:120px;display:inline-block;'{$dis}>";
      echo "<span class='form-text text-muted ms-2'>"
         . __('0 = immediate. Max 10080 (7 days). If calendar set, delay counts working minutes.', 'engage')
         . "</span></div></div></div>";

      // JS: toggle calendar row when override is checked
      echo "<script>
(function() {
   var overrideCb  = document.getElementById('override_calendar_chk');
   var calendarRow = document.getElementById('engage-calendar-row');
   function toggleCalendar() {
      if (calendarRow) {
         calendarRow.style.opacity = overrideCb && overrideCb.checked ? '0.4' : '1';
         calendarRow.style.pointerEvents = overrideCb && overrideCb.checked ? 'none' : '';
      }
   }
   if (overrideCb) {
      overrideCb.addEventListener('change', toggleCalendar);
      toggleCalendar();
   }
})();
</script>";

      TemplateRenderer::getInstance()->display('@engage/footer_form.html.twig', [
         'canedit' => $canedit,
         'entity'  => $entity->getEntityID(),
         'config'  => $config,
      ]);

      self::_renderInlineJS($canedit, $all_templates);

      return true;
   }

   // ── Slots editor HTML ─────────────────────────────────────────────────

   private static function _renderSlotsEditor(array $slots, array $all_templates, bool $canedit): void
   {
      echo "<div class='row ps-4'><div class='col-12'>";
      echo "<div id='engage-slots-container'>";

      if (empty($slots)) {
         echo "<div id='engage-slots-empty' class='text-muted mb-2' style='font-size:0.9rem;'>"
            . "<i class='ti ti-mood-empty me-1'></i>"
            . __('No time slots configured. Click + to add one.', 'engage')
            . "</div>";
      }

      foreach ($slots as $slot) {
         self::_renderSlotRow($slot, $all_templates, $canedit);
      }

      echo "</div>"; // #engage-slots-container

      if ($canedit) {
         echo "<button type='button' class='btn btn-sm btn-outline-primary mt-2' id='engage-add-slot'>"
            . "<i class='ti ti-plus me-1'></i>" . __('Add time slot', 'engage') . "</button>";
      }

      echo "</div></div>";

      // Template row for JS cloning (hidden)
      echo "<template id='engage-slot-template'>";
      self::_renderSlotRow([
         'id'          => 0,
         'name'        => '',
         'start_time'  => '08:00',
         'end_time'    => '18:00',
         'template_id' => 0,
         'sort_order'  => 0,
      ], $all_templates, $canedit, true);
      echo "</template>";
   }

   private static function _renderSlotRow(array $slot, array $all_templates, bool $canedit, bool $is_template = false): void
   {
      $dis     = $canedit ? '' : ' disabled';
      $slot_id = (int)($slot['id'] ?? 0);
      $tpl_id  = (int)($slot['template_id'] ?? 0);

      echo "<div class='engage-slot-row d-flex align-items-center gap-2 mb-2 p-2 rounded'"
         . " style='background:#f8f9fa;border:1px solid #dee2e6;'>";

      if ($canedit) {
         echo "<span class='engage-drag-handle' title='" . __('Drag to reorder', 'engage') . "'"
            . " style='cursor:grab;color:#adb5bd;font-size:1.3rem;line-height:1;padding:2px 6px;'>⠿</span>";
      }

      // Hidden: slot DB id
      echo "<input type='hidden' name='engage_slot_ids[]' value='{$slot_id}'>";

      // Name
      $name_val = htmlspecialchars($slot['name'] ?? '');
      echo "<input type='text' name='engage_slot_names[]' value='{$name_val}'"
         . " placeholder='" . __('Slot name', 'engage') . "'"
         . " class='form-control form-control-sm' style='width:130px;'{$dis}>";

      // Start time
      echo "<span class='form-text text-muted small'>" . __('From', 'engage') . "</span>";
      $start = htmlspecialchars($slot['start_time'] ?? '08:00');
      echo "<input type='time' name='engage_slot_starts[]' value='{$start}'"
         . " class='form-control form-control-sm' style='width:108px;'{$dis}>";

      echo "<span class='form-text text-muted small'>→</span>";

      // End time
      $end = htmlspecialchars($slot['end_time'] ?? '18:00');
      echo "<input type='time' name='engage_slot_ends[]' value='{$end}'"
         . " class='form-control form-control-sm' style='width:108px;'{$dis}>";

      // Template — native <select> to avoid GLPI hidden-field offset bug
      echo "<span class='form-text text-muted small'>" . __('Template', 'engage') . "</span>";
      echo "<select name='engage_slot_templates[]' class='form-select form-select-sm' style='width:200px;'{$dis}>";
      echo "<option value='0'>" . __('— choose —', 'engage') . "</option>";
      foreach ($all_templates as $tid => $tname) {
         $selected = ($tid === $tpl_id) ? ' selected' : '';
         echo "<option value='{$tid}'{$selected}>" . htmlspecialchars($tname) . "</option>";
      }
      echo "</select>";

      // Remove button
      if ($canedit) {
         echo "<button type='button' class='btn btn-sm btn-outline-danger engage-remove-slot'"
            . " title='" . __('Remove slot', 'engage') . "'>"
            . "<i class='ti ti-trash'></i></button>";
      }

      echo "</div>"; // .engage-slot-row
   }

   // ── Inline JS ─────────────────────────────────────────────────────────

   private static function _renderInlineJS(bool $canedit, array $all_templates): void
   {
      // Build template options HTML for JS cloning
      $options_html = "<option value='0'>" . __('— choose —', 'engage') . "</option>";
      foreach ($all_templates as $tid => $tname) {
         $options_html .= "<option value='{$tid}'>" . htmlspecialchars($tname) . "</option>";
      }
      $options_html_js = json_encode($options_html);

      echo "<script>
(function() {
   var canEdit = " . ($canedit ? 'true' : 'false') . ";
   var tplOptions = " . $options_html_js . ";

   // ── Drag-and-drop ─────────────────────────────────────────────────
   var container = document.getElementById('engage-slots-container');
   var dragSrc   = null;

   if (container && canEdit) {
      container.addEventListener('mousedown', function(e) {
         var handle = e.target.closest('.engage-drag-handle');
         if (handle) {
            var row = handle.closest('.engage-slot-row');
            if (row) row.setAttribute('draggable', 'true');
         }
      });

      document.addEventListener('mouseup', function() {
         if (container) {
            container.querySelectorAll('.engage-slot-row').forEach(function(r) {
               r.removeAttribute('draggable');
            });
         }
      });

      container.addEventListener('dragstart', function(e) {
         var row = e.target.closest('.engage-slot-row');
         if (!row) return;
         dragSrc = row;
         e.dataTransfer.effectAllowed = 'move';
         setTimeout(function() { row.style.opacity = '0.4'; }, 0);
      });

      container.addEventListener('dragend', function(e) {
         var row = e.target.closest('.engage-slot-row');
         if (row) row.style.opacity = '';
         dragSrc = null;
         container.querySelectorAll('.engage-slot-row').forEach(function(r) {
            r.style.borderTop = '';
         });
      });

      container.addEventListener('dragover', function(e) {
         e.preventDefault();
         var row = e.target.closest('.engage-slot-row');
         if (!row || row === dragSrc) return;
         container.querySelectorAll('.engage-slot-row').forEach(function(r) {
            r.style.borderTop = '';
         });
         row.style.borderTop = '2px solid #0d6efd';
      });

      container.addEventListener('drop', function(e) {
         e.preventDefault();
         var row = e.target.closest('.engage-slot-row');
         if (!row || row === dragSrc || !dragSrc) return;
         container.insertBefore(dragSrc, row);
         container.querySelectorAll('.engage-slot-row').forEach(function(r) {
            r.style.borderTop = '';
         });
      });
   }

   // ── Add slot ──────────────────────────────────────────────────────
   var addBtn = document.getElementById('engage-add-slot');
   var tpl    = document.getElementById('engage-slot-template');

   if (addBtn && tpl && container) {
      addBtn.addEventListener('click', function() {
         var clone = tpl.content.cloneNode(true);
         // Replace template select options with populated ones
         var sel = clone.querySelector('select[name=\"engage_slot_templates[]\"]');
         if (sel) sel.innerHTML = tplOptions;
         var empty = document.getElementById('engage-slots-empty');
         if (empty) empty.remove();
         container.appendChild(clone);
      });
   }

   // ── Remove slot ───────────────────────────────────────────────────
   document.addEventListener('click', function(e) {
      var btn = e.target.closest('.engage-remove-slot');
      if (!btn) return;
      var row = btn.closest('.engage-slot-row');
      if (row) row.remove();
      if (container && container.querySelectorAll('.engage-slot-row').length === 0) {
         if (!document.getElementById('engage-slots-empty')) {
            var msg = document.createElement('div');
            msg.id = 'engage-slots-empty';
            msg.className = 'text-muted mb-2';
            msg.style.fontSize = '0.9rem';
            msg.innerHTML = \"<i class='ti ti-mood-empty me-1'></i>" . __('No time slots configured. Click + to add one.', 'engage') . "\";
            container.appendChild(msg);
         }
      }
   });

   // ── Priority filter: serialize on Save button click ───────────────
   // Intercept the Save button (submit) to populate the hidden JSON field
   // before the form data is collected. Using 'click' on the button is more
   // reliable than 'submit' in GLPI 11's form handling.
   document.addEventListener('click', function(e) {
      var btn = e.target.closest('button[name=\"add\"], button[name=\"update\"]');
      if (!btn) return;
      var cbs = document.querySelectorAll('.engage-priority-cb:checked');
      var vals = Array.from(cbs).map(function(cb) { return parseInt(cb.value); });
      var jsonField = document.getElementById('priority_filter_json');
      if (jsonField) {
         jsonField.value = vals.length > 0 ? JSON.stringify(vals) : '';
      }
   });

})();
</script>";
   }
}
