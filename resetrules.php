<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Reset the course matching rules setting to the shipped defaults.
 *
 * A saved matchingrules setting is a full replacement and never inherits
 * new shipped defaults on upgrade — this confirmed action is the recovery
 * path back to the current defaults.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$confirm = optional_param('confirm', 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/local/curricmap/resetrules.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('settings:matchingrulesreset', 'local_curricmap'));
$PAGE->set_heading(get_string('pluginname', 'local_curricmap'));

$return = new moodle_url('/admin/settings.php', ['section' => 'local_curricmap_matchingsettings']);

if ($confirm && confirm_sesskey()) {
    set_config('matchingrules', \local_curricmap\local\matcher::default_rules_json(), 'local_curricmap');
    $message = get_string('resetrules:done', 'local_curricmap');
    redirect($return, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
$confirmurl = new moodle_url('/local/curricmap/resetrules.php', ['confirm' => 1, 'sesskey' => sesskey()]);
echo $OUTPUT->confirm(get_string('resetrules:confirm', 'local_curricmap'), $confirmurl, $return);
echo $OUTPUT->footer();
