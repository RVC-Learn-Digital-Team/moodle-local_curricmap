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

namespace local_curricmap\local;

use local_curricmap\api\bindings;
use local_curricmap\api\curriculum;
use local_curricmap\api\resources;

/**
 * Selectable evidence and curriculum browsing for one content location.
 *
 * @package local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_picker {
    /**
     * Readable labels with curriculum ancestry, including programme and session.
     *
     * @param array $nodes Curriculum nodes.
     * @return array UUID => label.
     */
    public static function labels(array $nodes): array {
        global $DB;
        $ids = [];
        $programmeids = [];
        foreach ($nodes as $node) {
            if (!empty($node->programmeid)) {
                $programmeids[(int) $node->programmeid] = (int) $node->programmeid;
            }
            foreach (explode('/', (string) $node->path) as $id) {
                if ($id !== '') {
                    $ids[(int) $id] = (int) $id;
                }
            }
        }
        $ancestors = $ids ? $DB->get_records_list('local_curricmap_node', 'id', array_values($ids)) : [];
        $programmes = $programmeids
            ? $DB->get_records_list('local_curricmap_programme', 'id', array_values($programmeids)) : [];
        $labels = [];
        foreach ($nodes as $node) {
            $programme = $programmes[$node->programmeid ?? 0] ?? null;
            $path = $programme ? [$programme->displayname ?: $programme->slug] : [];
            foreach (explode('/', (string) $node->path) as $id) {
                if (isset($ancestors[$id]) && (int) $id !== (int) $node->id) {
                    $path[] = $ancestors[$id]->title;
                }
            }
            $labels[$node->uuid] = contentmap::label($node) . ($path ? ' — ' . implode(' › ', $path) : '');
        }
        return $labels;
    }

    /**
     * One independently selectable node.
     *
     * @param string $key Content row key.
     * @param \stdClass $node Candidate.
     * @param string $label Full label.
     * @param string $evidence Escaped/formatted evidence.
     * @return string HTML.
     */
    private static function candidate(string $key, \stdClass $node, string $label, string $evidence = ''): string {
        $button = \html_writer::tag('button', get_string('contentmapping_pick', 'local_curricmap'), [
            'type' => 'button', 'class' => 'btn btn-outline-primary btn-sm',
            'data-curricmap-pick' => $node->uuid, 'data-curricmap-key' => $key, 'data-curricmap-picklabel' => $label,
        ]);
        $content = \html_writer::div(s($label) . $evidence, 'curricmap-candidate-text') . $button;
        return \html_writer::div($content, 'curricmap-candidate');
    }

    /**
     * Render saved choices, selectable suggestions and the searchable browser.
     *
     * @param string $key Content key.
     * @param array $current Explicit active anchor bindings.
     * @param array $hints Scored candidates.
     * @param array $pool Permitted candidates.
     * @param string $name Source title.
     * @param string $text Source text.
     * @return string HTML.
     */
    public static function editor(string $key, array $current, array $hints, array $pool, string $name, string $text): string {
        $nodes = [];
        foreach ($pool as $candidate) {
            $nodes[$candidate->node->uuid] = $candidate->node;
        }
        foreach ($current as $binding) {
            $node = curriculum::node($binding->nodeuuid);
            if ($node) {
                $nodes[$node->uuid] = $node;
            }
        }
        $labels = self::labels($nodes);
        $saved = [];
        foreach ($current as $binding) {
            $saved[$binding->nodeuuid] = ['uuid' => $binding->nodeuuid,
                'label' => $labels[$binding->nodeuuid] ?? $binding->nodeuuid];
        }
        $choices = \html_writer::tag('strong', get_string('contentmapping_choices', 'local_curricmap'));
        $choices .= \html_writer::div('', 'curricmap-choices', ['data-curricmap-choices' => '']);
        $choices .= \html_writer::tag('button', get_string('savechanges'), [
            'type' => 'button', 'class' => 'btn btn-primary', 'data-curricmap-save' => '', 'disabled' => 'disabled',
        ]) . ' ' . \html_writer::tag('button', get_string('cancel'), [
            'type' => 'button', 'class' => 'btn btn-secondary', 'data-curricmap-cancel' => '', 'disabled' => 'disabled',
        ]);
        $choices .= \html_writer::div('', 'mt-2', ['data-curricmap-save-status' => '', 'role' => 'status']);
        $byid = [];
        foreach ($hints as $hint) {
            $byid[$hint->candidate->node->id] = $hint;
        }
        $render = function ($node) use ($key, $byid, $labels, $name, $text): string {
            $hint = $byid[$node->id] ?? null;
            if ($hint) {
                $source = empty($hint->frombody) ? $name : $text;
                $needle = matcher::strip_types($node->title, matcher::rules());
                $position = \core_text::strpos(\core_text::strtolower($source), \core_text::strtolower($needle));
                if ($position !== false && $position > 80) {
                    $source = '…' . \core_text::substr($source, $position - 80);
                }
                $evidence = get_string('contentmapping_source', 'local_curricmap') . ': ' . shorten_text($source, 240);
            } else {
                $evidence = get_string('contentmapping_relatedoutcome', 'local_curricmap');
            }
            return self::candidate($key, $node, $labels[$node->uuid], \html_writer::div(s($evidence), 'small mt-1'));
        };
        $suggestions = '';
        foreach ($hints as $hint) {
            $node = $hint->candidate->node;
            // Outcomes appear beneath their suggested parent session, without being selected automatically.
            if (isset($byid[$node->parentid]) && $byid[$node->parentid]->candidate->node->role === 'session') {
                continue;
            }
            $suggestions .= $render($node);
            if ($node->role === 'session') {
                $children = '';
                foreach ($pool as $candidate) {
                    $child = $candidate->node;
                    if ((int) $child->parentid === (int) $node->id && $child->role === 'sessionoutcome') {
                        $children .= $render($child);
                    }
                }
                if ($children !== '') {
                    $suggestions .= \html_writer::div($children, 'curricmap-related');
                }
            }
        }
        $suggestions = $suggestions ?: \html_writer::tag('p', get_string('contentmapping_nosuggestions', 'local_curricmap'));
        $out = \html_writer::div($choices, 'curricmap-selection');
        $summary = \html_writer::tag('summary', get_string('contentmapping_evidence', 'local_curricmap'));
        $out .= \html_writer::tag('details', $summary . $suggestions, ['open' => 'open']);
        $out .= \html_writer::tag('button', get_string('contentmapping_browse', 'local_curricmap'), [
            'type' => 'button', 'class' => 'btn btn-outline-primary my-2', 'data-curricmap-open-browser' => '',
            'aria-expanded' => 'false', 'aria-controls' => 'curricmap-browser-' . $key,
        ]);
        $searchid = 'curricmap-query-' . $key;
        $browser = \html_writer::tag('label', get_string('contentmapping_search', 'local_curricmap'), ['for' => $searchid]);
        $browser .= \html_writer::empty_tag('input', ['id' => $searchid, 'type' => 'search', 'class' => 'form-control',
            'data-curricmap-query' => '', 'placeholder' => get_string('contentmapping_search', 'local_curricmap')]);
        $browser .= \html_writer::div('', 'mt-2', ['data-curricmap-results' => '', 'aria-live' => 'polite']);
        $out .= \html_writer::div($browser, 'curricmap-browser', ['data-curricmap-browser' => '',
            'id' => 'curricmap-browser-' . $key, 'hidden' => 'hidden']);
        return \html_writer::div($out, 'curricmap-editor', ['data-curricmap-editor' => $key,
            'data-saved' => json_encode(array_values($saved))]);
    }

    /**
     * Search the complete course curriculum or browse its permitted hierarchy.
     *
     * @param int $courseid Course whose central anchors delimit the search.
     * @param string $key Content location key.
     * @param string $rootuuid Browsed node; empty for course anchors.
     * @param string $query Search terms; empty to browse.
     * @return string HTML.
     */
    public static function browse(int $courseid, string $key, string $rootuuid, string $query): string {
        $anchors = bindings::anchors($courseid);
        $nodes = [];
        $out = '';
        if ($query !== '') {
            foreach ($anchors as $anchor) {
                foreach (curriculum::search(0, $query, null, 51, $anchor->uuid) as $node) {
                    $nodes[$node->uuid] = $node;
                }
            }
            $out = \html_writer::tag('p', get_string('contentmapping_searchresults', 'local_curricmap'));
            if (count($nodes) > 50) {
                $out .= \html_writer::tag('p', get_string('contentmapping_searchlimit', 'local_curricmap'));
                $nodes = array_slice($nodes, 0, 50, true);
            }
        } else if ($rootuuid !== '') {
            if (!resources::within_course_scope($rootuuid, $courseid)) {
                throw new \moodle_exception('errorbindnode', 'local_curricmap', '', s($rootuuid));
            }
            $out = \html_writer::tag('button', get_string('contentmapping_browseroot', 'local_curricmap'), [
                'type' => 'button', 'class' => 'btn btn-link', 'data-curricmap-drill' => '',
            ]);
            $root = curriculum::node($rootuuid);
            $nodes[$root->uuid] = $root;
            foreach (curriculum::children($rootuuid) as $node) {
                $nodes[$node->uuid] = $node;
            }
        } else {
            foreach ($anchors as $node) {
                $nodes[$node->uuid] = $node;
            }
        }
        $labels = self::labels($nodes);
        foreach ($nodes as $node) {
            $entry = self::candidate($key, $node, $labels[$node->uuid]);
            if (!in_array($node->role, ['sessionoutcome', 'strandoutcome']) && $node->uuid !== $rootuuid) {
                $entry .= \html_writer::tag('button', get_string('contentmapping_browsechildren', 'local_curricmap'), [
                    'type' => 'button', 'class' => 'btn btn-link btn-sm', 'data-curricmap-drill' => $node->uuid,
                ]);
            }
            $out .= \html_writer::div($entry, 'curricmap-search-result');
        }
        return $out . (!$nodes ? \html_writer::tag('p', get_string('contentmapping_noresults', 'local_curricmap')) : '');
    }
}
