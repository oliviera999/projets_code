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

use local_pronoteio\connector\connector_exception;
use local_pronoteio\connector\factory;
use local_pronoteio\local\account_manager;
use local_pronoteio\local\logger;

/**
 * Runs every Pronote -> Moodle flow for an account.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /** @var string[] Flow classes, in execution order (roster first so that groups exist). */
    public const FLOWS = [roster::class, timetable::class, homework::class, absences::class];

    /**
     * Synchronises one account. A failing flow does not stop the others.
     *
     * @param \stdClass $account
     * @return array<string, int> Items processed per flow.
     */
    public static function run_for_account(\stdClass $account): array {
        $connector = factory::for_account($account);
        $results = [];

        try {
            $connector->login();
        } catch (connector_exception $e) {
            account_manager::set_status($account, 'error');
            logger::log($account->id, 'session', 'error', $e->getMessage());
            return $results;
        }

        foreach (self::FLOWS as $class) {
            /** @var base $class */
            if (!$connector->supports($class::get_feature())) {
                continue;
            }
            try {
                $results[$class::get_flow()] = (new $class($connector, $account))->run();
            } catch (\Throwable $e) {
                logger::log($account->id, $class::get_flow(), 'error', $e->getMessage());
            }
        }

        account_manager::touch_sync($account);
        return $results;
    }
}
