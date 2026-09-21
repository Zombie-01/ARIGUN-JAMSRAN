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

namespace local_extsync\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\userlist;

/**
 * Privacy provider tests.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The data sent to the external system is declared as an external location with described fields.
     */
    public function test_metadata_declares_external_location(): void {
        $collection = provider::get_metadata(new collection('local_extsync'));
        $items = $collection->get_collection();

        $this->assertCount(1, $items);
        $this->assertInstanceOf(external_location::class, $items[0]);
        $this->assertSame('extsync', $items[0]->get_name());

        $fields = $items[0]->get_privacy_fields();
        foreach (['userid', 'username', 'idnumber', 'email', 'timefinish', 'grade', 'questions'] as $field) {
            $this->assertArrayHasKey($field, $fields);
        }
        $strings = get_string_manager();
        $this->assertTrue($strings->string_exists($items[0]->get_summary(), 'local_extsync'));
        foreach ($fields as $identifier) {
            $this->assertTrue($strings->string_exists($identifier, 'local_extsync'));
        }
    }

    /**
     * Nothing is stored in Moodle, so no context or user is reported.
     */
    public function test_no_local_data(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertEmpty(provider::get_contexts_for_userid($user->id)->get_contextids());

        $userlist = new userlist(\context_course::instance($course->id), 'local_extsync');
        provider::get_users_in_context($userlist);
        $this->assertEmpty($userlist->get_userids());
    }
}
