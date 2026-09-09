Hi Dean,

One small change to tonight's plan, if it's easy on your end: could we deploy
v7.4.3 instead of v7.4.1?

Two learner-facing UI fixes came out of today's staging testing, and I'd rather
they ride the window you've already set aside than chase them separately:

  - the in-widget help panel wasn't following the language switcher (it stayed
    in the page language while everything around it switched)
  - the Stop button was cutting off the bottom of the student's own question
    while a reply was streaming

Nothing else about the plan changes. Same window, same request to deploy by
REPLACING the plugin directory rather than copying over it, and the same four
files from v6.8.2 that need to disappear.

The only delta from v7.4.1 is one extra upgrade step, so 20 instead of 19: an
additive nullable column (reasoning_tokens) on the msgs table, guarded so it's
a no-op if it already exists. Nothing is rewritten, and a revert is still just
redeploying the previous tag plus a cache purge.

  Plugin:  local_ai_course_assistant
  Tag:     v7.4.3
  Build:   2026091003
  Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.4.3

And if it's too late to swap, or you'd rather not take something that hasn't sat
on staging first, going ahead with v7.4.1 tonight as planned is completely fine
by me. I'll put 7.4.3 through staging and pick it up in the next window.

Thanks again for fitting this in.

Tom
