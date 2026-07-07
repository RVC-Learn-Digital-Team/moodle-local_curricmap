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

namespace local_curricmap\task;

/**
 * Daily housekeeping: purge expired API log rows.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_task extends \core\task\scheduled_task {
    /**
     * Task name shown in the scheduled tasks admin screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup', 'local_curricmap');
    }

    /**
     * Run the cleanup.
     */
    public function execute(): void {
        $deleted = \local_curricmap\local\apilog::cleanup();
        mtrace("local_curricmap: purged {$deleted} expired API log rows.");
    }
}
