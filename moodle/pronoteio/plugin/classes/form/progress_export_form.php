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

use local_pronoteio\local\grade_converter;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Download the progress of a Completion Progress block as a Pronote import file.
 *
 * Custom data: 'courseid', 'instanceid', 'group' (group_filter value).
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_export_form extends \moodleform {

    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        foreach (['courseid' => PARAM_INT, 'instanceid' => PARAM_INT, 'group' => PARAM_ALPHANUMEXT] as $name => $type) {
            $mform->addElement('hidden', $name, $this->_customdata[$name]);
            $mform->setType($name, $type);
        }

        $mform->addElement('text', 'title', get_string('assessmenttitle', 'local_pronoteio'), ['size' => 50]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addElement('text', 'scale', get_string('setting_scale', 'local_pronoteio'), ['size' => 6]);
        $mform->setType('scale', PARAM_FLOAT);
        $mform->addElement('select', 'rounding', get_string('setting_rounding', 'local_pronoteio'), grade_converter::ROUNDINGS);

        $this->add_action_buttons(false, get_string('exportcsv', 'local_pronoteio'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if ((float) $data['scale'] <= 0 || (float) $data['scale'] > 1000) {
            $errors['scale'] = get_string('error_scale', 'local_pronoteio', 1000);
        }
        if (!array_key_exists($data['rounding'], grade_converter::ROUNDINGS)) {
            $errors['rounding'] = get_string('invaliddata', 'error');
        }
        return $errors;
    }
}
