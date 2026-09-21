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

/**
 * Cross-integration ownership isolation (SI-19) and empty-ownership fail-closed (SI-20).
 *
 * An integration is the authenticated web service account. Two integrations are two service
 * accounts working in the same course: knowing a course module id must never be enough to change
 * or delete an object that another integration created.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\local\ownership
 * @covers     \local_extsync\external::push_lesson
 * @covers     \local_extsync\external::push_exam
 */
final class integration_ownership_test extends \advanced_testcase {
    /** @var \stdClass course both integrations work in */
    private $course;

    /** @var \stdClass web service account of integration A */
    private $servicea;

    /** @var \stdClass web service account of integration B */
    private $serviceb;

    /**
     * One course with two independent web service accounts, both able to manage activities.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->servicea = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->serviceb = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->servicea);
    }

    /**
     * Moodle XML with true/false questions.
     *
     * @param int $count number of questions
     * @param string $prefix ID number prefix, so two integrations can use distinct references
     * @return string
     */
    private function xml(int $count, string $prefix = 'q'): string {
        $questions = '';
        for ($i = 1; $i <= $count; $i++) {
            $questions .= '<question type="truefalse"><name><text>E' . $i . '</text></name>'
                . '<questiontext format="html"><text>Statement ' . $i . '</text></questiontext>'
                . '<idnumber>' . $prefix . '-' . $i . '</idnumber>'
                . '<answer fraction="100"><text>true</text></answer>'
                . '<answer fraction="0"><text>false</text></answer></question>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><quiz>' . $questions . '</quiz>';
    }

    /**
     * Push a minimal lesson as the current user.
     *
     * @param array $overrides parameter overrides
     * @return array cleaned push_lesson result
     */
    private function lesson(array $overrides = []): array {
        $p = array_merge([
            'courseid' => $this->course->id, 'sectionid' => 0, 'unitname' => '', 'unitsectionid' => 0,
            'subsectioncmid' => 0, 'renamesection' => true, 'assigncmid' => 0, 'filecmids' => [],
            'name' => 'Lesson', 'assignname' => '', 'summary' => '', 'assignintro' => '', 'files' => [],
            'quizcmid' => 0, 'quizname' => '', 'quizxml' => '', 'pages' => [], 'quizzes' => [],
            'ordering' => [], 'deletecmids' => [], 'makeassign' => 1,
        ], $overrides);

        $result = external::push_lesson(
            $p['courseid'],
            $p['sectionid'],
            $p['unitname'],
            $p['unitsectionid'],
            $p['subsectioncmid'],
            $p['renamesection'],
            $p['assigncmid'],
            $p['filecmids'],
            $p['name'],
            $p['assignname'],
            $p['summary'],
            $p['assignintro'],
            $p['files'],
            $p['quizcmid'],
            $p['quizname'],
            $p['quizxml'],
            $p['pages'],
            $p['quizzes'],
            $p['ordering'],
            $p['deletecmids'],
            $p['makeassign']
        );

        return external_api::clean_returnvalue(external::push_lesson_returns(), $result);
    }

    /**
     * Push an exam as the current user.
     *
     * @param array $overrides parameter overrides
     * @return array cleaned push_exam result
     */
    private function exam(array $overrides = []): array {
        $p = array_merge([
            'courseid' => $this->course->id, 'sectionid' => 0, 'sectionname' => '', 'quizcmid' => 0,
            'name' => 'Exam', 'intro' => '', 'xml' => $this->xml(2), 'replace' => true, 'force' => false,
            'timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0, 'attempts' => 1,
        ], $overrides);

        $result = external::push_exam(
            $p['courseid'],
            $p['sectionid'],
            $p['sectionname'],
            $p['quizcmid'],
            $p['name'],
            $p['intro'],
            $p['xml'],
            $p['replace'],
            $p['force'],
            $p['timeopen'],
            $p['timeclose'],
            $p['timelimit'],
            $p['attempts']
        );

        return external_api::clean_returnvalue(external::push_exam_returns(), $result);
    }

    /**
     * Whether a course module still exists.
     *
     * @param int $cmid course module id
     * @return bool
     */
    private function cm_exists(int $cmid): bool {
        global $DB;

        return $DB->record_exists('course_modules', ['id' => $cmid]);
    }

    /**
     * Number of questions in a quiz course module.
     *
     * @param int $quizcmid quiz course module id
     * @return int
     */
    private function slots(int $quizcmid): int {
        global $DB;

        return $DB->count_records(
            'quiz_slots',
            ['quizid' => $DB->get_field('course_modules', 'instance', ['id' => $quizcmid])]
        );
    }

    /**
     * Warning codes in a result.
     *
     * @param array $result push result
     * @return string[]
     */
    private function warning_codes(array $result): array {
        return array_column($result['warnings'] ?? [], 'warningcode');
    }

    // SI-19: cross-integration ownership isolation.

    /**
     * SI-19-A: the integration that created an object may change it.
     */
    public function test_si19a_owner_may_mutate_its_own_object(): void {
        $first = $this->lesson(['name' => 'A lesson']);
        $this->assertNotEquals(0, $first['assigncmid']);

        $again = $this->lesson(['sectionid' => $first['sectionid'], 'assigncmid' => $first['assigncmid']]);

        $this->assertSame($first['assigncmid'], $again['assigncmid'], 'The owner must reuse its own activity');
        $this->assertNotContains('notowned', $this->warning_codes($again));
    }

    /**
     * SI-19-B: another integration may not change an object it did not create.
     */
    public function test_si19b_other_integration_may_not_mutate(): void {
        $first = $this->lesson(['name' => 'A lesson']);
        $acmid = $first['assigncmid'];

        $this->setUser($this->serviceb);
        $result = $this->lesson(['name' => 'B lesson', 'assigncmid' => $acmid]);

        $this->assertContains('notowned', $this->warning_codes($result), 'B must be refused A\'s activity');
        $this->assertNotSame($acmid, $result['assigncmid'], 'B must not take over A\'s activity');
        $this->assertTrue($this->cm_exists($acmid), 'A\'s activity must survive');
    }

    /**
     * SI-19-C: another integration may not delete an object it did not create.
     */
    public function test_si19c_other_integration_may_not_delete(): void {
        $first = $this->lesson(['name' => 'A lesson']);
        $acmid = $first['assigncmid'];

        $this->setUser($this->serviceb);
        $result = $this->lesson(['name' => 'B lesson', 'deletecmids' => [$acmid]]);

        $this->assertContains('notowned', $this->warning_codes($result));
        $this->assertTrue($this->cm_exists($acmid), 'B must not delete A\'s activity');
    }

    /**
     * SI-19-D: another integration may not replace the questions of an exam it does not own.
     */
    public function test_si19d_other_integration_may_not_replace_exam(): void {
        $exam = $this->exam(['xml' => $this->xml(2, 'a')]);
        $quizcmid = $exam['quizcmid'];
        $before = $this->slots($quizcmid);

        $this->setUser($this->serviceb);
        $result = $this->exam(['quizcmid' => $quizcmid, 'xml' => $this->xml(3, 'b')]);

        $this->assertFalse($result['replaced'], 'B must not replace A\'s exam questions');
        $this->assertSame($before, $this->slots($quizcmid), 'A\'s exam must keep its own questions');
    }

    /**
     * SI-19-E: a valid course module id is not by itself authority to act on it.
     */
    public function test_si19e_valid_cmid_is_not_authority(): void {
        $first = $this->lesson(['name' => 'A lesson']);
        $acmid = $first['assigncmid'];
        $this->assertTrue($this->cm_exists($acmid));

        $this->setUser($this->serviceb);

        $this->assertNull(
            ownership::get_cm((int)$this->course->id, (int)$acmid, 'assign'),
            'Ownership must not resolve for an integration that did not create the object'
        );
    }

    // SI-20: empty or unknown ownership must fail closed.

    /**
     * SI-20-A: an object whose ownership record carries no integration must not be mutable.
     */
    public function test_si20a_unknown_integration_denies_mutation(): void {
        global $DB;
        $first = $this->lesson(['name' => 'A lesson']);
        $acmid = $first['assigncmid'];

        // A record migrated from a version that did not record the integration.
        $DB->set_field('local_extsync_module', 'integrationid', null, ['cmid' => $acmid]);

        $result = $this->lesson(['sectionid' => $first['sectionid'], 'assigncmid' => $acmid]);

        $this->assertContains('notowned', $this->warning_codes($result), 'Unknown ownership must fail closed');
        $this->assertTrue($this->cm_exists($acmid), 'The object must be left alone, not replaced or deleted');
    }

    /**
     * SI-20-B: an object with no ownership record at all must not be destructively mutable.
     */
    public function test_si20b_missing_ownership_denies_destructive_mutation(): void {
        global $DB;
        $first = $this->lesson(['name' => 'A lesson']);
        $acmid = $first['assigncmid'];
        $DB->delete_records('local_extsync_module', ['cmid' => $acmid]);

        $result = $this->lesson(['name' => 'A lesson', 'deletecmids' => [$acmid]]);

        $this->assertContains('notowned', $this->warning_codes($result));
        $this->assertTrue($this->cm_exists($acmid), 'An unowned object must never be deleted');
    }

    /**
     * SI-20-C: unknown ownership is never silently adopted by the next push.
     */
    public function test_si20c_unknown_ownership_is_not_adopted(): void {
        global $DB;
        $first = $this->lesson(['name' => 'A lesson']);
        $acmid = $first['assigncmid'];
        $DB->set_field('local_extsync_module', 'integrationid', null, ['cmid' => $acmid]);

        $this->lesson(['sectionid' => $first['sectionid'], 'assigncmid' => $acmid]);

        $this->assertNull(
            $DB->get_field('local_extsync_module', 'integrationid', ['cmid' => $acmid]),
            'A push must never claim ownership of a record it could not prove it owns'
        );
    }

    // Authentication: the integration follows the authenticated account, never the request.

    /**
     * The integration is derived from the authenticated user, not from anything the caller sends.
     */
    public function test_integration_identity_follows_the_authenticated_user(): void {
        $ida = ownership::current_integration();
        $this->setUser($this->serviceb);
        $idb = ownership::current_integration();

        $this->assertNotSame($ida, $idb, 'Two service accounts must be two integrations');

        $this->setUser($this->servicea);
        $this->assertSame($ida, ownership::current_integration(), 'Identity must follow the authenticated user');
    }

    /**
     * A caller-supplied integration key cannot be submitted to the external API at all.
     */
    public function test_caller_supplied_integration_key_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);

        external_api::validate_parameters(
            external::push_exam_parameters(),
            [
                'courseid' => $this->course->id,
                'name' => 'Exam',
                'xml' => $this->xml(1),
                'integrationkey' => 'integration-b',
            ]
        );
    }

    // Concurrency: an object has exactly one owner.

    /**
     * Two integrations cannot both own the same object: the second is refused, not merged.
     */
    public function test_concurrent_claim_has_a_single_winner(): void {
        global $DB;
        $first = $this->lesson(['name' => 'A lesson']);
        $acmid = $first['assigncmid'];

        $this->setUser($this->serviceb);
        $this->lesson(['name' => 'B lesson', 'assigncmid' => $acmid]);

        $rows = $DB->get_records('local_extsync_module', ['cmid' => $acmid]);
        $this->assertCount(1, $rows, 'An object must have exactly one ownership record');
        $this->assertSame(
            ownership::integration_for_user((int)$this->servicea->id),
            (int)reset($rows)->integrationid,
            'The original creator must remain the owner'
        );
    }
}
