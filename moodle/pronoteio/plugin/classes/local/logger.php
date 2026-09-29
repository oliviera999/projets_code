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
 * Synchronisation log writer.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logger {

    /**
     * Adds a log entry.
     *
     * @param int $accountid
     * @param string $flow timetable, homework, roster, absences, grades, session
     * @param string $level info, warning or error
     * @param string $message Never include credentials or tokens.
     */
    public static function log(int $accountid, string $flow, string $level, string $message): void {
        global $DB;
        $DB->insert_record('local_pronoteio_log', (object) [
            'accountid' => $accountid,
            'flow' => $flow,
            'level' => $level,
            'message' => \core_text::substr($message, 0, 4000),
            'timecreated' => time(),
        ]);
    }

    /**
     * Removes entries older than a number of days.
     *
     * @param int $days
     */
    public static function purge(int $days = 90): void {
        global $DB;
        $DB->delete_records_select('local_pronoteio_log', 'timecreated < ?', [time() - $days * DAYSECS]);
    }
}
