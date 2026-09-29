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

namespace local_pronoteio\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_pronoteio\local\account_manager;

/**
 * Privacy provider. Account data lives in the user context of the teacher.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_pronoteio_account', [
            'userid' => 'privacy:metadata:local_pronoteio_account:userid',
            'pronoteurl' => 'privacy:metadata:local_pronoteio_account:pronoteurl',
            'username' => 'privacy:metadata:local_pronoteio_account:username',
            'token' => 'privacy:metadata:local_pronoteio_account:token',
            'icalurl' => 'privacy:metadata:local_pronoteio_account:icalurl',
            'lastsync' => 'privacy:metadata:local_pronoteio_account:lastsync',
        ], 'privacy:metadata:local_pronoteio_account');

        $collection->add_database_table('local_pronoteio_log', [
            'message' => 'privacy:metadata:local_pronoteio_log:message',
            'timecreated' => 'privacy:metadata:local_pronoteio_log:timecreated',
        ], 'privacy:metadata:local_pronoteio_log');

        $collection->add_database_table('local_pronoteio_push', [
            'userid' => 'privacy:metadata:local_pronoteio_push:userid',
            'itemid' => 'privacy:metadata:local_pronoteio_push:itemid',
            'blockinstanceid' => 'privacy:metadata:local_pronoteio_push:blockinstanceid',
            'servicename' => 'privacy:metadata:local_pronoteio_push:servicename',
            'timemodified' => 'privacy:metadata:local_pronoteio_push:timemodified',
        ], 'privacy:metadata:local_pronoteio_push');

        $collection->add_database_table('local_pronoteio_cmember', [
            'userid' => 'privacy:metadata:local_pronoteio_cmember:userid',
            'timecreated' => 'privacy:metadata:local_pronoteio_cmember:timecreated',
        ], 'privacy:metadata:local_pronoteio_cmember');

        $collection->add_external_location_link('pronote', [
            'username' => 'privacy:metadata:pronote:username',
            'password' => 'privacy:metadata:pronote:password',
            'pin' => 'privacy:metadata:pronote:pin',
            'grades' => 'privacy:metadata:pronote:grades',
        ], 'privacy:metadata:pronote');

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::has_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && self::has_data((int) $context->instanceid)) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Whether the plugin stores data about a user.
     *
     * @param int $userid
     * @return bool
     */
    protected static function has_data(int $userid): bool {
        global $DB;
        return account_manager::get_for_user($userid)
            || $DB->record_exists('local_pronoteio_push', ['userid' => $userid])
            || $DB->record_exists('local_pronoteio_cmember', ['userid' => $userid]);
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || (int) $context->instanceid !== $userid) {
                continue;
            }
            $data = [];
            if ($account = account_manager::get_for_user($userid)) {
                $logs = $DB->get_records('local_pronoteio_log', ['accountid' => $account->id], 'timecreated ASC',
                    'id, flow, level, message, timecreated');
                $data += [
                    'pronoteurl' => $account->pronoteurl,
                    'username' => $account->username,
                    'icalurl' => $account->icalurl,
                    'lastsync' => $account->lastsync ? transform::datetime($account->lastsync) : null,
                    'log' => array_values(array_map(fn($log) => [
                        'flow' => $log->flow,
                        'level' => $log->level,
                        'message' => $log->message,
                        'time' => transform::datetime($log->timecreated),
                    ], $logs)),
                ];
            }
            $pushes = $DB->get_records('local_pronoteio_push', ['userid' => $userid], 'timemodified ASC');
            if ($pushes) {
                $data['gradepushes'] = array_values(array_map(fn($push) => [
                    'courseid' => $push->courseid,
                    'itemid' => $push->itemid,
                    'blockinstanceid' => $push->blockinstanceid,
                    'service' => $push->servicename,
                    'written' => $push->written,
                    'time' => transform::datetime($push->timemodified),
                ], $pushes));
            }
            $memberships = $DB->get_records_sql(
                "SELECT m.id, c.name, pc.pronotename, m.timecreated
                   FROM {local_pronoteio_cmember} m
                   JOIN {local_pronoteio_cohort} pc ON pc.id = m.linkid
                   JOIN {cohort} c ON c.id = pc.cohortid
                  WHERE m.userid = ?", [$userid]);
            if ($memberships) {
                $data['cohorts'] = array_values(array_map(fn($m) => [
                    'cohort' => $m->name,
                    'pronoteclass' => $m->pronotename,
                    'time' => transform::datetime($m->timecreated),
                ], $memberships));
            }
            if ($data) {
                writer::with_context($context)->export_data([get_string('pluginname', 'local_pronoteio')], (object) $data);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_user) {
            self::delete_user((int) $context->instanceid);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                self::delete_user($userid);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            if ((int) $userid === (int) $context->instanceid) {
                self::delete_user((int) $userid);
            }
        }
    }

    /**
     * Deletes the account, mappings, logs, grade pushes and cohort tracking of a user.
     *
     * The cohort membership itself belongs to core_cohort and is handled by its own provider.
     *
     * @param int $userid
     */
    protected static function delete_user(int $userid): void {
        global $DB;
        if ($account = account_manager::get_for_user($userid)) {
            account_manager::delete($account);
        }
        $DB->delete_records('local_pronoteio_push', ['userid' => $userid]);
        $DB->delete_records('local_pronoteio_cmember', ['userid' => $userid]);
    }
}
