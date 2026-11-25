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

/**
 * Answer safety for live voice (8.4.0): posts each finished turn of a live session for checking and acts on the
 * verdict.
 *
 * Live voice (OpenAI and xAI realtime, Gemini Live) runs browser to provider, so the server never sees an answer
 * unless the browser sends it. While the course's answer safety mode is Watch or Enforce, get_realtime_token
 * returns a ticket and chat.js makes one tracker per session with create(). The engine reports the provider's
 * transcripts (text()) and the end of each assistant turn (turnEnd()); the tracker posts the turn and:
 *   - block: stops that turn's audio at once (engine.stopSpeaking), then the caller shows and speaks the safe
 *     message, once;
 *   - warn: the caller adds the check line to the transcript; the audio is left alone;
 *   - anything else, or a failed post: nothing (the same fail-safe as the text path).
 *
 * The check runs AFTER the turn was generated, so some or all of a blocked answer may already have been heard.
 * With no ticket (mode Off) chat.js makes no tracker: nothing is collected and nothing is posted.
 *
 * Whose words a question is: the provider's transcript of the learner can arrive after the model has begun to
 * answer, or after it has finished. Learner text that arrives before the answer starts, or while this turn has no
 * question yet, belongs to this turn; learner text that arrives once an answer with a question is under way is a
 * barge-in and belongs to the next. A finished turn with no question waits up to waitMs for one.
 *
 * @module     local_ai_course_assistant/voice_safety
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** Characters of each side that are sent (the server's caps: voice_safety::MAX_*_CHARS). */
    var MAX_QUESTION = 2000;
    var MAX_ANSWER = 8000;
    /** How long a finished turn waits for the learner's transcript. */
    var DEFAULT_WAIT_MS = 1500;

    /**
     * A server message as the voice transcript shows it and speech reads it: one line, without the markdown
     * underscores or asterisks that wrap the check line.
     *
     * @param {string} text
     * @returns {string}
     */
    var plain = function(text) {
        return String(text || '')
            .replace(/(^|\n)[ \t]*([_*]{1,3})([^\n]*?)\2[ \t]*(?=\n|$)/g, '$1$3')
            .replace(/\s*\n+\s*/g, ' ')
            .trim();
    };

    /**
     * Make the tracker for one live session.
     *
     * @param {Object} opts
     * @param {string} opts.ticket The safetyticket from get_realtime_token
     * @param {Function} opts.post function({ticket, turn, question, answer}): Promise of the verdict
     * @param {Function} opts.stop function(turn): stop that turn's audio
     * @param {Function} opts.onBlock function(text, turn): show and speak the safe message
     * @param {Function} opts.onWarn function(text, turn): add the check line to the transcript
     * @param {number=} opts.waitMs
     * @returns {{text: function(string, string), turnEnd: function(number), flush: function()}|null} null without
     *     a ticket
     */
    var create = function(opts) {
        opts = opts || {};
        if (!opts.ticket || typeof opts.post !== 'function') {
            return null;
        }
        var waitMs = typeof opts.waitMs === 'number' ? opts.waitMs : DEFAULT_WAIT_MS;
        var question = '';
        var nextQuestion = '';
        var answer = '';
        var answering = false;
        var pending = null;
        var blocked = {};

        var send = function(turn) {
            var result;
            try {
                result = opts.post({
                    ticket: opts.ticket,
                    turn: turn.turn,
                    question: turn.question.trim().slice(0, MAX_QUESTION),
                    answer: turn.answer.trim().slice(0, MAX_ANSWER)
                });
            } catch (e) {
                return;
            }
            if (!result || typeof result.then !== 'function') {
                return;
            }
            result.then(function(res) {
                if (!res || !res.text) {
                    return;
                }
                if (res.action === 'block' && !blocked[turn.turn]) {
                    blocked[turn.turn] = true;
                    // Silence first, then the message.
                    if (opts.stop) {
                        opts.stop(turn.turn);
                    }
                    if (opts.onBlock) {
                        opts.onBlock(plain(res.text), turn.turn);
                    }
                } else if (res.action === 'warn' && opts.onWarn) {
                    opts.onWarn(plain(res.text), turn.turn);
                }
            }).catch(function() {
                // A check that could not be made changes nothing for the session.
            });
        };

        var flush = function() {
            if (!pending) {
                return;
            }
            var turn = pending;
            pending = null;
            if (turn.timer) {
                clearTimeout(turn.timer);
            }
            send(turn);
        };

        return {
            /**
             * A piece of the provider's transcript.
             *
             * @param {string} role 'user' or 'assistant'
             * @param {string} text
             */
            text: function(role, text) {
                if (!text) {
                    return;
                }
                if (role !== 'user') {
                    answering = true;
                    answer += text;
                    return;
                }
                if (pending && !pending.hadQuestion) {
                    // The transcript the finished turn was waiting for.
                    pending.question += text;
                } else if (answering && question.trim()) {
                    nextQuestion += text;
                } else {
                    question += text;
                }
            },
            /**
             * The assistant's turn is over (finished, or cut off by the learner).
             *
             * @param {number} n Turn number within the session, from 1
             */
            turnEnd: function(n) {
                var turn = {turn: n, question: question, answer: answer, hadQuestion: !!question.trim(), timer: null};
                question = nextQuestion;
                nextQuestion = '';
                answer = '';
                answering = false;
                if (!turn.answer.trim()) {
                    // Nothing was said: the question stays for the turn that answers it.
                    question = turn.question + question;
                    return;
                }
                flush();
                if (turn.hadQuestion || waitMs <= 0) {
                    send(turn);
                    return;
                }
                pending = turn;
                turn.timer = setTimeout(function() {
                    turn.timer = null;
                    flush();
                }, waitMs);
            },
            /** Send a turn that is still waiting for its question (the session is ending). */
            flush: flush
        };
    };

    /**
     * Say a short message once, through the site's text-to-speech (tts.php), else the browser's own voice.
     * Resolves true when something was spoken. Never rejects.
     *
     * @param {string} text
     * @param {Object} o {ttsUrl, courseId, sessKey, voice, audioCtx}
     * @returns {Promise<boolean>}
     */
    var speak = function(text, o) {
        o = o || {};
        text = plain(text);
        var browserVoice = function() {
            try {
                var synth = typeof window !== 'undefined' ? window.speechSynthesis : null;
                if (!synth || typeof window.SpeechSynthesisUtterance !== 'function') {
                    return false;
                }
                synth.speak(new window.SpeechSynthesisUtterance(text));
                return true;
            } catch (e) {
                return false;
            }
        };
        if (!text) {
            return Promise.resolve(false);
        }
        var ctx = o.audioCtx;
        if (!o.ttsUrl || !ctx || ctx.state === 'closed' || typeof fetch !== 'function') {
            return Promise.resolve(browserVoice());
        }
        var body = new FormData();
        body.append('text', text);
        body.append('courseid', o.courseId || 0);
        body.append('sesskey', o.sessKey || '');
        if (o.voice) {
            body.append('voice', o.voice);
        }
        return fetch(o.ttsUrl, {method: 'POST', body: body}).then(function(r) {
            return r.json();
        }).then(function(data) {
            if (!data || data.error || !data.audio) {
                return browserVoice();
            }
            var binary = atob(data.audio);
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            return new Promise(function(resolve) {
                ctx.decodeAudioData(bytes.buffer, function(buf) {
                    try {
                        var src = ctx.createBufferSource();
                        src.buffer = buf;
                        src.connect(ctx.destination);
                        src.start();
                        resolve(true);
                    } catch (e) {
                        resolve(browserVoice());
                    }
                }, function() {
                    resolve(browserVoice());
                });
            });
        }).catch(function() {
            return browserVoice();
        });
    };

    return {
        create: create,
        speak: speak,
        plain: plain
    };
});
