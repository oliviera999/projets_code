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

namespace local_pronoteio\sync;

use local_pronoteio\connector\connector_interface;
use local_pronoteio\local\logger;
use local_pronoteio\local\student_matcher;

/**
 * Pronote class/group -> Moodle group of the mapped course.
 *
 * Only students already enrolled in the course are added (no enrolment is created).
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roster extends base {

    #[\Override]
    public static function get_flow(): string {
        return 'roster';
    }

    #[\Override]
    public static function get_feature(): string {
        return connector_interface::FEATURE_ROSTER;
    }

    #[\Override]
    public function run(): int {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $matcher = new student_matcher();
        $count = 0;
        foreach ($this->get_mappings(self::FLAG_ROSTER) as $mapping) {
            $context = \context_course::instance($mapping->courseid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            $groupid = $this->ensure_group($mapping);
            $users = get_enrolled_users($context, '', 0, 'u.id, u.firstname, u.lastname, u.email');
            $result = $matcher->match($users, $this->connector->get_roster($mapping->pronoteid));

            foreach ($result['pairs'] as $userid) {
                if (groups_add_member($groupid, $userid, 'local_pronoteio', $mapping->id)) {
                    $count++;
                }
            }
            $missing = count($result['unmatched']) + count($result['ambiguous']);
            if ($missing) {
                logger::log($this->account->id, self::get_flow(), 'warning',
                    "{$missing} student(s) of {$mapping->pronotename} not matched in course {$mapping->courseid}");
            }
            // TODO: remove members that left the Pronote class (only those added by this component).
        }
        return $count;
    }

    /**
     * Returns the Moodle group kept in sync with a mapping, creating it if needed.
     *
     * @param \stdClass $mapping Updated in place.
     * @return int
     */
    protected function ensure_group(\stdClass $mapping): int {
        global $DB;
        if ($mapping->groupid && $DB->record_exists('groups', ['id' => $mapping->groupid, 'courseid' => $mapping->courseid])) {
            return (int) $mapping->groupid;
        }
        $mapping->groupid = groups_create_group((object) [
            'courseid' => $mapping->courseid,
            'name' => $mapping->pronotename,
            'idnumber' => '',
            'description' => '',
        ]);
        $DB->set_field('local_pronoteio_map', 'groupid', $mapping->groupid, ['id' => $mapping->id]);
        return (int) $mapping->groupid;
    }
}
