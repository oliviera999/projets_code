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
 * Import of the cohort / Pronote class correspondence table.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_import_form extends \moodleform {

    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('header', 'importhdr', get_string('cohortimport', 'local_pronoteio'));
        $mform->addElement('static', 'importhelp', '', get_string('cohortimport_help', 'local_pronoteio'));
        $mform->addElement('filepicker', 'csvfile', get_string('file'), null, ['accepted_types' => ['.csv', '.txt']]);
        $mform->addRule('csvfile', null, 'required');
        $mform->addElement('advcheckbox', 'replace', get_string('cohortimport_replace', 'local_pronoteio'));
        $this->add_action_buttons(false, get_string('import'));
    }
}
