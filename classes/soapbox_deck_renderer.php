<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Rasterizes an uploaded PDF slide deck to page PNG images with Ghostscript
 * (v6.8.23). Reuses Moodle's core Ghostscript path ($CFG->pathtogs, the same
 * binary assign editpdf relies on), so no new server dependency is introduced.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_deck_renderer {

    /** @var int Hard cap on rendered pages (bounds cost / response size). */
    const MAX_PAGES = 60;

    /** @var int Render resolution in DPI. */
    const DPI = 110;

    /**
     * Whether Ghostscript is configured and executable.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $CFG;
        $gs = (string) ($CFG->pathtogs ?? '');
        return $gs !== '' && file_is_executable($gs);
    }

    /**
     * Render a PDF file to page PNGs. Returns the ordered list of PNG file paths
     * in a per-request temp dir (cleaned up automatically at request end).
     *
     * @param string $pdfpath Local path to the PDF.
     * @param int $maxpages Cap on pages (clamped to MAX_PAGES).
     * @return string[] Ordered PNG file paths (empty on failure / no Ghostscript).
     */
    public static function render(string $pdfpath, int $maxpages = self::MAX_PAGES): array {
        global $CFG;
        if (!self::is_available() || !is_readable($pdfpath)) {
            return [];
        }
        $maxpages = max(1, min($maxpages, self::MAX_PAGES));
        $outdir = make_request_directory();
        $pattern = $outdir . '/page-%d.png';

        $cmd = escapeshellarg($CFG->pathtogs)
            . ' -q -dNOPAUSE -dBATCH -dSAFER'
            . ' -sDEVICE=png16m'
            . ' -r' . self::DPI
            . ' -dTextAlphaBits=4 -dGraphicsAlphaBits=4'
            . ' -dFirstPage=1 -dLastPage=' . $maxpages
            . ' -sOutputFile=' . escapeshellarg($pattern)
            . ' ' . escapeshellarg($pdfpath)
            . ' 2>&1';

        exec($cmd, $output, $status);

        // Collect produced pages in numeric order (page-1.png, page-2.png, ...).
        $pages = [];
        for ($i = 1; $i <= $maxpages; $i++) {
            $file = $outdir . '/page-' . $i . '.png';
            if (is_file($file) && filesize($file) > 0) {
                $pages[] = $file;
            } else {
                break; // No gaps: the first missing page ends the deck.
            }
        }
        return $pages;
    }

    /**
     * Render a PDF to page images encoded as data URIs, ready to hand to the
     * browser slide viewer without persisting page images to storage.
     *
     * @param string $pdfpath
     * @param int $maxpages
     * @return string[] data:image/png;base64,... strings, one per page.
     */
    public static function render_to_datauris(string $pdfpath, int $maxpages = self::MAX_PAGES): array {
        $uris = [];
        foreach (self::render($pdfpath, $maxpages) as $file) {
            $data = file_get_contents($file);
            if ($data !== false) {
                $uris[] = 'data:image/png;base64,' . base64_encode($data);
            }
        }
        return $uris;
    }
}
