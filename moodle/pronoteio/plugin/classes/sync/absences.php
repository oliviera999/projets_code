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

/**
 * Pronote absences (read only).
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class absences extends base {

    #[\Override]
    public static function get_flow(): string {
        return 'absences';
    }

    #[\Override]
    public static function get_feature(): string {
        return connector_interface::FEATURE_ABSENCES;
    }

    #[\Override]
    public function run(): int {
        $from = usergetmidnight(time()) - 7 * DAYSECS;
        $to = time();
        $count = 0;

        foreach ($this->get_mappings(self::FLAG_ABSENCES) as $mapping) {
            $items = $this->connector->get_absences($mapping->pronoteid, $from, $to);
            $count += count($items);
            // TODO: choose the Moodle target (dedicated table + course report, or mod_attendance sessions)
            // and store the absences there. Until then, only the volume is logged.
            logger::log($this->account->id, self::get_flow(), 'info',
                count($items) . " absence(s) read for {$mapping->pronotename}");
        }
        return $count;
    }
}
