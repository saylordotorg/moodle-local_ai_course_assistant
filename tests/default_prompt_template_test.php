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

/**
 * The shipped default prompt template must be coherent in every locale.
 *
 * v7.2.1 stopped substituting {{coursetopics}} and {{coursecontent}}, which made
 * the headings above them meaningless. Removing the placeholder but leaving its
 * heading is worse than either state on its own: the prompt then carries a
 * heading, or a sentence promising course text, with nothing beneath it. The
 * first pass at this keyed on markdown "##" headings and so missed the 40
 * locales that use a colon-terminated label instead, leaving exactly the dangling
 * heading it set out to remove. This asserts the end state directly rather than
 * trusting the shape of the edit.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder
 */
final class default_prompt_template_test extends \basic_testcase {

    /**
     * Every locale's default template and placeholder-list description.
     *
     * @return array
     */
    private function templates(): array {
        global $CFG;
        $out = [];
        foreach (glob($CFG->dirroot . '/local/ai_course_assistant/lang/*/local_ai_course_assistant.php') as $file) {
            $locale = basename(dirname($file));
            $string = [];
            include($file);
            $out[$locale] = [
                'template' => $string['settings:systemprompt_default'] ?? '',
                'desc' => $string['settings:systemprompt_desc'] ?? '',
            ];
        }
        return $out;
    }

    public function test_no_locale_still_references_a_removed_placeholder(): void {
        foreach ($this->templates() as $locale => $parts) {
            foreach (['{{coursetopics}}', '{{coursecontent}}'] as $gone) {
                $this->assertStringNotContainsString($gone, $parts['template'], "{$locale} template");
                $this->assertStringNotContainsString($gone, $parts['desc'], "{$locale} description");
            }
        }
    }

    /**
     * The headings that introduced the two removed placeholders, per locale.
     *
     * Captured from the template as it stood immediately before the removal.
     * Hard-coded rather than derived, because the defect is precisely that these
     * strings survived their content: there is nothing left in the file to
     * compare against. Six locales used a markdown "##" heading and forty used a
     * colon-terminated label, which is why the first removal pass -- keyed on
     * "##" alone -- silently missed most of them.
     *
     * @return array locale => list of headings that must no longer appear.
     */
    private function removed_headings(): array {
        return [
        'am' => ['የሚሸፈኑ የኮርስ ርዕሶች፦'],
        'ar' => ['موضوعات المقرر المغطاة:'],
        'bg' => ['Теми, разгледани в курса:'],
        'bm' => ['Kalanso kow minw kɛra:'],
        'bn' => ['কোর্সে আলোচিত বিষয়সমূহ:'],
        'cs' => ['Témata probíraná v kurzu:'],
        'da' => ['Emner dækket i kurset:'],
        'de' => ['Im Kurs behandelte Themen:'],
        'el' => ['## Course Structure', 'The following is the actual text of the course pages and materials. This is your primary knowledge source for this course.'],
        'en' => ['## Course Structure', 'The following is the actual text of the course pages and materials. This is your primary knowledge source for this course.'],
        'es' => ['Temas del curso:'],
        'fi' => ['Kurssilla käsitellyt aiheet:'],
        'fr' => ['Sujets abordés dans le cours :'],
        'ha' => ['Batutuwan kozi da aka rufe:'],
        'he' => ['## Course Structure', 'The following is the actual text of the course pages and materials. This is your primary knowledge source for this course.'],
        'hi' => ['पाठ्यक्रम में शामिल विषय:'],
        'hu' => ['## Course Structure', 'The following is the actual text of the course pages and materials. This is your primary knowledge source for this course.'],
        'id' => ['Topik yang dibahas dalam kursus:'],
        'ig' => ['Isiokwu kọọsị a kọwara:'],
        'it' => ['## Course Structure', 'The following is the actual text of the course pages and materials. This is your primary knowledge source for this course.'],
        'ja' => ['コースで取り上げるトピック：'],
        'ko' => ['코스에서 다루는 주제:'],
        'ms' => ['Topik kursus yang diliputi:'],
        'nb' => ['Emner dekket i kurset:'],
        'ne' => ['समेटिएका पाठ्यक्रम विषयहरू:'],
        'nl' => ['Onderwerpen die in de cursus worden behandeld:'],
        'om' => ['Mata-duree koorsii hammatame:'],
        'pa' => ['ਕੋਰਸ ਦੇ ਵਿਸ਼ੇ:'],
        'pl' => ['Tematy omawiane w kursie:'],
        'pt_br' => ['Tópicos abordados no curso:'],
        'ro' => ['Subiecte acoperite în curs:'],
        'ru' => ['Темы курса:'],
        'sk' => ['Témy preberané v kurze:'],
        'so' => ['Mawduucyada koorsada la daboolay:'],
        'sv' => ['Ämnen som behandlas i kursen:'],
        'sw' => ['Mada za kozi zinazoshughulikiwa:'],
        'ta' => ['உள்ளடக்கப்பட்ட பாட தலைப்புகள்:'],
        'th' => ['หัวข้อที่ครอบคลุมในรายวิชา:'],
        'tl' => ['Mga paksa ng kurso:'],
        'tr' => ['Derste işlenen konular:'],
        'uk' => ['## Course Structure', 'The following is the actual text of the course pages and materials. This is your primary knowledge source for this course.'],
        'vi' => ['Các chủ đề khóa học được đề cập:'],
        'wo' => ['Sujets cours bi ñu dakkal:'],
        'yo' => ['Àwọn ìdánimọ̀ ẹkọ tí a bò:'],
        'zh_cn' => ['课程涵盖的主题：'],
        'zu' => ['Izihloko zesifundo ezifundisiwe:'],
        ];
    }

