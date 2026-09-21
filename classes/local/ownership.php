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

namespace local_extsync\local;

/**
 * Registry of the course modules this plugin created.
 *
 * The web service receives module ids from an external system. An id alone proves nothing, so a
 * module is only updated, moved, replaced or deleted when it is recorded here, still exists in the
 * same course and still has the type it was created with. Teacher-created activities never match.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ownership {
    /**
     * The integration the current request acts as, creating the record on first use.
     *
     * The integration is resolved from the authenticated web service account and from nothing
     * else. A caller cannot name its own integration: the external functions accept no such
     * parameter, and Moodle refuses unexpected parameters outright.
     *
     * @return int integration id
     */
    public static function current_integration(): int {
        global $USER;

        return self::integration_for_user((int)($USER->id ?? 0), true);
    }

    /**
     * The integration of a web service account.
     *
     * @param int $userid the account
     * @param bool $create create the integration when the account has none yet
     * @return int integration id, 0 when the account has none and none was created
     */
    public static function integration_for_user(int $userid, bool $create = false): int {
        global $DB;

        if ($userid <= 0) {
            return 0;
        }
        if ($id = $DB->get_field('local_extsync_integration', 'id', ['userid' => $userid])) {
            return (int)$id;
        }
        if (!$create) {
            return 0;
        }

        // The key is derived from the account, never from the request.
        return (int)$DB->insert_record('local_extsync_integration', [
            'userid' => $userid,
            'integrationkey' => 'user-' . $userid,
            'timecreated' => time(),
        ]);
    }

    /**
     * Record a module created by the plugin, owned by the calling integration.
     *
     * @param int $courseid course id
     * @param int $cmid course module id
     * @param string $modname module type
     */
    public static function record(int $courseid, int $cmid, string $modname): void {
        global $DB;

        $DB->insert_record('local_extsync_module', [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'modname' => $modname,
            'integrationid' => self::current_integration(),
            'timecreated' => time(),
        ]);
    }

    /**
     * Forget a module and the questions imported for it, after it was deleted.
     *
     * @param int $cmid course module id
     */
    public static function forget(int $cmid): void {
        global $DB;

        $DB->delete_records('local_extsync_module', ['cmid' => $cmid]);
        $DB->delete_records('local_extsync_question', ['cmid' => $cmid]);
    }

    /**
     * Record the questions the plugin imported for a quiz, with the external system's reference for each.
     *
     * @param int $cmid quiz course module id
     * @param array $refs question id reported by the importer => the external system reference ('' when none)
     */
    public static function record_questions(int $cmid, array $refs): void {
        global $DB;

        $integrationid = self::current_integration();
        foreach ($refs as $questionid => $ref) {
            $DB->insert_record('local_extsync_question', [
                'questionid' => $questionid,
                'cmid' => $cmid,
                'ref' => $ref === '' ? null : $ref,
                'integrationid' => $integrationid,
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * The external system's references for questions used in an attempt of a quiz, from the provenance records.
     *
     * A question is matched through its question bank entry, so a new version a teacher saved of an
     * imported question keeps the reference. A question without provenance for this quiz, for example
     * one a teacher added with the same Moodle ID number, has no reference.
     *
     * @param int $cmid quiz course module id
     * @param int[] $questionids question ids used in the attempt
     * @return array|null question id => reference; null when the quiz has no provenance record at all
     */
    public static function question_refs(int $cmid, array $questionids): ?array {
        global $DB;

        if (!$DB->record_exists('local_extsync_question', ['cmid' => $cmid])) {
            return null;
        }
        if (!$questionids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
        $params['cmid'] = $cmid;

        return $DB->get_records_sql_menu(
            "SELECT qv.questionid, o.ref
               FROM {question_versions} qv
               JOIN {question_versions} ov ON ov.questionbankentryid = qv.questionbankentryid
               JOIN {local_extsync_question} o ON o.questionid = ov.questionid
              WHERE qv.questionid $insql
                AND o.cmid = :cmid
                AND o.ref IS NOT NULL",
            $params
        );
    }

    /**
     * Questions the plugin imported for a quiz that are still in that quiz's own question bank.
     *
     * Absence of use is no proof of ownership: only recorded questions are returned. A recorded
     * question a teacher moved to another question bank is no longer considered the exam's.
     *
     * @param \context_module $context quiz context
     * @return int[] question ids
     */
    public static function question_ids(\context_module $context): array {
        global $DB;

        // Only questions this integration imported are candidates for deletion.
        $integrationid = self::current_integration();
        if ($integrationid <= 0) {
            return [];
        }

        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT o.questionid
               FROM {local_extsync_question} o
               JOIN {question_versions} qv ON qv.questionid = o.questionid
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE o.cmid = :cmid
                AND o.integrationid = :integrationid
                AND qc.contextid = :contextid
           ORDER BY o.questionid",
            ['cmid' => $context->instanceid, 'integrationid' => $integrationid, 'contextid' => $context->id]
        ));
    }

    /**
     * Forget a question, after it was deleted.
     *
     * @param int $questionid question id
     */
    public static function forget_question(int $questionid): void {
        global $DB;

        $DB->delete_records('local_extsync_question', ['questionid' => $questionid]);
    }

    /**
     * Get an owned course module.
     *
     * @param int $courseid course the module must be in
     * @param int $cmid course module id from the caller
     * @param string|null $modname module type the caller expects, null for any
     * @return \stdClass|null course_modules record with modname, null when not owned
     */
    public static function get_cm(int $courseid, int $cmid, ?string $modname = null): ?\stdClass {
        global $DB;

        if ($cmid <= 0) {
            return null;
        }
        // Fails closed: an integration that cannot be resolved owns nothing, and a record whose
        // integration is unknown (NULL) never matches, so it is never changed or deleted.
        $integrationid = self::current_integration();
        if ($integrationid <= 0) {
            return null;
        }
        $params = ['cmid' => $cmid, 'courseid' => $courseid, 'integrationid' => $integrationid];
        $typesql = '';
        if ($modname !== null) {
            $typesql = 'AND m.name = :modname';
            $params['modname'] = $modname;
        }
        $cm = $DB->get_record_sql(
            "SELECT cm.*, m.name AS modname
               FROM {local_extsync_module} o
               JOIN {course_modules} cm ON cm.id = o.cmid AND cm.course = o.courseid
               JOIN {modules} m ON m.id = cm.module AND m.name = o.modname
              WHERE o.cmid = :cmid
                AND o.courseid = :courseid
                AND o.integrationid = :integrationid
                AND cm.deletioninprogress = 0
                    $typesql",
            $params
        );

        return $cm ?: null;
    }
}
