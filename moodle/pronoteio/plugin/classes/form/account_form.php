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

namespace local_pronoteio\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Link a Pronote teacher account.
 *
 * Custom data: 'hastoken' (bool) - whether a working token is already stored.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class account_form extends \moodleform {

    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('text', 'pronoteurl', get_string('pronoteurl', 'local_pronoteio'), ['size' => 60]);
        $mform->setType('pronoteurl', PARAM_URL);
        $mform->addRule('pronoteurl', null, 'required', null, 'client');
        $mform->addHelpButton('pronoteurl', 'pronoteurl', 'local_pronoteio');

        $mform->addElement('text', 'username', get_string('username', 'local_pronoteio'), ['autocomplete' => 'username']);
        $mform->setType('username', PARAM_RAW_TRIMMED);
        $mform->addRule('username', null, 'required', null, 'client');

        $mform->addElement('passwordunmask', 'password', get_string('password', 'local_pronoteio'),
            ['autocomplete' => 'current-password']);
        $mform->setType('password', PARAM_RAW);
        $mform->addHelpButton('password', 'password', 'local_pronoteio');

        $mform->addElement('passwordunmask', 'pin', get_string('pin', 'local_pronoteio'),
            ['autocomplete' => 'off', 'inputmode' => 'numeric', 'size' => 8]);
        $mform->setType('pin', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('pin', 'pin', 'local_pronoteio');

        $mform->addElement('text', 'icalurl', get_string('icalurl', 'local_pronoteio'), ['size' => 60]);
        $mform->setType('icalurl', PARAM_URL);
        $mform->addHelpButton('icalurl', 'icalurl', 'local_pronoteio');

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_pronoteio'));
        $mform->setDefault('enabled', 1);

        $this->add_action_buttons(true, get_string('linkaccount', 'local_pronoteio'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (!preg_match('#^https://#i', $data['pronoteurl'])) {
            $errors['pronoteurl'] = get_string('invalidurl', 'error');
        }
        if (!empty($data['icalurl']) && !preg_match('#^https?://#i', $data['icalurl'])) {
            $errors['icalurl'] = get_string('invalidurl', 'error');
        }
        $needspassword = empty($this->_customdata['hastoken']) && get_config('local_pronoteio', 'connector') !== 'file';
        if ($needspassword && $data['password'] === '') {
            $errors['password'] = get_string('required');
        }
        if ($data['pin'] !== '' && !preg_match('/^\d{4,8}$/', $data['pin'])) {
            $errors['pin'] = get_string('error_pinformat', 'local_pronoteio');
        }
        if ($data['pin'] !== '' && $data['password'] === '') {
            $errors['password'] = get_string('required');
        }
        return $errors;
    }
}
