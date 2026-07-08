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

/**
 * Sofia API request logging.
 *
 * Database-backed by design: production Moodle runs load-balanced, so local log
 * files would be per-node and lost on redeploy, and FR-SOF-7 requires the recent
 * API error log to be visible to administrators. Errors are always recorded;
 * successful requests are recorded (with a truncated response preview) only when
 * the enabledebuglog setting is on. Credentials and bearer tokens are never
 * written here. Rows are purged by the daily cleanup task after the configured
 * retention period.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apilog {
    /** @var string Table name. */
    const TABLE = 'local_curricmap_apilog';

    /** @var int Maximum stored length of a response preview. */
    const PREVIEW_LENGTH = 2000;

    /** @var int Default retention in days when the setting is unset. */
    const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Record one API request.
     *
     * @param string $method HTTP method.
     * @param string $url Path and query only - the caller must never pass credentials.
     * @param int|null $httpcode HTTP status code, null if the request never completed.
     * @param int $elapsedms Elapsed time in milliseconds.
     * @param int|null $ratecount X-Sofia-Request-Count header value, if seen.
     * @param int|null $ratelimit X-Sofia-Request-Limit header value, if seen.
     * @param bool $ok Whether the request succeeded.
     * @param string|null $message Error detail for failed requests.
     * @param string|null $body Response body; stored truncated, and only in debug mode.
     */
    public static function record(
        string $method,
        string $url,
        ?int $httpcode,
        int $elapsedms,
        ?int $ratecount,
        ?int $ratelimit,
        bool $ok,
        ?string $message = null,
        ?string $body = null
    ): void {
        global $DB;

        $debug = (bool) get_config('local_curricmap', 'enabledebuglog');
        if ($ok && !$debug) {
            return;
        }

        $record = new \stdClass();
        $record->timecreated = time();
        $record->method = substr($method, 0, 8);
        $record->url = substr($url, 0, 255);
        $record->httpcode = $httpcode;
        $record->elapsedms = $elapsedms;
        $record->ratecount = $ratecount;
        $record->ratelimit = $ratelimit;
        $record->outcome = $ok ? 'ok' : 'error';
        $record->message = $message;
        $record->responsepreview = $debug && $body !== null
            ? substr($body, 0, self::PREVIEW_LENGTH) : null;

        $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Purge log rows older than the configured retention period.
     *
     * @return int Number of rows deleted.
     */
    public static function cleanup(): int {
        global $DB;

        $days = (int) get_config('local_curricmap', 'apilogretention');
        if ($days <= 0) {
            $days = self::DEFAULT_RETENTION_DAYS;
        }
        $cutoff = time() - ($days * DAYSECS);

        $count = $DB->count_records_select(self::TABLE, 'timecreated < ?', [$cutoff]);
        if ($count > 0) {
            $DB->delete_records_select(self::TABLE, 'timecreated < ?', [$cutoff]);
        }
        return $count;
    }
}
