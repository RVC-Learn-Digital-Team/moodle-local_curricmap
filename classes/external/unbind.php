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
 * Delete a binding by id.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unbind extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Binding id'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $id Binding id.
     * @return array
     */
    public static function execute(int $id): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);

        $binding = $DB->get_record('local_curricmap_binding', ['id' => $params['id']], '*', MUST_EXIST);
        $address = [
            'categoryid' => $binding->categoryid !== null ? (int) $binding->categoryid : null,
            'courseid' => $binding->courseid !== null ? (int) $binding->courseid : null,
        ];
        if ($binding->scope === 'central') {
            $context = \context_system::instance();
        } else {
            try {
                $context = bindings::address_context($address);
            } catch (\moodle_exception $e) {
                // Orphaned binding whose Moodle end is gone: manage centrally.
                $context = \context_system::instance();
            }
        }
        self::validate_context($context);
        require_capability('local/curricmap:managebindings', $context);

        bindings::unbind((int) $binding->id);
        return ['deleted' => true];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'deleted' => new external_value(PARAM_BOOL, 'Whether the binding was deleted'),
        ]);
    }
}
