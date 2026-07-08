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
 * On-demand sync of one programme (queued by admin trigger-now, and later by
 * the webhook receiver). Custom data: programmeid (int), force (bool).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adhoc_sync_task extends \core\task\adhoc_task {
    /**
     * Run the sync for the programme named in the custom data.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $programme = $DB->get_record('local_curricmap_programme', ['id' => $data->programmeid ?? 0]);
        if (!$programme) {
            mtrace('local_curricmap: adhoc sync: programme not found, nothing to do.');
            return;
        }

        $engine = new \local_curricmap\local\sync();
        $log = $engine->sync_programme($programme, !empty($data->force));
        mtrace("local_curricmap: adhoc sync {$programme->slug}: {$log->status}");
    }
}
