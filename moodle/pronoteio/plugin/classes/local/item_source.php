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
 * Final grades of a numeric grade item.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_source extends grade_source {

    /**
     * Constructor.
     *
     * @param \grade_item $item
     */
    public function __construct(
        /** @var \grade_item */
        protected \grade_item $item,
    ) {
    }

    /**
     * Grade item of the source.
     *
     * @return \grade_item
     */
    public function get_item(): \grade_item {
        return $this->item;
    }

    #[\Override]
    public function get_courseid(): int {
        return (int) $this->item->courseid;
    }

    #[\Override]
    public function get_name(): string {
        return $this->item->get_name();
    }

    #[\Override]
    public function get_min(): float {
        return (float) $this->item->grademin;
    }

    #[\Override]
    public function get_max(): float {
        return (float) $this->item->grademax;
    }

    #[\Override]
    public function collect(int|array $groupids = 0): array {
        $users = $this->participants($groupids);
        $grades = $users ? \grade_grade::fetch_users_grades($this->item, array_keys($users), false) : [];
        $rows = [];
        foreach ($users as $user) {
            $grade = $grades[$user->id] ?? null;
            $rows[] = $this->row($user, ($grade && $grade->finalgrade !== null) ? (float) $grade->finalgrade : null,
                $grade ? (bool) $grade->is_excluded() : false);
        }
        return $rows;
    }

    #[\Override]
    public function get_history_key(): array {
        return ['itemid' => (int) $this->item->id, 'blockinstanceid' => 0];
    }
}
