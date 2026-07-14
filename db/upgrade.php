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
 * Upgrade steps for local_curricmap.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the local_curricmap plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_curricmap_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026070710) {
        // M2: create the full schema for sites installed from the v0.1.0 scaffold
        // (fresh installs get these tables from install.xml directly).
        $tables = [
            'local_curricmap_programme',
            'local_curricmap_node',
            'local_curricmap_edge',
            'local_curricmap_tagfield',
            'local_curricmap_tagoption',
            'local_curricmap_nodetag',
            'local_curricmap_synclog',
            'local_curricmap_audit',
            'local_curricmap_binding',
        ];
        $installfile = __DIR__ . '/install.xml';
        foreach ($tables as $tablename) {
            if (!$dbman->table_exists($tablename)) {
                $dbman->install_one_table_from_xmldb_file($installfile, $tablename);
            }
        }

        upgrade_plugin_savepoint(true, 2026070710, 'local', 'curricmap');
    }

    if ($oldversion < 2026070800) {
        // M3: Sofia API request log table.
        if (!$dbman->table_exists('local_curricmap_apilog')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_curricmap_apilog');
        }

        upgrade_plugin_savepoint(true, 2026070800, 'local', 'curricmap');
    }

    if ($oldversion < 2026071300) {
        // Multi-academic-year model: programmes identified by (slug, versionlabel),
        // node identity becomes the composed key slug_academicyear_uuid.
        $programmetable = new xmldb_table('local_curricmap_programme');

        $field = new xmldb_field('displayname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'slug');
        if (!$dbman->field_exists($programmetable, $field)) {
            $dbman->add_field($programmetable, $field);
        }

        $oldindex = new xmldb_index('slug_uq', XMLDB_INDEX_UNIQUE, ['slug']);
        if ($dbman->index_exists($programmetable, $oldindex)) {
            $dbman->drop_index($programmetable, $oldindex);
        }
        $newindex = new xmldb_index('slugversion_uq', XMLDB_INDEX_UNIQUE, ['slug', 'versionlabel']);
        if (!$dbman->index_exists($programmetable, $newindex)) {
            $dbman->add_index($programmetable, $newindex);
        }

        // Widen the node key column (index must come off around the change),
        // then compose existing keys in place.
        $nodetable = new xmldb_table('local_curricmap_node');
        $uuidindex = new xmldb_index('uuid_uq', XMLDB_INDEX_UNIQUE, ['uuid']);
        if ($dbman->index_exists($nodetable, $uuidindex)) {
            $dbman->drop_index($nodetable, $uuidindex);
        }
        $uuidfield = new xmldb_field('uuid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null, 'programmeid');
        $dbman->change_field_precision($nodetable, $uuidfield);

        foreach ($DB->get_records('local_curricmap_programme') as $programme) {
            $prefix = \local_curricmap\local\sync::programme_prefix($programme) . '_';
            $concat = $DB->sql_concat("'" . $prefix . "'", 'uuid');
            $DB->execute(
                "UPDATE {local_curricmap_node} SET uuid = $concat WHERE programmeid = ? AND source = 'sofia'",
                [$programme->id]
            );
        }

        $dbman->add_index($nodetable, $uuidindex);

        // Bindings reference the composed key too (table is empty at this point).
        $bindingtable = new xmldb_table('local_curricmap_binding');
        $bindingindex = new xmldb_index('nodeuuid_relation_ix', XMLDB_INDEX_NOTUNIQUE, ['nodeuuid', 'relation']);
        if ($dbman->index_exists($bindingtable, $bindingindex)) {
            $dbman->drop_index($bindingtable, $bindingindex);
        }
        $bindingfield = new xmldb_field('nodeuuid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null, 'nodeid');
        $dbman->change_field_precision($bindingtable, $bindingfield);
        $dbman->add_index($bindingtable, $bindingindex);

        upgrade_plugin_savepoint(true, 2026071300, 'local', 'curricmap');
    }

    if ($oldversion < 2026071330) {
        // M8: binding gains category level, scope tier and sort order; resource
        // and (optional) group tables arrive.
        $table = new xmldb_table('local_curricmap_binding');

        $field = new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The dbman refuses to alter a field any index or key covers, so the
        // indexes over courseid/relation and the courseid foreign key step
        // aside while courseid becomes nullable and relation's default flips.
        $courserelationix = new xmldb_index('course_relation_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'relation']);
        $noderelationix = new xmldb_index('nodeuuid_relation_ix', XMLDB_INDEX_NOTUNIQUE, ['nodeuuid', 'relation']);
        $categoryrelationix = new xmldb_index('category_relation_ix', XMLDB_INDEX_NOTUNIQUE, ['categoryid', 'relation']);
        foreach ([$courserelationix, $noderelationix, $categoryrelationix] as $index) {
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
        }
        $courseidkey = new xmldb_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        if ($dbman->find_key_name($table, $courseidkey)) {
            $dbman->drop_key($table, $courseidkey);
        }

        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'categoryid');
        $dbman->change_field_notnull($table, $field);
        $field = new xmldb_field('relation', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'related', 'nodeuuid');
        $dbman->change_field_default($table, $field);
        $field = new xmldb_field('scope', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'course', 'relation');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'scope');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The find_key_name() result cannot be trusted to report the key's underlying
        // index, so check for the index itself before re-adding the key.
        $courseidix = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->index_exists($table, $courseidix)) {
            $dbman->add_key($table, $courseidkey);
        }
        foreach ([$courserelationix, $noderelationix, $categoryrelationix] as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        foreach (['local_curricmap_resource', 'local_curricmap_group', 'local_curricmap_groupitem'] as $tablename) {
            if (!$dbman->table_exists($tablename)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $tablename);
            }
        }

        upgrade_plugin_savepoint(true, 2026071330, 'local', 'curricmap');
    }

    return true;
}
