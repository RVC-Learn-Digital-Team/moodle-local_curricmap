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
use core_external\external_single_structure;
use core_external\external_value;
use local_curricmap\api\bindings;

/**
 * Create a binding between a Moodle address and a curriculum node (idempotent).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bind extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'nodeuuid' => new external_value(PARAM_ALPHANUMEXT, 'Composed node key'),
            'categoryid' => new external_value(PARAM_INT, 'Category id (0 = unset)', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Course id (0 = unset)', VALUE_DEFAULT, 0),
            'sectionid' => new external_value(PARAM_INT, 'Course section id (0 = unset)', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course module id (0 = unset)', VALUE_DEFAULT, 0),
            'component' => new external_value(PARAM_COMPONENT, 'Sub-activity component', VALUE_DEFAULT, ''),
            'subitemid' => new external_value(PARAM_INT, 'Sub-activity id (0 = unset)', VALUE_DEFAULT, 0),
            'relation' => new external_value(PARAM_ALPHANUMEXT, 'Relation verb', VALUE_DEFAULT, 'related'),
            'scope' => new external_value(PARAM_ALPHA, 'central or course', VALUE_DEFAULT, 'course'),
            'sortorder' => new external_value(PARAM_INT, 'Order among bindings sharing the address', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $nodeuuid Composed node key.
     * @param int $categoryid Category id, 0 when unset.
     * @param int $courseid Course id, 0 when unset.
     * @param int $sectionid Section id, 0 when unset.
     * @param int $cmid Course module id, 0 when unset.
     * @param string $component Sub-activity component, empty when unset.
     * @param int $subitemid Sub-activity id, 0 when unset.
     * @param string $relation Relation verb.
     * @param string $scope central or course.
     * @param int $sortorder Order among bindings sharing the address.
     * @return array
     */
    public static function execute(
        string $nodeuuid,
        int $categoryid = 0,
        int $courseid = 0,
        int $sectionid = 0,
        int $cmid = 0,
        string $component = '',
        int $subitemid = 0,
        string $relation = 'related',
        string $scope = 'course',
        int $sortorder = 0
    ): array {
        $data = [
            'nodeuuid' => $nodeuuid,
            'categoryid' => $categoryid,
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'cmid' => $cmid,
            'component' => $component,
            'subitemid' => $subitemid,
            'relation' => $relation,
            'scope' => $scope,
            'sortorder' => $sortorder,
        ];
        $params = self::validate_parameters(self::execute_parameters(), $data);

        $address = bindings::normalise_address($params);
        $context = $params['scope'] === 'central'
            ? \context_system::instance() : bindings::address_context($address);
        self::validate_context($context);
        require_capability('local/curricmap:managebindings', $context);

        $id = bindings::bind(
            $address,
            $params['nodeuuid'],
            $params['relation'],
            $params['scope'],
            $params['sortorder']
        );
        return ['id' => $id];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Binding id (existing id when the binding already existed)'),
        ]);
    }
}
