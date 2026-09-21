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

/**
 * Exam push tests.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\external::push_exam
 * @covers     \local_extsync\local\modules::update_quiz_settings
 */
final class push_exam_test extends \advanced_testcase {
    /** @var \stdClass course */
    private $course;

    /** @var \stdClass web service user */
    private $service;

    /**
     * Course with a teacher acting as the web service account.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->service = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
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
                . '<idnumber>chk-' . $i . '</idnumber>'
                . '<answer fraction="100"><text>true</text></answer><answer fraction="0"><text>false</text></answer>'
                . '</question>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><quiz>' . $questions . '</quiz>';
    }

    /**
     * Call push_exam with named arguments.
     *
     * @param array $args arguments by name
     * @return array cleaned result
     */
    private function exam(array $args): array {
        $values = [];
        $args += ['courseid' => $this->course->id, 'name' => 'Final exam'];
        foreach (external::push_exam_parameters()->keys as $name => $description) {
            $values[] = array_key_exists($name, $args) ? $args[$name] : $description->default;
        }
        $result = external::push_exam(...$values);
        return external_api::clean_returnvalue(external::push_exam_returns(), $result);
    }

    /**
     * Number of questions in a quiz.
     *
     * @param int $cmid quiz course module id
     * @return int
     */
    private function slots(int $cmid): int {
        global $DB;
        return $DB->count_records('quiz_slots', ['quizid' => $DB->get_field('course_modules', 'instance', ['id' => $cmid])]);
    }

