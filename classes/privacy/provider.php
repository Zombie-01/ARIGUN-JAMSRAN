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
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_extsync\local\ownership;

/**
 * Privacy provider for External Sync.
 *
 * The plugin stores one personal record: the integration of each web service account that called it,
 * in the system context. It also sends quiz results to the external system when that system's web
 * service account asks for them, which is declared as an external location.
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
     * Describe the personal data stored and sent to the external system.
     *
     * @param collection $collection the collection to add to
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_extsync_integration', [
            'userid' => 'privacy:metadata:integration:userid',
            'integrationkey' => 'privacy:metadata:integration:integrationkey',
            'timecreated' => 'privacy:metadata:integration:timecreated',
        ], 'privacy:metadata:integration');

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
     * The system context, when the user is the account of an integration.
     *
     * @param int $userid the user
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_extsync_integration} i ON i.userid = :userid
              WHERE ctx.contextlevel = :contextlevel",
            ['userid' => $userid, 'contextlevel' => CONTEXT_SYSTEM]
        );

        return $contextlist;
    }

    /**
     * The accounts of all integrations, in the system context.
     *
     * @param userlist $userlist the list to fill
     */
    public static function get_users_in_context(userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_extsync_integration}', []);
    }

    /**
     * Export the integration of the user.
     *
     * @param approved_contextlist $contextlist the approved contexts
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $context = \context_system::instance();
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $integration = $DB->get_record('local_extsync_integration', ['userid' => $contextlist->get_user()->id]);
        if (!$integration) {
            return;
        }
        writer::with_context($context)->export_data([get_string('pluginname', 'local_extsync')], (object)[
            'integrationkey' => $integration->integrationkey,
            'timecreated' => transform::datetime($integration->timecreated),
        ]);
    }

    /**
     * Delete every integration.
     *
     * @param \context $context the context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach ($DB->get_fieldset_select('local_extsync_integration', 'userid', '1 = 1') as $userid) {
            ownership::delete_integration((int)$userid);
        }
    }

    /**
     * Delete the integration of the user.
     *
     * @param approved_contextlist $contextlist the approved contexts
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            ownership::delete_integration((int)$contextlist->get_user()->id);
        }
    }

    /**
     * Delete the integrations of the users.
     *
     * @param approved_userlist $userlist the approved users
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            ownership::delete_integration((int)$userid);
        }
    }
}
