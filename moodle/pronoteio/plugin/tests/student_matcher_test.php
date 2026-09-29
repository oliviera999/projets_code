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

use local_pronoteio\local\student_matcher;

/**
 * Tests of the student matching.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(student_matcher::class)]
final class student_matcher_test extends \basic_testcase {

    /** @var array[] Moodle users. */
    private const USERS = [
        ['id' => 1, 'firstname' => 'Élodie', 'lastname' => 'Dupont', 'email' => 'elodie.dupont@example.org'],
        ['id' => 2, 'firstname' => 'Léo', 'lastname' => 'Martin', 'email' => 'leo.m@example.org'],
        ['id' => 3, 'firstname' => 'Léo', 'lastname' => 'Martin', 'email' => 'leo.martin2@example.org'],
        ['id' => 4, 'firstname' => 'Inès', 'lastname' => 'Nguyen-Petit', 'email' => ''],
    ];

    /** @var array[] Pronote students. */
    private const STUDENTS = [
        ['id' => 'p1', 'firstname' => 'Elodie', 'lastname' => 'DUPONT', 'email' => null],
        ['id' => 'p2', 'firstname' => 'Léo', 'lastname' => 'MARTIN', 'email' => 'LEO.M@example.org'],
        ['id' => 'p4', 'firstname' => 'Inès', 'lastname' => 'NGUYEN PETIT', 'email' => null],
        ['id' => 'p9', 'firstname' => 'Zoé', 'lastname' => 'ABSENTE', 'email' => null],
    ];

    public function test_normalise(): void {
        $this->assertSame('elodie dupont', student_matcher::normalise('  Élodie   DUPONT '));
        $this->assertSame('nguyen petit', student_matcher::normalise('Nguyen-Petit'));
        $this->assertSame('', student_matcher::normalise(' - '));
    }

    public function test_name_mode_reports_homonyms(): void {
        $result = (new student_matcher(student_matcher::MODE_NAME))->match(self::USERS, self::STUDENTS);
        $this->assertSame(['p1' => 1, 'p4' => 4], $result['pairs']);
        $this->assertSame(['p2'], array_column($result['ambiguous'], 'id'));
        $this->assertSame(['p9'], array_column($result['unmatched'], 'id'));
    }

    public function test_name_then_email_resolves_homonyms(): void {
        $result = (new student_matcher(student_matcher::MODE_NAME_EMAIL))->match(self::USERS, self::STUDENTS);
        $this->assertSame(['p1' => 1, 'p4' => 4, 'p2' => 2], $result['pairs']);
        $this->assertSame([], $result['ambiguous']);
        $this->assertSame(['p9'], array_column($result['unmatched'], 'id'));
    }

    public function test_email_mode(): void {
        $result = (new student_matcher(student_matcher::MODE_EMAIL))->match(self::USERS, self::STUDENTS);
        $this->assertSame(['p2' => 2], $result['pairs']);
        $this->assertCount(3, $result['unmatched']);
    }

    public function test_user_is_never_matched_twice(): void {
        $users = [['id' => 1, 'firstname' => 'Léa', 'lastname' => 'Roux', 'email' => 'lea@example.org']];
        $students = [
            ['id' => 'a', 'firstname' => 'Léa', 'lastname' => 'Roux', 'email' => null],
            ['id' => 'b', 'firstname' => 'Autre', 'lastname' => 'Nom', 'email' => 'lea@example.org'],
        ];
        $result = (new student_matcher(student_matcher::MODE_NAME_EMAIL))->match($users, $students);
        $this->assertSame(['a' => 1], $result['pairs']);
        $this->assertSame(['b'], array_column($result['unmatched'], 'id'));
    }

    public function test_accepts_objects(): void {
        $users = array_map(fn($u) => (object) $u, self::USERS);
        $result = (new student_matcher(student_matcher::MODE_NAME))->match($users, self::STUDENTS);
        $this->assertSame(1, $result['pairs']['p1']);
    }
}
