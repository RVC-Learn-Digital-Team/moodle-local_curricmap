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

/**
 * Course-ordered content and mapping controls shared by both entry points.
 *
 * @package local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_tree {
    /** @var \stdClass Course. */
    private $course;
    /** @var \course_modinfo Cached course structure. */
    private $modinfo;
    /** @var string Return URL. */
    private $returnurl;
    /** @var array Mapping buckets. */
    private $buckets;
    /** @var array Subsection instance to delegated section. */
    private $delegated = [];
    /** @var array Descendant counts by section id. */
    private $counts = [];
    /** @var array Activity type filter. */
    private $modtypes;
    /** @var array Curriculum type filter. */
    private $nodetypes;
    /** @var array Unsaved parent mapping selections. */
    private $pendingroots;
    /** @var int Section whose unsaved mappings accompany this fragment. */
    private $pendingsectionid = 0;
    /** @var array Allowed mapping activity types. */
    private $mappable;
    /** @var array Candidate pools cached within the request. */
    private $pools = [];

    /**
     * Prepare the structure without loading activity bodies.
     *
     * @param \stdClass $course Course.
     * @param string $returnurl Shared mapper URL.
     * @param array $modtypes Activity type filter.
     * @param array $nodetypes Curriculum type filter.
     * @param array $pendingroots Unsaved parent mapping selections.
     */
    public function __construct(
        \stdClass $course,
        string $returnurl,
        array $modtypes = [],
        array $nodetypes = [],
        array $pendingroots = []
    ) {
        $this->course = $course;
        $this->returnurl = $returnurl;
        $this->modtypes = $modtypes;
        $this->nodetypes = $nodetypes;
        $this->pendingroots = array_values(array_filter($pendingroots,
            fn($uuid) => \local_curricmap\api\resources::within_course_scope($uuid, (int) $course->id)));
        $this->modinfo = get_fast_modinfo($course);
        $this->buckets = contentmap::buckets((int) $course->id);
        $this->mappable = array_filter(explode(',', (string) get_config('local_curricmap', 'mappablemodtypes')));
        foreach ($this->modinfo->get_section_info_all() as $section) {
            $this->counts[$section->id] = (object) ['activities' => 0, 'mapped' => 0, 'subsections' => 0];
            if (($section->component ?? '') === 'mod_subsection') {
                $this->delegated[$section->itemid] = $section;
            }
        }
        foreach ($this->delegated as $section) {
            if (!$section->uservisible) {
                continue;
            }
            foreach (array_slice(bindings::section_path((int) $course->id, (int) $section->id), 1) as $ancestor) {
                $this->counts[$ancestor]->subsections++;
            }
        }
        foreach ($this->modinfo->cms as $cm) {
            if ($cm->modname === 'subsection' || $cm->deletioninprogress || !$cm->uservisible) {
                continue;
            }
            foreach (bindings::section_path((int) $course->id, (int) $cm->section) as $ancestor) {
                $this->counts[$ancestor]->activities++;
                if (!empty($this->buckets[1][$cm->id])) {
                    $this->counts[$ancestor]->mapped++;
                }
            }
        }

    }

    /**
     * Resolve a submitted row key to a validated course-local address.
     *
     * @param int $courseid Course id.
     * @param string $key Section/activity/chapter key.
     * @return array
     */
    public static function address_for_key(int $courseid, string $key): array {
        global $DB;
        if (!preg_match('/^([sch])([1-9][0-9]*)$/', $key, $matches)) {
            throw new \moodle_exception('errorbindaddress', 'local_curricmap');
        }
        $address = ['courseid' => $courseid];
        $id = (int) $matches[2];
        if ($matches[1] === 's') {
            $address['sectionid'] = $id;
        } else if ($matches[1] === 'c') {
            $address['cmid'] = $id;
        } else {
            $chapter = $DB->get_record('book_chapters', ['id' => $id], 'id, bookid', MUST_EXIST);
            $books = get_fast_modinfo($courseid)->get_instances_of('book');
            $address['cmid'] = isset($books[$chapter->bookid]) ? (int) $books[$chapter->bookid]->id : 0;
            $address['component'] = 'mod_book';
            $address['subitemid'] = $id;
        }
        $address = bindings::normalise_address($address);
        bindings::validate_location($address);
        return $address;
    }

    /**
     * A section branch. Children load on first opening and remain in the form.
     *
     * @param \section_info $section Section.
     * @return string
     */
    public function section(\section_info $section): string {
        $key = 's' . $section->id;
        $name = get_section_name($this->course, $section);
        $tally = $this->counts[$section->id];
        $counttext = get_string('contentmapping_treecounts', 'local_curricmap', $tally);
        $heading = s($name) . ' ' . \html_writer::span($counttext, 'small text-muted');
        if (!$section->visible) {
            $heading .= ' ' . \html_writer::span(get_string('hidden'), 'badge badge-secondary');
        }
        $url = new \moodle_url('/course/section.php', ['id' => $section->id]);
        $body = $this->format((string) $section->summary, (int) $section->summaryformat,
            \context_course::instance($this->course->id), 'course', 'section', (int) $section->id);
        $text = contentmap::section_body_text($this->course, $section);
        $address = ['courseid' => $this->course->id, 'sectionid' => $section->id];
        $row = $this->row($key, $name, $url, $body, $text, $address, $this->buckets[0][$section->id] ?? []);
        $attrs = ['data-curricmap-branch' => $key, 'data-sectionid' => $section->id,
            'class' => 'curricmap-branch', 'id' => 'curricmap-' . $key];
        return \html_writer::tag('details', \html_writer::tag('summary', $heading) . $row
            . \html_writer::div('', 'curricmap-children', ['data-curricmap-children' => $section->id]), $attrs);
    }

    /**
     * Direct children in Moodle sequence order, substituting subsection branches.
     *
     * @param int $sectionid Section id.
     * @return string
     */
    public function children(int $sectionid): string {
        $section = $this->modinfo->get_section_info_by_id($sectionid, MUST_EXIST);
        if (!$section->uservisible) {
            throw new \moodle_exception('errorbindaddress', 'local_curricmap');
        }
        $this->pendingsectionid = $sectionid;
        $out = '';
        foreach ($this->modinfo->sections[$section->section] ?? [] as $cmid) {
            $cm = $this->modinfo->cms[$cmid];
            if ($cm->deletioninprogress || !$cm->uservisible) {
                continue;
            }
            if ($cm->modname === 'subsection') {
                if (isset($this->delegated[$cm->instance]) && $this->delegated[$cm->instance]->uservisible) {
                    $out .= $this->section($this->delegated[$cm->instance]);
                }
            } else if (!$this->modtypes || in_array($cm->modname, $this->modtypes)) {
                $out .= $this->activity($cm);
            }
        }
        return $out ?: \html_writer::tag('p', get_string('contentmapping_norows', 'local_curricmap'));
    }

    /**
     * Activity text and, for books, chapter/subchapter branches.
     *
     * @param \cm_info $cm Activity.
     * @return string
     */
    private function activity(\cm_info $cm): string {
        global $DB;
        $record = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        $body = '';
        foreach ($cm->modname === 'page' ? ['intro', 'content'] : ['intro'] as $field) {
            if (!empty($record->$field)) {
                $formatfield = $field . 'format';
                $body .= $this->format((string) $record->$field, (int) ($record->$formatfield ?? FORMAT_HTML),
                    $cm->context, 'mod_' . $cm->modname, $field, null);
            }
        }
        $address = ['courseid' => $this->course->id, 'cmid' => $cm->id, 'sectionid' => $cm->section];
        $name = format_string($cm->name, true, ['context' => $cm->context]);
        $name = content_to_text($name, FORMAT_HTML);
        $row = $this->row('c' . $cm->id, $name, $cm->url, $body, contentmap::body_text($cm),
            $address, $this->buckets[1][$cm->id] ?? [], in_array($cm->modname, $this->mappable));
        $type = \html_writer::span(s(get_string('modulename', $cm->modname)), 'small text-muted');
        if (!$cm->visible) {
            $type .= ' ' . \html_writer::span(get_string('hidden'), 'badge badge-secondary');
        }
        $out = $type . $row;
        if ($cm->modname === 'book') {
            $chapters = '';
            $parentopen = false;
            foreach ($DB->get_records('book_chapters', ['bookid' => $cm->instance], 'pagenum, id') as $chapter) {
                if ($chapter->hidden && !has_capability('mod/book:viewhiddenchapters', $cm->context)) {
                    continue;
                }
                if (!$chapter->subchapter && $parentopen) {
                    $chapters .= \html_writer::end_tag('details');
                    $parentopen = false;
                }
                $chapterbody = $this->format($chapter->content, (int) $chapter->contentformat,
                    $cm->context, 'mod_book', 'chapter', (int) $chapter->id);
                $chapteraddress = $address + ['component' => 'mod_book', 'subitemid' => $chapter->id];
                $url = new \moodle_url('/mod/book/view.php', ['id' => $cm->id, 'chapterid' => $chapter->id]);
                $chapters .= \html_writer::start_tag('details', ['data-curricmap-branch' => 'h' . $chapter->id,
                    'class' => 'curricmap-branch', 'id' => 'curricmap-h' . $chapter->id]);
                $title = s($chapter->title);
                if ($chapter->hidden) {
                    $title .= ' ' . \html_writer::span(get_string('hidden'), 'badge badge-secondary');
                }
                $chapters .= \html_writer::tag('summary', $title);
                $chapters .= $this->row('h' . $chapter->id, $chapter->title, $url, $chapterbody,
                    content_to_text($chapter->content, $chapter->contentformat), $chapteraddress,
                    $this->buckets[2][$cm->id][$chapter->id] ?? [], in_array('book', $this->mappable));
                if ($chapter->subchapter) {
                    $chapters .= \html_writer::end_tag('details');
                } else {
                    $parentopen = true;
                }
            }
            if ($parentopen) {
                $chapters .= \html_writer::end_tag('details');
            }
            $out .= \html_writer::tag('details', \html_writer::tag('summary',
                get_string('contentmapping_chapters', 'local_curricmap')) . $chapters,
                ['data-curricmap-branch' => 'book' . $cm->id, 'class' => 'curricmap-branch']);
        }
        return \html_writer::div($out, 'curricmap-content-item', ['id' => 'curricmap-c' . $cm->id]);
    }

    /**
     * Format safe previews with the source context and file area; no curriculum filters.
     *
     * @param string $text Source text.
     * @param int $format Source format.
     * @param \context $context Source context.
     * @param string $component Source component.
     * @param string $area File area.
     * @param int|null $itemid File item id.
     * @return string
     */
    private function format(string $text, int $format, \context $context, string $component, string $area, ?int $itemid): string {
        $text = file_rewrite_pluginfile_urls($text, 'pluginfile.php', $context->id, $component, $area, $itemid);
        return format_text($text, $format, ['context' => $context, 'filter' => false, 'noclean' => false, 'blanktarget' => true]);
    }

    /**
     * Shared mapping row with a full content preview and evidence-led suggestions.
     *
     * @param string $key Form key.
     * @param string $name Location name.
     * @param \moodle_url|null $url Location link.
     * @param string $body Formatted preview.
     * @param string $text Matching text.
     * @param array $address Location.
     * @param array $current Explicit mappings.
     * @param bool $mappable Whether the type is enabled for mapping.
     * @return string
     */
    private function row(string $key, string $name, ?\moodle_url $url, string $body, string $text,
            array $address, array $current, bool $mappable = true): string {
        $resolved = bindings::resolve($address, bindings::RELATION_ANCHOR);
        $roots = array_map(fn($b) => $b->nodeuuid, $resolved);
        $nearestsection = $resolved ? (int) $resolved[0]->sectionid : 0;
        if (!$current && $this->pendingroots && (!$nearestsection || $nearestsection === $this->pendingsectionid)) {
            $roots = $this->pendingroots;
        }
        $poolkey = implode(',', $roots);
        if (!isset($this->pools[$poolkey])) {
            $this->pools[$poolkey] = contentmap::filter_pool(
                matcher::content_candidates($roots, array_merge(['strand'], contentmap::TARGET_ROLES), true), $this->nodetypes);
        }
        $pool = $this->pools[$poolkey];
        $hints = contentmap::merged_hints($name, $text, $pool, matcher::rules());
        $namehtml = $url ? \html_writer::link($url, s($name), ['target' => '_blank', 'rel' => 'noopener']) : s($name);
        $preview = $body !== '' ? $body : \html_writer::tag('p', get_string('contentmapping_notext', 'local_curricmap'));
        $preview = \html_writer::tag('details', \html_writer::tag('summary',
            get_string('contentmapping_content', 'local_curricmap')) . \html_writer::div($preview, 'curricmap-preview-body'),
            ['data-curricmap-preview' => $key, 'open' => 'open']);
        $left = \html_writer::tag('strong', $namehtml) . $preview;
        $right = \html_writer::tag('strong', get_string('contentmapping_current', 'local_curricmap'))
            . \html_writer::div(contentmap::current_cell($current, $this->returnurl));
        if ($mappable) {
            $right .= contentmap::proposal_cell($key, $hints, $pool, !empty($current), $roots[0] ?? null);
            if ($hints) {
                $evidence = [];
                foreach ($hints as $hint) {
                    $source = empty($hint->frombody) ? $name : $text;
                    $needle = matcher::strip_types($hint->candidate->node->title, matcher::rules());
                    $position = \core_text::strpos(\core_text::strtolower($source), \core_text::strtolower($needle));
                    if ($position !== false && $position > 80) {
                        $source = '…' . \core_text::substr($source, $position - 80);
                    }
                    $evidence[] = s($hint->candidate->node->title) . ': '
                        . s(shorten_text($source, 240));
                }
                $right .= \html_writer::tag('details', \html_writer::tag('summary',
                    get_string('contentmapping_evidence', 'local_curricmap')) . \html_writer::alist($evidence));
            }
        } else {
            $right .= \html_writer::tag('p', get_string('contentmapping_typedisabled', 'local_curricmap'));
        }
        $tick = $mappable ? contentmap::tick($key, $name) : '';
        return \html_writer::div(\html_writer::div($tick, 'curricmap-cell-tick')
            . \html_writer::div($left, 'curricmap-cell-name')
            . \html_writer::div($right, 'curricmap-cell-proposal'), 'curricmap-row', ['data-content-key' => $key]);
    }
}
