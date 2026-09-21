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
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for External Sync.
 *
 * The plugin stores no personal data. It sends quiz results to the external system when that
 * system's web service account asks for them, which is declared as an external location.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data sent to the external system.
     *
     * @param collection $collection the collection to add to
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('extsync', [
            'userid' => 'privacy:metadata:extsync:userid',
            'username' => 'privacy:metadata:extsync:username',
            'idnumber' => 'privacy:metadata:extsync:idnumber',
            'email' => 'privacy:metadata:extsync:email',
            'timefinish' => 'privacy:metadata:extsync:timefinish',
            'grade' => 'privacy:metadata:extsync:grade',
            'questions' => 'privacy:metadata:extsync:questions',
        ], 'privacy:metadata:extsync');

        return $collection;
    }

    /**
     * No personal data is stored, so no context holds any.
     *
     * @param int $userid the user
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * No personal data is stored, so no user is added.
     *
     * @param userlist $userlist the list to fill
     */
    public static function get_users_in_context(userlist $userlist) {
    }

    /**
     * Nothing is stored, so nothing is exported.
     *
     * @param approved_contextlist $contextlist the approved contexts
     */
    public static function export_user_data(approved_contextlist $contextlist) {
    }

    /**
     * Nothing is stored, so nothing is deleted.
     *
     * @param \context $context the context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
    }

    /**
     * Nothing is stored, so nothing is deleted.
     *
     * @param approved_contextlist $contextlist the approved contexts
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
    }

    /**
     * Nothing is stored, so nothing is deleted.
     *
     * @param approved_userlist $userlist the approved users
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
    }
}
