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
 * Question identity tests: the external system's question references survive exam replacement and reach fetch_grades.
 *
 * Each question's default grade is chosen per reference, and students answer some questions right and
 * others wrong, so a result reported under the wrong reference shows up as a wrong mark or fraction.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\external::push_exam
 * @covers     \local_extsync\external::fetch_grades
 * @covers     \local_extsync\local\question_import
 * @covers     \local_extsync\local\ownership
 */
final class question_identity_test extends \advanced_testcase {
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
     * Moodle XML with one true/false question per reference, in the given order. "true" is correct.
     *
     * @param array $refs reference => default grade
     * @param string $version label that makes the texts of each payload version different
     * @return string
     */
    private function xml(array $refs, string $version = 'v1'): string {
        $questions = '';
        foreach ($refs as $ref => $grade) {
            $questions .= '<question type="truefalse"><name><text>' . $version . ' ' . s($ref) . '</text></name>'
                . '<questiontext format="html"><text>' . $version . ' statement ' . s($ref) . '</text></questiontext>'
                . '<idnumber>' . s($ref) . '</idnumber><defaultgrade>' . $grade . '</defaultgrade>'
                . '<answer fraction="100"><text>true</text></answer><answer fraction="0"><text>false</text></answer>'
                . '</question>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><quiz>' . $questions . '</quiz>';
    }

    /**
     * Call push_exam as the web service account.
     *
     * @param array $args arguments by name
     * @return array cleaned result
     */
    private function exam(array $args): array {
        $this->setUser($this->service);
        $values = [];
        $args += ['courseid' => $this->course->id, 'name' => 'Exam'];
        foreach (external::push_exam_parameters()->keys as $name => $description) {
            $values[] = array_key_exists($name, $args) ? $args[$name] : $description->default;
        }
        return external_api::clean_returnvalue(external::push_exam_returns(), external::push_exam(...$values));
    }

    /**
     * A student finishes an attempt with the given answers.
     *
     * @param int $cmid quiz course module id
     * @param array $answers slot => 'True' or 'False'
     * @return \stdClass the student
     */
    private function attempt(int $cmid, array $answers): \stdClass {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attempt = $generator->create_attempt($DB->get_field('course_modules', 'instance', ['id' => $cmid]), $student->id);
        $generator->submit_responses($attempt->id, $answers, false, true);
        $this->setUser($this->service);
        return $student;
    }

    /**
     * The questions fetch_grades reports for a student, by reference; fails on a repeated reference.
     *
     * @param int $cmid quiz course module id
     * @param \stdClass $student the student
     * @return array reference => [fraction, mark, maxmark]
     */
    private function results(int $cmid, \stdClass $student): array {
        $this->setUser($this->service);
        $rows = external_api::clean_returnvalue(
            external::fetch_grades_returns(),
            external::fetch_grades($this->course->id, $cmid)
        );
        $row = array_column($rows, null, 'moodleuserid')[$student->id];
        $byref = [];
        foreach ($row['questions'] as $question) {
            $this->assertArrayNotHasKey($question['ref'], $byref, 'Reference reported twice: ' . $question['ref']);
            $byref[$question['ref']] = [(float)$question['fraction'], (float)$question['mark'], (float)$question['maxmark']];
        }
        return $byref;
    }

    /**
     * Moodle ID numbers of the questions in a quiz's own question bank.
     *
     * @param int $cmid quiz course module id
     * @return array question id => ID number ('' when none)
     */
    private function bank(int $cmid): array {
        global $DB;
        return array_map('strval', $DB->get_records_sql_menu(
            "SELECT q.id, qbe.idnumber
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
     * Provenance records carry each imported question's reference, trimmed, and follow replacement.
     */
    public function test_references_are_recorded_with_provenance(): void {
        global $DB;
        $first = $this->exam(['xml' => $this->xml([' q1 ' => 1, 'q2' => 2])]);
        $cmid = $first['quizcmid'];
        $recorded = fn() => $DB->get_records_menu('local_extsync_question', ['cmid' => $cmid], 'questionid', 'questionid, ref');

        $this->assertSame(array_keys($this->bank($cmid)), array_keys($recorded()));
        $this->assertSame(['q1', 'q2'], array_values($recorded()));

        $this->exam(['quizcmid' => $cmid, 'xml' => $this->xml(['q1' => 1, 'q2' => 2], 'v2')]);

        $this->assertSame(array_keys($this->bank($cmid)), array_keys($recorded()));
        $this->assertSame(['q1', 'q2'], array_values($recorded()));
    }

    /**
     * Replacement with the same references keeps them, in Moodle and in the results.
     */
    public function test_replacement_keeps_question_identity(): void {
        $first = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(['q1' => 1, 'q2' => 2], 'v2')]);

        $this->assertSame(['q1', 'q2'], array_values($this->bank($first['quizcmid'])));
        $right = $this->attempt($first['quizcmid'], [1 => 'True', 2 => 'False']);
        $wrong = $this->attempt($first['quizcmid'], [1 => 'False', 2 => 'True']);
        $this->assertSame(['q1' => [1.0, 1.0, 1.0], 'q2' => [0.0, 0.0, 2.0]], $this->results($first['quizcmid'], $right));
        $this->assertSame(['q1' => [0.0, 0.0, 1.0], 'q2' => [1.0, 2.0, 2.0]], $this->results($first['quizcmid'], $wrong));
    }

    /**
     * v1 -> v2 -> v3 -> v4 with the same references, including a changed order, stays unambiguous.
     */
    public function test_repeated_replacement_keeps_question_identity(): void {
        $refs = ['q1' => 1, 'q2' => 2, 'q3' => 3];
        $first = $this->exam(['xml' => $this->xml($refs)]);
        $cmid = $first['quizcmid'];
        foreach (['v2' => $refs, 'v3' => ['q3' => 3, 'q1' => 1, 'q2' => 2], 'v4' => $refs] as $version => $order) {
            $this->exam(['quizcmid' => $cmid, 'xml' => $this->xml($order, $version)]);
            $bank = $this->bank($cmid);
            $this->assertCount(3, $bank);
            $this->assertEqualsCanonicalizing(['q1', 'q2', 'q3'], array_values($bank));
        }

        $student = $this->attempt($cmid, [1 => 'True', 2 => 'False', 3 => 'True']);
        $this->assertEquals(
            ['q1' => [1.0, 1.0, 1.0], 'q2' => [0.0, 0.0, 2.0], 'q3' => [1.0, 3.0, 3.0]],
            $this->results($cmid, $student)
        );
    }

    /**
     * Old questions that must be kept (no provenance, as from an earlier plugin version) still hold the
     * references in Moodle; the replacement questions are nevertheless reported under them.
     */
    public function test_identity_survives_kept_questions_without_provenance(): void {
        global $DB;
        $first = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $legacy = array_keys($this->bank($first['quizcmid']));
        $DB->delete_records('local_extsync_question', ['cmid' => $first['quizcmid']]);

        $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(['q1' => 1, 'q2' => 2], 'v2')]);

        $bank = $this->bank($first['quizcmid']);
        foreach ($legacy as $questionid) {
            $this->assertArrayHasKey($questionid, $bank);
        }
        $student = $this->attempt($first['quizcmid'], [1 => 'True', 2 => 'False']);
        $this->assertSame(['q1' => [1.0, 1.0, 1.0], 'q2' => [0.0, 0.0, 2.0]], $this->results($first['quizcmid'], $student));
    }

