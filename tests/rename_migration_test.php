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

namespace local_extsync;

use core_external\external_api;
use local_extsync\local\ownership;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/extsync/db/install.php');
require_once($CFG->dirroot . '/local/extsync/db/upgradelib.php');

/**
 * Acceptance tests for the rename: a site that ran local_selbe installs local_extsync beside it.
 *
 * Each test builds the state such a site is in, runs the install step exactly as Moodle does, and
 * replays a push with the ids the external system already holds. The property defended is SI-20:
 * the replay updates what exists and creates nothing again (docs/V3-MIGRATION.md section 12).
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_extsync_install
 * @covers     ::local_extsync_copy_former_tables
 */
final class rename_migration_test extends \advanced_testcase {
    /** @var \stdClass course the former component pushed into */
    private $course;

    /** @var \stdClass the web service account, authorised on the former component's service only */
    private $service;

    /**
     * A course and a connector account exactly as the former component's site has them.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->service = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $serviceid = $DB->insert_record('external_services', [
            'name' => 'Former lesson push', 'shortname' => 'selbe', 'enabled' => 1, 'restrictedusers' => 1,
            'component' => 'local_selbe', 'timecreated' => time(), 'timemodified' => time(),
            'downloadfiles' => 0, 'uploadfiles' => 0,
        ]);
        $DB->insert_record('external_services_users', [
            'externalserviceid' => $serviceid, 'userid' => $this->service->id, 'timecreated' => time(),
        ]);
        $this->setUser($this->service);
    }

    /**
     * Moodle XML with true/false questions.
     *
     * @param int $count number of questions
     * @return string
     */
    private function xml(int $count): string {
        $questions = '';
        for ($i = 1; $i <= $count; $i++) {
            $questions .= '<question type="truefalse"><name><text>E' . $i . '</text></name>'
                . '<questiontext format="html"><text>Statement ' . $i . '</text></questiontext>'
                . '<idnumber>ref-' . $i . '</idnumber>'
                . '<answer fraction="100"><text>true</text></answer>'
                . '<answer fraction="0"><text>false</text></answer></question>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><quiz>' . $questions . '</quiz>';
    }

    /**
     * Push a lesson with an assignment.
     *
     * @param int $sectionid section to reuse, 0 to create
     * @param int $assigncmid assignment to reuse, 0 to create
     * @return array cleaned result
     */
    private function lesson(int $sectionid = 0, int $assigncmid = 0): array {
        $result = external::push_lesson(
            $this->course->id,
            $sectionid,
            '',
            0,
            0,
            true,
            $assigncmid,
            [],
            'Lesson',
            '',
            '',
            '',
            [],
            0,
            '',
            '',
            [],
            [],
            [],
            [],
            1
        );

        return external_api::clean_returnvalue(external::push_lesson_returns(), $result);
    }

    /**
     * Push an exam.
     *
     * @param int $quizcmid quiz to replace, 0 to create
     * @param int $count number of questions
     * @return array cleaned result
     */
    private function exam(int $quizcmid, int $count): array {
        $result = external::push_exam($this->course->id, 0, '', $quizcmid, 'Exam', '', $this->xml($count));

        return external_api::clean_returnvalue(external::push_exam_returns(), $result);
    }

    /**
     * Number of course modules in the course.
     *
     * @return int
     */
    private function cms(): int {
        global $DB;

        return $DB->count_records('course_modules', ['course' => $this->course->id]);
    }

    /**
     * Log every module of the course as created through the web service by the connector account.
     */
    private function log_all_created(): void {
        global $DB;
        foreach ($DB->get_records('course_modules', ['course' => $this->course->id]) as $cm) {
            $context = \context_module::instance($cm->id);
            $DB->insert_record('logstore_standard_log', [
                'eventname' => '\\core\\event\\course_module_created', 'component' => 'core', 'action' => 'created',
                'target' => 'course_module', 'objecttable' => 'course_modules', 'objectid' => $cm->id,
                'crud' => 'c', 'edulevel' => 1, 'contextid' => $context->id, 'contextlevel' => CONTEXT_MODULE,
                'contextinstanceid' => $cm->id, 'userid' => $this->service->id, 'courseid' => $cm->course,
                'anonymous' => 0, 'timecreated' => time(), 'origin' => 'ws',
            ]);
        }
    }

    /**
     * Empty this plugin's tables: the state of a component Moodle has just installed.
     */
    private function fresh_install_state(): void {
        global $DB;
        foreach (['local_extsync_question', 'local_extsync_module', 'local_extsync_sync', 'local_extsync_integration'] as $t) {
            $DB->delete_records($t);
        }
    }

    /**
     * Create the former component's 2.0.0 tables and fill them from this plugin's current rows.
     *
     * Temporary tables, so the test database keeps its schema; the SQL is the same.
     */
    private function former_tables_from_current(): void {
        global $DB;
        $dbman = $DB->get_manager();

        $table = new \xmldb_table('local_selbe_module');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('modname', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_temp_table($table);

        $table = new \xmldb_table('local_selbe_question');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('ref', XMLDB_TYPE_CHAR, '100');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_temp_table($table);

        $table = new \xmldb_table('local_selbe_sync');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('students', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('removed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_temp_table($table);

        foreach ($DB->get_records('local_extsync_module') as $row) {
            $DB->insert_record('local_selbe_module', [
                'cmid' => $row->cmid, 'courseid' => $row->courseid, 'modname' => $row->modname,
                'timecreated' => $row->timecreated,
            ]);
        }
        foreach ($DB->get_records('local_extsync_question') as $row) {
            $DB->insert_record('local_selbe_question', [
                'questionid' => $row->questionid, 'cmid' => $row->cmid, 'ref' => $row->ref,
                'timecreated' => $row->timecreated,
            ]);
        }
        $DB->insert_record('local_selbe_sync', [
            'courseid' => $this->course->id, 'students' => 20, 'removed' => 4, 'timecreated' => time(),
        ]);
    }

    /**
     * Drop the former component's temporary tables, also after a failure, so no other test sees them.
     */
    protected function tearDown(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (['local_selbe_module', 'local_selbe_question', 'local_selbe_sync'] as $name) {
            if ($dbman->table_exists($name)) {
                $dbman->drop_table(new \xmldb_table($name));
            }
        }
        parent::tearDown();
    }

    /**
     * Acceptance 1 and 14: a 1.x site kept no ownership tables; the log proves ownership, and the
     * replayed push updates in place instead of duplicating the course.
     */
    public function test_former_1x_site_replay_does_not_duplicate(): void {
        $first = $this->lesson();
        $this->log_all_created();
        $this->fresh_install_state();
        $before = $this->cms();

        xmldb_local_extsync_install();
        $replay = $this->lesson((int)$first['sectionid'], (int)$first['assigncmid']);

        $this->assertNotContains('notowned', array_column($replay['warnings'], 'warningcode'));
        $this->assertSame($first['assigncmid'], $replay['assigncmid']);
        $this->assertSame($before, $this->cms(), 'The replay must not create any module again');
    }

    /**
     * Acceptance 2, 13, 14 and 16: a 2.0.0 site's tables are copied row for row even with the log
     * rotated away; lessons and exams then update in place, question references survive, the
     * removal budget keeps its history, the former tables stay untouched, and a second run is a no-op.
     */
    public function test_former_20_site_tables_are_copied_and_replay_does_not_duplicate(): void {
        global $DB;
        $lesson = $this->lesson();
        $exam = $this->exam(0, 2);
        $refs = $DB->get_fieldset_select('local_extsync_question', 'ref', 'cmid = ?', [$exam['quizcmid']]);
        $this->former_tables_from_current();
        $this->fresh_install_state();
        $before = $this->cms();
        $formerrows = $DB->count_records('local_selbe_module') + $DB->count_records('local_selbe_question');

        xmldb_local_extsync_install();

        // Row for row, with the references the external system resolves grades by.
        $this->assertSame($DB->count_records('local_selbe_module'), $DB->count_records('local_extsync_module'));
        $copiedrefs = $DB->get_fieldset_select('local_extsync_question', 'ref', 'cmid = ?', [$exam['quizcmid']]);
        sort($refs);
        sort($copiedrefs);
        $this->assertSame($refs, $copiedrefs);
        $this->assertSame(1, $DB->count_records('local_extsync_sync', ['courseid' => $this->course->id, 'removed' => 4]));
        $this->assertNotEmpty(ownership::question_ids(\context_module::instance($exam['quizcmid'])));

        // A second install step changes nothing.
        $this->assertSame(['module' => 0, 'question' => 0, 'sync' => 0], local_extsync_copy_former_tables());

        $replay = $this->lesson((int)$lesson['sectionid'], (int)$lesson['assigncmid']);
        $this->assertNotContains('notowned', array_column($replay['warnings'], 'warningcode'));
        $this->assertSame($lesson['assigncmid'], $replay['assigncmid']);

        $replaced = $this->exam((int)$exam['quizcmid'], 3);
        $this->assertTrue($replaced['replaced'], 'The migrated exam must be replaceable by its owner');
        $this->assertSame($exam['quizcmid'], $replaced['quizcmid']);
        $quizid = $DB->get_field('course_modules', 'instance', ['id' => $exam['quizcmid']]);
        $this->assertSame(3, $DB->count_records('quiz_slots', ['quizid' => $quizid]));

        $this->assertSame($before, $this->cms(), 'The replay must not create any module again');
        $this->assertSame(
            $formerrows,
            $DB->count_records('local_selbe_module') + $DB->count_records('local_selbe_question'),
            'The former tables are a rollback source and must stay untouched'
        );
    }

    /**
     * Acceptance 3: a site that never ran the former component installs with nothing adopted.
     */
    public function test_fresh_site_adopts_nothing(): void {
        global $DB;
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->fresh_install_state();

        xmldb_local_extsync_install();

        $this->assertSame(0, $DB->count_records('local_extsync_module'));
        $this->assertSame(0, $DB->count_records('local_extsync_question'));
        $this->assertSame(0, $DB->count_records('local_extsync_sync'));
    }
}
