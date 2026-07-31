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
use local_curricmap\api\resources;

/**
 * Show or hide a node resource (hidden rows are kept but never rendered to
 * viewers).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_resource_visibility extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Resource id'),
            'visible' => new external_value(PARAM_BOOL, 'Whether the resource renders to viewers'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $id Resource id.
     * @param bool $visible Whether the resource renders to viewers.
     * @return array
     */
    public static function execute(int $id, bool $visible): array {
        if (!resources::enabled()) {
            throw new \moodle_exception('errorresourcesdisabled', 'local_curricmap');
        }
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id, 'visible' => $visible]);

        $resource = $DB->get_record('local_curricmap_resource', ['id' => $params['id']], '*', MUST_EXIST);
        if ($resource->courseid !== null) {
            try {
                $context = \context_course::instance((int) $resource->courseid);
            } catch (\moodle_exception $e) {
                // The scoping course is gone: manage centrally.
                $context = \context_system::instance();
            }
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);
        if (!resources::can_manage($resource->courseid !== null ? (int) $resource->courseid : null)) {
            throw new \required_capability_exception(
                $context,
                $resource->courseid !== null
                    ? 'local/curricmap:managecourseresources' : 'local/curricmap:managebindings',
                'nopermissions',
                ''
            );
        }

        resources::set_visible((int) $resource->id, $params['visible']);
        return ['visible' => $params['visible']];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'visible' => new external_value(PARAM_BOOL, 'The stored visibility after the update'),
        ]);
    }
}
