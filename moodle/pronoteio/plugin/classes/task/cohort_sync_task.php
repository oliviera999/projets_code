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

use local_pronoteio\local\cohort_sync;
use local_pronoteio\local\logger;

/**
 * Nightly synchronisation of the cohorts linked to Pronote classes.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_sync_task extends \core\task\scheduled_task {

    #[\Override]
    public function get_name(): string {
        return get_string('task_cohortsync', 'local_pronoteio');
    }

    #[\Override]
    public function execute(): void {
        $connector = cohort_sync::get_connector();
        if (!$connector) {
            mtrace('No Pronote account configured for the cohorts.');
            return;
        }
        foreach (cohort_sync::get_links() as $link) {
            try {
                $report = cohort_sync::sync_link($connector, $link);
                mtrace("  {$link->pronotename}: {$report['matched']}/{$report['students']} matched, "
                    . "+{$report['added']} -{$report['removed']}");
            } catch (\moodle_exception $e) {
                logger::log((int) get_config('local_pronoteio', 'cohortaccount'), 'cohorts', 'error',
                    "{$link->pronotename}: {$e->getMessage()}");
                mtrace("  {$link->pronotename}: " . $e->getMessage());
            }
        }
    }
}
