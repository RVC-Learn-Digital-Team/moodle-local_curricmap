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
use local_curricmap\local\contentmap;
use local_curricmap\local\matcher;

/**
 * Scored curriculum hints for the content being edited.
 *
 * The deepest mapping in the location's cascade (book chapter -> activity ->
 * section -> course anchors; blocks are course grain by design) supplies the
 * candidate pool: the mapped nodes themselves plus their subtrees at the
 * content grains. The LIVE title and text from the editor are matched against
 * that pool (title containment + body-text signal, synonyms expanded) so the
 * dialog can lead with the objectives this content already talks about.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_hints extends external_api {
    /** @var int Pool entries returned for listing (hints are never capped). */
    const LIST_CAP = 100;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Course section id (0 = unset)', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course module id (0 = unset)', VALUE_DEFAULT, 0),
            'component' => new external_value(PARAM_COMPONENT, 'Sub-activity component', VALUE_DEFAULT, ''),
            'subitemid' => new external_value(PARAM_INT, 'Sub-activity id (0 = unset)', VALUE_DEFAULT, 0),
            'title' => new external_value(PARAM_RAW, 'Live title of the content being edited', VALUE_DEFAULT, ''),
            'text' => new external_value(PARAM_RAW, 'Live html of the content being edited', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $courseid Course id.
     * @param int $sectionid Section id, 0 when unset.
     * @param int $cmid Course module id, 0 when unset.
     * @param string $component Sub-activity component, empty when unset.
     * @param int $subitemid Sub-activity id, 0 when unset.
     * @param string $title Live title, may be empty.
     * @param string $text Live html, may be empty.
     * @return array
     */
    public static function execute(
        int $courseid,
        int $sectionid = 0,
        int $cmid = 0,
        string $component = '',
        int $subitemid = 0,
        string $title = '',
        string $text = ''
    ): array {
        $data = [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'cmid' => $cmid,
            'component' => $component,
            'subitemid' => $subitemid,
            'title' => $title,
            'text' => $text,
        ];
        $params = self::validate_parameters(self::execute_parameters(), $data);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/curricmap:viewstaffmeta', $context);

        // The deepest mapping in the cascade is the pool root: resolve()
        // already walks chapter -> activity -> section -> course.
        $address = [
            'courseid' => $params['courseid'],
            'sectionid' => $params['sectionid'],
            'cmid' => $params['cmid'],
            'component' => $params['component'],
            'subitemid' => $params['subitemid'],
        ];
        $roots = [];
        foreach (bindings::resolve($address) as $binding) {
            if ($binding->node && empty($binding->node->deleted)) {
                $roots[$binding->node->uuid] = $binding->node;
            }
        }
        if (!$roots) {
            return ['roots' => [], 'hints' => [], 'pool' => [], 'pooltotal' => 0, 'capped' => false];
        }

        // Pool: the mapped nodes THEMSELVES first (content may teach the
        // whole mapped node - the spine-book lesson), then their subtrees at
        // the content grains, in curriculum order.
        $rules = matcher::rules();
        $pool = [];
        foreach ($roots as $node) {
            $pool[] = (object) ['node' => $node, 'tokens' => matcher::tokens((string) $node->title)];
        }
        foreach (matcher::content_candidates(array_keys($roots), contentmap::TARGET_ROLES) as $candidate) {
            if (!isset($roots[$candidate->node->uuid])) {
                $pool[] = $candidate;
            }
        }

        // Live text: html to plain, capped like stored body matching.
        $bodytext = '';
        if ($params['text'] !== '') {
            $bodytext = \core_text::substr(content_to_text($params['text'], FORMAT_HTML), 0, 8000);
        }
        $hints = contentmap::merged_hints((string) $params['title'], $bodytext, $pool, $rules);

        $pooltotal = count($pool);
        $capped = $pooltotal > self::LIST_CAP;
        $listed = array_slice($pool, 0, self::LIST_CAP);

        $hintnodes = self::export_with_programme(array_map(fn($hint) => $hint->candidate->node, $hints));
        foreach ($hints as $index => $hint) {
            $hintnodes[$index]['score'] = (int) round($hint->score * 100);
            $hintnodes[$index]['frombody'] = !empty($hint->frombody);
        }

        return [
            'roots' => self::export_with_programme(array_values($roots)),
            'hints' => $hintnodes,
            'pool' => self::export_with_programme(array_map(fn($candidate) => $candidate->node, $listed)),
            'pooltotal' => $pooltotal,
            'capped' => $capped,
        ];
    }

    /**
     * Export nodes with programmeid included (the browse walker needs it).
     *
     * @param \stdClass[] $nodes Node records.
     * @return array[]
     */
    private static function export_with_programme(array $nodes): array {
        $out = helper::export_nodes($nodes);
        foreach (array_values($nodes) as $index => $node) {
            $out[$index]['programmeid'] = (int) $node->programmeid;
        }
        return $out;
    }

    /**
     * Node structure plus programmeid.
     *
     * @return array
     */
    private static function hint_node_structure(): array {
        $structure = helper::node_structure();
        $structure['programmeid'] = new external_value(PARAM_INT, 'Owning programme id');
        return $structure;
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $hintstructure = self::hint_node_structure();
        $hintstructure['score'] = new external_value(PARAM_INT, 'Match strength percent');
        $hintstructure['frombody'] = new external_value(PARAM_BOOL, 'Matched from body text rather than the title');
        return new external_single_structure([
            'roots' => new external_multiple_structure(
                new external_single_structure(self::hint_node_structure()),
                'The deepest mapped nodes the pool grows from'
            ),
            'hints' => new external_multiple_structure(
                new external_single_structure($hintstructure),
                'Scored matches of the live title/text, best first'
            ),
            'pool' => new external_multiple_structure(
                new external_single_structure(self::hint_node_structure()),
                'The candidate pool in curriculum order, capped for listing'
            ),
            'pooltotal' => new external_value(PARAM_INT, 'Uncapped pool size'),
            'capped' => new external_value(PARAM_BOOL, 'Whether the pool listing was capped'),
        ]);
    }
}
