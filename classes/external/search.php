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
 * Search curriculum nodes by title or code (the picker's search box).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id providing the permission context'),
            'programmeid' => new external_value(PARAM_INT, 'Programme id, 0 for all enabled programmes'),
            'query' => new external_value(PARAM_TEXT, 'Search text'),
            'roles' => new external_multiple_structure(
                new external_value(PARAM_ALPHA, 'Role to include'),
                'Restrict results to these roles; empty for all',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $courseid Course id for the capability check.
     * @param int $programmeid Programme id (0 = all enabled).
     * @param string $query Search text.
     * @param array $roles Roles to include; empty for all.
     * @return array
     */
    public static function execute(int $courseid, int $programmeid, string $query, array $roles = []): array {
        $data = ['courseid' => $courseid, 'programmeid' => $programmeid, 'query' => $query, 'roles' => $roles];
        $params = self::validate_parameters(self::execute_parameters(), $data);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/curricmap:viewstaffmeta', $context);

        $roles = $params['roles'] ? array_values($params['roles']) : null;
        return helper::export_nodes(curriculum::search($params['programmeid'], $params['query'], $roles));
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
