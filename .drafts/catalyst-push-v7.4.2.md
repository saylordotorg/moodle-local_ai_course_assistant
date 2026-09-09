Hi Dean,

Could we deploy v7.4.2 tonight instead of v7.4.1?

Same window, same plan. I re-cut the v7.4.2 tag this evening to pick up three
fixes from today's staging testing — two small UI bugs in the chat widget, and
one ordering bug that was quietly able to delete the wrong record.

  Plugin:  local_ai_course_assistant
  Tag:     v7.4.2
  Build:   2026091002
  Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.4.2

The only difference from v7.4.1 in the upgrade path is one additive nullable
column, guarded so it's a no-op if it already exists. Everything else in my
earlier note still applies — please deploy by replacing the plugin directory
rather than copying over it.

Note the tag was moved rather than superseded, so if you already pulled v7.4.2
you'll need a `git fetch --tags --force` to get the current one.

If it's easier to stick with v7.4.1 tonight, that's completely fine.

Thanks,
Tom
