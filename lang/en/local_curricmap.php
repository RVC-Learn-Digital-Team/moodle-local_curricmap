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

/**
 * English language strings for local_curricmap.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cachedef_token'] = 'Sofia API OAuth2 bearer token';
$string['curricmap:editmanual'] = 'Create and edit manual curriculum entries';
$string['curricmap:importcsv'] = 'Import curriculum data from CSV';
$string['curricmap:managebindings'] = 'Manage bindings between Moodle locations and curriculum nodes';
$string['curricmap:managesync'] = 'Configure, trigger and inspect curriculum syncs';
$string['curricmap:viewstaffmeta'] = 'View staff-only curriculum metadata (Sofia links, source, codes)';
$string['errorhttp'] = 'Sofia API request failed (HTTP {$a->code}) for {$a->url}';
$string['errorinvalidjson'] = 'Sofia API returned a response that is not valid JSON for {$a->url}';
$string['errornotconfigured'] = 'The Sofia API connection is not configured (base URL, client ID and client secret are required)';
$string['errorratefloor'] = 'Sofia API request refused: remaining rate budget ({$a->remaining}) is at or below the configured floor ({$a->floor})';
$string['errortoken'] = 'Could not obtain a Sofia API access token';
$string['pluginname'] = 'Curriculum map';
$string['privacy:metadata'] = 'The Curriculum map plugin does not currently store any personal data. This will be revised when audit logging of manual edits is implemented.';
$string['settings:apilogretention'] = 'API log retention (days)';
$string['settings:apilogretention_desc'] = 'API log entries older than this are purged by the daily cleanup task.';
$string['settings:baseurl'] = 'Sofia base URL';
$string['settings:baseurl_desc'] = 'For example https://rvc-vetmed-test.sofiasrv.net — no trailing slash required.';
$string['settings:clientid'] = 'Sofia client ID';
$string['settings:clientid_desc'] = 'OAuth2 client ID issued by the Sofia team (40 characters).';
$string['settings:clientsecret'] = 'Sofia client secret';
$string['settings:clientsecret_desc'] = 'OAuth2 client secret issued by the Sofia team (128 characters). Stored in Moodle configuration; never logged.';
$string['settings:debuglog'] = 'Enable API debug logging';
$string['settings:debuglog_desc'] = 'When enabled, every Sofia API request is recorded in the database log with a truncated response preview. Errors are always recorded regardless of this setting. Credentials and tokens are never logged.';
$string['settings:diagnostics_heading'] = 'Diagnostics';
$string['settings:diagnostics_heading_desc'] = 'API request logging is database-backed so it works on load-balanced installations and is visible to administrators.';
$string['settings:ratelimitfloor'] = 'Rate-limit floor';
$string['settings:ratelimitfloor_desc'] = 'Sofia allows 60 API requests per hour. Requests are refused once the remaining budget reaches this floor, keeping headroom for manual operations.';
$string['settings:sofia_heading'] = 'Sofia API connection';
$string['settings:sofia_heading_desc'] = 'OAuth2 client-credentials connection to the Sofia curriculum management system.';
$string['task_cleanup'] = 'Purge expired curriculum map API logs';
