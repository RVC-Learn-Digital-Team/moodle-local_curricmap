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
use local_curricmap\api\resources;

/**
 * List node resources filtered by node, type, or both — with or without a
 * course scope (institutional rows always included when a course is given).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_resources extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'nodeuuid' => new external_value(PARAM_ALPHANUMEXT, 'Composed node key', VALUE_DEFAULT, ''),
            'type' => new external_value(PARAM_TEXT, 'Resource type, case-insensitive', VALUE_DEFAULT, ''),
            'courseid' => new external_value(PARAM_INT, 'Course scope (0 = institutional only)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $nodeuuid Composed node key, empty for any node.
     * @param string $type Resource type, empty for any type.
     * @param int $courseid Course scope, 0 for institutional only.
     * @return array
     */
    public static function execute(string $nodeuuid = '', string $type = '', int $courseid = 0): array {
        if (!resources::enabled()) {
            // The master switch is off: readers degrade to an empty list so
            // every consumer (filter, presenter, tiny dialog) shows nothing
            // without needing its own guard.
            return [];
        }
        $data = ['nodeuuid' => $nodeuuid, 'type' => $type, 'courseid' => $courseid];
        $params = self::validate_parameters(self::execute_parameters(), $data);

        if ($params['nodeuuid'] === '' && $params['type'] === '') {
            throw new \invalid_parameter_exception('One of nodeuuid or type is required.');
        }

        $context = $params['courseid'] > 0
            ? \context_course::instance($params['courseid']) : \context_system::instance();
        self::validate_context($context);
        require_capability('local/curricmap:viewstaffmeta', $context);

        // Hidden rows are included (with their visible flag) — this is a
        // staff/integration listing, not a viewer render path.
        $found = resources::query(
            $params['nodeuuid'] !== '' ? $params['nodeuuid'] : null,
            $params['type'] !== '' ? $params['type'] : null,
            $params['courseid'] > 0 ? $params['courseid'] : null,
            true
        );
        return helper::export_resources($found);
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure(helper::resource_structure()));
    }
}
