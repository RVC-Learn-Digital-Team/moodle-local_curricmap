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

namespace local_curricmap\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_curricmap\api\bindings;
use local_curricmap\api\curriculum;
use local_curricmap\api\resources;
use local_curricmap\local\content_picker;
use local_curricmap\local\content_tree;

/**
 * Atomically save the explicit anchor mappings at one course content location.
 *
 * @package local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_content_mappings extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'key' => new external_value(PARAM_ALPHANUMEXT, 'Section, activity or chapter key'),
            'nodeuuids' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Node UUID')),
        ]);
    }

    /**
     * Save just this location; course affiliations and other relations are untouched.
     *
     * @param int $courseid Course id.
     * @param string $key Content key.
     * @param array $nodeuuids Complete set of chosen anchors, including retained mappings.
     * @return array Saved choices.
     */
    public static function execute(int $courseid, string $key, array $nodeuuids): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), compact('courseid', 'key', 'nodeuuids'));
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/curricmap:managebindings', $context);
        $address = content_tree::address_for_key($params['courseid'], $params['key']);
        $nodes = [];
        foreach (array_unique($params['nodeuuids']) as $uuid) {
            if (!resources::within_course_scope($uuid, $params['courseid'])) {
                throw new \moodle_exception('errorbindnode', 'local_curricmap', '', s($uuid));
            }
            $nodes[$uuid] = curriculum::node($uuid);
        }
        $transaction = $DB->start_delegated_transaction();
        // Match the same grain as resolve(): API bindings may also carry redundant ancestor fields.
        $location = ['courseid' => $address['courseid'], 'cmid' => $address['cmid'],
            'component' => $address['component'], 'subitemid' => $address['subitemid']];
        if (!$address['cmid']) {
            $location['sectionid'] = $address['sectionid'];
        }
        $existing = $DB->get_records('local_curricmap_binding', $location + ['relation' => bindings::RELATION_ANCHOR]);
        $retained = [];
        foreach ($existing as $binding) {
            if (!isset($nodes[$binding->nodeuuid]) || $binding->status !== 'active') {
                bindings::unbind((int) $binding->id);
            } else {
                $retained[$binding->nodeuuid] = true;
            }
        }
        foreach ($nodes as $node) {
            if (!isset($retained[$node->uuid])) {
                bindings::bind($address, $node->uuid, bindings::RELATION_ANCHOR, 'course');
            }
        }
        $transaction->allow_commit();
        $labels = content_picker::labels($nodes);
        $saved = [];
        foreach ($nodes as $node) {
            $saved[] = ['uuid' => $node->uuid, 'label' => $labels[$node->uuid]];
        }
        return ['mappings' => $saved];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'mappings' => new external_multiple_structure(new external_single_structure([
                'uuid' => new external_value(PARAM_ALPHANUMEXT, 'Saved node UUID'),
                'label' => new external_value(PARAM_TEXT, 'Readable curriculum label'),
            ])),
        ]);
    }
}
