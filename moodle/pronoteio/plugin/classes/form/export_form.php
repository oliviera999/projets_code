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
 * Download the grades of a grade item as a Pronote import file.
 *
 * Custom data: 'courseid', 'items' (id => name), 'groups' (group_filter::options()).
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_form extends \moodleform {

    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('select', 'itemid', get_string('gradeitem', 'local_pronoteio'), $this->_customdata['items']);
        $mform->setType('itemid', PARAM_INT);

        $mform->addElement('select', 'groupid', get_string('groupsgroupings', 'group'), $this->_customdata['groups']);
        $mform->setType('groupid', PARAM_ALPHANUMEXT);

        $this->add_action_buttons(false, get_string('exportcsv', 'local_pronoteio'));
    }
}
