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

use local_pronoteio\export\pronote_csv;
use local_pronoteio\local\grade_converter;

/**
 * Tests of the grade conversion and of the Pronote import file.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(grade_converter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(pronote_csv::class)]
final class grade_converter_test extends \basic_testcase {

    public function test_proportional_conversion(): void {
        $converter = new grade_converter(20);
        $this->assertSame(['value' => 15.0, 'status' => ''], $converter->convert(75, 0, 100));
        $this->assertSame(['value' => 10.0, 'status' => ''], $converter->convert(6, 2, 10));
        $this->assertSame(['value' => 0.0, 'status' => ''], $converter->convert(0, 0, 100));
    }

    public function test_rounding_step(): void {
        $this->assertSame(12.5, (new grade_converter(20, 0.5))->convert(12.3, 0, 20)['value']);
        $this->assertSame(12.0, (new grade_converter(20, 1))->convert(12.4, 0, 20)['value']);
        $this->assertSame(12.33, (new grade_converter(20, 0.01))->convert(12.333, 0, 20)['value']);
        $this->assertSame(12.33, (new grade_converter(20, 0))->convert(12.333, 0, 20)['value']);
    }

    public function test_value_is_clamped_to_the_scale(): void {
        $converter = new grade_converter(20);
        $this->assertSame(20.0, $converter->convert(110, 0, 100)['value']);
        $this->assertSame(0.0, $converter->convert(-5, 0, 100)['value']);
    }

    public function test_missing_and_excluded_grades(): void {
        $converter = new grade_converter(20, 0.01, grade_converter::STATUS_SKIP, 'disp');
        $this->assertNull($converter->convert(null, 0, 20));
        $this->assertSame(['value' => null, 'status' => 'disp'], $converter->convert(15, 0, 20, true));

        $converter = new grade_converter(20, 0.01, 'abs', grade_converter::STATUS_SKIP);
        $this->assertSame(['value' => null, 'status' => 'abs'], $converter->convert(null, 0, 20));
        $this->assertNull($converter->convert(15, 0, 20, true));
    }

    public function test_invalid_scale(): void {
        $this->expectException(\coding_exception::class);
        new grade_converter(0);
    }

    public function test_csv_labels_cover_every_status(): void {
        foreach (grade_converter::STATUSES as $status) {
            $this->assertArrayHasKey($status, pronote_csv::STATUS_LABELS);
        }
    }
}
