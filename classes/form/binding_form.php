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

namespace local_curricmap\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_curricmap\api\curriculum;

/**
 * Add-mapping form for the per-course curriculum mappings page: pick a
 * location (whole course, a section, or an activity), a programme year, a
 * node within it, and the relation.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class binding_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $course = $this->_customdata['course'];
        $cancentral = !empty($this->_customdata['cancentral']);

        $locations = ['course' => get_string('mappings_location_course', 'local_curricmap')];
        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_section_info_all() as $section) {
            $locations['section:' . $section->id] = get_string(
                'mappings_location_section',
                'local_curricmap',
                get_section_name($course, $section)
            );
        }
        foreach ($modinfo->cms as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            $locations['cm:' . $cm->id] = get_string(
                'mappings_location_activity',
                'local_curricmap',
                $cm->get_formatted_name()
            );
        }
        $mform->addElement('select', 'location', get_string('mappings_location', 'local_curricmap'), $locations);

        $programmechoices = ['' => get_string('choosedots')];
        foreach (curriculum::programmes() as $programme) {
            $year = $programme->versionlabel;
            if (preg_match('/^\d{4}$/', $year)) {
                $year = $year . '/' . sprintf('%02d', ((int) $year + 1) % 100);
            }
            $programmechoices[(int) $programme->id] = ($programme->displayname ?: $programme->slug) . ' ' . $year;
        }
        $mform->addElement(
            'select',
            'programmeid',
            get_string('mappings_programme', 'local_curricmap'),
            $programmechoices
        );

        $attributes = [
            'ajax' => 'local_curricmap/nodeselector',
            'noselectionstring' => get_string('choosedots'),
            'placeholder' => get_string('mappings_node_placeholder', 'local_curricmap'),
        ];
        $mform->addElement(
            'autocomplete',
            'nodeuuid',
            get_string('mappings_node', 'local_curricmap'),
            [],
            $attributes
        );
        $mform->addHelpButton('nodeuuid', 'mappings_node', 'local_curricmap');
        $mform->getElement('nodeuuid')->updateAttributes(['data-courseid' => $course->id]);

        $relations = [
            'related' => get_string('relation_related', 'local_curricmap'),
            'anchor' => get_string('relation_anchor', 'local_curricmap'),
        ];
        $mform->addElement('select', 'relation', get_string('mappings_relation', 'local_curricmap'), $relations);
        $mform->addHelpButton('relation', 'mappings_relation', 'local_curricmap');

        if ($cancentral) {
            $scopes = [
                'course' => get_string('scope_course', 'local_curricmap'),
                'central' => get_string('scope_central', 'local_curricmap'),
            ];
            $mform->addElement('select', 'scope', get_string('mappings_scope', 'local_curricmap'), $scopes);
        } else {
            $mform->addElement('hidden', 'scope', 'course');
            $mform->setType('scope', PARAM_ALPHA);
        }

        $mform->addElement('hidden', 'courseid', $course->id);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(false, get_string('mappings_addmapping', 'local_curricmap'));
    }

    /**
     * A node must be picked.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['nodeuuid'])) {
            $errors['nodeuuid'] = get_string('required');
        }
        return $errors;
    }
}
