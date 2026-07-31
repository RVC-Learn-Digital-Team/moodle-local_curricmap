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

/**
 * List bindings by course or by node.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_bindings extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'List a course\'s bindings (0 = unset)', VALUE_DEFAULT, 0),
            'nodeuuid' => new external_value(PARAM_ALPHANUMEXT, 'List a node\'s bindings', VALUE_DEFAULT, ''),
            'relation' => new external_value(PARAM_ALPHANUMEXT, 'Restrict to one relation', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $courseid Course id, 0 when listing by node.
     * @param string $nodeuuid Composed node key, empty when listing by course.
     * @param string $relation Restrict to one relation, empty for all.
     * @return array
     */
    public static function execute(int $courseid = 0, string $nodeuuid = '', string $relation = ''): array {
        $data = ['courseid' => $courseid, 'nodeuuid' => $nodeuuid, 'relation' => $relation];
        $params = self::validate_parameters(self::execute_parameters(), $data);

        if ($params['courseid'] <= 0 && $params['nodeuuid'] === '') {
            throw new \invalid_parameter_exception('One of courseid or nodeuuid is required.');
        }

        $context = $params['courseid'] > 0
            ? \context_course::instance($params['courseid']) : \context_system::instance();
        self::validate_context($context);
        require_capability('local/curricmap:viewstaffmeta', $context);

        $relationfilter = $params['relation'] !== '' ? $params['relation'] : null;
        if ($params['courseid'] > 0) {
            $found = bindings::for_course($params['courseid']);
            if ($relationfilter !== null) {
                $found = array_values(array_filter($found, fn($b) => $b->relation === $relationfilter));
            }
            if ($params['nodeuuid'] !== '') {
                $found = array_values(array_filter($found, fn($b) => $b->nodeuuid === $params['nodeuuid']));
            }
        } else {
            $found = bindings::for_node($params['nodeuuid'], $relationfilter);
        }
        // Note export_bindings() only exports the node payload for bindings that
        // arrive with ->node attached, and the api readers return bare rows -
        // so this ws had returned node-less bindings since it shipped, and the
        // tiny resources tab (which requires binding.node) always saw an empty
        // list. Attach live nodes here; dead ones stay unattached, which
        // consumers already treat as unusable.
        foreach ($found as $binding) {
            $node = curriculum::node($binding->nodeuuid);
            if ($node && empty($node->deleted)) {
                $binding->node = $node;
            }
        }
        return helper::export_bindings($found);
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure(helper::binding_structure()));
    }
}
