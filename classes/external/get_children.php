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
use local_curricmap\api\curriculum;

/**
 * Browse the curriculum tree one level at a time (the picker backend).
 *
 * With an empty parentuuid, returns the programme's year nodes; otherwise the
 * children of the given node. Capability-checked in the calling course context.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_children extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id providing the permission context'),
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'parentuuid' => new external_value(PARAM_ALPHANUMEXT, 'Parent node uuid, empty for years',
                VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $courseid Course id for the capability check.
     * @param int $programmeid Programme id.
     * @param string $parentuuid Parent node uuid, empty string for year level.
     * @return array
     */
    public static function execute(int $courseid, int $programmeid, string $parentuuid = ''): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['courseid' => $courseid, 'programmeid' => $programmeid, 'parentuuid' => $parentuuid]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/curricmap:viewstaffmeta', $context);

        if ($params['parentuuid'] === '') {
            $nodes = curriculum::years($params['programmeid']);
        } else {
            $nodes = curriculum::children($params['parentuuid']);
        }
        return helper::export_nodes($nodes);
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure(helper::node_structure()));
    }
}
