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
 * English language strings for local_curricmap.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Curriculum map';

// Capabilities.
$string['curricmap:managesync'] = 'Configure, trigger and inspect curriculum syncs';
$string['curricmap:importcsv'] = 'Import curriculum data from CSV';
$string['curricmap:editmanual'] = 'Create and edit manual curriculum entries';
$string['curricmap:viewstaffmeta'] = 'View staff-only curriculum metadata (Sofia links, source, codes)';
$string['curricmap:managebindings'] = 'Manage bindings between Moodle locations and curriculum nodes';

// Settings.
$string['settings:general_heading'] = 'Curriculum map';
$string['settings:general_heading_desc'] = 'Base plugin scaffold. Sofia connection, sync scheduling, CSV import and binding settings arrive with the corresponding features — see PLAN.md in the plugin repository.';

// Privacy.
$string['privacy:metadata'] = 'The Curriculum map plugin does not currently store any personal data. This will be revised when audit logging of manual edits is implemented.';
