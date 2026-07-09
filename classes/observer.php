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

namespace local_curricmap;

/**
 * Event observers: bindings whose Moodle end disappears are marked orphaned
 * (never silently deleted - the mappings page and orphan report surface them).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A course module was deleted.
     *
     * @param \core\event\course_module_deleted $event The event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        \local_curricmap\api\bindings::mark_orphaned(['cmid' => $event->objectid]);
    }

    /**
     * A course section was deleted.
     *
     * @param \core\event\course_section_deleted $event The event.
     */
    public static function course_section_deleted(\core\event\course_section_deleted $event): void {
        \local_curricmap\api\bindings::mark_orphaned(['sectionid' => $event->objectid]);
    }

    /**
     * A course was deleted.
     *
     * @param \core\event\course_deleted $event The event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        \local_curricmap\api\bindings::mark_orphaned(['courseid' => $event->objectid]);
    }

    /**
     * A course category was deleted.
     *
     * @param \core\event\course_category_deleted $event The event.
     */
    public static function course_category_deleted(\core\event\course_category_deleted $event): void {
        \local_curricmap\api\bindings::mark_orphaned(['categoryid' => $event->objectid]);
    }
}
