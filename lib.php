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
 * Add the per-course "Add Additional Mappings" page to the course navigation.
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
 * Add a "Map to Sofia curriculum" item to each supported activity's More
 * menu (ruled 2026-08-06): the activity-level sibling of the course item
 * above, offered only for module types on the mappablemodtypes setting,
 * linking to the course's Add Additional Mappings page with the activity
 * preselected as the location.
 *
 * @param settings_navigation $settingsnav The settings navigation.
 * @param context|null $context The current context.
 */
function local_curricmap_extend_settings_navigation(settings_navigation $settingsnav, ?context $context): void {
    global $PAGE;
    if (!$context || $context->contextlevel !== CONTEXT_MODULE || !$PAGE->cm) {
        return;
    }
    $mappable = array_filter(explode(',', (string) get_config('local_curricmap', 'mappablemodtypes')));
    if (!in_array($PAGE->cm->modname, $mappable, true)) {
        return;
    }
    if (!has_capability('local/curricmap:viewstaffmeta', context_course::instance((int) $PAGE->cm->course))) {
        return;
    }
    $modulenode = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$modulenode) {
        return;
    }
    $url = new moodle_url('/local/curricmap/mappings.php',
        ['courseid' => (int) $PAGE->cm->course, 'cmid' => (int) $PAGE->cm->id]);
    $modulenode->add(
        get_string('mappings_mapactivity', 'local_curricmap'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'curricmapactivity'
    );
}

/**
 * Fragment: one section's activity mapping rows for the Moodle Course Mapping page
 * (loaded lazily when the admin opens a section's "Map activities").
 *
 * @param array $args courseid, sectionid, modtypes/nodetypes/pending (csv), returnurl.
 * @return string HTML rows.
 */
function local_curricmap_output_fragment_activities(array $args): string {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $course = get_course((int) $args['courseid']);
    $modtypes = array_filter(explode(',', (string) ($args['modtypes'] ?? '')));
    $returnurl = (string) ($args['returnurl'] ?? '/local/curricmap/section_module_mapping.php?courseid=' . $course->id);
    $nodetypes = array_filter(explode(',', (string) ($args['nodetypes'] ?? '')));
    $pending = array_filter(explode(',', (string) ($args['pending'] ?? '')));
    $pending = array_slice(array_map(fn($uuid) => clean_param($uuid, PARAM_RAW_TRIMMED), $pending), 0, 20);
    return \local_curricmap\local\contentmap::activity_rows(
        $course,
        (int) $args['sectionid'],
        $modtypes,
        $returnurl,
        $nodetypes,
        $pending
    );
}

/**
 * Fragment: one level of the curriculum tree for a mapping row's Browse panel.
 *
 * @param array $args courseid, root (node uuid), key (row key).
 * @return string HTML.
 */
function local_curricmap_output_fragment_browsenode(array $args): string {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $root = clean_param((string) ($args['root'] ?? ''), PARAM_RAW_TRIMMED);
    $key = clean_param((string) ($args['key'] ?? ''), PARAM_ALPHANUMEXT);
    $grain = clean_param((string) ($args['grain'] ?? 'content'), PARAM_ALPHA);
    $year = (int) ($args['year'] ?? 0);
    return \local_curricmap\local\contentmap::browse_panel($root, $key, $grain, $year ?: null);
}
