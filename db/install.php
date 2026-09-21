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
 * Install steps for External Sync.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adopt objects an earlier identity of this plugin created on this site.
 *
 * Moodle has no component rename: local_extsync installs as a new plugin with empty ownership
 * tables. On a site that ran the earlier component that is the SI-20 failure — every course module
 * it created becomes unowned, the next push is told notowned, takes the create branch, and the
 * whole course is duplicated while the originals stay behind unmanaged.
 *
 * A site that ran the earlier component's 2.0 still has its tables; their rows are copied first,
 * because they are exact. Adoption then reconstructs ownership from the standard log, which records which account created each
 * module through a web service. On a site that never ran the earlier component there is no such
 * evidence and nothing is adopted, which is the correct outcome for a fresh install.
 *
 * @return void
 */
function xmldb_local_extsync_install(): void {
    global $CFG;

    require_once($CFG->dirroot . '/local/extsync/db/upgradelib.php');

    // Exact records first; the log then attributes them and adopts what they do not cover.
    local_extsync_copy_former_tables();
    local_extsync_adopt_legacy_modules();
    local_extsync_attribute_sole_integration();
    local_extsync_attribute_question_integrations();
    local_extsync_backfill_question_refs();
}