    /**
     * Ids of the questions in a quiz's own question bank.
     *
     * @param int $cmid quiz course module id
     * @return int[]
     */
    private function bank(int $cmid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT q.id
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid = ?
           ORDER BY q.id",
            [\context_module::instance($cmid)->id]
        ));
    }

    /**
     * A question written in Moodle by the current user in a quiz's own question bank.
     *
     * @param int $cmid quiz course module id
     * @return int question id
     */
    private function handwritten_question(int $cmid): int {
        $category = \local_extsync\local\question_import::default_category(\context_module::instance($cmid));
        return (int)$this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('truefalse', null, ['category' => $category->id])->id;
    }

    /**
     * Replacement deletes the questions the plugin imported and nothing else: not questions a
     * colleague wrote, used or unused, nor questions the web service account itself wrote in Moodle.
     */
    public function test_replace_deletes_only_imported_questions(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $first = $this->exam(['xml' => $this->xml(2)]);
        $imported = $this->bank($first['quizcmid']);
        $colleague = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($colleague);
        $unused = $this->handwritten_question($first['quizcmid']);
        $inquiz = $this->handwritten_question($first['quizcmid']);
        $quiz = $DB->get_record('quiz', ['id' => $DB->get_field('course_modules', 'instance', ['id' => $first['quizcmid']])]);
        $quiz->cmid = $first['quizcmid'];
        quiz_add_quiz_question($inquiz, $quiz);
        $this->setUser($this->service);
        $handwritten = $this->handwritten_question($first['quizcmid']);

        $second = $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(3)]);

        $this->assertTrue($second['replaced']);
        $this->assertSame(3, $this->slots($first['quizcmid']));
        $bank = $this->bank($first['quizcmid']);
        foreach ($imported as $questionid) {
            $this->assertNotContains($questionid, $bank);
        }
        foreach ([$unused, $inquiz, $handwritten] as $questionid) {
            $this->assertContains($questionid, $bank);
        }
        $recorded = array_map('intval', $DB->get_fieldset_select(
            'local_extsync_question',
            'questionid',
            'cmid = ?',
            [$first['quizcmid']]
        ));
        $this->assertEqualsCanonicalizing(array_values(array_diff($bank, [$unused, $inquiz, $handwritten])), $recorded);
        $this->assertCount(3, $recorded);
    }

    /**
     * An imported question that a teacher reuses in another quiz is kept, as are that quiz's questions.
     */
    public function test_question_used_by_another_quiz_is_kept(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $first = $this->exam(['xml' => $this->xml(2)]);
        $imported = $this->bank($first['quizcmid']);
        $teacherquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $teacherquestion = $this->handwritten_question((int)$teacherquiz->cmid);
        quiz_add_quiz_question($imported[0], $teacherquiz);
        quiz_add_quiz_question($teacherquestion, $teacherquiz);

        $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(1)]);

        $bank = $this->bank($first['quizcmid']);
        $this->assertContains($imported[0], $bank);
        // Not even hidden, as Moodle does when a question in use is deleted.
        $this->assertSame('ready', $DB->get_field('question_versions', 'status', ['questionid' => $imported[0]]));
        $this->assertNotContains($imported[1], $bank);
        $this->assertContains($teacherquestion, $this->bank((int)$teacherquiz->cmid));
        $this->assertSame(2, $this->slots((int)$teacherquiz->cmid));
    }

    /**
     * An imported question a teacher moved to another question bank is no longer the exam's and is kept.
     */
    public function test_imported_question_moved_to_another_bank_is_kept(): void {
        $first = $this->exam(['xml' => $this->xml(2)]);
        $imported = $this->bank($first['quizcmid']);
        $teacherquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $target = \local_extsync\local\question_import::default_category(\context_module::instance((int)$teacherquiz->cmid));
        question_move_questions_to_category([$imported[0]], $target->id);

        $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(1)]);

        $this->assertContains($imported[0], $this->bank((int)$teacherquiz->cmid));
        $this->assertNotContains($imported[1], $this->bank($first['quizcmid']));
    }

    /**
     * A caller who may not edit questions replaces the exam but deletes no question.
     */
    public function test_replace_without_question_edit_capability_deletes_nothing(): void {
        global $DB;
        $first = $this->exam(['xml' => $this->xml(2)]);
        $imported = $this->bank($first['quizcmid']);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $contextid = \context_module::instance($first['quizcmid'])->id;
        assign_capability('moodle/question:editall', CAP_PROHIBIT, $roleid, $contextid, true);
        assign_capability('moodle/question:editmine', CAP_PROHIBIT, $roleid, $contextid, true);

        $second = $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(3)]);

        $this->assertTrue($second['replaced']);
        $this->assertSame(3, $this->slots($first['quizcmid']));
        $bank = $this->bank($first['quizcmid']);
        foreach ($imported as $questionid) {
            $this->assertContains($questionid, $bank);
        }
    }

    /**
     * Repeated replacement only ever removes the previous import.
     */
    public function test_repeated_replacement_keeps_other_questions(): void {
        $first = $this->exam(['xml' => $this->xml(2)]);
        $handwritten = $this->handwritten_question($first['quizcmid']);

        for ($i = 0; $i < 3; $i++) {
            $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(2)]);
        }

        $bank = $this->bank($first['quizcmid']);
        $this->assertContains($handwritten, $bank);
        $this->assertCount(3, $bank);
    }

    /**
     * Questions imported before the plugin recorded provenance cannot be proven to be the plugin's
     * and are kept.
     */
    public function test_questions_without_provenance_are_kept(): void {
        global $DB;
        $first = $this->exam(['xml' => $this->xml(2)]);
        $legacy = $this->bank($first['quizcmid']);
        // The state of an exam created by an earlier plugin version.
        $DB->delete_records('local_extsync_question', ['cmid' => $first['quizcmid']]);

        $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(1)]);

        $bank = $this->bank($first['quizcmid']);
        foreach ($legacy as $questionid) {
            $this->assertContains($questionid, $bank);
        }
        $this->assertSame(1, $this->slots($first['quizcmid']));
    }

    /**
     * A module is only owned as the type it was recorded with.
     */
    public function test_ownership_requires_the_recorded_module_type(): void {
        $teacherquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        // A record that does not match the module, as after tampering.
        \local_extsync\local\ownership::record((int)$this->course->id, (int)$teacherquiz->cmid, 'page');

        $result = $this->exam(['quizcmid' => $teacherquiz->cmid, 'xml' => $this->xml(1)]);

        $this->assertTrue($result['created']);
        $this->assertNotEquals($teacherquiz->cmid, $result['quizcmid']);
        $this->assertSame(0, $this->slots((int)$teacherquiz->cmid));
    }

    /**
     * A new exam gets its times through create_module, including the calendar events.
     */
    public function test_create_sets_times_and_events(): void {
        global $DB;
        $open = time() + DAYSECS;
        $close = time() + 2 * DAYSECS;

        $result = $this->exam(['xml' => $this->xml(2), 'timeopen' => $open, 'timeclose' => $close,
            'intro' => '<p>Rules</p><script>x()</script>']);

        $quiz = $DB->get_record('quiz', ['id' => $DB->get_field('course_modules', 'instance', ['id' => $result['quizcmid']])]);
        $this->assertTrue($result['created']);
        $this->assertSame(2, $result['questions']);
        $this->assertEquals($open, $quiz->timeopen);
        $this->assertEquals($close, $quiz->timeclose);
        $this->assertEquals(1, $quiz->attempts);
        $this->assertStringNotContainsString('<script', $quiz->intro);
        $this->assertTrue($DB->record_exists('event', ['modulename' => 'quiz', 'instance' => $quiz->id, 'timestart' => $close]));
    }

    /**
     * Questions of an unattempted exam are replaced; its settings change through update_module and
     * keep what the teacher configured.
     */
    public function test_replace_without_attempts(): void {
        global $DB;
        $first = $this->exam(['xml' => $this->xml(2)]);
        $quizid = $DB->get_field('course_modules', 'instance', ['id' => $first['quizcmid']]);
        $DB->set_field('quiz', 'password', 'secret', ['id' => $quizid]);
        $DB->set_field('quiz_feedback', 'feedbacktext', 'Well done', ['quizid' => $quizid]);
        $close = time() + 3 * DAYSECS;

        $second = $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(3), 'timeclose' => $close]);

        $this->assertTrue($second['replaced']);
        $this->assertSame($first['quizcmid'], $second['quizcmid']);
        $this->assertSame(3, $this->slots($first['quizcmid']));
        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        $this->assertEquals($close, $quiz->timeclose);
        $this->assertSame('secret', $quiz->password);
        $this->assertSame('Well done', $DB->get_field('quiz_feedback', 'feedbacktext', ['quizid' => $quizid]));
        $this->assertTrue($DB->record_exists('event', ['modulename' => 'quiz', 'instance' => $quizid, 'timestart' => $close]));
        $this->assertSame(1, $DB->count_records('course_sections', ['course' => $this->course->id, 'id' => $first['sectionid']]));
    }

    /**
     * A finished attempt blocks replacement.
     */
    public function test_replace_with_finished_attempt_is_refused(): void {
        $this->assert_attempt_blocks_replacement(true);
    }

    /**
     * An attempt still in progress blocks replacement too.
     */
    public function test_replace_with_inprogress_attempt_is_refused(): void {
        $this->assert_attempt_blocks_replacement(false);
    }

    /**
     * Create an attempt and check that replacing the questions is refused and changes nothing.
     *
     * @param bool $finish finish the attempt
     */
    private function assert_attempt_blocks_replacement(bool $finish): void {
        global $DB;
        $first = $this->exam(['xml' => $this->xml(2)]);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $quizid = $DB->get_field('course_modules', 'instance', ['id' => $first['quizcmid']]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        // Attempts started by a teacher are previews, which do not count.
        $this->setUser($student);
        $attempt = $generator->create_attempt($quizid, $student->id);
        if ($finish) {
            $generator->submit_responses($attempt->id, [], false, true);
        }
        $this->setUser($this->service);

        try {
            $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(3), 'force' => 1]);
            $this->fail('Questions of an attempted exam were replaced');
        } catch (\moodle_exception $e) {
            $this->assertSame('examhasattempts', $e->errorcode);
        }
        $this->assertSame(2, $this->slots($first['quizcmid']));
    }

    /**
     * A refused replacement does not move the exam to the section that was sent.
     */
    public function test_refused_replacement_does_not_move_the_exam(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $first = $this->exam(['xml' => $this->xml(2)]);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $quizid = $DB->get_field('course_modules', 'instance', ['id' => $first['quizcmid']]);
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_attempt($quizid, $student->id);
        $this->setUser($this->service);
        $other = course_create_section($this->course->id);

        try {
            $this->exam(['quizcmid' => $first['quizcmid'], 'sectionid' => $other->id, 'xml' => $this->xml(3)]);
            $this->fail('An attempted exam was replaced');
        } catch (\moodle_exception $e) {
            $this->assertSame('examhasattempts', $e->errorcode);
        }

        $this->assertEquals($first['sectionid'], $DB->get_field('course_modules', 'section', ['id' => $first['quizcmid']]));
    }

    /**
     * A new exam whose questions cannot be imported leaves no empty section behind.
     */
    public function test_failed_new_exam_leaves_no_section(): void {
        global $DB;
        $sections = $DB->count_records('course_sections', ['course' => $this->course->id]);

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->exam(['xml' => '<quiz><question type="truefalse"']);
                $this->fail('A broken exam was accepted');
            } catch (\moodle_exception $e) {
                $this->assertSame('importfailed', $e->errorcode);
            }
        }

        $this->assertSame($sections, $DB->count_records('course_sections', ['course' => $this->course->id]));
    }

    /**
     * Dates sent for an exam that is not replaced are reported as not applied.
     */
    public function test_settings_without_replacement_are_reported(): void {
        $first = $this->exam(['xml' => $this->xml(1)]);

        $result = $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(1), 'replace' => 0,
            'timeclose' => time() + DAYSECS]);

        $this->assertFalse($result['replaced']);
        $this->assertContains('settingsnotapplied', array_column($result['warnings'], 'warningcode'));
    }

    /**
     * A replacement is refused, and changes nothing, while another replacement of the same exam holds its lock.
     */
    public function test_replacement_is_refused_while_another_runs(): void {
        global $CFG;
        $first = $this->exam(['xml' => $this->xml(2)]);
        // Database record locks also conflict within one process, as two web requests would.
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $lock = \core\lock\lock_config::get_lock_factory('local_extsync')->get_lock('push_exam_' . $first['quizcmid'], 0);
        $this->assertNotFalse($lock);

        try {
            $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(3)]);
            $this->fail('An exam was replaced while another replacement held its lock');
        } catch (\moodle_exception $e) {
            $this->assertSame('examinprogress', $e->errorcode);
        } finally {
            $lock->release();
        }

        $this->assertSame(2, $this->slots($first['quizcmid']));
    }

    /**
     * A failed import leaves the exam exactly as it was.
     */
    public function test_failed_import_keeps_exam(): void {
        // The replacement's own transaction must really roll back, not only inside the test's transaction.
        $this->preventResetByRollback();
        $first = $this->exam(['xml' => $this->xml(2)]);

        try {
            $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => '<quiz><question type="truefalse"']);
            $this->fail('A broken import was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('importfailed', $e->errorcode);
        }
        $this->assertSame(2, $this->slots($first['quizcmid']));
    }

    /**
     * A teacher's own quiz is never replaced; a new exam is created instead.
     */
    public function test_teacher_quiz_is_not_replaced(): void {
        $teacherquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);

        $result = $this->exam(['quizcmid' => $teacherquiz->cmid, 'xml' => $this->xml(1)]);

        $this->assertTrue($result['created']);
        $this->assertNotEquals($teacherquiz->cmid, $result['quizcmid']);
        $this->assertSame(0, $this->slots($teacherquiz->cmid));
        $this->assertContains('notowned', array_column($result['warnings'], 'warningcode'));
    }

    /**
     * Replacing questions needs mod/quiz:manage in the quiz context.
     */
    public function test_replace_requires_quiz_manage(): void {
        global $DB;
        $first = $this->exam(['xml' => $this->xml(2)]);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('mod/quiz:manage', CAP_PROHIBIT, $roleid, \context_module::instance($first['quizcmid'])->id, true);

        try {
            $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(3)]);
            $this->fail('Questions replaced without mod/quiz:manage');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_capability_string('mod/quiz:manage'), $e->getMessage());
        }
        $this->assertSame(2, $this->slots($first['quizcmid']));
    }
}
