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

namespace local_pronoteio\export;

/**
 * Grade file accepted by the Pronote client import (text file, tab separated, UTF-16LE with BOM).
 *
 * Columns: "NOM Prénom", grade. The scale (barème) is chosen in the import dialog; coefficients cannot be imported.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pronote_csv {

    /** @var array<string, string> Abbreviations displayed by Pronote for special grades. */
    public const STATUS_LABELS = [
        'abs' => 'Abs',
        'disp' => 'Disp',
        'nonnote' => 'N.Not',
        'inapte' => 'Inap',
        'nonrendu' => 'N.Rdu',
        'abszero' => 'Abs0',
        'nonrenduzero' => 'N.Rdu0',
    ];

    /**
     * Builds the file content.
     *
     * @param array[] $rows ['lastname', 'firstname', 'value' (float|null), 'status' (''|grade_converter::STATUSES)]
     * @param string $title Assessment title, written as header of the grade column.
     * @return string UTF-16LE bytes with BOM.
     */
    public static function build(array $rows, string $title): string {
        usort($rows, fn($a, $b) => strcoll(
            \core_text::strtoupper($a['lastname']) . ' ' . $a['firstname'],
            \core_text::strtoupper($b['lastname']) . ' ' . $b['firstname']
        ));

        $lines = [self::clean(get_string('fullnameuser')) . "\t" . self::clean($title)];
        foreach ($rows as $row) {
            $name = \core_text::strtoupper($row['lastname']) . ' ' . $row['firstname'];
            $lines[] = self::clean($name) . "\t" . self::format_value($row);
        }
        $text = implode("\r\n", $lines) . "\r\n";

        return "\xFF\xFE" . \core_text::convert($text, 'utf-8', 'utf-16le');
    }

    /**
     * Grade cell: French decimal comma, Pronote abbreviation for a status, empty when not graded.
     *
     * @param array $row
     * @return string
     */
    protected static function format_value(array $row): string {
        $status = $row['status'] ?? '';
        if ($status !== '') {
            return self::STATUS_LABELS[$status] ?? '';
        }
        if ($row['value'] === null || $row['value'] === '') {
            return '';
        }
        return str_replace('.', ',', format_float((float) $row['value'], 2, false, true));
    }

    /**
     * Removes separators from a cell.
     *
     * @param string $value
     * @return string
     */
    protected static function clean(string $value): string {
        return trim(preg_replace('/[\t\r\n]+/', ' ', $value));
    }
}
