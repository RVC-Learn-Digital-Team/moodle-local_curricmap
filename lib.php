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
 * Moodle callbacks for local_curricmap.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the per-course "Curriculum mappings" page to the course navigation.
 *
 * @param navigation_node $parentnode The course navigation node.
 * @param stdClass $course The course record.
 * @param context_course $context The course context.
 */
function local_curricmap_extend_navigation_course(
    navigation_node $parentnode,
    stdClass $course,
    context_course $context
): void {
    if (!has_capability('local/curricmap:viewstaffmeta', $context)) {
        return;
    }
    $url = new moodle_url('/local/curricmap/mappings.php', ['courseid' => $course->id]);
    $parentnode->add(
        get_string('mappings', 'local_curricmap'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'curricmapmappings'
    );
}

/**
 * Fragment: one section's activity mapping rows for the content mapping page
 * (loaded lazily when the admin opens a section's "Map activities").
 *
 * @param array $args courseid, sectionid, modtypes (csv), returnurl.
 * @return string HTML rows.
 */
function local_curricmap_output_fragment_activities(array $args): string {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $course = get_course((int) $args['courseid']);
    $modtypes = array_filter(explode(',', (string) ($args['modtypes'] ?? '')));
    $returnurl = (string) ($args['returnurl'] ?? '/local/curricmap/section_module_mapping.php?courseid=' . $course->id);
    return \local_curricmap\local\contentmap::activity_rows(
        $course,
        (int) $args['sectionid'],
        $modtypes,
        $returnurl
    );
}
