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
 * Upgrade helpers for External Sync.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The user accounts authorised to call this plugin's web service.
 *
 * A module can only have been created by one of these, so they are both the evidence filter for
 * adoption and the answer to "does this site have exactly one integration?".
 *
 * @return int[] distinct user ids, may be empty
 */
function local_extsync_service_userids(): array {
    global $DB;

    // The local_selbe component is this plugin's earlier identity. A site that ran it created its
    // modules under that component's service; without it here adoption finds no evidence, every
    // object is reported notowned, and the next push duplicates the whole course (SI-20).
    $components = ['local_extsync', 'local_selbe'];
    [$csql1, $cparams1] = $DB->get_in_or_equal($components, SQL_PARAMS_NAMED, 'comp1');
    [$csql2, $cparams2] = $DB->get_in_or_equal($components, SQL_PARAMS_NAMED, 'comp2');

    $userids = $DB->get_fieldset_sql(
        "SELECT t.userid
           FROM {external_tokens} t
           JOIN {external_services} s ON s.id = t.externalserviceid
          WHERE s.component $csql1
          UNION
         SELECT su.userid
           FROM {external_services_users} su
           JOIN {external_services} s ON s.id = su.externalserviceid
          WHERE s.component $csql2",
        $cparams1 + $cparams2
    );

    return array_values(array_unique(array_map('intval', $userids)));
}

/**
 * Copy the records the former component kept, when its tables are still on the site.
 *
 * A site that ran local_selbe 2.0 has its ownership, question references and synchronisation
 * history in local_selbe_* tables. Those are exact records, better evidence than the log: without
 * the question rows fetch_grades loses every reference and replacing an exam cannot tell imported
 * questions from a teacher's, and without the sync rows the removal budget restarts at zero.
 *
 * The former tables are only read, never changed, so they stay a rollback source. Rows whose
 * Moodle object is gone, or that were already copied, are skipped, so running this twice is a
 * no-op. Integrations are not copied: 2.0 recorded none, so copied rows carry no integration and
 * are attributed afterwards from evidence, like any other row that predates integration ownership.
 *
 * @return int[] rows copied, keyed module, question and sync
 */
function local_extsync_copy_former_tables(): array {
    global $DB;

    $dbman = $DB->get_manager();
    $copied = ['module' => 0, 'question' => 0, 'sync' => 0];

    if ($dbman->table_exists('local_selbe_module')) {
        $rs = $DB->get_recordset_sql(
            "SELECT f.cmid, f.courseid, f.modname, f.timecreated
               FROM {local_selbe_module} f
               JOIN {course_modules} cm ON cm.id = f.cmid
          LEFT JOIN {local_extsync_module} o ON o.cmid = f.cmid
              WHERE o.id IS NULL"
        );
        foreach ($rs as $row) {
            $DB->insert_record('local_extsync_module', $row);
            $copied['module']++;
        }
        $rs->close();
    }

    if ($dbman->table_exists('local_selbe_question')) {
        // A former table from before 2.0.0 has no ref column; the ID number backfill covers those rows.
        $ref = $dbman->field_exists('local_selbe_question', 'ref') ? 'f.ref' : 'NULL';
        $rs = $DB->get_recordset_sql(
            "SELECT f.questionid, f.cmid, $ref AS ref, f.timecreated
               FROM {local_selbe_question} f
               JOIN {question} q ON q.id = f.questionid
               JOIN {course_modules} cm ON cm.id = f.cmid
          LEFT JOIN {local_extsync_question} o ON o.questionid = f.questionid
              WHERE o.id IS NULL"
        );
        foreach ($rs as $row) {
            $DB->insert_record('local_extsync_question', $row);
            $copied['question']++;
        }
        $rs->close();
    }

    if ($dbman->table_exists('local_selbe_sync') && !$DB->record_exists('local_extsync_sync', [])) {
        $rs = $DB->get_recordset_sql(
            "SELECT f.courseid, f.students, f.removed, f.timecreated
               FROM {local_selbe_sync} f
               JOIN {course} c ON c.id = f.courseid"
        );
        foreach ($rs as $row) {
            $DB->insert_record('local_extsync_sync', $row);
            $copied['sync']++;
        }
        $rs->close();
    }

    return $copied;
}

/**
 * Register modules created by earlier plugin versions, and attribute ones already registered.
 *
 * Earlier versions did not record which modules they created. The standard log still shows it:
 * a course_module_created event raised through a web service call by a user who holds a token for,
 * or is authorised on, this plugin's service. Only module types the plugin creates are adopted.
 * Without the standard log store nothing can be proven, so nothing is adopted.
 *
 * A row that already exists but carries no integration is *attributed* from the same evidence
 * rather than inserted again. That is the 2.0.0 -> 2.1.0 case: the row was written before
 * ownership was integration-scoped, and without attribution it would match no integration, the
 * caller would be told notowned, and the create branch would duplicate the whole lesson.
 *
 * @return int number of modules adopted or attributed
 */
