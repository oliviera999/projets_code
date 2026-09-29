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

namespace local_pronoteio\connector;

/**
 * Builds the connector selected in the plugin settings.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class factory {

    /**
     * Connector for a linked account.
     *
     * @param \stdClass $account Record of local_pronoteio_account.
     * @param string|null $name Force a connector, defaults to the site setting.
     * @return connector_interface
     */
    public static function for_account(\stdClass $account, ?string $name = null): connector_interface {
        $name = $name ?? (get_config('local_pronoteio', 'connector') ?: 'sidecar');
        return match ($name) {
            'file' => new file_connector($account),
            default => new sidecar_connector($account),
        };
    }
}
