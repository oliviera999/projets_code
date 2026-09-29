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

use local_pronoteio\connector\connector_interface;
use local_pronoteio\local\cohort_sync;

/**
 * Tests of the cohort / Pronote class synchronisation.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cohort_sync::class)]
final class cohort_sync_test extends \advanced_testcase {

    /** @var array[] Pronote classes. */
    private const RESOURCES = [
        ['id' => 'c3a', 'name' => '3A', 'type' => 'class'],
        ['id' => 'c3b', 'name' => '3B', 'type' => 'class'],
        ['id' => 'g1', 'name' => 'Latin 4e', 'type' => 'group'],
    ];

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
    }

    public function test_suggest(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $a = $gen->create_cohort(['name' => 'Élèves 3A', 'idnumber' => '']);
        $b = $gen->create_cohort(['name' => 'Classe troisième B', 'idnumber' => '3b']);
        $gen->create_cohort(['name' => 'Latin 4e', 'idnumber' => 'lat']);
        $gen->create_cohort(['name' => 'Latin 4e bis', 'idnumber' => 'lat2']);

        $suggestions = cohort_sync::suggest(self::RESOURCES, cohort_sync::get_cohorts());
        $this->assertSame((int) $a->id, $suggestions['c3a']);
        $this->assertSame((int) $b->id, $suggestions['c3b']);
        $this->assertArrayHasKey('g1', $suggestions);
    }

    public function test_save_links(): void {
        global $DB;
        $this->resetAfterTest();
        $cohort = $this->getDataGenerator()->create_cohort();

        cohort_sync::save_links(self::RESOURCES, ['c3a' => $cohort->id, 'unknown' => $cohort->id], ['c3a' => true]);
        $links = cohort_sync::get_links();
        $this->assertSame(['c3a'], array_keys($links));
        $this->assertEquals(1, $links['c3a']->autofill);

        cohort_sync::save_links(self::RESOURCES, ['c3a' => $cohort->id], ['c3a' => false]);
        $this->assertEquals(0, cohort_sync::get_links()['c3a']->autofill);

        cohort_sync::save_links(self::RESOURCES, ['c3a' => 0], []);
        $this->assertSame(0, $DB->count_records(cohort_sync::TABLE));
    }

    public function test_sync_link_only_removes_its_own_members(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $cohort = $gen->create_cohort();
        $alice = $gen->create_user(['firstname' => 'Alice', 'lastname' => 'Durand', 'email' => 'alice@example.org']);
        $bob = $gen->create_user(['firstname' => 'Bob', 'lastname' => 'Petit', 'email' => 'bob@example.org']);
        $manual = $gen->create_user(['firstname' => 'Carl', 'lastname' => 'Manuel', 'email' => 'carl@example.org']);
        cohort_add_member($cohort->id, $manual->id);
        set_config('matchmode', 'name', 'local_pronoteio');

        cohort_sync::save_links(self::RESOURCES, ['c3a' => $cohort->id], ['c3a' => true]);
        $link = cohort_sync::get_links()['c3a'];

        $connector = $this->connector([
            ['id' => 's1', 'firstname' => 'Alice', 'lastname' => 'DURAND', 'email' => null],
            ['id' => 's2', 'firstname' => 'Bob', 'lastname' => 'PETIT', 'email' => null],
            ['id' => 's3', 'firstname' => 'Inconnu', 'lastname' => 'NOM', 'email' => null],
        ]);
        $report = cohort_sync::sync_link($connector, $link);
        $this->assertSame(3, $report['students']);
        $this->assertSame(2, $report['matched']);
        $this->assertSame(2, $report['added']);
        $this->assertSame(['NOM Inconnu'], $report['unmatched']);
        $this->assertSame(1, $report['extra']);
        $this->assertTrue(cohort_is_member($cohort->id, $alice->id));

        // Bob leaves the class; the manually added member stays.
        $connector = $this->connector([['id' => 's1', 'firstname' => 'Alice', 'lastname' => 'DURAND', 'email' => null]]);
        $report = cohort_sync::sync_link($connector, $link);
        $this->assertSame(1, $report['removed']);
        $this->assertFalse(cohort_is_member($cohort->id, $bob->id));
        $this->assertTrue(cohort_is_member($cohort->id, $manual->id));
        $this->assertSame(1, $DB->count_records(cohort_sync::MEMBERS, ['linkid' => $link->id]));
    }

    public function test_sync_link_without_autofill_changes_nothing(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $cohort = $gen->create_cohort();
        $user = $gen->create_user(['firstname' => 'Alice', 'lastname' => 'Durand']);
        set_config('matchmode', 'name', 'local_pronoteio');
        cohort_sync::save_links(self::RESOURCES, ['c3a' => $cohort->id], []);

        $report = cohort_sync::sync_link($this->connector([
            ['id' => 's1', 'firstname' => 'Alice', 'lastname' => 'DURAND', 'email' => null],
        ]), cohort_sync::get_links()['c3a']);
        $this->assertSame(1, $report['matched']);
        $this->assertSame(0, $report['added']);
        $this->assertFalse(cohort_is_member($cohort->id, $user->id));
    }

    public function test_parse_csv(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $a = $gen->create_cohort(['name' => 'Élèves 3A', 'idnumber' => 'e3a']);
        $b = $gen->create_cohort(['name' => 'Élèves 3B', 'idnumber' => '']);

        $csv = "\xEF\xBB\xBFcohort_idnumber;cohort_name;pronote_class;autofill\n"
            . "e3a;;3A;1\n"
            . ";elèves 3b;3b;oui\n"
            . "nope;;3A;0\n"
            . "e3a;;4C;1\n";
        $result = cohort_sync::parse_csv($csv, self::RESOURCES);
        $this->assertSame(['c3a' => (int) $a->id, 'c3b' => (int) $b->id], $result['cohorts']);
        $this->assertSame(['c3a' => true, 'c3b' => true], $result['autofill']);
        $this->assertCount(2, $result['errors']);
    }

    /**
     * Connector returning a fixed roster.
     *
     * @param array[] $students
     * @return connector_interface
     */
    private function connector(array $students): connector_interface {
        $connector = $this->createStub(connector_interface::class);
        $connector->method('get_roster')->willReturn($students);
        return $connector;
    }
}
