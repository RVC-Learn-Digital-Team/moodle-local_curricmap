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
 * List enabled programmes (the picker's top level).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_programmes extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id providing the permission context'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $courseid Course id for the capability check.
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/curricmap:viewstaffmeta', $context);

        $out = [];
        foreach (curriculum::programmes() as $programme) {
            $out[] = [
                'id' => (int) $programme->id,
                'slug' => $programme->slug,
                'versionlabel' => $programme->versionlabel,
            ];
        }
        return $out;
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Programme id'),
            'slug' => new external_value(PARAM_TEXT, 'Programme slug'),
            'versionlabel' => new external_value(PARAM_TEXT, 'Tracked version label'),
        ]));
    }
}
