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
$string['coursemapping'] = 'Central course matching';
$string['coursemapping_anchored'] = 'Central anchors';
$string['coursemapping_applied'] = '{$a} central anchor(s) created.';
$string['coursemapping_apply'] = 'Create anchors for selected courses';
$string['coursemapping_course'] = 'Course';
$string['coursemapping_intro'] = 'Courses with an ID number are matched against the synced programme years. Review the proposals (matched rows are preselected), then confirm to create central anchors. Deeper mapping is on each course\'s mappings page.';
$string['coursemapping_noaction'] = 'No action';
$string['coursemapping_nocourses'] = 'No courses to match — courses need an ID number and an academic year at or above the discovery floor.';
$string['coursemapping_proposal'] = 'Proposed anchor';
$string['coursemapping_search'] = 'Search name or ID number';
$string['coursemapping_status_match'] = 'Matched';
$string['coursemapping_status_nocoverage'] = 'No synced coverage';
$string['coursemapping_status_nomatch'] = 'No match';
$string['coursemapping_status_noyear'] = 'No year detected';
$string['coursemapping_status_suggest'] = 'Suggestions';
$string['coursemapping_year'] = 'Academic year';
$string['curricmap:editmanual'] = 'Create and edit manual curriculum entries';
$string['curricmap:importcsv'] = 'Import curriculum data from CSV';
$string['curricmap:managebindings'] = 'Manage bindings between Moodle locations and curriculum nodes';
$string['curricmap:managesync'] = 'Configure, trigger and inspect curriculum syncs';
$string['curricmap:viewstaffmeta'] = 'View staff-only curriculum metadata (Sofia links, source, codes)';
$string['errorbindaddress'] = 'Invalid mapping address: a category or course is required, section/module addresses need a course, and sub-activity ids need a component.';
$string['errorbindnode'] = 'The curriculum node {$a} does not exist or is deleted.';
$string['errorhttp'] = 'Sofia API request failed (HTTP {$a->code}) for {$a->url}';
$string['errorinvalidjson'] = 'Sofia API returned a response that is not valid JSON for {$a->url}';
$string['errornotconfigured'] = 'The Sofia API connection is not configured (base URL, client ID and client secret are required)';
$string['errorratefloor'] = 'Sofia API request refused: remaining rate budget ({$a->remaining}) is at or below the configured floor ({$a->floor})';
$string['errorsyncnohash'] = 'Could not resolve the current revision hash from the Compare API response';
$string['errortoken'] = 'Could not obtain a Sofia API access token';
$string['mappings'] = 'Curriculum mappings';
$string['mappings_added'] = 'Mapping added.';
$string['mappings_addmapping'] = 'Add mapping';
$string['mappings_anchors'] = 'Course curriculum scope (anchors)';
$string['mappings_centrallocked'] = 'Centrally managed - changed by administrators only';
$string['mappings_deleted'] = 'Mapping deleted.';
$string['mappings_inherited'] = 'Inherited';
$string['mappings_inheritedfrom'] = 'Inherited from category: {$a}';
$string['mappings_location'] = 'Location';
$string['mappings_location_activity'] = 'Activity: {$a}';
$string['mappings_location_course'] = 'Whole course';
$string['mappings_location_section'] = 'Section: {$a}';
$string['mappings_node'] = 'Curriculum node';
$string['mappings_node_help'] = 'Pick the programme year first, then search by title or code. Any node can be mapped, including individual outcomes.';
$string['mappings_node_placeholder'] = 'Search by title or code';
$string['mappings_none'] = 'No curriculum mappings yet.';
$string['mappings_orphaned'] = 'Orphaned mappings';
$string['mappings_orphaned_desc'] = 'The Moodle location or curriculum node these mappings pointed at no longer exists. They are kept for review - delete them when no longer needed.';
$string['mappings_programme'] = 'Programme year';
$string['mappings_relation'] = 'Relation';
$string['mappings_relation_help'] = 'Anchor marks the course\'s default curriculum scope, consumed by curriculum map activities and integrations. Related is a general link.';
$string['mappings_scope'] = 'Scope';
$string['pluginname'] = 'Curriculum map';
$string['privacy:metadata'] = 'The Curriculum map plugin does not currently store any personal data. This will be revised when audit logging of manual edits is implemented.';
$string['relation_anchor'] = 'Anchor (course default scope)';
$string['relation_related'] = 'Related';
$string['scope_central'] = 'Central';
$string['scope_course'] = 'Course';
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
$string['settings:discoveryfloor'] = 'Discovery floor year';
$string['settings:discoveryfloor_desc'] = 'The earliest academic-year version probed during discovery. Set before curriculum mapping existed; there is no cost to a low value beyond a few one-off probes.';
$string['settings:matchingrules'] = 'Course matching rules';
$string['settings:matchingrules_desc'] = 'JSON rules for the central course matching page: "skip" (idnumber regexes to exclude), "minscore" (minimum shared words for a suggestion) and ordered "aliases" ("pattern" over idnumber/names/category, "slug" the Sofia programme, "node" narrowing year-node titles, {n} filled from a named capture). Invalid JSON falls back to the shipped defaults.';
$string['settings:programmeslugs'] = 'Programme slugs';
$string['settings:programmeslugs_desc'] = 'Comma-separated Sofia programme slugs, e.g. "vet-med, vet-nur, bio-sc". Academic-year versions are discovered automatically (daily, or via Discover years on the status page) — no annual settings changes needed. Removing a slug disables its sync but keeps its data.';
$string['settings:ratelimitfloor'] = 'Rate-limit floor';
$string['settings:ratelimitfloor_desc'] = 'Sofia allows 60 API requests per hour. Requests are refused once the remaining budget reaches this floor, keeping headroom for manual operations.';
$string['settings:resourcetypes'] = 'Resource type vocabulary';
$string['settings:resourcetypes_desc'] = 'Comma-separated suggested types for node resources (free text elsewhere is always allowed) — e.g. panopto, pebblepad, ebook, images, link.';
$string['settings:sofia_heading'] = 'Sofia API connection';
$string['settings:sofia_heading_desc'] = 'OAuth2 client-credentials connection to the Sofia curriculum management system.';
$string['status_configured'] = 'Configured';
$string['status_connection'] = 'Sofia connection';
$string['status_disabled'] = 'disabled';
$string['status_discover'] = 'Discover years';
$string['status_discoverresult'] = 'Discovery complete: {$a->probed} year(s) probed, {$a->created} new programme year(s) found.';
$string['status_downloadcsv'] = 'Download sync log (CSV)';
$string['status_forcesync'] = 'Force full sync';
$string['status_lastrate'] = 'Last seen rate budget: {$a->count}/{$a->limit}, {$a->when} ago.';
$string['status_lastsynced'] = 'Last synced';
$string['status_nodes'] = 'Active nodes';
$string['status_noprogrammes'] = 'No programmes configured — set programme slugs in the plugin settings.';
$string['status_programme'] = 'Programme';
$string['status_programmegone'] = 'That programme row no longer exists — the page below has been refreshed with the current programmes.';
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
