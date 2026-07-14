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
 * Curriculum map status page: connection test, sync triggers, recent logs.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_curricmap_status');

$action = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/local/curricmap/status.php');
$notifications = [];

if ($action === 'csv' && confirm_sesskey()) {
    $rows = $DB->get_records_sql(
        'SELECT l.*, p.slug FROM {local_curricmap_synclog} l
           LEFT JOIN {local_curricmap_programme} p ON p.id = l.programmeid
          ORDER BY l.id DESC'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="curricmap_synclog.csv"');
    $out = fopen('php://output', 'w');
    $header = ['id', 'programme', 'type', 'status', 'timestart', 'timeend', 'fetched',
        'inserted', 'updated', 'deleted', 'edges', 'tags', 'requests', 'remaining', 'message'];
    fputcsv($out, $header);
    foreach ($rows as $row) {
        $line = [$row->id, $row->slug, $row->synctype, $row->status,
            userdate($row->timestart), $row->timeend ? userdate($row->timeend) : '',
            $row->nodesfetched, $row->nodesinserted, $row->nodesupdated, $row->nodesdeleted,
            $row->edgeschanged, $row->tagschanged, $row->requestcount,
            $row->ratelimitremaining, $row->message];
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

if ($action === 'test' && confirm_sesskey()) {
    $client = new \local_curricmap\api\client();
    if (!$client->is_configured()) {
        $notifications[] = ['error', get_string('errornotconfigured', 'local_curricmap')];
    } else {
        $slugsetting = (string) get_config('local_curricmap', 'programmeslugs');
        $slug = trim(explode(',', $slugsetting)[0]);
        if ($slug === '') {
            $notifications[] = ['error', get_string('status_noprogrammes', 'local_curricmap')];
        } else {
            try {
                $started = microtime(true);
                $payload = $client->compare($slug, 'LATEST', 'LATEST');
                $hash = $payload['meta']['compare']['to'] ?? '?';
                $a = (object) [
                    'slug' => $slug,
                    'hash' => substr((string) $hash, 0, 12),
                    'remaining' => $client->remaining() ?? '?',
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                ];
                $notifications[] = ['success', get_string('status_testok', 'local_curricmap', $a)];
            } catch (\Throwable $exception) {
                $notifications[] = ['error', get_string('status_testfail', 'local_curricmap', $exception->getMessage())];
            }
        }
    }
}

if ($action === 'discover' && confirm_sesskey()) {
    try {
        $found = \local_curricmap\local\sync::discover_programmes();
        $notifications[] = ['success', get_string('status_discoverresult', 'local_curricmap', (object) $found)];
    } catch (\Throwable $exception) {
        $notifications[] = ['error', s($exception->getMessage())];
    }
}

if ($action === 'sync' && confirm_sesskey()) {
    $programmeid = required_param('programmeid', PARAM_INT);
    $force = optional_param('force', 0, PARAM_BOOL);
    $programme = $DB->get_record('local_curricmap_programme', ['id' => $programmeid]);
    if (!$programme) {
        // Stale button from a previously rendered page; the row is gone.
        $notifications[] = ['warning', get_string('status_programmegone', 'local_curricmap')];
    } else {
        $engine = new \local_curricmap\local\sync();
        $log = $engine->sync_programme($programme, (bool) $force);
        $a = (object) [
            'slug' => $programme->slug . ':' . $programme->versionlabel,
            'status' => $log->status,
            'inserted' => (int) ($log->nodesinserted ?? 0),
            'updated' => (int) ($log->nodesupdated ?? 0),
            'deleted' => (int) ($log->nodesdeleted ?? 0),
        ];
        $level = $log->status === 'error' ? 'error' : 'success';
        $message = get_string('status_syncresult', 'local_curricmap', $a);
        if ($log->status === 'error') {
            $message .= ' — ' . s($log->message ?? '');
        }
        $notifications[] = [$level, $message];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('statuspage', 'local_curricmap'));

foreach ($notifications as [$level, $message]) {
    echo $OUTPUT->notification($message, $level);
}

// Connection section.
echo $OUTPUT->heading(get_string('status_connection', 'local_curricmap'), 3);
$client = new \local_curricmap\api\client();
$baseurl = (string) get_config('local_curricmap', 'sofia_baseurl');
$ratecount = get_config('local_curricmap', 'lastratecount');
$ratelimit = get_config('local_curricmap', 'lastratelimit');
$rateseen = get_config('local_curricmap', 'lastrateseen');
$configured = $client->is_configured()
    ? get_string('yes')
    : get_string('no') . ' — ' . get_string('errornotconfigured', 'local_curricmap');
echo html_writer::tag('p', s($baseurl) . ' · ' . get_string('status_configured', 'local_curricmap') . ': ' . $configured);
if ($ratecount !== false && $ratelimit !== false && $rateseen) {
    $a = (object) [
        'count' => $ratecount,
        'limit' => $ratelimit,
        'when' => format_time(time() - (int) $rateseen),
    ];
    echo html_writer::tag('p', get_string('status_lastrate', 'local_curricmap', $a));
}
// When Sofia refused us, it said how long to wait - show that verbatim.
$timeformat = get_string('strftimedatetimeshort', 'langconfig');
$budgetback = (int) get_config('local_curricmap', 'ratebudgetback');
if ($budgetback > time()) {
    $backat = userdate($budgetback, $timeformat) . ' (' . format_time($budgetback - time()) . ')';
    echo $OUTPUT->notification(get_string('status_ratelimited', 'local_curricmap', $backat), 'warning', false);
}
// Rolling-window forecast from our own request log: each request occupies its
// slot for an hour, so spend in the trailing hour predicts when slots free up.
$windowtimes = $DB->get_fieldset_select(
    'local_curricmap_apilog',
    'timecreated',
    'timecreated > :since',
    ['since' => time() - HOURSECS]
);
if ($windowtimes) {
    sort($windowtimes);
    $a = (object) [
        'spent' => count($windowtimes),
        'next' => userdate($windowtimes[0] + HOURSECS, $timeformat),
        'full' => userdate(end($windowtimes) + HOURSECS, $timeformat),
    ];
    echo html_writer::tag('p', get_string('status_rateforecast', 'local_curricmap', $a));
}
$testurl = new moodle_url($pageurl, ['action' => 'test', 'sesskey' => sesskey()]);
echo $OUTPUT->single_button($testurl, get_string('status_testconnection', 'local_curricmap'), 'post');

// Programmes section. Materialise rows from the programmeslugs setting first,
// so setting changes are reflected here without waiting for the scheduled task.
echo $OUTPUT->heading(get_string('status_programmes', 'local_curricmap'), 3);
\local_curricmap\local\sync::ensure_programmes();
$discoverurl = new moodle_url($pageurl, ['action' => 'discover', 'sesskey' => sesskey()]);
echo $OUTPUT->single_button($discoverurl, get_string('status_discover', 'local_curricmap'), 'post');
$programmes = $DB->get_records('local_curricmap_programme', [], 'slug ASC, versionlabel ASC');
if (!$programmes) {
    echo $OUTPUT->notification(get_string('status_noprogrammes', 'local_curricmap'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('status_programme', 'local_curricmap'),
        get_string('status_revision', 'local_curricmap'),
        get_string('status', 'moodle'),
        get_string('status_lastsynced', 'local_curricmap'),
        get_string('status_nodes', 'local_curricmap'),
        get_string('actions', 'moodle'),
    ];
    foreach ($programmes as $programme) {
        $syncparams = ['action' => 'sync', 'programmeid' => $programme->id, 'sesskey' => sesskey()];
        $syncurl = new moodle_url($pageurl, $syncparams);
        $forceurl = new moodle_url($syncurl, ['force' => 1]);
        $buttons = $OUTPUT->single_button($syncurl, get_string('status_syncnow', 'local_curricmap'), 'post')
            . $OUTPUT->single_button($forceurl, get_string('status_forcesync', 'local_curricmap'), 'post');
        $nodecount = $DB->count_records('local_curricmap_node', ['programmeid' => $programme->id, 'deleted' => 0]);
        $label = $programme->versionlabel;
        if (preg_match('/^\d{4}$/', $label)) {
            $label = $label . '/' . sprintf('%02d', ((int) $label + 1) % 100);
        }
        $name = ($programme->displayname ?: $programme->slug) . ' ' . $label;
        $table->data[] = [
            s($name) . ($programme->enabled ? '' : ' (' . get_string('status_disabled', 'local_curricmap') . ')'),
            $programme->revisionhash ? substr($programme->revisionhash, 0, 12) : '—',
            s($programme->lastsyncstatus),
            $programme->timelastsynced ? userdate($programme->timelastsynced) : '—',
            $nodecount,
            $buttons,
        ];
    }
    echo html_writer::table($table);
}

// Recent sync runs.
echo $OUTPUT->heading(get_string('status_recentsyncs', 'local_curricmap'), 3);
$logs = $DB->get_records_sql(
    'SELECT l.*, p.slug FROM {local_curricmap_synclog} l
       LEFT JOIN {local_curricmap_programme} p ON p.id = l.programmeid
      ORDER BY l.id DESC',
    [],
    0,
    15
);
if (!$logs) {
    echo html_writer::tag('p', get_string('none'));
} else {
    $table = new html_table();
    $table->head = ['#', get_string('status_programme', 'local_curricmap'), get_string('status', 'moodle'),
        get_string('date'), '+', '~', '-', get_string('status_requests', 'local_curricmap'),
        get_string('status_remaining', 'local_curricmap'), get_string('status_report', 'local_curricmap')];
    foreach ($logs as $row) {
        $report = (string) ($row->message ?? '');
        if (core_text::strlen($report) > 300) {
            $report = core_text::substr($report, 0, 300) . '…';
        }
        $table->data[] = [
            $row->id,
            s((string) $row->slug),
            s($row->status),
            userdate($row->timestart),
            (int) $row->nodesinserted,
            (int) $row->nodesupdated,
            (int) $row->nodesdeleted,
            (int) $row->requestcount,
            $row->ratelimitremaining ?? '—',
            html_writer::tag('pre', s($report), ['class' => 'small mb-0']),
        ];
    }
    echo html_writer::table($table);
    $csvurl = new moodle_url($pageurl, ['action' => 'csv', 'sesskey' => sesskey()]);
    echo $OUTPUT->single_button($csvurl, get_string('status_downloadcsv', 'local_curricmap'), 'post');
}

// Recent API errors.
echo $OUTPUT->heading(get_string('status_recentapierrors', 'local_curricmap'), 3);
$errors = $DB->get_records('local_curricmap_apilog', ['outcome' => 'error'], 'id DESC', '*', 0, 10);
if (!$errors) {
    echo html_writer::tag('p', get_string('none'));
} else {
    $table = new html_table();
    $table->head = [get_string('date'), get_string('url'), 'HTTP', get_string('status_report', 'local_curricmap')];
    foreach ($errors as $row) {
        $table->data[] = [
            userdate($row->timecreated),
            s($row->method . ' ' . $row->url),
            $row->httpcode ?? '—',
            s(core_text::substr((string) ($row->message ?? ''), 0, 200)),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
