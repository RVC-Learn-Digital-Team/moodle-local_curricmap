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
    'local_curricmap_bind' => [
        'classname' => 'local_curricmap\external\bind',
        'description' => 'Bind a Moodle address to a curriculum node (idempotent).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/curricmap:managebindings',
    ],
    'local_curricmap_unbind' => [
        'classname' => 'local_curricmap\external\unbind',
        'description' => 'Delete a binding by id.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/curricmap:managebindings',
    ],
    'local_curricmap_resolve' => [
        'classname' => 'local_curricmap\external\resolve',
        'description' => 'Resolve the bindings applying to a Moodle location, deepest level first.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/curricmap:viewstaffmeta',
    ],
    'local_curricmap_list_bindings' => [
        'classname' => 'local_curricmap\external\list_bindings',
        'description' => 'List bindings by course or by node.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/curricmap:viewstaffmeta',
    ],
    'local_curricmap_add_resource' => [
        'classname' => 'local_curricmap\external\add_resource',
        'description' => 'Attach a resource to a curriculum node (idempotent).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/curricmap:managebindings',
    ],
    'local_curricmap_delete_resource' => [
        'classname' => 'local_curricmap\external\delete_resource',
        'description' => 'Delete a node resource by id.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/curricmap:managebindings',
    ],
    'local_curricmap_list_resources' => [
        'classname' => 'local_curricmap\external\list_resources',
        'description' => 'List node resources by node and/or type, with optional course scope.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/curricmap:viewstaffmeta',
    ],
    'local_curricmap_list_resource_types' => [
        'classname' => 'local_curricmap\external\list_resource_types',
        'description' => 'The suggested resource type vocabulary.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/curricmap:viewstaffmeta',
    ],
];

$services = [
    'Curriculum mapping API' => [
        'shortname' => 'curricmap_mapping',
        'enabled' => 1,
        'restrictedusers' => 1,
        'downloadfiles' => 0,
        'uploadfiles' => 0,
        'functions' => [
            'local_curricmap_get_programmes',
            'local_curricmap_get_children',
            'local_curricmap_search',
            'local_curricmap_bind',
            'local_curricmap_unbind',
            'local_curricmap_resolve',
            'local_curricmap_list_bindings',
            'local_curricmap_add_resource',
            'local_curricmap_delete_resource',
            'local_curricmap_list_resources',
            'local_curricmap_list_resource_types',
            // Core lookups so one token can browse Moodle structure to build
            // binding addresses (independent of the Mobile service).
            'core_course_get_categories',
            'core_course_get_courses',
            'core_course_get_contents',
        ],
    ],
];
