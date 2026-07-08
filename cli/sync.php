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
 * CLI trigger for the Sofia sync (used by admins and by sofia_staging-style tooling).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['programme' => '', 'force' => false, 'help' => false],
    ['p' => 'programme', 'f' => 'force', 'h' => 'help']
);

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln('Sync curriculum data from Sofia.');
    cli_writeln('');
    cli_writeln('Options:');
    cli_writeln('  -p, --programme=SLUG  Sync only this programme slug (default: all enabled).');
    cli_writeln('  -f, --force           Apply the snapshot even if the revision hash is unchanged.');
    cli_writeln('  -h, --help            Print this help.');
    exit(0);
}

$client = new \local_curricmap\api\client();
if (!$client->is_configured()) {
    cli_error('Sofia connection is not configured (see plugin settings).');
}

$programmes = \local_curricmap\local\sync::ensure_programmes();
if ($options['programme'] !== '') {
    $programmes = array_filter($programmes, fn($p) => $p->slug === $options['programme']);
    if (!$programmes) {
        cli_error("Programme '{$options['programme']}' is not configured/enabled (programmeslugs setting).");
    }
}
if (!$programmes) {
    cli_error('No programmes configured (programmeslugs setting).');
}

$engine = new \local_curricmap\local\sync($client);
$exitcode = 0;
foreach ($programmes as $programme) {
    $log = $engine->sync_programme($programme, (bool) $options['force']);
    $line = sprintf('%s: %s (fetched=%d +%d ~%d -%d edges=%d tags=%d requests=%d remaining=%s)',
        $programme->slug, $log->status, $log->nodesfetched ?? 0, $log->nodesinserted ?? 0,
        $log->nodesupdated ?? 0, $log->nodesdeleted ?? 0, $log->edgeschanged ?? 0,
        $log->tagschanged ?? 0, $log->requestcount ?? 0, $log->ratelimitremaining ?? '-');
    cli_writeln($line);
    if ($log->status === 'error') {
        cli_writeln('  error: ' . ($log->message ?? 'unknown'));
        $exitcode = 1;
    }
}
exit($exitcode);
