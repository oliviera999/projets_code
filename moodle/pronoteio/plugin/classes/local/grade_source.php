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
 * What is sent to Pronote: the grades of a grade item, or the progress of a Completion Progress block.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class grade_source {

    /**
     * Course of the source.
     *
     * @return int
     */
    abstract public function get_courseid(): int;

    /**
     * Name shown to the teacher and default assessment title.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Minimum value of collect() 'finalgrade'.
     *
     * @return float
     */
    abstract public function get_min(): float;

    /**
     * Maximum value of collect() 'finalgrade', used as scale when the scale follows Moodle.
     *
     * @return float
     */
    abstract public function get_max(): float;

    /**
     * Gradable participants with their value.
     *
     * @param int|int[] $groupids 0 for all participants.
     * @return array[] ['userid', 'fullname', 'firstname', 'lastname', 'email', 'finalgrade' (float|null), 'excluded' (bool)]
     */
    abstract public function collect(int|array $groupids = 0): array;

    /**
     * Fields identifying the source in the push history (local_pronoteio_push).
     *
     * @return array ['itemid' => int, 'blockinstanceid' => int]
     */
    abstract public function get_history_key(): array;

    /**
     * Push form values that differ from the site defaults for this kind of source.
     *
     * @return array
     */
    public function get_default_options(): array {
        return [];
    }

    /**
     * Enrolled users able to receive a grade, ordered by name.
     *
     * @param int|int[] $groupids
     * @return \stdClass[]
     */
    protected function participants(int|array $groupids): array {
        $context = \context_course::instance($this->get_courseid());
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        return get_enrolled_users($context, 'moodle/grade:view', $groupids, 'u.id, u.email, ' . $namefields,
            'u.lastname, u.firstname', 0, 0, true);
    }

    /**
     * Row of collect() for a user.
     *
     * @param \stdClass $user
     * @param float|null $value
     * @param bool $excluded
     * @return array
     */
    protected function row(\stdClass $user, ?float $value, bool $excluded = false): array {
        return [
            'userid' => (int) $user->id,
            'fullname' => fullname($user),
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'finalgrade' => $value,
            'excluded' => $excluded,
        ];
    }
}
