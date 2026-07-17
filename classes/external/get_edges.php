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
 * Graph extraction: a programme's directed edges (implements, event-outcome,
 * unit-outcome, ...) with both ends as composed keys.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_edges extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programmeid' => new external_value(PARAM_INT, 'Programme id (see get_programmes)'),
            'connectiontype' => new external_value(
                PARAM_TEXT,
                'Restrict to one edge type, e.g. implements',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $programmeid Programme id.
     * @param string $connectiontype Edge type; empty for all.
     * @return array
     */
    public static function execute(int $programmeid, string $connectiontype = ''): array {
        $data = ['programmeid' => $programmeid, 'connectiontype' => $connectiontype];
        $params = self::validate_parameters(self::execute_parameters(), $data);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/curricmap:viewstaffmeta', $context);

        $edges = curriculum::edges(
            $params['programmeid'],
            $params['connectiontype'] !== '' ? $params['connectiontype'] : null
        );
        $out = [];
        foreach ($edges as $edge) {
            $out[] = [
                'sourceuuid' => $edge->sourceuuid,
                'targetuuid' => $edge->targetuuid,
                'connectiontype' => $edge->connectiontype,
                'sortorder' => (int) $edge->sortorder,
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
            'sourceuuid' => new external_value(PARAM_ALPHANUMEXT, 'Source composed key'),
            'targetuuid' => new external_value(PARAM_ALPHANUMEXT, 'Target composed key'),
            'connectiontype' => new external_value(PARAM_TEXT, 'Edge type as Sofia stores it'),
            'sortorder' => new external_value(PARAM_INT, 'Order within the source node\'s connections'),
        ]));
    }
}
