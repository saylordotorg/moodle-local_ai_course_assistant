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

namespace local_ai_course_assistant\provider;

/**
 * Together AI provider.
 *
 * Together's Serverless Inference tier exposes an OpenAI-compatible chat
 * completions API. Default model is `meta-llama/Llama-3.1-8B-Instruct-Turbo`
 * (FP8-quantized) — Saylor's chosen production chat model at $0.18/M flat
 * across input and output. The 70B and 405B Turbo variants are also
 * available via the same endpoint by overriding the model field.
 *
 * Together does NOT expose an embedding endpoint (use OpenAI for RAG) and
 * does NOT support OpenAI Realtime / WebSocket voice (use OpenAI or xAI
 * for the voice surfaces).
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class together_provider extends openai_compatible_provider {

    protected function get_default_model(): string {
        return 'meta-llama/Llama-3.1-8B-Instruct-Turbo';
    }

    protected function get_default_base_url(): string {
        return 'https://api.together.xyz';
    }
}