    /**
     * Old questions kept because the caller may not edit questions do not break identity either.
     */
    public function test_identity_survives_questions_kept_for_lack_of_capability(): void {
        global $DB;
        $first = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $old = array_keys($this->bank($first['quizcmid']));
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $contextid = \context_module::instance($first['quizcmid'])->id;
        assign_capability('moodle/question:editall', CAP_PROHIBIT, $roleid, $contextid, true);
        assign_capability('moodle/question:editmine', CAP_PROHIBIT, $roleid, $contextid, true);

        $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(['q1' => 1, 'q2' => 2], 'v2')]);

        $this->assertCount(4, $this->bank($first['quizcmid']));
        foreach ($old as $questionid) {
            $this->assertArrayHasKey($questionid, $this->bank($first['quizcmid']));
        }
        $student = $this->attempt($first['quizcmid'], [1 => 'False', 2 => 'True']);
        $this->assertSame(['q1' => [0.0, 0.0, 1.0], 'q2' => [1.0, 2.0, 2.0]], $this->results($first['quizcmid'], $student));
    }

    /**
     * A teacher question with the same Moodle ID number, added to the exam, is not reported as the external system's.
     */
    public function test_teacher_question_with_same_idnumber_is_not_reported(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $first = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $colleague = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($colleague);
        $teacherquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $category = local\question_import::default_category(\context_module::instance($teacherquiz->cmid));
        $teacherquestion = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('truefalse', null, ['category' => $category->id, 'idnumber' => 'q1', 'defaultmark' => 5]);
        $quiz = $DB->get_record('quiz', ['id' => $DB->get_field('course_modules', 'instance', ['id' => $first['quizcmid']])]);
        $quiz->cmid = $first['quizcmid'];
        quiz_add_quiz_question($teacherquestion->id, $quiz);

        $student = $this->attempt($first['quizcmid'], [1 => 'True', 2 => 'False', 3 => 'True']);

        $this->assertSame(['q1' => [1.0, 1.0, 1.0], 'q2' => [0.0, 0.0, 2.0]], $this->results($first['quizcmid'], $student));
        $this->assertSame('q1', $DB->get_field_sql(
            'SELECT qbe.idnumber FROM {question_versions} qv
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid WHERE qv.questionid = ?',
            [$teacherquestion->id]
        ));
    }

    /**
     * A the external system question of another exam, added to this exam by a teacher, is not reported under this exam.
     */
    public function test_question_from_another_exam_is_not_reported(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $one = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $two = $this->exam(['xml' => $this->xml(['q1' => 3], 'other')]);
        $foreign = array_search('q1', $this->bank($two['quizcmid']));
        $quiz = $DB->get_record('quiz', ['id' => $DB->get_field('course_modules', 'instance', ['id' => $one['quizcmid']])]);
        $quiz->cmid = $one['quizcmid'];
        quiz_add_quiz_question($foreign, $quiz);

        $student = $this->attempt($one['quizcmid'], [1 => 'True', 2 => 'False', 3 => 'True']);

        $this->assertSame(['q1' => [1.0, 1.0, 1.0], 'q2' => [0.0, 0.0, 2.0]], $this->results($one['quizcmid'], $student));
    }

    /**
     * A retried replacement (the first response was lost) leaves exactly one question per reference.
     */
    public function test_retried_replacement_is_deterministic(): void {
        $first = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $payload = $this->xml(['q1' => 1, 'q2' => 2], 'v2');

        $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $payload]);
        $once = $this->bank($first['quizcmid']);
        $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $payload]);
        $twice = $this->bank($first['quizcmid']);

        $this->assertSame(['q1', 'q2'], array_values($once));
        $this->assertSame(['q1', 'q2'], array_values($twice));
        $this->assertEmpty(array_intersect_key($once, $twice));
        $student = $this->attempt($first['quizcmid'], [1 => 'True', 2 => 'False']);
        $this->assertSame(['q1' => [1.0, 1.0, 1.0], 'q2' => [0.0, 0.0, 2.0]], $this->results($first['quizcmid'], $student));
    }

    /**
     * A replacement after a failed replacement keeps identity.
     */
    public function test_replacement_after_failed_replacement(): void {
        // The replacement's own transaction must really roll back, not only inside the test's transaction.
        $this->preventResetByRollback();
        $first = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $before = $this->bank($first['quizcmid']);
        try {
            $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => '<quiz><question type="truefalse"']);
            $this->fail('A broken import was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('importfailed', $e->errorcode);
        }
        $this->assertSame($before, $this->bank($first['quizcmid']));

        $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(['q1' => 1, 'q2' => 2], 'v2')]);

        $student = $this->attempt($first['quizcmid'], [1 => 'False', 2 => 'True']);
        $this->assertSame(['q1' => [0.0, 0.0, 1.0], 'q2' => [1.0, 2.0, 2.0]], $this->results($first['quizcmid'], $student));
    }

    /**
     * The same references in unrelated exams stay separate.
     */
    public function test_same_references_in_unrelated_exams(): void {
        $one = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $two = $this->exam(['xml' => $this->xml(['q1' => 3, 'q2' => 4], 'other')]);
        $this->exam(['quizcmid' => $two['quizcmid'], 'xml' => $this->xml(['q1' => 3, 'q2' => 4], 'other v2')]);

        $a = $this->attempt($one['quizcmid'], [1 => 'True', 2 => 'False']);
        $b = $this->attempt($two['quizcmid'], [1 => 'False', 2 => 'True']);

        $this->assertSame(['q1' => [1.0, 1.0, 1.0], 'q2' => [0.0, 0.0, 2.0]], $this->results($one['quizcmid'], $a));
        $this->assertSame(['q1' => [0.0, 0.0, 3.0], 'q2' => [1.0, 4.0, 4.0]], $this->results($two['quizcmid'], $b));
    }

    /**
     * References that repeat within one payload, also after trimming or ignoring case, are refused before
     * anything changes: on creation and on replacement.
     */
    public function test_repeated_references_in_one_payload_are_refused(): void {
        global $DB;
        // The replacement's own transaction must really roll back, not only inside the test's transaction.
        $this->preventResetByRollback();
        $modules = $DB->count_records('course_modules', ['course' => $this->course->id]);
        foreach ([['q1' => 1, 'q2' => 2, ' q1 ' => 3], ['Q1' => 1, 'q1' => 2]] as $refs) {
            try {
                $this->exam(['xml' => $this->xml($refs)]);
                $this->fail('A payload with repeated references was accepted');
            } catch (\moodle_exception $e) {
                $this->assertSame('duplicatequestionref', $e->errorcode);
            }
        }
        $this->assertSame($modules, $DB->count_records('course_modules', ['course' => $this->course->id]));

        $first = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $before = $this->bank($first['quizcmid']);
        try {
            $this->exam(['quizcmid' => $first['quizcmid'], 'xml' => $this->xml(['q2' => 1, 'Q2' => 2])]);
            $this->fail('A replacement with repeated references was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('duplicatequestionref', $e->errorcode);
        }
        $this->assertSame($before, $this->bank($first['quizcmid']));
    }

    /**
     * A teacher's new version of an external question keeps being reported under its reference.
     */
    public function test_new_version_of_a_question_keeps_its_reference(): void {
        $first = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $q1 = array_search('q1', $this->bank($first['quizcmid']));
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $generator->update_question(\question_bank::load_question_data($q1), null, ['name' => 'Edited by a teacher']);

        $student = $this->attempt($first['quizcmid'], [1 => 'True', 2 => 'False']);

        $this->assertSame(['q1' => [1.0, 1.0, 1.0], 'q2' => [0.0, 0.0, 2.0]], $this->results($first['quizcmid'], $student));
    }

    /**
     * An exam without any provenance record (created before the plugin recorded provenance) is still
     * reported by Moodle ID number.
     */
    public function test_exam_without_provenance_uses_moodle_idnumbers(): void {
        global $DB;
        $first = $this->exam(['xml' => $this->xml(['q1' => 1, 'q2' => 2])]);
        $DB->delete_records('local_extsync_question', ['cmid' => $first['quizcmid']]);

        $student = $this->attempt($first['quizcmid'], [1 => 'True', 2 => 'False']);

        $this->assertSame(['q1' => [1.0, 1.0, 1.0], 'q2' => [0.0, 0.0, 2.0]], $this->results($first['quizcmid'], $student));
    }
}