function local_extsync_adopt_legacy_modules(): int {
    global $DB;

    if (!$DB->get_manager()->table_exists('logstore_standard_log')) {
        return 0;
    }

    $userids = local_extsync_service_userids();
    if (!$userids) {
        return 0;
    }

    [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
    [$modsql, $modparams] = $DB->get_in_or_equal(
        ['assign', 'page', 'quiz', 'resource', 'subsection'],
        SQL_PARAMS_NAMED,
        'mod'
    );
    // The log records which account created each module, so ownership is reconstructed from
    // evidence rather than assumed. MIN() keeps one creator per module when several accounts
    // logged an event for it, so a module is adopted exactly once.
    $sql = "SELECT cm.id AS cmid, cm.course AS courseid, m.name AS modname, o.id AS ownedid,
                   MIN(l.userid) AS userid
              FROM {logstore_standard_log} l
              JOIN {course_modules} cm ON cm.id = l.objectid AND cm.course = l.courseid
              JOIN {modules} m ON m.id = cm.module
         LEFT JOIN {local_extsync_module} o ON o.cmid = cm.id
             WHERE l.eventname = :eventname
               AND l.origin = :origin
               AND l.userid $usersql
               AND m.name $modsql
               AND (o.id IS NULL OR o.integrationid IS NULL)
          GROUP BY cm.id, cm.course, m.name, o.id";
    $params = ['eventname' => '\\core\\event\\course_module_created', 'origin' => 'ws'] + $userparams + $modparams;

    $count = 0;
    $now = time();
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach ($rs as $row) {
        // Ownership is attributed to the integration of the account that created the module.
        // A module whose creator cannot be resolved stays unowned and is never acted on again.
        $integrationid = \local_extsync\local\ownership::integration_for_user((int)$row->userid, true);
        if ($row->ownedid) {
            $DB->set_field('local_extsync_module', 'integrationid', $integrationid ?: null, ['id' => $row->ownedid]);
        } else {
            $DB->insert_record('local_extsync_module', [
                'cmid' => $row->cmid,
                'courseid' => $row->courseid,
                'modname' => $row->modname,
                'integrationid' => $integrationid ?: null,
                'timecreated' => $now,
            ]);
        }
        $count++;
    }
    $rs->close();

    return $count;
}

/**
 * Record the external system's reference for questions already recorded as imported by the plugin.
 *
 * Earlier versions recorded only the question id. Until now fetch_grades identified these questions by
 * their Moodle ID number, so that number is their reference. A question whose ID number is already gone
 * gets no reference: it cannot be proven any more. No question gains a provenance record here.
 *
 * @return int number of references recorded
 */
function local_extsync_backfill_question_refs(): int {
    global $DB;

    $rs = $DB->get_recordset_sql(
        "SELECT o.id, qbe.idnumber
           FROM {local_extsync_question} o
           JOIN {question_versions} qv ON qv.questionid = o.questionid
           JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
          WHERE o.ref IS NULL
            AND qbe.idnumber IS NOT NULL"
    );
    $count = 0;
    foreach ($rs as $row) {
        if (trim((string)$row->idnumber) !== '') {
            $DB->set_field('local_extsync_question', 'ref', $row->idnumber, ['id' => $row->id]);
            $count++;
        }
    }
    $rs->close();

    return $count;
}

/**
 * Attribute ownership rows the log could not explain, on a site that has only one integration.
 *
 * The standard log is rotated on most sites, so evidence-based attribution alone leaves rows
 * unowned, and an unowned row is one the caller is told it does not own — after which the create
 * branch duplicates the content. On a site where exactly one account is authorised on the service
 * there is only one integration those rows can belong to, so attributing them is a deduction, not
 * a guess (docs/V3-MIGRATION.md section 6).
 *
 * With two or more authorised accounts nothing is attributed: guessing between them is exactly the
 * cross-integration confusion SI-19 exists to prevent, and a wrong owner is worse than none.
 *
 * @return int number of module rows attributed
 */
function local_extsync_attribute_sole_integration(): int {
    global $DB;

    $userids = local_extsync_service_userids();
    if (count($userids) !== 1) {
        return 0;
    }

    $integrationid = \local_extsync\local\ownership::integration_for_user($userids[0], true);
    if ($integrationid <= 0) {
        return 0;
    }

    $count = $DB->count_records_select('local_extsync_module', 'integrationid IS NULL');
    if ($count) {
        $DB->set_field_select('local_extsync_module', 'integrationid', $integrationid, 'integrationid IS NULL');
    }

    return $count;
}

/**
 * Give each recorded question the integration that owns the quiz it was imported into.
 *
 * A question has no independent creator: it exists because a module was pushed, so its owner is
 * the module's owner. A question whose quiz is itself unowned stays unowned.
 *
 * @return int number of question rows attributed
 */
function local_extsync_attribute_question_integrations(): int {
    global $DB;

    $count = 0;
    $rs = $DB->get_recordset_select(
        'local_extsync_module',
        'integrationid IS NOT NULL',
        null,
        '',
        'id, cmid, integrationid'
    );
    foreach ($rs as $row) {
        $count += $DB->count_records_select(
            'local_extsync_question',
            'cmid = :cmid AND integrationid IS NULL',
            ['cmid' => $row->cmid]
        );
        $DB->set_field_select(
            'local_extsync_question',
            'integrationid',
            $row->integrationid,
            'cmid = :cmid AND integrationid IS NULL',
            ['cmid' => $row->cmid]
        );
    }
    $rs->close();

    return $count;
}
