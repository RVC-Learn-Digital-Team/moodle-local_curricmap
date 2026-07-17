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
    // The plugin's pages live in their own category (like Quiz or Accessibility):
    // general settings, matching settings, status, and the matching page itself.
    $ADMIN->add('localplugins', new admin_category(
        'local_curricmap_category',
        get_string('pluginname', 'local_curricmap')
    ));

    // Keep the page name 'local_curricmap' so the plugins-overview Settings link resolves.
    $settings = new admin_settingpage('local_curricmap', get_string('settings:generalpage', 'local_curricmap'));
    $ADMIN->add('local_curricmap_category', $settings);

    $matchingsettings = new admin_settingpage(
        'local_curricmap_matchingsettings',
        get_string('settings:matchingpage', 'local_curricmap')
    );
    $matchingsettings->add(new admin_setting_configtextarea(
        'local_curricmap/matchingrules',
        get_string('settings:matchingrules', 'local_curricmap'),
        get_string('settings:matchingrules_desc', 'local_curricmap'),
        \local_curricmap\local\matcher::default_rules_json(),
        PARAM_RAW
    ));
    $ADMIN->add('local_curricmap_category', $matchingsettings);

    $ADMIN->add('local_curricmap_category', new admin_externalpage(
        'local_curricmap_status',
        get_string('statuspage', 'local_curricmap'),
        new moodle_url('/local/curricmap/status.php'),
        'local/curricmap:managesync'
    ));

    $ADMIN->add('local_curricmap_category', new admin_externalpage(
        'local_curricmap_coursemapping',
        get_string('coursemapping', 'local_curricmap'),
        new moodle_url('/local/curricmap/course_mapping.php'),
        'local/curricmap:managebindings'
    ));

    $ADMIN->add('local_curricmap_category', new admin_externalpage(
        'local_curricmap_contentmapping',
        get_string('contentmapping', 'local_curricmap'),
        new moodle_url('/local/curricmap/section_module_mapping.php'),
        'local/curricmap:managebindings'
    ));

    $ADMIN->add('local_curricmap_category', new admin_externalpage(
        'local_curricmap_studyresources',
        get_string('studyresources', 'local_curricmap'),
        new moodle_url('/local/curricmap/study_resources.php'),
        'local/curricmap:managebindings'
    ));

    $ADMIN->add('local_curricmap_category', new admin_externalpage(
        'local_curricmap_coverage',
        get_string('coverage', 'local_curricmap'),
        new moodle_url('/local/curricmap/coverage.php'),
        'local/curricmap:viewstaffmeta'
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
        'local_curricmap/resourcetypes',
        get_string('settings:resourcetypes', 'local_curricmap'),
        get_string('settings:resourcetypes_desc', 'local_curricmap'),
        'panopto, pebblepad, ebook, images, link',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_curricmap/discoveryfloor',
        get_string('settings:discoveryfloor', 'local_curricmap'),
        get_string('settings:discoveryfloor_desc', 'local_curricmap'),
        2020,
        PARAM_INT
    ));

    // Which activity module types are offered on the content mapping page.
    $modchoices = [];
    foreach (core_plugin_manager::instance()->get_installed_plugins('mod') as $modname => $unused) {
        $modchoices[$modname] = get_string('pluginname', 'mod_' . $modname);
    }
    asort($modchoices);
    $moddefaults = array_intersect(
        ['book', 'forum', 'lesson', 'lti', 'page', 'quiz', 'resource', 'url'],
        array_keys($modchoices)
    );
    $settings->add(new admin_setting_configmultiselect(
        'local_curricmap/mappablemodtypes',
        get_string('settings:mappablemodtypes', 'local_curricmap'),
        get_string('settings:mappablemodtypes_desc', 'local_curricmap'),
        $moddefaults,
        $modchoices
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