    public function test_no_locale_kept_a_heading_whose_content_was_removed(): void {
        foreach ($this->templates() as $locale => $parts) {
            foreach ($this->removed_headings()[$locale] ?? [] as $heading) {
                $this->assertStringNotContainsString(
                    $heading,
                    $parts['template'],
                    "{$locale}: the heading " . var_export($heading, true) . ' survived, but the '
                        . 'placeholder beneath it was removed -- so the prompt now announces a '
                        . 'section that is not there.'
                );
            }
        }
    }

    public function test_no_locale_ends_a_block_with_a_dangling_heading(): void {
        foreach ($this->templates() as $locale => $parts) {
            $lines = preg_split('/\R/u', $parts['template']);
            foreach ($lines as $i => $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                // A heading is a markdown "##" line or a short colon-terminated
                // label; both were used across the 46 locales.
                $isheading = str_starts_with($trimmed, '#')
                    || (preg_match('/[:：：︓﹕፦]$/u', $trimmed) && \core_text::strlen($trimmed) < 60);
                if (!$isheading) {
                    continue;
                }
                // Find the next non-blank line.
                $next = '';
                for ($j = $i + 1; $j < count($lines); $j++) {
                    if (trim($lines[$j]) !== '') {
                        $next = trim($lines[$j]);
                        break;
                    }
                }
                $this->assertNotSame(
                    '',
                    $next,
                    "{$locale}: the template ends on the heading " . var_export($trimmed, true)
                        . ' with nothing beneath it.'
                );
            }
        }
    }

    public function test_the_placeholder_list_has_no_dangling_separator(): void {
        foreach ($this->templates() as $locale => $parts) {
            $this->assertDoesNotMatchRegularExpression(
                '/[、,，]\s*[。.]|[、,，]\s*$|[、,，]\s+を/u',
                $parts['desc'],
                "{$locale}: removing a placeholder left its separator behind."
            );
        }
    }

    public function test_no_blank_line_runs(): void {
        foreach ($this->templates() as $locale => $parts) {
            $this->assertDoesNotMatchRegularExpression(
                '/\n{3,}/',
                $parts['template'],
                "{$locale}: removing a block left a run of blank lines."
            );
        }
    }
}
