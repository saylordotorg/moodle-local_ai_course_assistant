Hi David,

Could I get S3 credentials for Soapbox on staging? I'm testing v7.4.1 and the
speech-assignment flow is the one piece I can't exercise yet.

WHAT'S BLOCKING

On staging (https://s-saylor-academy-moodle.catalyst-ca.net) the assignment page
shows "Recording storage is not set up yet. Please contact your administrator."
and hides the recorder entirely. The plugin gates on the access key and secret
both being non-empty, and on staging they're blank. The bucket, region and
prefix are already filled in:

  soapbox_storage_bucket  = archive-course
  soapbox_storage_region  = us-east-1
  soapbox_storage_prefix  = soapbox/
  soapbox_storage_key     = (empty)  <-- needed
  soapbox_storage_secret  = (empty)  <-- needed

WHAT I NEED

An access key ID and secret for a user or role that can read, write and delete
objects under `soapbox/` in that bucket. If you'd rather scope a new key just
for staging, the plugin only ever does three operations, all as presigned URLs:

  s3:PutObject      - the browser uploads the recording directly
  s3:GetObject      - playback of a stored recording
  s3:DeleteObject   - the nightly cleanup task removes expired audio

So a policy limited to arn:aws:s3:::archive-course/soapbox/* with those three
actions is sufficient. No bucket-level or list permissions are needed.

IF YOU'D RATHER SET IT UP YOURSELF

That's honestly easier for you than sending me keys, and I'd prefer it. Two
fields, both on:

  Site administration > Plugins > Local plugins > AI Course Assistant > Settings
  https://s-saylor-academy-moodle.catalyst-ca.net/admin/settings.php?section=local_ai_course_assistant_general

  Search the page for "Soapbox recording storage". Fill in:
    - Soapbox storage access key   (soapbox_storage_key)
    - Soapbox storage secret       (soapbox_storage_secret)

Both are password-masked fields, so nothing is echoed back into the page. Save,
then this page should show a Record button instead of the storage warning:

  https://s-saylor-academy-moodle.catalyst-ca.net/local/ai_course_assistant/soapbox_present.php?id=1

To be clear about what I would rather not do: I don't want key material pasted
into chat or email. A password manager share, or you entering it directly, is
better. If it has to be handed over, an AWS Secrets Manager reference or a
short-lived key I can rotate afterwards works too.

WHAT'S ALREADY DONE ON STAGING

I enabled Soapbox site-wide and created a test assignment on course 1319
(solatest), audio-only, 15-180 seconds, up to 8 recordings kept per student. I
also raised the site cap soapbox_max_recordings from 3 to 8, because the ESL
test compares feedback across four speaking levels and three kept recordings
wasn't enough. Both are easy to revert.

A couple of notes in case they matter to you:

- Audio retention on staging is 7 days (soapbox_retention_days), and the
  cleanup task deletes the object then, keeping only the score and metadata.
  Audio and transcripts are never stored in the database at all.
- I used course 1319 rather than 1320 on purpose. 1320 is the restored test
  course, and the AI call fails there with an HTTP 400 from the vendor
  (issue #219). Speech scoring makes an AI call too, so 1319 is the course
  where this can actually be tested.
- Transcription is on server mode with no self-hosted Whisper URL, so it falls
  back to hosted Whisper on the OpenAI key. That key works (the backend
  self-test passes its embedding round-trip), so I expect it to be fine, but
  transcription is untested until someone records real audio.

No rush if it's awkward - the rest of the release testing is done and the
practice page at soapbox.php works without storage, so only the assignment
flow is waiting on this.

Thanks,
Tom
