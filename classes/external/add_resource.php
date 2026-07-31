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
 * Attach a resource to a curriculum node (idempotent on node+url+course scope).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_resource extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'nodeuuid' => new external_value(PARAM_ALPHANUMEXT, 'Composed node key'),
            'type' => new external_value(PARAM_TEXT, 'Free-string type, e.g. Panopto'),
            'label' => new external_value(PARAM_TEXT, 'Display label'),
            'url' => new external_value(PARAM_URL, 'Resource URL'),
            'courseid' => new external_value(PARAM_INT, 'Course scope (0 = institutional)', VALUE_DEFAULT, 0),
            'sortorder' => new external_value(PARAM_INT, 'Display order within the node', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $nodeuuid Composed node key.
     * @param string $type Free-string type.
     * @param string $label Display label.
     * @param string $url Resource URL.
     * @param int $courseid Course scope, 0 for institutional.
     * @param int $sortorder Display order within the node.
     * @return array
     */
    public static function execute(
        string $nodeuuid,
        string $type,
        string $label,
        string $url,
        int $courseid = 0,
        int $sortorder = 0
    ): array {
        if (!resources::enabled()) {
            throw new \moodle_exception('errorresourcesdisabled', 'local_curricmap');
        }
        $data = [
            'nodeuuid' => $nodeuuid,
            'type' => $type,
            'label' => $label,
            'url' => $url,
            'courseid' => $courseid,
            'sortorder' => $sortorder,
        ];
        $params = self::validate_parameters(self::execute_parameters(), $data);

        $context = $params['courseid'] > 0
            ? \context_course::instance($params['courseid']) : \context_system::instance();
        self::validate_context($context);
        if (!resources::can_manage($params['courseid'] > 0 ? $params['courseid'] : null)) {
            throw new \required_capability_exception(
                $context,
                $params['courseid'] > 0 ? 'local/curricmap:managecourseresources' : 'local/curricmap:managebindings',
                'nopermissions',
                ''
            );
        }

        // The strict lock: course staff attach resources only within the
        // course's centrally mapped scope. Central managers are unrestricted
        // (so the platform engine and admin tooling can attach anywhere).
        $iscentraluser = has_capability('local/curricmap:managebindings', \context_system::instance());
        if ($params['courseid'] > 0 && !$iscentraluser) {
            if (!resources::within_course_scope($params['nodeuuid'], $params['courseid'])) {
                throw new \moodle_exception('errorresourcescope', 'local_curricmap');
            }
        }

        $id = resources::add(
            $params['nodeuuid'],
            $params['type'],
            $params['label'],
            $params['url'],
            $params['courseid'] > 0 ? $params['courseid'] : null,
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
            'id' => new external_value(PARAM_INT, 'Resource id (existing id when the resource already existed)'),
        ]);
    }
}
