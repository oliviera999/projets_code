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

namespace local_pronoteio\task;

use local_pronoteio\local\logger;
use local_pronoteio\sync\manager;

/**
 * Scheduled Pronote synchronisation of every enabled account.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_task extends \core\task\scheduled_task {

    #[\Override]
    public function get_name(): string {
        return get_string('task_sync', 'local_pronoteio');
    }

    #[\Override]
    public function execute(): void {
        global $DB;

        $select = 'enabled = 1';
        if (get_config('local_pronoteio', 'connector') !== 'file') {
            $select .= ' AND token IS NOT NULL';
        }
        $accounts = $DB->get_recordset_select('local_pronoteio_account', $select);
        foreach ($accounts as $account) {
            mtrace("Pronote sync for account {$account->id} (user {$account->userid})");
            foreach (manager::run_for_account($account) as $flow => $count) {
                mtrace("  {$flow}: {$count}");
            }
        }
        $accounts->close();

        logger::purge();
    }
}
