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

namespace local_pronoteio\local;

/**
 * Persistence of linked Pronote accounts.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class account_manager {

    /** @var string Table name. */
    public const TABLE = 'local_pronoteio_account';

    /**
     * Account of a user, or null.
     *
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get_for_user(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['userid' => $userid]) ?: null;
    }

    /**
     * Account by id.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Creates or updates the account of a user. The token is left untouched.
     *
     * @param int $userid
     * @param \stdClass $data pronoteurl, username, icalurl, enabled
     * @return \stdClass Saved record.
     */
    public static function save(int $userid, \stdClass $data): \stdClass {
        global $DB;
        $now = time();
        $record = self::get_for_user($userid);
        $changedidentity = !$record || $record->pronoteurl !== $data->pronoteurl || $record->username !== $data->username;

        if (!$record) {
            $record = (object) [
                'userid' => $userid,
                'deviceuuid' => \core\uuid::generate(),
                'status' => 'pending',
                'lastsync' => 0,
                'timecreated' => $now,
            ];
        }
        $record->pronoteurl = $data->pronoteurl;
        $record->username = $data->username;
        $record->icalurl = $data->icalurl ?? null;
        $record->enabled = empty($data->enabled) ? 0 : 1;
        $record->timemodified = $now;
        if ($changedidentity) {
            $record->token = null;
            $record->status = 'pending';
        }

        if (empty($record->id)) {
            $record->id = $DB->insert_record(self::TABLE, $record);
        } else {
            $DB->update_record(self::TABLE, $record);
        }
        return $record;
    }

    /**
     * Decrypted token of an account, or empty string.
     *
     * @param \stdClass $account
     * @return string
     */
    public static function get_token(\stdClass $account): string {
        if (empty($account->token)) {
            return '';
        }
        return \core\encryption::decrypt($account->token);
    }

    /**
     * Stores the token returned by Pronote after each login (tokens are single use).
     *
     * @param \stdClass $account Updated in place.
     * @param string $token
     */
    public static function store_token(\stdClass $account, string $token): void {
        global $DB;
        $account->token = $token === '' ? null : \core\encryption::encrypt($token);
        $account->timemodified = time();
        $DB->set_field(self::TABLE, 'token', $account->token, ['id' => $account->id]);
        $DB->set_field(self::TABLE, 'timemodified', $account->timemodified, ['id' => $account->id]);
    }

    /**
     * Updates the connection status.
     *
     * @param \stdClass $account Updated in place.
     * @param string $status pending, ok or error.
     */
    public static function set_status(\stdClass $account, string $status): void {
        global $DB;
        $account->status = $status;
        $DB->set_field(self::TABLE, 'status', $status, ['id' => $account->id]);
    }

    /**
     * Records a successful synchronisation.
     *
     * @param \stdClass $account Updated in place.
     */
    public static function touch_sync(\stdClass $account): void {
        global $DB;
        $account->lastsync = time();
        $DB->set_field(self::TABLE, 'lastsync', $account->lastsync, ['id' => $account->id]);
    }

    /**
     * Deletes an account and everything attached to it.
     *
     * @param \stdClass $account
     */
    public static function delete(\stdClass $account): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_pronoteio_map', ['accountid' => $account->id]);
        $DB->delete_records('local_pronoteio_log', ['accountid' => $account->id]);
        $DB->delete_records('local_pronoteio_push', ['accountid' => $account->id]);
        if ((int) get_config('local_pronoteio', 'cohortaccount') === (int) $account->id) {
            unset_config('cohortaccount', 'local_pronoteio');
        }
        $DB->delete_records(self::TABLE, ['id' => $account->id]);
        $transaction->allow_commit();
    }

    /**
     * Whether a user holds local/pronoteio:linkaccount in at least one course.
     *
     * @param int $userid
     * @return bool
     */
    public static function can_link(int $userid): bool {
        if (!isloggedin() || isguestuser($userid)) {
            return false;
        }
        if (has_capability('moodle/site:config', \context_system::instance(), $userid)) {
            return true;
        }
        return (bool) get_user_capability_course('local/pronoteio:linkaccount', $userid, true, '', '', 1);
    }
}
