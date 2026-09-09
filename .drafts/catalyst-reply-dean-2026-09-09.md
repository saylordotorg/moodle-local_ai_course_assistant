Hi Dean,

Thanks for checking in, and for the heads-up on tomorrow's 9:00 PM EDT window.

Good timing: we have a new release ready and I'd like to do one final test pass
on it today. If you're able to update staging to v7.4.0, I'll run that pass and
confirm back to you well before tomorrow evening.

  Release / tag:  https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.4.0
  Latest commit:  https://github.com/saylordotorg/moodle-local_ai_course_assistant/commit/c99f57a66e295085566e28be80153a3383bcf82e

One request on the deploy itself: please replace the plugin directory rather
than copying over the existing one (`rsync -a --delete` does the same thing).
Two files were removed in an earlier release for a security finding — an
in-plugin self-updater — and a merge-style copy would leave them in place.

And to be clear about the schedule: if there isn't time to fit this in, or if
you need to revert the staged changes so tonight's release goes out clean,
that's completely fine by us. I'd rather not add risk to your window. We can
pick this up in the next release cycle.

Thanks,
Tom
