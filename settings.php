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
 * Admin settings for local_curricmap.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_curricmap', get_string('pluginname', 'local_curricmap'));
    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_curricmap_status',
        get_string('statuspage', 'local_curricmap'),
        new moodle_url('/local/curricmap/status.php'),
        'local/curricmap:managesync'
    ));

    // Sofia API connection, with a link to the status page for connection testing.
    $statuslink = html_writer::link(
        new moodle_url('/local/curricmap/status.php'),
        get_string('statuspage_link', 'local_curricmap')
    );
    $settings->add(new admin_setting_heading(
        'local_curricmap/sofia_heading',
        get_string('settings:sofia_heading', 'local_curricmap'),
        get_string('settings:sofia_heading_desc', 'local_curricmap') . ' ' . $statuslink
    ));

    $settings->add(new admin_setting_configtext(
        'local_curricmap/sofia_baseurl',
        get_string('settings:baseurl', 'local_curricmap'),
        get_string('settings:baseurl_desc', 'local_curricmap'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_curricmap/sofia_clientid',
        get_string('settings:clientid', 'local_curricmap'),
        get_string('settings:clientid_desc', 'local_curricmap'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_curricmap/sofia_clientsecret',
        get_string('settings:clientsecret', 'local_curricmap'),
        get_string('settings:clientsecret_desc', 'local_curricmap'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_curricmap/ratelimitfloor',
        get_string('settings:ratelimitfloor', 'local_curricmap'),
        get_string('settings:ratelimitfloor_desc', 'local_curricmap'),
        10,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_curricmap/programmeslugs',
        get_string('settings:programmeslugs', 'local_curricmap'),
        get_string('settings:programmeslugs_desc', 'local_curricmap'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_curricmap/discoveryfloor',
        get_string('settings:discoveryfloor', 'local_curricmap'),
        get_string('settings:discoveryfloor_desc', 'local_curricmap'),
        2020,
        PARAM_INT
    ));

    // Diagnostics.
    $settings->add(new admin_setting_heading(
        'local_curricmap/diagnostics_heading',
        get_string('settings:diagnostics_heading', 'local_curricmap'),
        get_string('settings:diagnostics_heading_desc', 'local_curricmap')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_curricmap/enabledebuglog',
        get_string('settings:debuglog', 'local_curricmap'),
        get_string('settings:debuglog_desc', 'local_curricmap'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_curricmap/apilogretention',
        get_string('settings:apilogretention', 'local_curricmap'),
        get_string('settings:apilogretention_desc', 'local_curricmap'),
        30,
        PARAM_INT
    ));
}
