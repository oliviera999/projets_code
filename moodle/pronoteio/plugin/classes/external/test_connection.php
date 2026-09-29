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

namespace local_pronoteio\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_pronoteio\connector\connector_exception;
use local_pronoteio\connector\factory;
use local_pronoteio\local\account_manager;

/**
 * Tests the Pronote connection of the current user.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_connection extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Opens a session with the stored token.
     *
     * @return array
     */
    public static function execute(): array {
        global $USER;
        self::validate_context(\context_user::instance($USER->id));

        $account = account_manager::get_for_user((int) $USER->id);
        if (!$account) {
            return ['success' => false, 'message' => get_string('error_noaccount', 'local_pronoteio')];
        }
        try {
            factory::for_account($account)->login();
            return ['success' => true, 'message' => get_string('connectionok', 'local_pronoteio')];
        } catch (connector_exception $e) {
            account_manager::set_status($account, 'error');
            return ['success' => false, 'message' => get_string('connectionfailed', 'local_pronoteio', $e->getMessage())];
        }
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the session could be opened'),
            'message' => new external_value(PARAM_TEXT, 'Human readable result'),
        ]);
    }
}
