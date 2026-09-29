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

/**
 * "Test connection" button of the Pronote account page.
 *
 * @module     local_pronoteio/test_connection
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {add as addToast} from 'core/toast';

/**
 * Binds the button.
 *
 * @param {string} selector
 */
export const init = (selector) => {
    const button = document.querySelector(selector);
    if (!button) {
        return;
    }
    button.addEventListener('click', async() => {
        button.disabled = true;
        try {
            const [result] = await Promise.all(Ajax.call([{methodname: 'local_pronoteio_test_connection', args: {}}]));
            await addToast(result.message, {type: result.success ? 'success' : 'danger'});
        } catch (error) {
            Notification.exception(error);
        } finally {
            button.disabled = false;
        }
    });
};
