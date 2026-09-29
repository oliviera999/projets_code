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

namespace local_pronoteio;

use local_pronoteio\form\push_form;
use local_pronoteio\local\grade_converter;
use local_pronoteio\local\group_filter;
use local_pronoteio\local\progress_source;
use local_pronoteio\sync\grades;

/**
 * Tests of the export of the Completion Progress block.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(progress_source::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(group_filter::class)]
final class progress_source_test extends \advanced_testcase {

    /** @var \stdClass */
    private \stdClass $course;
    /** @var int */
    private int $instanceid;
    /** @var \stdClass[] Students by name. */
    private array $students = [];
    /** @var \stdClass[] Groups by name. */
    private array $groups = [];
    /** @var int */
    private int $groupingid;

    #[\Override]
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        if (!progress_source::available()) {
            $this->markTestSkipped('block_completion_progress is not installed');
        }
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        set_config('enablecompletion', 1);

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $cms = [];
        for ($i = 1; $i <= 4; $i++) {
            $page = $gen->create_module('page', ['course' => $this->course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
            $cms[] = (int) $page->cmid;
        }

        $this->groups['A'] = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Groupe A']);
        $this->groups['B'] = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Groupe B']);
        $this->groupingid = (int) $gen->create_grouping(['courseid' => $this->course->id, 'name' => 'Tous'])->id;
        foreach ($this->groups as $group) {
            $gen->create_grouping_group(['groupingid' => $this->groupingid, 'groupid' => $group->id]);
        }

        // Completed activities: Alice 3/4, Bruno 1/4 (group A), Chloé 0/4 (group B).
        foreach (['Alice' => ['A', 3], 'Bruno' => ['A', 1], 'Chloé' => ['B', 0]] as $name => [$group, $done]) {
            $user = $gen->create_user(['firstname' => $name, 'lastname' => 'Test']);
            $gen->enrol_user($user->id, $this->course->id, 'student');
            $gen->create_group_member(['groupid' => $this->groups[$group]->id, 'userid' => $user->id]);
            foreach (array_slice($cms, 0, $done) as $cmid) {
                $DB->insert_record('course_modules_completion', ['coursemoduleid' => $cmid, 'userid' => $user->id,
                    'completionstate' => COMPLETION_COMPLETE, 'timemodified' => time()]);
            }
            $this->students[$name] = $user;
        }

        $this->instanceid = $this->add_block($this->course, 'Ma progression');
    }

    /**
     * Adds a Completion Progress block to a course.
     *
     * @param \stdClass $course
     * @param string $title
     * @return int Block instance id.
     */
    private function add_block(\stdClass $course, string $title): int {
        global $DB;
        return (int) $DB->insert_record('block_instances', [
            'blockname' => progress_source::BLOCK,
            'parentcontextid' => \context_course::instance($course->id)->id,
            'showinsubcontexts' => 0,
            'requiredbytheme' => 0,
            'pagetypepattern' => 'course-view-*',
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => base64_encode(serialize((object) ['progressTitle' => $title])),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    public function test_from_instance(): void {
        $source = progress_source::from_instance($this->course, $this->instanceid);
        $this->assertNotNull($source);
        $this->assertSame('Ma progression', $source->get_name());
        $this->assertSame(['itemid' => 0, 'blockinstanceid' => $this->instanceid], $source->get_history_key());

        $other = $this->getDataGenerator()->create_course();
        $this->assertNull(progress_source::from_instance($other, $this->instanceid));
        $this->assertNull(progress_source::from_instance($this->course, 0));

        $this->setAdminUser();
        $this->add_block($other, 'Autre');
        $this->assertCount(1, progress_source::for_course($this->course));
    }

    public function test_collect_by_group(): void {
        $source = progress_source::from_instance($this->course, $this->instanceid);

        $all = array_column($source->collect(0), 'finalgrade', 'firstname');
        $this->assertEquals(['Alice' => 75.0, 'Bruno' => 25.0, 'Chloé' => 0.0], $all);

        $groupa = group_filter::resolve($this->course->id, (string) $this->groups['A']->id);
        $this->assertSame((int) $this->groups['A']->id, $groupa);
        $this->assertEquals(['Alice' => 75.0, 'Bruno' => 25.0], array_column($source->collect($groupa), 'finalgrade', 'firstname'));

        $grouping = group_filter::resolve($this->course->id, 'g' . $this->groupingid);
        $this->assertCount(2, $grouping);
        $this->assertCount(3, $source->collect($grouping));

        $this->assertSame(0, group_filter::resolve($this->course->id, '999999'));
        $this->assertSame(0, group_filter::resolve($this->course->id, 'x'));
        $this->assertArrayHasKey('g' . $this->groupingid, group_filter::options($this->course->id));
        $this->assertSame('0', group_filter::clean($this->course->id, 'g0'));
    }

    public function test_file_on_ten(): void {
        $source = progress_source::from_instance($this->course, $this->instanceid);
        $file = grades::build_file($source, 0, new grade_converter(progress_source::DEFAULT_SCALE));
        $text = \core_text::convert(substr($file, 2), 'utf-16le', 'utf-8');
        $this->assertStringContainsString("TEST Alice\t7,5", $text);
        $this->assertStringContainsString("TEST Bruno\t2,5", $text);
        $this->assertStringContainsString("TEST Chloé\t0", $text);
        $this->assertStringStartsWith(get_string('fullnameuser') . "\tMa progression", $text);
    }

    public function test_push_defaults(): void {
        global $DB;
        $source = progress_source::from_instance($this->course, $this->instanceid);

        $defaults = push_form::defaults($source, grades::get_last_push($source));
        $this->assertSame('Ma progression', $defaults['title']);
        $this->assertSame(grade_converter::SCALE_FIXED, $defaults['scalemode']);
        $this->assertSame(10.0, $defaults['scale']);
        $this->assertSame(0.2, $defaults['coefficient']);

        $converter = grades::converter($source, $defaults);
        $this->assertSame(['value' => 7.5, 'status' => ''], $converter->convert(75.0, 0, 100));
        $this->assertSame(0.2, grades::assessment($source, $defaults, $converter)['coefficient']);

        // The history of a progress is kept apart from the grade items.
        $DB->insert_record(grades::TABLE, $source->get_history_key() + [
            'courseid' => $this->course->id, 'accountid' => 1, 'serviceid' => 's:SVT|1A', 'servicename' => 'SVT - 1A',
            'periodid' => 'p:Trimestre 1', 'assessmentid' => 'd:1', 'options' => json_encode(['coefficient' => 0.5]),
            'written' => 3, 'userid' => 2, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record(grades::TABLE, ['itemid' => 5, 'blockinstanceid' => 0,
            'courseid' => $this->course->id, 'accountid' => 1, 'serviceid' => 's:SVT|1A', 'servicename' => 'SVT - 1A',
            'periodid' => 'p:Trimestre 1', 'written' => 3, 'userid' => 2, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->assertSame('d:1', grades::get_previous_push($source, 's:SVT|1A')->assessmentid);
        $this->assertSame(0.5, push_form::defaults($source, grades::get_last_push($source))['coefficient']);
    }
}
