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
 * Scheduled Sofia sync. Default hourly; the agreed cadence bounds are at least
 * once a day and at most once an hour, so the task self-guards: a programme
 * synced successfully less than the guard interval ago is skipped even if an
 * admin schedules the task more frequently.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_task extends \core\task\scheduled_task {
    /** @var int Minimum seconds between syncs of the same programme (55 minutes). */
    const GUARD_SECONDS = 55 * 60;

    /**
     * Task name shown in the scheduled tasks admin screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync', 'local_curricmap');
    }

    /**
     * Sync every enabled programme, respecting the per-programme guard.
     */
    public function execute(): void {
        $client = new \local_curricmap\api\client();
        if (!$client->is_configured()) {
            mtrace('local_curricmap: Sofia connection not configured, skipping sync.');
            return;
        }

        $programmes = \local_curricmap\local\sync::ensure_programmes();
        if (!$programmes) {
            mtrace('local_curricmap: no programmes configured (programmeslugs setting).');
            return;
        }

        $engine = new \local_curricmap\local\sync($client);
        foreach ($programmes as $programme) {
            $recent = $programme->timelastsynced
                && (time() - $programme->timelastsynced) < self::GUARD_SECONDS;
            if ($recent && $programme->lastsyncstatus !== 'error') {
                mtrace("local_curricmap: {$programme->slug} synced recently, skipping (guard).");
                continue;
            }
            $log = $engine->sync_programme($programme);
            $summary = "status={$log->status}"
                . ' +' . ($log->nodesinserted ?? 0) . ' ~' . ($log->nodesupdated ?? 0)
                . ' -' . ($log->nodesdeleted ?? 0)
                . ' requests=' . ($log->requestcount ?? 0)
                . ' remaining=' . ($log->ratelimitremaining ?? '-');
            mtrace("local_curricmap: {$programme->slug}: {$summary}");
            if ($log->status === 'error') {
                mtrace('local_curricmap:   error: ' . ($log->message ?? 'unknown'));
            }
        }
    }
}
