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
 * Student synchronisation tests.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\external::sync_students
 */
final class sync_students_test extends \advanced_testcase {
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
     * Call the web service function the way the web service layer does.
     *
     * @param array $students student rows
     * @param bool $removemissing unenrol students not listed
     * @param int|null $expectedcount expected count, null for the number of rows
     * @return array
     */
    private function sync(array $students, bool $removemissing = false, ?int $expectedcount = null): array {
        $result = external::sync_students(
            $this->course->id,
            $students,
            $removemissing,
            $expectedcount ?? count($students)
        );
        return external_api::clean_returnvalue(external::sync_students_returns(), $result);
    }

    /**
     * A student row with only one key.
     *
     * @param string $field idnumber, username or email
     * @param string $value key value
     * @return array
     */
    private function row(string $field, string $value): array {
        return array_merge(['idnumber' => '', 'username' => '', 'email' => ''], [$field => $value]);
    }

    /**
     * Enrol a number of students manually.
     *
     * @param int $count how many
     * @return \stdClass[]
     */
    private function enrol_students(int $count): array {
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $users[] = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['idnumber' => 'S' . $i]);
        }
        return $users;
    }

    /**
     * Whether a user is enrolled in the course.
     *
     * @param \stdClass $user the user
     * @return bool
     */
    private function enrolled(\stdClass $user): bool {
        return is_enrolled(\context_course::instance($this->course->id), $user);
    }

    /**
     * Rows for the given students.
     *
     * @param \stdClass[] $students students
     * @return array
     */
    private function rows(array $students): array {
        return array_values(array_map(fn($s) => $this->row('idnumber', $s->idnumber), $students));
    }

    /**
     * How many of the given users are enrolled.
     *
     * @param \stdClass[] $users users
     * @return int
     */
    private function count_enrolled(array $users): int {
        return count(array_filter($users, fn($user) => $this->enrolled($user)));
    }

    /**
     * Check that a sync is refused because it removes too many students.
     *
     * @param array $rows student rows
     * @param string $message failure message
     */
    private function assert_removal_refused(array $rows, string $message): void {
        try {
            $this->sync($rows, true);
            $this->fail($message);
        } catch (\moodle_exception $e) {
            $this->assertSame('removaltoolarge', $e->errorcode);
        }
    }

    /**
     * Removals of several requests add up: 10 -> 7 -> 5 removes half of the class, and the rest of
     * the 10 -> 7 -> 5 -> 3 -> 1 sequence is refused.
     */
    public function test_staged_removals_are_refused(): void {
        $this->mock_clock_with_frozen();
        $students = $this->enrol_students(10);

        $this->assertSame(3, $this->sync($this->rows(array_slice($students, 0, 7)), true)['unenrolled']);
        $this->assertSame(2, $this->sync($this->rows(array_slice($students, 0, 5)), true)['unenrolled']);
        $this->assert_removal_refused($this->rows(array_slice($students, 0, 3)), 'Staged removal to 3 of 10 was accepted');
        $this->assert_removal_refused($this->rows(array_slice($students, 0, 1)), 'Staged removal to 1 of 10 was accepted');

        $this->assertSame(5, $this->count_enrolled($students));
    }

    /**
     * The second audit's sequence of lists of 5, 3, 2 and 1 stops after the first half.
     */
    public function test_audit_erosion_sequence_is_refused(): void {
        $this->mock_clock_with_frozen();
        $students = $this->enrol_students(10);

        $this->assertSame(5, $this->sync($this->rows(array_slice($students, 0, 5)), true)['unenrolled']);
        foreach ([3, 2, 1] as $keep) {
            $this->assert_removal_refused($this->rows(array_slice($students, 0, $keep)), "Removal to $keep was accepted");
        }

        $this->assertSame(5, $this->count_enrolled($students));
    }

    /**
     * Students enrolled during the period do not raise the removal limit: a class inflated with other
     * accounts cannot then lose its original students.
     */
    public function test_inflated_class_cannot_lose_its_students(): void {
        $this->mock_clock_with_frozen();
        $students = $this->enrol_students(10);
        $others = [];
        for ($i = 0; $i < 20; $i++) {
            $others[] = $this->getDataGenerator()->create_user(['idnumber' => 'O' . $i]);
        }

        $this->assertSame(20, $this->sync($this->rows(array_merge($students, $others)), true)['enrolled']);
        $this->assert_removal_refused($this->rows($others), 'The original class was removed after inflating it');

        $this->assertSame(10, $this->count_enrolled($students));
    }

    /**
     * Repeating a sync that removed students changes nothing and is not refused.
     */
    public function test_retry_after_removal_changes_nothing(): void {
        $this->mock_clock_with_frozen();
        $students = $this->enrol_students(10);
        $rows = $this->rows(array_slice($students, 0, 6));

        $this->assertSame(4, $this->sync($rows, true)['unenrolled']);
        for ($i = 0; $i < 3; $i++) {
            $again = $this->sync($rows, true);
            $this->assertSame(0, $again['unenrolled']);
            $this->assertSame(6, $again['already']);
        }

        $this->assertSame(6, $this->count_enrolled($students));
    }

    /**
     * Enrolment continues while removals are refused, and removals continue after the period.
     */
    public function test_removals_continue_after_the_period(): void {
        $clock = $this->mock_clock_with_frozen();
        $students = $this->enrol_students(10);
        $this->sync($this->rows(array_slice($students, 0, 5)), true);
        $this->assert_removal_refused($this->rows(array_slice($students, 0, 4)), 'A removal beyond half was accepted');
        $newcomer = $this->getDataGenerator()->create_user(['idnumber' => 'NEW']);

        $rows = $this->rows(array_merge(array_slice($students, 0, 5), [$newcomer]));
        $this->assertSame(1, $this->sync($rows)['enrolled']);

        $clock->bump(external::REMOVAL_PERIOD + 1);
        $result = $this->sync($this->rows(array_merge(array_slice($students, 0, 3), [$newcomer])), true);
        $this->assertSame(2, $result['unenrolled']);
        $this->assertSame(4, $this->count_enrolled(array_merge($students, [$newcomer])));
    }

    /**
     * Every request needs expectedcount, so a request whose trailing parameters were cut off is refused
     * instead of being reported as a successful partial sync.
     */
    public function test_expectedcount_is_always_required(): void {
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'A1']);

        try {
            $this->sync([$this->row('idnumber', 'A1')], false, -1);
            $this->fail('A sync without expectedcount was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('countrequired', $e->errorcode);
        }

        $this->assertFalse($this->enrolled($user));
    }

    /**
     * A second request for the same course is refused while one is running.
     */
    public function test_parallel_request_is_refused(): void {
        global $CFG;
        $students = $this->enrol_students(4);
        // Database record locks also conflict within one process, as two web requests would.
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $lock = \core\lock\lock_config::get_lock_factory('local_extsync')->get_lock('sync_students_' . $this->course->id, 0);
        $this->assertNotFalse($lock);

        try {
            $this->sync($this->rows(array_slice($students, 0, 3)), true);
            $this->fail('A sync ran while another held the course lock');
        } catch (\moodle_exception $e) {
            $this->assertSame('syncinprogress', $e->errorcode);
        } finally {
            $lock->release();
        }

        $this->assertSame(4, $this->count_enrolled($students));
    }

    /**
     * The enrolment role must be one the web service account may assign.
     */
    public function test_role_must_be_assignable(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'R2']);
        $DB->delete_records('role_allow_assign', [
            'roleid' => $DB->get_field('role', 'id', ['shortname' => 'editingteacher']),
            'allowassign' => $DB->get_field('role', 'id', ['shortname' => 'student']),
        ]);
        accesslib_clear_all_caches_for_unit_testing();

        try {
            $this->sync([$this->row('idnumber', 'R2')]);
            $this->fail('Students were enrolled with a role the account may not assign');
        } catch (\moodle_exception $e) {
            $this->assertSame('rolenotassignable', $e->errorcode);
        }

        $this->assertFalse($this->enrolled($user));
    }

    /**
     * Exact match enrols, and repeating the sync changes nothing.
     */
    public function test_exact_match_is_idempotent(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'A100']);

        $first = $this->sync([$this->row('idnumber', 'A100')]);
        $this->assertSame(1, $first['enrolled']);
        for ($i = 0; $i < 3; $i++) {
            $again = $this->sync([$this->row('idnumber', 'A100')]);
            $this->assertSame(0, $again['enrolled']);
            $this->assertSame(1, $again['already']);
        }
        $this->assertTrue($this->enrolled($user));
        $this->assertSame(1, $DB->count_records('user_enrolments', ['userid' => $user->id]));
    }

    /**
     * A key without an account is reported and no user is created.
     */
    public function test_no_match(): void {
        global $DB;
        $users = $DB->count_records('user');

        $result = $this->sync([$this->row('email', 'nobody@example.com')]);

        $this->assertSame(['nobody@example.com'], $result['unmatched']);
        $this->assertSame(0, $result['enrolled']);
        $this->assertSame($users, $DB->count_records('user'));
    }

    /**
     * A key shared by two accounts enrols neither of them.
     */
    public function test_duplicate_match(): void {
        $one = $this->getDataGenerator()->create_user(['idnumber' => 'DUP']);
        $two = $this->getDataGenerator()->create_user(['idnumber' => 'DUP']);

        $result = $this->sync([$this->row('idnumber', 'DUP')]);

        $this->assertSame(['DUP'], $result['ambiguous']);
        $this->assertSame(0, $result['enrolled']);
        $this->assertFalse($this->enrolled($one));
        $this->assertFalse($this->enrolled($two));
    }

    /**
     * A row whose keys identify different accounts enrols neither and protects both from removal.
     */
    public function test_conflicting_keys_are_ambiguous(): void {
        $students = $this->enrol_students(4);
        $other = $this->getDataGenerator()->create_user(['email' => 'other@example.com']);
        $conflict = ['idnumber' => $students[0]->idnumber, 'username' => '', 'email' => 'other@example.com'];
        $rows = array_merge([$conflict], $this->rows(array_slice($students, 1)));

        $result = $this->sync($rows, true);

        $this->assertSame([$students[0]->idnumber], $result['ambiguous']);
        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(0, $result['unenrolled']);
        $this->assertTrue($this->enrolled($students[0]));
        $this->assertFalse($this->enrolled($other));
    }

    /**
     * The same value in two different keys of a row can name two accounts; the row is then ambiguous.
     */
    public function test_same_value_in_different_keys_is_ambiguous(): void {
        $byidnumber = $this->getDataGenerator()->create_user(['idnumber' => '12345']);
        $byusername = $this->getDataGenerator()->create_user(['username' => '12345']);

        $result = $this->sync([['idnumber' => '12345', 'username' => '12345', 'email' => '']]);

        $this->assertSame(['12345'], $result['ambiguous']);
        $this->assertSame(0, $result['enrolled']);
        $this->assertFalse($this->enrolled($byidnumber));
        $this->assertFalse($this->enrolled($byusername));
    }

    /**
     * A key naming several accounts does not unprotect the account another key of the same row names.
     */
    public function test_several_matches_protect_every_candidate(): void {
        $students = $this->enrol_students(4);
        $this->getDataGenerator()->create_user(['email' => 'shared@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'shared@example.com']);
        $row = ['idnumber' => $students[0]->idnumber, 'username' => '', 'email' => 'shared@example.com'];

        $result = $this->sync(array_merge([$row], $this->rows(array_slice($students, 1))), true);

        $this->assertSame([$students[0]->idnumber], $result['ambiguous']);
        $this->assertSame(0, $result['unenrolled']);
        $this->assertTrue($this->enrolled($students[0]));
    }

    /**
     * The guest account is never matched.
     */
    public function test_guest_account_is_never_matched(): void {
        $result = $this->sync([$this->row('username', 'guest')]);

        $this->assertSame(['guest'], $result['unmatched']);
        $this->assertSame(0, $result['enrolled']);
    }

    /**
     * A disabled manual enrolment method is refused instead of enrolling students without access.
     */
    public function test_disabled_manual_enrolment_is_refused(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'D1']);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $this->course->id, 'enrol' => 'manual']);
        // The web service account keeps access to the course without its own manual enrolment.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, \context_system::instance()->id, true);

        try {
            $this->sync([$this->row('idnumber', 'D1')]);
            $this->fail('Students were enrolled into a disabled enrolment method');
        } catch (\moodle_exception $e) {
            $this->assertSame('manualenroldisabled', $e->errorcode);
        }

        $this->assertFalse($DB->record_exists('user_enrolments', ['userid' => $user->id]));
    }

    /**
     * Keys that identify the same account, or a key that identifies nobody next to one that does, still match.
     */
    public function test_consistent_and_partly_unknown_keys_match(): void {
        $one = $this->getDataGenerator()->create_user(['idnumber' => 'C1', 'email' => 'c1@example.com']);
        $two = $this->getDataGenerator()->create_user(['email' => 'c2@example.com']);

        $result = $this->sync([
            ['idnumber' => 'C1', 'username' => '', 'email' => 'C1@example.com'],
            ['idnumber' => 'UNKNOWN', 'username' => '', 'email' => 'c2@example.com'],
        ]);

        $this->assertSame(2, $result['enrolled']);
        $this->assertSame([], $result['ambiguous']);
        $this->assertTrue($this->enrolled($one));
        $this->assertTrue($this->enrolled($two));
    }

    /**
     * Email is matched case-insensitively on every database.
     */
    public function test_email_case_variation(): void {
        $user = $this->getDataGenerator()->create_user(['email' => 'Student.One@Example.com']);

        $result = $this->sync([$this->row('email', 'student.one@EXAMPLE.COM')]);

        $this->assertSame(1, $result['enrolled']);
        $this->assertTrue($this->enrolled($user));
    }

    /**
     * Suspended accounts are matched and enrolled like any other account.
     */
    public function test_suspended_user(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'SUSP', 'suspended' => 1]);

        $result = $this->sync([$this->row('idnumber', 'SUSP')]);

        $this->assertSame(1, $result['enrolled']);
        $this->assertTrue($DB->record_exists('user_enrolments', ['userid' => $user->id]));
    }

    /**
     * Candidates of an ambiguous key are protected from removal.
     */
    public function test_multiple_candidates_are_not_removed(): void {
        $students = $this->enrol_students(4);
        // A second, unenrolled account shares the email of an enrolled student.
        $this->getDataGenerator()->create_user(['email' => $students[0]->email]);

        $rows = [$this->row('email', $students[0]->email)];
        foreach (array_slice($students, 1) as $student) {
            $rows[] = $this->row('idnumber', $student->idnumber);
        }
        $result = $this->sync($rows, true);

        $this->assertSame([$students[0]->email], $result['ambiguous']);
        $this->assertSame(0, $result['unenrolled']);
        $this->assertTrue($this->enrolled($students[0]));
    }

    /**
     * An empty list never means "remove everyone".
     */
    public function test_empty_list_is_refused(): void {
        $students = $this->enrol_students(3);

        $this->expectExceptionObject(new \moodle_exception('emptystudentlist', 'local_extsync'));
        try {
            $this->sync([], true);
        } finally {
            foreach ($students as $student) {
                $this->assertTrue($this->enrolled($student));
            }
        }
    }

    /**
     * Removing one of ten students is allowed.
     */
    public function test_small_removal(): void {
        $students = $this->enrol_students(10);
        $rows = array_map(fn($s) => $this->row('idnumber', $s->idnumber), array_slice($students, 1));

        $result = $this->sync($rows, true);

        $this->assertSame(1, $result['unenrolled']);
        $this->assertFalse($this->enrolled($students[0]));
        $this->assertTrue($this->enrolled($this->service));
    }

    /**
     * Removing exactly half of the students is the largest removal allowed.
     */
    public function test_half_removal(): void {
        $students = $this->enrol_students(10);
        $rows = array_map(fn($s) => $this->row('idnumber', $s->idnumber), array_slice($students, 5));

        $result = $this->sync($rows, true);

        $this->assertSame(5, $result['unenrolled']);
    }

    /**
     * Removing nine of ten students is refused and changes nothing.
     */
    public function test_large_removal_is_refused(): void {
        $students = $this->enrol_students(10);
        $newcomer = $this->getDataGenerator()->create_user(['idnumber' => 'NEW']);
        $rows = [$this->row('idnumber', $students[9]->idnumber), $this->row('idnumber', 'NEW')];

        try {
            $this->sync($rows, true);
            $this->fail('A 90% removal was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('removaltoolarge', $e->errorcode);
        }
        foreach ($students as $student) {
            $this->assertTrue($this->enrolled($student));
        }
        $this->assertFalse($this->enrolled($newcomer));
    }

    /**
     * Truncated and oversized requests are refused before anything changes.
     */
    public function test_truncated_or_oversized_request(): void {
        $students = $this->enrol_students(4);
        $rows = array_map(fn($s) => $this->row('idnumber', $s->idnumber), array_slice($students, 0, 2));

        // Two rows arrived where the caller sent four.
        try {
            $this->sync($rows, true, 4);
            $this->fail('A truncated list was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('countmismatch', $e->errorcode);
        }

        // Removal without a count cannot detect truncation.
        try {
            $this->sync($rows, true, -1);
            $this->fail('Removal without expectedcount was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('countrequired', $e->errorcode);
        }

        $oversized = array_fill(0, external::MAX_STUDENTS + 1, $this->row('idnumber', 'X'));
        try {
            $this->sync($oversized);
            $this->fail('An oversized list was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('toomanystudents', $e->errorcode);
        }

        foreach ($students as $student) {
            $this->assertTrue($this->enrolled($student));
        }
    }

    /**
     * Users holding another role in the course are never unenrolled.
     */
    public function test_user_with_other_role_is_kept(): void {
        global $DB;
        $students = $this->enrol_students(4);
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        role_assign($teacherrole, $students[0]->id, \context_course::instance($this->course->id));
        $rows = array_map(fn($s) => $this->row('idnumber', $s->idnumber), array_slice($students, 1));

        $result = $this->sync($rows, true);

        $this->assertSame(0, $result['unenrolled']);
        $this->assertTrue($this->enrolled($students[0]));
    }

    /**
     * The enrolment instance's role is used, whatever the role is called.
     */
    public function test_uses_instance_role(): void {
        global $DB;
        $DB->set_field('role', 'shortname', 'learner', ['shortname' => 'student']);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'learner']);
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'R1']);

        $this->sync([$this->row('idnumber', 'R1')]);

        $this->assertTrue(user_has_role_assignment($user->id, $roleid, \context_course::instance($this->course->id)->id));
    }

    /**
     * Removal requires enrol/manual:unenrol, checked before anything changes.
     */
    public function test_unenrol_capability_required(): void {
        global $DB;
        $students = $this->enrol_students(2);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('enrol/manual:unenrol', CAP_PROHIBIT, $roleid, \context_course::instance($this->course->id)->id, true);

        $this->expectException(\required_capability_exception::class);
        try {
            $this->sync([$this->row('idnumber', $students[1]->idnumber)], true);
        } finally {
            $this->assertTrue($this->enrolled($students[0]));
        }
    }
}
