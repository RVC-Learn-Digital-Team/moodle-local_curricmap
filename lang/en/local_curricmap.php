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

$string['cachedef_queries'] = 'Curriculum query results';
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
$string['errorsyncnohash'] = 'Could not resolve the current revision hash from the Compare API response';
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
$string['settings:programmeslugs'] = 'Programme slugs';
$string['settings:programmeslugs_desc'] = 'Comma-separated programmes to sync, as slug or slug:version — e.g. "vet-med:2026, vet-med:2025" for pinned academic years (recommended for delivery courses; bare slugs track LATEST, which rolls over). Removing an entry disables its sync but keeps its data.';
$string['settings:ratelimitfloor'] = 'Rate-limit floor';
$string['settings:ratelimitfloor_desc'] = 'Sofia allows 60 API requests per hour. Requests are refused once the remaining budget reaches this floor, keeping headroom for manual operations.';
$string['settings:sofia_heading'] = 'Sofia API connection';
$string['settings:sofia_heading_desc'] = 'OAuth2 client-credentials connection to the Sofia curriculum management system.';
$string['status_configured'] = 'Configured';
$string['status_connection'] = 'Sofia connection';
$string['status_downloadcsv'] = 'Download sync log (CSV)';
$string['status_forcesync'] = 'Force full sync';
$string['status_lastrate'] = 'Last seen rate budget: {$a->count}/{$a->limit}, {$a->when} ago.';
$string['status_lastsynced'] = 'Last synced';
$string['status_nodes'] = 'Active nodes';
$string['status_noprogrammes'] = 'No programmes configured — set programme slugs in the plugin settings.';
$string['status_programme'] = 'Programme';
$string['status_programmes'] = 'Programmes';
$string['status_recentapierrors'] = 'Recent API errors';
$string['status_recentsyncs'] = 'Recent sync runs';
$string['status_remaining'] = 'Remaining';
$string['status_report'] = 'Report';
$string['status_requests'] = 'Requests';
$string['status_revision'] = 'Revision';
$string['status_syncnow'] = 'Sync now';
$string['status_syncresult'] = '{$a->slug}: {$a->status} (+{$a->inserted} ~{$a->updated} -{$a->deleted})';
$string['status_testconnection'] = 'Test connection';
$string['status_testfail'] = 'Connection test failed: {$a}';
$string['status_testok'] = 'Connection OK: {$a->slug} LATEST is revision {$a->hash} ({$a->ms} ms, {$a->remaining} requests remaining this hour).';
$string['statuspage'] = 'Curriculum map status';
$string['statuspage_link'] = 'Test the connection and view sync status on the curriculum map status page.';
$string['task_cleanup'] = 'Purge expired curriculum map API logs';
$string['task_sync'] = 'Sync curriculum data from Sofia';
