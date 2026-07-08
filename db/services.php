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
 * External function definitions for local_curricmap.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_curricmap_get_programmes' => [
        'classname' => 'local_curricmap\external\get_programmes',
        'description' => 'List enabled curriculum programmes.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/curricmap:viewstaffmeta',
    ],
    'local_curricmap_get_children' => [
        'classname' => 'local_curricmap\external\get_children',
        'description' => 'Browse curriculum nodes one level at a time.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/curricmap:viewstaffmeta',
    ],
    'local_curricmap_search' => [
        'classname' => 'local_curricmap\external\search',
        'description' => 'Search curriculum nodes by title or code.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/curricmap:viewstaffmeta',
    ],
];
