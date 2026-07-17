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
$string['contentmapping'] = 'Course content matching';
$string['contentmapping_back'] = 'Back to sections';
$string['contentmapping_bodyhint'] = 'text';
$string['contentmapping_chaptercounts'] = '{$a->chapters} chapters, {$a->chaptersmapped} mapped';
$string['contentmapping_counts'] = '{$a->activities} activities, {$a->mapped} mapped';
$string['contentmapping_coursesearch'] = 'Find a course by name or ID number';
$string['contentmapping_currentcourse'] = 'Course: {$a} — switch:';
$string['contentmapping_filternodetypes'] = 'Strand types';
$string['contentmapping_filtersections'] = 'Sections';
$string['contentmapping_filtertypes'] = 'Module types';
$string['contentmapping_help'] = 'Sections propose strands; a section\'s modules propose sessions and outcomes within its matched strand. Match coarse first — a section match covers its modules unless they get their own.';
$string['contentmapping_housekeeping'] = 'Housekeeping';
$string['contentmapping_intro'] = 'Map one course\'s sections and activities to curriculum nodes. Pick a course to begin — it needs a central match first.';
$string['contentmapping_item'] = 'Section / activity';
$string['contentmapping_link'] = 'Map course content';
$string['contentmapping_mapactivities'] = 'Map activities';
$string['contentmapping_mapchapters'] = 'Map chapters ({$a} mapped)';
$string['contentmapping_matchchapters'] = 'Match selected chapters';
$string['contentmapping_modtype'] = 'Module type';
$string['contentmapping_narrowfirst'] = 'Match the section to a strand to unlock the full target list.';
$string['contentmapping_nopool'] = 'No targets available.';
$string['contentmapping_norows'] = 'Nothing to show for the current filters.';
$string['contentmapping_notmatched'] = 'This course has no central match yet, so there is no curriculum to map its content against. <a href="{$a}">Match the course first</a>.';
$string['contentmapping_section'] = 'Section';
$string['contentmapping_toolarge'] = 'Too many targets to list — showing name hints only.';
$string['coursemapping'] = 'Central course matching';
$string['coursemapping_alreadymatched'] = 'Already matched';
$string['coursemapping_applied'] = '{$a} central match(es) created.';
$string['coursemapping_apply'] = 'Create matches for selected';
$string['coursemapping_apply_sofia'] = 'Match selected courses to this programme year';
$string['coursemapping_course'] = 'Course';
$string['coursemapping_currentmatches'] = 'Current matches';
$string['coursemapping_fit'] = 'Fit';
$string['coursemapping_includestrands'] = 'include strands';
$string['coursemapping_intro'] = 'Courses are matched against the synced programme years by ID number, name and category. Tick the rows to apply, then confirm. Deeper mapping is on each course\'s mappings page.';
$string['coursemapping_mode'] = 'Matching direction';
$string['coursemapping_mode_course'] = 'Match by Moodle course';
$string['coursemapping_mode_sofia'] = 'Match by Sofia curriculum';
$string['coursemapping_noaction'] = 'No action';
$string['coursemapping_nocourses'] = 'No courses found for the current filters.';
$string['coursemapping_nonodes'] = 'No programme years are synced yet — configure and sync programmes first.';
$string['coursemapping_onlyidnumber'] = 'idnumber only';
$string['coursemapping_proposal'] = 'Proposed match';
$string['coursemapping_removed'] = 'Match removed.';
$string['coursemapping_removematch'] = 'Remove match';
$string['coursemapping_search'] = 'Search name, ID number or year';
$string['coursemapping_selectall'] = 'Select all on page';
$string['coursemapping_selectcourse'] = 'Select {$a}';
$string['coursemapping_show'] = 'Show';
$string['coursemapping_show_all'] = 'Show: all courses ({$a})';
$string['coursemapping_show_existing'] = 'Show: already matched ({$a})';
$string['coursemapping_show_matched'] = 'Show: matched proposals ({$a})';
$string['coursemapping_show_unmatched'] = 'Show: unmatched ({$a})';
$string['coursemapping_slugyear'] = 'Programme and year';
$string['coursemapping_slugyear_all'] = 'All programmes and years';
$string['coursemapping_sofianode'] = 'Sofia programme year';
$string['coursemapping_status_match'] = 'Matched';
$string['coursemapping_status_nocoverage'] = 'No synced coverage';
$string['coursemapping_status_nomatch'] = 'No match';
$string['coursemapping_status_noyear'] = 'No year detected';
$string['coursemapping_status_searchresult'] = 'Search result';
$string['coursemapping_status_skipped'] = 'Skipped';
$string['coursemapping_status_suggest'] = 'Suggestions';
$string['coursemapping_typetosearch'] = 'Type to search';
$string['coursemapping_year'] = 'Academic year';
$string['courseresources'] = 'Course study resources';
$string['courseresources_added'] = 'Course resource added.';
$string['courseresources_confirmdelete'] = 'This removes the link everywhere its curriculum node appears in this course. Delete "{$a}"?';
$string['courseresources_deleted'] = 'Course resource deleted.';
$string['courseresources_hiddenbadge'] = 'Hidden';
$string['courseresources_intro'] = 'Links added here belong to the curriculum node and appear everywhere that node is displayed in this course. Centrally provided resources always display and are managed by the central team.';
$string['courseresources_name'] = 'Name';
$string['courseresources_namerequired'] = 'A name and a URL are both required — the name is the link text students see.';
$string['courseresources_none'] = 'No course resources yet.';
$string['courseresources_nonodes'] = 'This course has no curriculum mappings yet — resources attach to the nodes a course is mapped to.';
$string['courseresources_visibilitychanged'] = 'Course resource visibility changed.';
$string['curricmap:editmanual'] = 'Create and edit manual curriculum entries';
$string['curricmap:importcsv'] = 'Import curriculum data from CSV';
$string['curricmap:managebindings'] = 'Manage bindings between Moodle locations and curriculum nodes';
$string['curricmap:managecourseresources'] = 'Manage a course\'s own course-scoped node resources';
$string['curricmap:managesync'] = 'Configure, trigger and inspect curriculum syncs';
$string['curricmap:viewstaffmeta'] = 'View staff-only curriculum metadata (Sofia links, source, codes)';
$string['errorbindaddress'] = 'Invalid mapping address: a category or course is required, section/module addresses need a course, and sub-activity ids need a component.';
$string['errorbindnode'] = 'The curriculum node {$a} does not exist or is deleted.';
$string['errorhttp'] = 'Sofia API request failed (HTTP {$a->code}) for {$a->url}';
$string['errorinvalidjson'] = 'Sofia API returned a response that is not valid JSON for {$a->url}';
$string['errornotconfigured'] = 'The Sofia API connection is not configured (base URL, client ID and client secret are required)';
$string['errorratefloor'] = 'Sofia API request refused: remaining rate budget ({$a->remaining}) is at or below the configured floor ({$a->floor})';
$string['errorresourcescope'] = 'Course resources can only be attached to curriculum nodes within the course\'s centrally mapped scope.';
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
$string['settings:generalpage'] = 'General settings';
$string['settings:mappablemodtypes'] = 'Course activities to map';
$string['settings:mappablemodtypes_desc'] = 'Only these activity module types are offered on the course content matching page. Types not selected still display normally in courses — they just cannot be mapped.';
$string['settings:matchingpage'] = 'Course matching settings';
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
$string['status_rateforecast'] = '{$a->spent} request(s) sent in the last hour — next slot frees ~{$a->next}, full budget back ~{$a->full} (rolling-window estimate from this plugin\'s own log).';
$string['status_ratelimited'] = 'Sofia is rate limiting us — budget expected back at {$a}.';
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
$string['studyresources'] = 'Study resources';
$string['studyresources_add'] = 'Add a resource';
$string['studyresources_addbutton'] = 'Add resource';
$string['studyresources_added'] = 'Resource added.';
$string['studyresources_bycourse'] = 'or find a course by name';
$string['studyresources_bynode'] = 'Browse by curriculum node';
$string['studyresources_count'] = 'resources ({$a})';
$string['studyresources_courseintro'] = 'Every central mapping in this course, with the node\'s resources and an inline add form. Resources belong to the node, so they follow it into every course and year that maps it.';
$string['studyresources_deleted'] = 'Resource deleted.';
$string['studyresources_forcourse'] = 'Study resources for this course';
$string['studyresources_intro'] = 'Attach learning material to curriculum nodes. A resource belongs to the node itself, so it appears wherever the node is displayed, in any course and any year the node is mapped — and rollover never touches it.';
$string['studyresources_label_short'] = 'Label (optional)';
$string['studyresources_node'] = 'Curriculum node';
$string['studyresources_none'] = 'No resources on this node or below it yet.';
$string['studyresources_nourl'] = 'A URL is required.';
$string['studyresources_onnode'] = 'On node';
$string['studyresources_pick'] = 'Pick a programme year and a curriculum node to see and manage its resources.';
$string['studyresources_type'] = 'Type';
$string['studyresources_typeother'] = 'or custom type';
$string['studyresources_url'] = 'URL';
$string['task_cleanup'] = 'Purge expired curriculum map API logs';
$string['task_sync'] = 'Sync curriculum data from Sofia';
