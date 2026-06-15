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

// AI-generated translation. Professional review recommended.

/**
 * Language strings for local_ai_course_assistant — Norwegian Bokmål.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI-kursassistent';
$string['attachment:attach'] = 'Legg ved';
$string['attachment:attach_image_or_pdf'] = 'Legg ved bilde eller PDF';
$string['privacy:metadata:local_ai_course_assistant_convs'] = 'Lagrer AI-veiledersamtaler per bruker og kurs.';
$string['privacy:metadata:local_ai_course_assistant_convs:userid'] = 'ID-en til brukeren som eier samtalen.';
$string['privacy:metadata:local_ai_course_assistant_convs:courseid'] = 'ID-en til kurset samtalen tilhører.';
$string['privacy:metadata:local_ai_course_assistant_convs:title'] = 'Tittelen på samtalen.';
$string['privacy:metadata:local_ai_course_assistant_convs:timecreated'] = 'Tidspunktet da samtalen ble opprettet.';
$string['privacy:metadata:local_ai_course_assistant_convs:timemodified'] = 'Tidspunktet da samtalen sist ble endret.';
$string['privacy:metadata:local_ai_course_assistant_msgs'] = 'Lagrer individuelle meldinger i AI-veiledersamtaler.';
$string['privacy:metadata:local_ai_course_assistant_msgs:userid'] = 'ID-en til brukeren som sendte meldingen.';
$string['privacy:metadata:local_ai_course_assistant_msgs:courseid'] = 'ID-en til kurset meldingen tilhører.';
$string['privacy:metadata:local_ai_course_assistant_msgs:role'] = 'Rollen til meldingssenderen (bruker eller assistent).';
$string['privacy:metadata:local_ai_course_assistant_msgs:message'] = 'Innholdet i meldingen.';
$string['privacy:metadata:local_ai_course_assistant_msgs:tokens_used'] = 'Antall tokens brukt for meldingen.';
$string['privacy:metadata:local_ai_course_assistant_msgs:timecreated'] = 'Tidspunktet da meldingen ble opprettet.';
$string['ai_course_assistant:use'] = 'Bruke AI-veilederen';
$string['ai_course_assistant:viewanalytics'] = 'Vise AI-veilederanalyser';
$string['ai_course_assistant:manage'] = 'Administrere AI-veilederinnstillinger (administratorrolle)';
$string['settings:enabled'] = 'Aktiver AI-kursassistenten';
$string['settings:enabled_desc'] = 'Aktiver eller deaktiver AI-kursassistent-widgeten på kurssider.';
$string['settings:default_course_mode'] = 'Standard for nye kurs';
$string['settings:default_course_mode_desc'] = 'Styrer om SOLA vises på et kurs når det ikke er gjort et valg per kurs. Nye installasjoner er som standard satt til "Deaktivert som standard" slik at administratorer kan aktivere kurs for kurs fra Analytics-siden eller Course AI Settings-siden.';
$string['settings:default_course_mode_per_course'] = 'Deaktivert som standard (aktiver per kurs)';
$string['settings:default_course_mode_all'] = 'Aktivert på alle kurs';
$string['settings:auto_open'] = 'Åpne automatisk ved første besøk';
$string['settings:auto_open_desc'] = 'Når aktivert åpnes SOLA-skuffen automatisk første gang en student kommer til hvert kurs. Etterfølgende sideinnlastinger i samme kurs åpner ikke skuffen på nytt — tilstanden spores per kurs i studentens nettleser via localStorage. Gjelder på datamaskin og mobil. Kan overstyres per kurs fra Course AI Settings-siden.';
$string['settings:comparison_providers'] = 'Sammenligningsleverandører (LLM-velger)';
$string['settings:comparison_providers_desc'] = 'Legg til ekstra AI-leverandører i den innebygde LLM-velgeren slik at administratorer kan sammenligne svar på tvers av leverandører. Bruk tabellen nedenfor for å legge til rader. Temperaturkolonnen er valgfri (la stå tom for å bruke den globale temperaturen). Lagret format: provider_id|api_key|model1,model2|temperature. Den primære leverandøren konfigurert ovenfor er alltid inkludert automatisk. Kun administratorer med administrasjonstilgang ser velgeren; studenter ser den aldri. Gyldige provider IDs: openai, claude, deepseek, gemini, ollama, minimax, mistral, openrouter, xai, coreai, custom.';
$string['settings:provider'] = 'Standard AI-leverandør';
$string['settings:provider_desc'] = 'Velg AI-leverandøren som skal brukes til chat-fullføringer. Velg "Moodle AI (core_ai subsystem)" for å rute forespørsler gjennom Moodles innebygde AI-konfigurasjon på Site admin > AI; API-nøkkel, modell og basis-URL-feltene nedenfor ignoreres i den modusen. Streaming, verktøybruk og prompt caching er ikke tilgjengelig via core_ai — svar leveres som en enkelt del. Bruk en direkte leverandør for best studentopplevelse.';
$string['settings:provider_claude'] = 'Claude (Anthropic)';
$string['settings:provider_openai'] = 'OpenAI';
$string['settings:provider_deepseek'] = 'DeepSeek';
$string['settings:provider_ollama'] = 'Ollama (lokal)';
$string['settings:provider_minimax'] = 'MiniMax';
$string['settings:provider_custom'] = 'Egendefinert (OpenAI-kompatibel)';
$string['settings:apikey'] = 'API-nøkkel';
$string['settings:apikey_desc'] = 'API-nøkkel for den valgte leverandøren. Ikke nødvendig for Ollama.';
$string['settings:model'] = 'Modellnavn';
$string['settings:model_desc'] = 'Modellen som skal brukes. Standard avhenger av leverandøren (f.eks. claude-sonnet-4-5-20250929, gpt-4o, llama3, MiniMax-Text-01).';
$string['settings:apibaseurl'] = 'API-basis-URL';
$string['settings:apibaseurl_desc'] = 'API-basis-URL. Fylles ut automatisk basert på leverandør, men kan overstyres. La stå tom for å bruke leverandørens standard.';
$string['settings:systemprompt'] = 'Systemprompt-mal';
$string['settings:systemprompt_desc'] = 'Systemprompten som sendes til AI-en. Bruk plassholderne {{coursename}}, {{userrole}}, {{coursetopics}}.';
$string['settings:systemprompt_default'] = 'Du er en hjelpsom AI-veileder for kurset «{{coursename}}». Studentens rolle er {{userrole}}.

Emner dekket i kurset:
{{coursetopics}}

Hjelp studenten med å forstå kursinnholdet. Vær oppmuntrende, tydelig og pedagogisk grundig.';
$string['settings:temperature'] = 'Temperatur';
$string['settings:temperature_desc'] = 'Kontrollerer tilfeldighet. Lave verdier er mer fokuserte, høye verdier er mer kreative. Område: 0,0 til 2,0.';
$string['settings:maxhistory'] = 'Maksimal samtalehistorikk';
$string['settings:maxhistory_desc'] = 'Maksimalt antall meldingspar som inkluderes i API-forespørsler. Eldre meldinger fjernes.';
$string['settings:avatar'] = 'Chat-avatar';
$string['settings:avatar_desc'] = 'Velg avatarikonet for chat-widget-knappen.';
$string['settings:avatar_saylor'] = '{$a}-logo (standard)';
$string['settings:position'] = 'Widget-posisjon';
$string['settings:position_desc'] = 'Posisjonen til chat-widgeten på siden.';
$string['settings:position_br'] = 'Nederst til høyre';
$string['settings:position_bl'] = 'Nederst til venstre';
$string['settings:position_tr'] = 'Øverst til høyre';
$string['settings:position_tl'] = 'Øverst til venstre';
$string['chat:settings'] = 'Plugininnstillinger';
$string['analytics:viewdashboard'] = 'Vis analysedashboard';
$string['coursesettings:title'] = 'Kurs-AI-innstillinger';
$string['coursesettings:enabled'] = 'Aktiver kursoverstyringer';
$string['coursesettings:enabled_desc'] = 'Når aktivert, overstyrer innstillingene nedenfor den globale AI-leverandørkonfigurasjonen kun for dette kurset. La felt stå tomme for å arve globale verdier.';
$string['coursesettings:sola_enabled'] = 'SOLA på dette kurset';
$string['coursesettings:sola_enabled_toggle'] = 'Vis SOLA-widgeten på dette kurset';
$string['coursesettings:sola_enabled_desc'] = 'Styrer om SOLA-chattewidgeten vises på dette kurset. Standardinnstillingen for hele nettstedet angis i tilleggsinnstillingene under General > Default for new courses.';
$string['coursesettings:using_global'] = 'Bruker global innstilling';
$string['coursesettings:saved'] = 'Kurs-AI-innstillinger lagret.';
$string['coursesettings:global_settings_link'] = 'Globale AI-innstillinger';
$string['lang:switch'] = 'Ja, bytt';
$string['lang:dismiss'] = 'Nei takk';
$string['lang:change'] = 'Bytt språk';
$string['lang:english'] = 'Engelsk';
$string['chat:title'] = 'AI-veileder';
$string['chat:placeholder'] = 'Still et spørsmål...';
$string['chat:send'] = 'Send';
$string['chat:close'] = 'Lukk chat';
$string['chat:open'] = 'Åpne AI-veilederen';
$string['chat:clear'] = 'Tøm skjermen';
$string['chat:clear_confirm'] = 'Tømme de synlige meldingene? Hele chathistorikken din forblir lagret og kan lastes inn på nytt ved å åpne widgeten igjen.';
$string['chat:copy'] = 'Kopier samtale';
$string['chat:copied'] = 'Samtalen er kopiert til utklippstavlen';
$string['chat:copy_failed'] = 'Kunne ikke kopiere samtalen';
$string['chat:thinking'] = 'Tenker...';
$string['chat:error'] = 'Beklager, det oppstod en feil. Vennligst prøv igjen.';
$string['chat:error_auth'] = 'Autentiseringsfeil. Vennligst kontakt administratoren din.';
$string['chat:error_ratelimit'] = 'For mange forespørsler. Vennligst vent litt og prøv igjen.';
$string['chat:error_unavailable'] = 'AI-tjenesten er midlertidig utilgjengelig. Vennligst prøv igjen senere.';
$string['chat:error_notconfigured'] = 'AI-veilederen er ikke konfigurert ennå. Vennligst kontakt administratoren din.';
$string['chat:mic'] = 'Still spørsmålet ditt med stemmen';
$string['chat:mic_error'] = 'Mikrofonfeil. Vennligst sjekk nettleserens tillatelser.';
$string['chat:mic_unsupported'] = 'Taleinndata støttes ikke i denne nettleseren.';
$string['chat:newline_hint'] = 'Shift+Enter for nytt avsnitt';
$string['chat:you'] = 'Du';
$string['chat:assistant'] = 'AI-veileder';
$string['chat:history_loaded'] = 'Tidligere samtale lastet.';
$string['chat:history_cleared'] = 'Chathistorikk slettet.';
$string['chat:offtopic_warning'] = 'Det ser ut som spørsmålet ditt ikke er relatert til dette kurset. Prøv å holde deg til emnet slik at jeg kan hjelpe deg best mulig!';
$string['chat:offtopic_ended'] = 'Tilgangen din til AI-veilederen er midlertidig suspendert i {$a} minutter fordi samtalen har gått for langt utenfor emnet. Bruk denne tiden til å gå gjennom kursmaterialet, og du kan prøve igjen senere.';
$string['chat:offtopic_locked'] = 'Tilgangen din til AI-veilederen er midlertidig suspendert. Du kan prøve igjen om {$a} minutter. Fokuser på kursrelaterte spørsmål når du kommer tilbake.';
$string['chat:escalated_to_support'] = 'Jeg klarte ikke å svare fullstendig på spørsmålet ditt, så jeg har opprettet en støttesak for deg. Et medlem av støtteteamet vil kontakte deg. Saksnummeret ditt er: {$a}';
$string['chat:studyplan_intro'] = 'Jeg kan hjelpe deg med å lage en studieplan for dette kurset! Bare fortell meg hvor mange timer i uken du kan bruke på studier, så hjelper jeg deg med å bygge en strukturert plan.';
$string['settings:faq_heading'] = 'FAQ og støtte';
$string['settings:faq_heading_desc'] = 'Konfigurer sentralisert FAQ og Zendesk-støttebillettintegrasjon.';
$string['settings:faq_content'] = 'FAQ-innhold';
$string['settings:faq_content_desc'] = 'Skriv inn FAQ-oppføringer (én per linje i formatet: Q: spørsmål | A: svar). Disse gis til AI-en for å besvare vanlige støttespørsmål.';
$string['settings:zendesk_enabled'] = 'Aktiver Zendesk-eskalering';
$string['settings:zendesk_enabled_desc'] = 'Når AI-en ikke kan løse et støttespørsmål, opprettes det automatisk en Zendesk-sak med en samtaleoppsummering.';
$string['settings:zendesk_subdomain'] = 'Zendesk-underdomene';
$string['settings:zendesk_subdomain_desc'] = 'Ditt Zendesk-underdomene (f.eks. «mittfirma» for mittfirma.zendesk.com).';
$string['settings:zendesk_email'] = 'Zendesk API-e-post';
$string['settings:zendesk_email_desc'] = 'Zendesk-brukerens e-postadresse for API-autentisering (med /token-suffiks).';
$string['settings:zendesk_token'] = 'Zendesk API-token';
$string['settings:zendesk_token_desc'] = 'API-token for Zendesk-autentisering.';
$string['settings:offtopic_heading'] = 'Utenfor-emne-deteksjon';
$string['settings:offtopic_heading_desc'] = 'Konfigurer hvordan chatten håndterer samtaler utenfor emnet.';
$string['settings:offtopic_enabled'] = 'Aktiver utenfor-emne-deteksjon';
$string['settings:offtopic_enabled_desc'] = 'La AI-en oppdage og omdirigere samtaler utenfor emnet.';
$string['settings:offtopic_max'] = 'Maksimalt antall meldinger utenfor emnet';
$string['settings:offtopic_max_desc'] = 'Antall påfølgende meldinger utenfor emnet før tiltak iverksettes.';
$string['settings:offtopic_action'] = 'Tiltak ved utenfor-emne';
$string['settings:offtopic_action_desc'] = 'Hva som skal gjøres når grensen for utenfor-emne er nådd.';
$string['settings:offtopic_action_warn'] = 'Advar og omdiriger';
$string['settings:offtopic_action_end'] = 'Midlertidig sperr tilgang';
$string['settings:offtopic_lockout_duration'] = 'Sperrevarighet (minutter)';
$string['settings:offtopic_lockout_duration_desc'] = 'Varighet (i minutter) en student mister tilgang til AI-veilederen etter å ha overskredet grensen for utenfor-emne. Standard: 30 minutter.';
$string['settings:studyplan_heading'] = 'Studieplanlegging og påminnelser';
$string['settings:studyplan_heading_desc'] = 'Konfigurer studieplanfunksjoner og påminnelsesvarsler.';
$string['settings:studyplan_enabled'] = 'Aktiver studieplanlegging';
$string['settings:studyplan_enabled_desc'] = 'La AI-veilederen hjelpe studenter med å lage personlige studieplaner basert på tilgjengelig tid.';
$string['settings:reminders_email_enabled'] = 'Aktiver e-postpåminnelser';
$string['settings:reminders_email_enabled_desc'] = 'La studenter melde seg på studiepåminnelser via e-post.';
$string['settings:reminders_whatsapp_enabled'] = 'Aktiver WhatsApp-påminnelser';
$string['settings:reminders_whatsapp_enabled_desc'] = 'La studenter melde seg på studiepåminnelser via WhatsApp (krever WhatsApp API-konfigurasjon).';
$string['settings:whatsapp_api_url'] = 'WhatsApp API-URL';
$string['settings:whatsapp_api_url_desc'] = 'API-endepunkt for sending av WhatsApp-meldinger (f.eks. Twilio, MessageBird). Må akseptere POST med JSON-body som inneholder feltene «to», «from» og «body».';
$string['settings:whatsapp_api_token'] = 'WhatsApp API-token';
$string['settings:whatsapp_api_token_desc'] = 'Autentiseringstoken for WhatsApp API.';
$string['settings:whatsapp_from_number'] = 'WhatsApp-avsendernummer';
$string['settings:whatsapp_from_number_desc'] = 'Telefonnummeret WhatsApp-meldinger sendes fra (med landskode, f.eks. +14155238886).';
$string['settings:whatsapp_blocked_countries'] = 'WhatsApp-blokkerte land';
$string['settings:whatsapp_blocked_countries_desc'] = 'Kommaseparerte ISO 3166-1 alpha-2 landkoder der WhatsApp-påminnelser ikke er tillatt på grunn av lokale forskrifter (f.eks. «CN,IR,KP»).';
$string['reminder:email_subject'] = 'Studiepåminnelse: {$a}';
$string['reminder:email_body'] = 'Hei {$a->firstname},

Her er din studiepåminnelse for «{$a->coursename}».

{$a->message}

Studieplanen din foreslår {$a->hours_per_week} timer per uke for dette kurset.

Fortsett det gode arbeidet!

---
For å slutte å motta disse påminnelsene, klikk her: {$a->unsubscribe_url}';
$string['reminder:email_body_no_hours'] = 'Hi {$a->firstname},

This is your study reminder for "{$a->coursename}".

{$a->message}

Keep up the great work!

---
To stop receiving these reminders, click here: {$a->unsubscribe_url}';
$string['reminder:email_body_with_prefs'] = 'Hi {$a->firstname},

This is your study reminder for "{$a->coursename}".

{$a->message}

Your study plan: {$a->hours_per_week} hours per week, on {$a->preferred_days} ({$a->preferred_time}).

Keep up the great work!

---
To stop receiving these reminders, click here: {$a->unsubscribe_url}';
$string['studytip:pomodoro']            = 'Try the Pomodoro technique: 25 minutes of focused study, then a 5-minute break.';
$string['studytip:review_notes']        = 'Review your notes from the last session before starting new material.';
$string['studytip:active_recall']       = 'Test yourself on what you learned recently — active recall strengthens memory.';
$string['studytip:summarise']           = 'Take a few minutes to summarise what you have learned in your own words.';
$string['studytip:mix_modes']           = 'Mix different types of study: reading, practice problems, and teaching concepts to others.';
$string['studytip:tackle_hard_first']   = 'Start with the most challenging topic while your energy is highest.';
$string['studytip:connect_concepts']    = 'Create connections between new concepts and what you already know.';
$string['studytip:short_breaks']        = 'Take short breaks to stay focused — a refreshed mind learns better.';
$string['studyplan:hours_out_of_range'] = 'Hours per week must be between {$a->min} and {$a->max}. Got {$a->got}. Please tell SOLA a different number and it will save your plan.';
$string['reminder:whatsapp_body'] = 'Studiepåminnelse for {$a->coursename}: {$a->message} (Avslutt abonnement: {$a->unsubscribe_url})';
$string['reminder:study_tip_prefix'] = 'Dagens studiemål: ';
$string['unsubscribe:title'] = 'Avslutt abonnement på studiepåminnelser';
$string['unsubscribe:success'] = 'Du har avsluttet abonnementet på studiepåminnelser for dette kurset.';
$string['unsubscribe:already'] = 'Du har allerede avsluttet abonnementet på disse påminnelsene.';
$string['unsubscribe:invalid'] = 'Ugyldig eller utløpt avmeldingslenke.';
$string['unsubscribe:resubscribe'] = 'Ombestemt deg? Du kan aktivere påminnelser igjen via AI-veilederen.';
$string['task:send_reminders'] = 'Send AI-veileder studiepåminnelser';
$string['privacy:metadata:local_ai_course_assistant_plans'] = 'Lagrer studentenes studieplaner.';
$string['privacy:metadata:local_ai_course_assistant_plans:userid'] = 'ID-en til brukeren som eier studieplanen.';
$string['privacy:metadata:local_ai_course_assistant_plans:courseid'] = 'Kurset studieplanen tilhører.';
$string['privacy:metadata:local_ai_course_assistant_plans:hours_per_week'] = 'Timer per uke studenten planlegger å studere.';
$string['privacy:metadata:local_ai_course_assistant_plans:plan_data'] = 'Studieplandetaljer i JSON-format.';
$string['privacy:metadata:local_ai_course_assistant_reminders'] = 'Lagrer studiepåminnelsesinnstillinger og abonnementer.';
$string['privacy:metadata:local_ai_course_assistant_reminders:userid'] = 'ID-en til brukeren som abonnerer på påminnelser.';
$string['privacy:metadata:local_ai_course_assistant_reminders:channel'] = 'Påminnelseskanalen (e-post eller WhatsApp).';
$string['privacy:metadata:local_ai_course_assistant_reminders:destination'] = 'E-postadressen eller telefonnummeret for påminnelser.';
$string['privacy:metadata:local_ai_course_assistant_reminders:country_code'] = 'Brukerens landskode for regulatorisk overholdelse.';
$string['analytics:title'] = 'AI-veilederanalyser';
$string['analytics:overview'] = 'Oversikt';
$string['analytics:total_conversations'] = 'Totalt antall samtaler';
$string['analytics:total_messages'] = 'Totalt antall meldinger';
$string['analytics:active_students'] = 'Aktive studenter';
$string['analytics:avg_messages_per_student'] = 'Gj.sn. meldinger per student';
$string['analytics:offtopic_rate'] = 'Utenfor-emne-rate';
$string['analytics:escalation_count'] = 'Eskalert til støtte';
$string['analytics:studyplan_adoption'] = 'Studenter med studieplaner';
$string['analytics:usage_trends'] = 'Brukstrender';
$string['analytics:daily_messages'] = 'Daglig meldingsvolum';
$string['analytics:hotspots'] = 'Kurshotspots';
$string['analytics:hotspots_desc'] = 'Kursseksjoner som oftest refereres i studentspørsmål. Høye tall kan indikere områder der studenter trenger mer støtte.';
$string['analytics:section'] = 'Seksjon';
$string['analytics:mention_count'] = 'Omtaler';
$string['analytics:common_prompts'] = 'Vanlige spørsmålsmønstre';
$string['analytics:common_prompts_desc'] = 'Gjentakende spørsmålsmønstre fra studenter. Gjennomgå disse for å identifisere systematiske hull i kursinnholdet.';
$string['analytics:prompt_pattern'] = 'Mønster';
$string['analytics:frequency'] = 'Frekvens';
$string['analytics:recent_activity'] = 'Nylig aktivitet';
$string['analytics:no_data'] = 'Ingen analysedata tilgjengelig ennå. Data vises når studenter begynner å bruke AI-veilederen.';
$string['analytics:timerange'] = 'Tidsperiode';
$string['analytics:last_7_days'] = 'Siste 7 dager';
$string['analytics:last_30_days'] = 'Siste 30 dager';
$string['analytics:all_time'] = 'Hele perioden';
$string['analytics:export'] = 'Eksporter data';
$string['analytics:provider_comparison'] = 'AI-leverandørsammenligning';
$string['analytics:provider_comparison_desc'] = 'Sammenlign ytelsen til AI-leverandører brukt i dette kurset.';
$string['analytics:provider'] = 'Leverandør';
$string['analytics:response_count'] = 'Svar';
$string['analytics:avg_response_length'] = 'Gj.sn. svarlengde';
$string['analytics:total_tokens'] = 'Totalt antall tokens';
$string['analytics:avg_tokens'] = 'Gj.sn. tokens / svar';
$string['usersettings:title'] = 'AI-kursassistent: Dine data';
$string['usersettings:intro'] = 'Administrer dine AI-veileder chatdata og personverninnstillinger';
$string['usersettings:privacy_info'] = 'Samtalene dine med AI-veilederen lagres for å gi deg kontinuerlig støtte gjennom kurset. Du har full kontroll over disse dataene og kan slette dem når som helst.';
$string['usersettings:usage_stats'] = 'Din bruksstatistikk';
$string['usersettings:total_messages'] = 'Totalt antall meldinger';
$string['usersettings:total_conversations'] = 'Samtaler';
$string['usersettings:messages'] = 'Meldinger';
$string['usersettings:last_activity'] = 'Siste aktivitet';
$string['usersettings:delete_course_data'] = 'Slett kursdata';
$string['usersettings:no_data'] = 'Du har ikke brukt AI-veilederen ennå. Bruksdataene dine vises her når du begynner å chatte.';
$string['usersettings:delete_all_title'] = 'Slett alle dine data';
$string['usersettings:delete_all_warning'] = 'Denne handlingen vil permanent slette alle dine samtaler med AI-veilederen i alle kurs. Denne handlingen kan ikke angres.';
$string['usersettings:delete_all_button'] = 'Slett alle mine data';
$string['usersettings:confirm_delete_course'] = 'Er du sikker på at du vil slette alle AI-veilederdata for kurset «{$a}» permanent? Denne handlingen kan ikke angres.';
$string['usersettings:confirm_delete_all'] = 'Er du sikker på at du vil slette ALLE AI-veilederdata i alle kurs permanent? Denne handlingen kan ikke angres.';
$string['usersettings:data_deleted'] = 'Dataene dine er slettet.';

// === SOLA v1.0.12 — updated/new strings ===

// Updated strings (override earlier values):
$string['chat:greeting'] = 'Hei, {$a}! Jeg er SOLA. Hvordan kan jeg hjelpe deg i dag?';
$string['chat:title'] = 'SOLA';
$string['chat:assistant'] = 'SOLA';
$string['chat:open'] = 'Åpne SOLA';
$string['chat:change_avatar'] = 'Bytt avatar';

// Quiz UI.
$string['chat:quiz'] = 'Ta en øvingsquiz';
$string['chat:quiz_setup_title'] = 'Øvingsquiz';
$string['chat:quiz_questions'] = 'Antall spørsmål';
$string['chat:quiz_topic'] = 'Emne';
$string['chat:quiz_topic_guided'] = 'AI-veiledet (basert på din fremgang)';
$string['chat:quiz_topic_adaptive']      = 'Adaptiv — fokuser på mine svake punkter';
$string['chat:quiz_topic_default'] = 'Gjeldende kursinnhold';
$string['chat:quiz_topic_custom'] = 'Egendefinert emne…';
$string['chat:quiz_custom_placeholder'] = 'Skriv inn et emne eller spørsmål...';
$string['chat:quiz_start'] = 'Start quiz';
$string['chat:quiz_cancel'] = 'Avbryt';
$string['chat:quiz_loading'] = 'Genererer quiz…';
$string['chat:quiz_error'] = 'Kunne ikke generere quiz. Vennligst prøv igjen.';
$string['chat:quiz_correct'] = 'Riktig!';
$string['chat:quiz_wrong'] = 'Feil.';
$string['chat:quiz_next'] = 'Neste spørsmål';
$string['chat:quiz_finish'] = 'Se resultater';
$string['chat:quiz_score'] = 'Quiz fullført! Du fikk {$a->score} av {$a->total}.';
$string['chat:quiz_summary'] = 'Jeg fullførte en øvingsquiz med {$a->total} spørsmål om «{$a->topic}» og fikk {$a->score}/{$a->total}.';
$string['chat:quiz_topic_objectives'] = 'Læringsmål';
$string['chat:quiz_topic_modules'] = 'Kursemne';
$string['chat:quiz_subtopic_select'] = 'Velg et spesifikt element…';
$string['chat:quiz_topic_sections'] = 'Kursseksjoner';
$string['chat:quiz_score_great'] = 'Utmerket arbeid! Du behersker virkelig dette stoffet.';
$string['chat:quiz_score_good'] = 'Godt jobbet! Fortsett å repetere for å styrke forståelsen din.';
$string['chat:quiz_score_practice'] = 'Fortsett å øve. Prøv å gå gjennom det relevante kursinnholdet og ta quizen på nytt.';
$string['chat:quiz_review_heading'] = 'Gjennomgang';
$string['chat:quiz_retake'] = 'Ta quizen på nytt';
$string['chat:quiz_exit'] = 'Avslutt quiz';
$string['chat:quiz_your_answer'] = 'Ditt svar';
$string['chat:quiz_correct_answer'] = 'Riktig svar';

// Conversation starters.
$string['chat:starters_label'] = 'Samtalestartere';
$string['chat:starter_quiz'] = 'Test meg på dette';
$string['chat:starter_explain'] = 'Forklar dette';
$string['chat:starter_key_concepts'] = 'Nøkkelkonsepter';
$string['chat:starter_study_plan'] = 'Studieplan';
$string['chat:starter_help_me'] = 'AI-hjelp';
$string['chat:starter_ai_project_coach'] = 'AI-prosjektveileder';
$string['chat:starter_ell_practice'] = 'Samtaleøvelse';
$string['chat:starter_ell_pronunciation'] = 'ELL-uttale';
$string['chat:starter_ai_coach'] = 'AI-coach';
$string['chat:starter_speak'] = 'Si en starter';

// Reset / home.
$string['chat:reset'] = 'Start på nytt';

// Topic picker.
$string['chat:topic_picker_title'] = 'Hva vil du fokusere på?';
$string['chat:topic_picker_title_help'] = 'Hva trenger du hjelp med?';
$string['chat:topic_picker_title_explain'] = 'Hva vil du at jeg skal forklare?';
$string['chat:topic_picker_title_study'] = 'Hvilket område vil du fokusere på?';
$string['chat:topic_start'] = 'Fortsett';

// Expand states.
$string['chat:fullscreen'] = 'Fullskjerm';
$string['chat:exitfullscreen'] = 'Avslutt fullskjerm';

// Settings panel.
$string['chat:language'] = 'Bytt språk';
$string['chat:settings_panel'] = 'Innstillinger';
$string['chat:settings_language'] = 'Språk';
$string['chat:settings_avatar'] = 'Avatar';
$string['chat:settings_voice'] = 'Stemme';
$string['chat:settings_voice_admin'] = 'Stemmeinnstillinger administreres i nettstedsadminpanelet.';

// Voice mode.
$string['chat:voice_mode'] = 'Stemmemodus';
$string['chat:voice_end'] = 'Avslutt stemmeøkt';
$string['chat:voice_connecting'] = 'Kobler til...';
$string['chat:voice_listening'] = 'Lytter...';
$string['chat:voice_speaking'] = 'SOLA snakker...';
$string['chat:voice_idle'] = 'Klar';
$string['chat:voice_error'] = 'Stemmetilkobling mislyktes. Vennligst sjekk innstillingene dine.';
$string['chat:quiz_locked'] = 'SOLA er satt på pause under quizer for å bevare akademisk integritet. Lykke til!';

// Bottom nav.
$string['chat:mode_nav'] = 'Mode navigation';
$string['chat:mode_chat'] = 'Chat';
$string['chat:mode_voice'] = 'Voice';
$string['chat:mode_history'] = 'Notater';

// History panel.
$string['chat:history_title'] = 'Notater og samtalehistorikk';
$string['task:send_inactivity_reminders'] = 'Send ukentlige inaktivitetspåminnelser via e-post';
$string['task:run_meta_ai_query'] = 'Kjør planlagt Læringsradar-analyseforespørsel';
$string['messageprovider:study_notes'] = 'Studieøktnotater';

// CDN settings.
$string['settings:cdn_heading'] = 'CDN / Frontend-levering';
$string['settings:cdn_heading_desc'] = 'Server SOLA frontend-ressurser (JS/CSS) fra en ekstern CDN i stedet for Moodles filsystem. Dette muliggjør frontend-oppdateringer uten plugin-oppgradering. La CDN URL stå tom for å bruke lokale plugin-filer.';
$string['settings:cdn_url'] = 'CDN-basis-URL';
$string['settings:cdn_url_desc'] = 'Basis-URL der sola.min.js og sola.min.css er vert. Eksempel: https://your-org.github.io/sola-cdn. La feltet stå tomt for å bruke lokale plugin-filer.';
$string['settings:cdn_version'] = 'CDN-ressursversjon';
$string['settings:cdn_version_desc'] = 'Versjonsstreng som legges til CDN-URLer for cache busting. Oppdater etter hver CDN-utrulling (f.eks. 3.2.4 eller et commit hash).';

// Analytics dashboard.
$string['analytics:tab_overall'] = 'Samlet bruk';
$string['analytics:tab_bycourse'] = 'Per kurs';
$string['analytics:tab_comparison'] = 'AI vs ikke-brukere';
$string['analytics:tab_byunit'] = 'Per enhet';
$string['analytics:tab_usagetypes'] = 'Brukstyper';
$string['analytics:tab_themes'] = 'Temaer';
$string['analytics:tab_feedback'] = 'AI-tilbakemelding';
$string['analytics:total_students'] = 'Totalt antall studenter';
$string['analytics:active_users'] = 'Aktive AI-brukere';
$string['analytics:msgs_per_student'] = 'Meldinger per student';
$string['analytics:avg_session'] = 'Gjennomsnittlig øktvarighet';
$string['analytics:return_rate'] = 'Returrate';
$string['analytics:total_sessions'] = 'Totalt antall økter';
$string['analytics:thumbs_up'] = 'Tommel opp';
$string['analytics:thumbs_down'] = 'Tommel ned';
$string['analytics:hallucination_flags'] = 'Markeringer for unøyaktighet';
$string['analytics:msgs_to_resolution'] = 'Meldinger til løsning';
$string['analytics:helpful'] = 'Nyttig';
$string['analytics:not_helpful'] = 'Ikke nyttig';
$string['analytics:flag_hallucination'] = 'Dette svaret inneholder unøyaktig informasjon';
$string['analytics:submit_rating'] = 'Send inn';
$string['analytics:thanks_feedback'] = 'Takk for tilbakemeldingen';

// LLM provider names.
$string['settings:provider_mistral'] = 'Mistral AI';
$string['settings:provider_openrouter'] = 'OpenRouter';
$string['settings:provider_together'] = 'Together AI (Llama 3.1 8B/70B/405B Turbo, Mistral, Qwen)';
$string['settings:provider_xai'] = 'xAI (Grok)';

$string['settings:provider_coreai'] = 'Moodle AI (core_ai subsystem)';
// Strings added by update_langs.py.
$string['chat:starter_help_page'] = 'Forklar denne siden';
$string['chat:starter_ask_anything'] = 'Spør om hva som helst';
$string['chat:starter_review_practice'] = 'Gjennomgå og øv';
$string['chat:history_saved_subtitle'] = 'Lagrede svar blir værende på denne enheten for dette kurset.';
$string['chat:history_saved_empty'] = 'Lagre et AI-svar for å se det her.';
$string['chat:history_views_label'] = 'Historikkvisninger';
$string['chat:history_view_saved'] = 'Lagrede';
$string['chat:history_view_recent'] = 'Historikk';
$string['chat:debug_refresh'] = 'Oppdater';
$string['chat:debug_copy_all'] = 'Kopier alt';
$string['chat:debug_close'] = 'Lukk';
$string['chat:language_switch'] = 'Bytt språk';
$string['chat:language_dismiss'] = 'Avvis språkforslag';
$string['chat:llm_label'] = 'LLM';
$string['chat:llm_provider_select'] = 'Velg LLM-leverandør';
$string['chat:llm_model_label'] = 'Modell';
$string['chat:llm_model_select'] = 'Velg LLM-modell';
$string['chat:footer_usertesting'] = 'Brukervennlighetstesting';
$string['chat:footer_feedback'] = 'Tilbakemelding';
$string['chat:voice_panel_title']       = 'Talk with {$a}';

// Additional translated strings.
$string['chat:debug_context'] = 'Kontekst feilsøking';
$string['chat:debug_context_browser'] = 'Nettleser-snapshot';
$string['chat:debug_context_copy'] = 'Kopier';
$string['chat:debug_context_prompt'] = 'Serversvar';
$string['chat:debug_context_request'] = 'Siste SSE-forespørsel';
$string['chat:debug_context_toggle'] = 'Veksle inspektør';
$string['chat:history_empty'] = 'Ingen samtaler ennå.';
$string['chat:history_refresh'] = 'Oppdater';
$string['chat:history_subtitle'] = 'Dine nylige meldinger.';
$string['chat:starter_explain_prompt'] = 'Forklar det viktigste konseptet?';
$string['chat:starter_help_lesson'] = 'Forklar dette';
$string['chat:starter_help_lesson_prompt'] = 'Hjelp meg forstå leksjonen. Oppsummer nøkkelbegrepene.';
$string['chat:starter_prompt_coach'] = 'AI prompt-trener';
$string['chat:starter_quick_study'] = 'Hurtigstudium';
$string['chat:starter_study_plan_prompt'] = 'Jeg vil planlegge studier. Spør: (1) mål, (2) tid. Oppdater planen.';
$string['chat:voice_copy'] = 'Samtale med læringsassistenten.';
$string['chat:voice_ready'] = 'Klar';
$string['chat:voice_start'] = 'Start';
$string['chat:voice_title'] = 'Snakk med SOLA';
$string['coursesettings:ell_pronunciation'] = 'Pronunciation Practice Mode';
$string['coursesettings:ell_pronunciation_desc'] = 'Show the "Pronunciation Practice" chip for students in this course. Uses OpenAI Realtime API for phoneme-level pronunciation feedback. Requires Voice Mode to be enabled in global plugin settings.';
$string['coursesettings:ell_pronunciation_enable'] = 'Enable Pronunciation Practice chip for this course';
$string['coursesettings:rag'] = 'Semantic Search (RAG)';
$string['coursesettings:rag_desc'] = 'Enable retrieval-augmented generation for this course. When enabled, SOLA embeds and searches course content to ground its answers. Requires RAG to be enabled globally in plugin settings.';
$string['coursesettings:rag_enable'] = 'Enable RAG for this course';
$string['coursesettings:speaking_practice'] = 'Speaking Practice';
$string['coursesettings:speaking_practice_desc'] = 'Show the "Practice Speaking" chip for students in this course. Uses OpenAI TTS for voice responses. Requires an OpenAI API key in global plugin settings.';
$string['coursesettings:speaking_practice_enable'] = 'Enable Speaking Practice chip for this course';
$string['coursesettings:token_usage'] = 'Token Usage & Cost';
$string['coursesettings:token_usage_desc'] = 'View token usage, cost estimates, and per-student breakdowns for this course.';

// v5.2.0: per-quiz SOLA assistance level controls.
$string['quizsettings:title'] = 'Quiz Assistance Levels';
$string['quizsettings:desc'] = 'Choose how much help SOLA gives on each quiz. "Default" uses the legacy rule: ungraded quizzes get full help, graded quizzes hide the widget. Use "Coach" to keep SOLA available on a graded quiz but block direct answers.';
$string['quizsettings:colquiz'] = 'Quiz';
$string['quizsettings:colgrade'] = 'Max grade';
$string['quizsettings:collevel'] = 'Assistance level';
$string['quizsettings:coleffective'] = 'Effective';
$string['quizsettings:level_default'] = 'Default (by grade)';
$string['quizsettings:level_full'] = 'Full help';
$string['quizsettings:level_coach'] = 'Coach (Socratic only)';
$string['quizsettings:level_hidden'] = 'Hidden';
$string['quizsettings:ungraded'] = 'Ungraded';
$string['error'] = '{$a}';
$string['error_no_tts_key'] = 'No OpenAI API key configured for TTS.';
$string['error_rate_limit_ip'] = 'Too many requests from your IP address. Please wait a moment.';
$string['error_rate_limit_user'] = 'Too many requests. Please wait a moment.';
$string['error_reminders_email_disabled'] = 'Email reminders are not enabled.';
$string['error_reminders_whatsapp_country_blocked'] = 'WhatsApp reminders are not available in your country.';
$string['error_reminders_whatsapp_disabled'] = 'WhatsApp reminders are not enabled.';
$string['insights:desc'] = 'Analyze feedback, survey, and usability testing data to surface issues, feature requests, and recommendations.';
$string['insights:error'] = 'Could not generate insights.';
$string['insights:generate'] = 'Generate AI Insights';
$string['insights:generating'] = 'Analyzing data… this may take a moment.';
$string['insights:no_data'] = 'No feedback, survey, or usability testing data available to analyze yet. Insights will be available once users submit feedback or complete surveys.';
$string['insights:title'] = 'AI Insights';
$string['integrity:desc'] = 'Automated daily health checks that verify PHP syntax, JS builds, lang files, database tables, and more. Email alerts are sent only when issues are found.';
$string['integrity:email'] = 'Report Email Address(es)';
$string['integrity:email_desc'] = 'Email address(es) for failure reports. Separate multiple addresses with commas. Leave blank to notify the primary site admin.';
$string['integrity:enabled'] = 'Enable Daily Integrity Checks';
$string['integrity:enabled_desc'] = 'Run automated plugin health checks daily at 3 AM server time.';
$string['integrity:run_now'] = 'Run Checks Now';
$string['integrity:title'] = 'Integrity Checks';
$string['integrity:view_results'] = 'View Integrity Results';
$string['messageprovider:integrity_report'] = 'SOLA integrity check failure report';
$string['mobile_chip_concepts'] = 'Nøkkelbegreper';
$string['mobile_chip_quiz'] = 'Quiz';
$string['mobile_chip_studyplan'] = 'Studieplan';
$string['mobile_clear'] = 'Slett historikk';
$string['mobile_disabled'] = 'SOLA er ikke tilgjengelig for dette kurset.';
$string['mobile_placeholder'] = 'Still et spørsmål...';
$string['mobile_welcome'] = 'Hei, {$a}!';
$string['mobile_welcome_sub'] = 'Jeg er SOLA, din læringsassistent. Hvordan kan jeg hjelpe?';
$string['privacy:metadata:local_ai_course_assistant_audit'] = 'Stores audit log entries for compliance tracking.';
$string['privacy:metadata:local_ai_course_assistant_audit:action'] = 'The action that was performed.';
$string['privacy:metadata:local_ai_course_assistant_audit:courseid'] = 'The course context of the action.';
$string['privacy:metadata:local_ai_course_assistant_audit:details'] = 'Additional details about the action.';
$string['privacy:metadata:local_ai_course_assistant_audit:ipaddress'] = 'The IP address of the user.';
$string['privacy:metadata:local_ai_course_assistant_audit:timecreated'] = 'The time the action was logged.';
$string['privacy:metadata:local_ai_course_assistant_audit:useragent'] = 'The browser user agent string.';
$string['privacy:metadata:local_ai_course_assistant_audit:userid'] = 'The ID of the user whose action was logged.';
$string['privacy:metadata:local_ai_course_assistant_feedback'] = 'Stores user feedback and ratings.';
$string['privacy:metadata:local_ai_course_assistant_feedback:browser'] = 'The browser used when submitting feedback.';
$string['privacy:metadata:local_ai_course_assistant_feedback:comment'] = 'The feedback comment text.';
$string['privacy:metadata:local_ai_course_assistant_feedback:courseid'] = 'The course the feedback relates to.';
$string['privacy:metadata:local_ai_course_assistant_feedback:device'] = 'The device type used when submitting feedback.';
$string['privacy:metadata:local_ai_course_assistant_feedback:os'] = 'The operating system used when submitting feedback.';
$string['privacy:metadata:local_ai_course_assistant_feedback:page_url'] = 'The page URL where feedback was submitted.';
$string['privacy:metadata:local_ai_course_assistant_feedback:rating'] = 'The numeric rating given.';
$string['privacy:metadata:local_ai_course_assistant_feedback:screen_size'] = 'The screen size when submitting feedback.';
$string['privacy:metadata:local_ai_course_assistant_feedback:timecreated'] = 'The time the feedback was submitted.';
$string['privacy:metadata:local_ai_course_assistant_feedback:user_agent'] = 'The browser user agent string.';
$string['privacy:metadata:local_ai_course_assistant_feedback:userid'] = 'The ID of the user who submitted feedback.';
$string['privacy:metadata:local_ai_course_assistant_msgs:completion_tokens'] = 'The number of completion tokens generated.';
$string['privacy:metadata:local_ai_course_assistant_msgs:model_name'] = 'The AI model used for the response.';
$string['privacy:metadata:local_ai_course_assistant_msgs:prompt_tokens'] = 'The number of prompt tokens used.';
$string['privacy:metadata:local_ai_course_assistant_msgs:provider'] = 'The AI provider used for the response.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores'] = 'Stores practice session scores and AI feedback.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:ai_feedback'] = 'AI-generated feedback on the practice session.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:courseid'] = 'The course the practice session belongs to.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:overall_score'] = 'The overall score achieved.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:scores'] = 'Per-criterion scores in JSON format.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:session_type'] = 'The type of practice session (conversation or pronunciation).';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:timecreated'] = 'The time the score was recorded.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:userid'] = 'The ID of the user who completed the practice.';
$string['privacy:metadata:local_ai_course_assistant_survey_resp'] = 'Stores survey responses from students.';
$string['privacy:metadata:local_ai_course_assistant_survey_resp:answer'] = 'The answer text or selection.';
$string['privacy:metadata:local_ai_course_assistant_survey_resp:courseid'] = 'The course the survey relates to.';
$string['privacy:metadata:local_ai_course_assistant_survey_resp:question_index'] = 'The index of the question answered.';
$string['privacy:metadata:local_ai_course_assistant_survey_resp:timecreated'] = 'The time the response was submitted.';
$string['privacy:metadata:local_ai_course_assistant_survey_resp:userid'] = 'The ID of the user who responded.';
$string['privacy:metadata:local_ai_course_assistant_ut_resp'] = 'Stores usability testing responses.';
$string['privacy:metadata:local_ai_course_assistant_ut_resp:answer'] = 'The free-text response for the task.';
$string['privacy:metadata:local_ai_course_assistant_ut_resp:courseid'] = 'The course the usability test relates to.';
$string['privacy:metadata:local_ai_course_assistant_ut_resp:rating'] = 'The numeric rating given for the task.';
$string['privacy:metadata:local_ai_course_assistant_ut_resp:task_index'] = 'The index of the task completed.';
$string['privacy:metadata:local_ai_course_assistant_ut_resp:timecreated'] = 'The time the response was submitted.';
$string['privacy:metadata:local_ai_course_assistant_ut_resp:userid'] = 'The ID of the user who completed the test.';
$string['ragadmin:back_to_settings'] = 'Back to plugin settings';
$string['ragadmin:col_actions'] = 'Actions';
$string['ragadmin:col_chunks'] = 'Chunks';
$string['ragadmin:col_course'] = 'Course';
$string['ragadmin:col_embedded'] = 'Embedded';
$string['ragadmin:col_lastindexed'] = 'Last indexed';
$string['ragadmin:deleteindex'] = 'Clear index';
$string['ragadmin:deleteindex_confirm'] = 'Delete all indexed chunks for this course? The AI tutor will fall back to full content stuffing until the course is re-indexed.';
$string['ragadmin:deleteindex_done'] = 'Course index cleared.';
$string['ragadmin:index_status'] = 'Per-course index status';
$string['ragadmin:never'] = 'Never';
$string['ragadmin:no_courses'] = 'No indexed courses and no active courses found.';
$string['ragadmin:rag_disabled_notice'] = 'RAG is currently disabled. Enable it in the plugin settings to activate semantic search. You can still pre-index courses below so the index is ready when you enable RAG.';
$string['ragadmin:reindex'] = 'Reindex';
$string['ragadmin:reindexall'] = 'Reindex all active courses';
$string['ragadmin:reindexall_confirm'] = 'This will call the embedding API for all new/changed content across all active courses. Continue?';
$string['ragadmin:reindexall_desc'] = 'Runs incremental indexing on all courses with active enrolments. Only new or changed content is re-embedded.';
$string['ragadmin:reindexall_done'] = 'Reindexing complete: {$a->courses} course(s) processed — {$a->indexed} chunks indexed, {$a->skipped} skipped, {$a->errors} error(s).';
$string['ragadmin:reindexcourse_done'] = 'Course reindexed: {$a->indexed} chunks indexed, {$a->skipped} skipped, {$a->errors} error(s).';
$string['ragadmin:stat_active_courses'] = 'Active courses';
$string['ragadmin:stat_courses_indexed'] = 'Courses indexed';
$string['ragadmin:stat_embedded_chunks'] = 'Embedded chunks';
$string['ragadmin:stat_total_chunks'] = 'Total chunks';
$string['ragadmin:title'] = 'RAG Index Status & Reindex';
$string['ragadmin:view_status'] = 'View RAG index status / reindex';
$string['redash_api_key'] = 'Redash API Key';
$string['redash_api_key_desc'] = 'API key for external analytics platforms like Redash. Provides read-only access to usage data, feedback, and cost analytics. Leave blank to disable the export endpoint.';
$string['redash_heading'] = 'Analytics Export';
$string['redash_heading_desc'] = 'Configure API key access for external analytics platforms like Redash. The export endpoint provides read-only JSON access to usage data, feedback, and cost analytics.';
$string['remoteconfigurl'] = 'Remote config URL';
$string['remoteconfigurl_desc'] = 'URL to a JSON file containing remotely-managed SOLA configuration (system prompt, instruction blocks, model default). Must be HTTPS. Leave blank to use the default GitHub URL. Local admin settings always take priority over remote config values.';
$string['rubric:done'] = 'Ferdig';
$string['rubric:encourage_high'] = 'Utmerket! Fortsett!';
$string['rubric:encourage_low'] = 'God start! Regelmessig øvelse hjelper.';
$string['rubric:encourage_mid'] = 'Bra innsats! Fortsett å øve.';
$string['rubric:overall'] = 'Totalt';
$string['rubric:practice_again'] = 'Øv igjen';
$string['rubric:score_title_conversation'] = 'Samtaleøvelsespoeng';
$string['rubric:score_title_pronunciation'] = 'Uttaleøvelsespoeng';
$string['rubric:scoring'] = 'Evaluerer...';
$string['settings:avatar_color'] = 'Avatar Border Color';
$string['settings:avatar_color_desc'] = 'Border color of the floating avatar button. Use a hex value, e.g. #023e8a.';
$string['settings:avatar_fill'] = 'Avatar Background Color';
$string['settings:avatar_fill_desc'] = 'Fill color inside the floating avatar button (shown behind transparent avatar areas). Use a hex value, e.g. #ffffff.';
$string['settings:display_mode'] = 'Display Mode';
$string['settings:display_mode_desc'] = 'How SOLA appears on the page. "Widget" shows a floating avatar button with a popup chat panel. "Side drawer" shows a full-height panel that slides in from the right edge of the screen.';
$string['settings:display_mode_drawer'] = 'Side drawer (right edge)';
$string['settings:display_mode_widget'] = 'Widget (floating button)';
$string['settings:embed_apibaseurl'] = 'Embedding API Base URL';
$string['settings:embed_apibaseurl_desc'] = 'Base URL for the embedding API. Leave blank for OpenAI default. For Ollama: http://localhost:11434/api';
$string['settings:embed_apikey'] = 'Embedding API Key';
$string['settings:embed_apikey_desc'] = 'API key for the embedding provider. Can be different from the chat API key. Not required for Ollama.';
$string['settings:embed_dimensions'] = 'Embedding Dimensions';
$string['settings:embed_dimensions_desc'] = 'Number of dimensions in the embedding vector. Must match your model output. OpenAI text-embedding-3-small: 1536. nomic-embed-text: 768.';
$string['settings:embed_model'] = 'Embedding Model';
$string['settings:embed_model_desc'] = 'Model to use for generating embeddings. OpenAI default: text-embedding-3-small. Ollama example: nomic-embed-text.';
$string['settings:embed_provider'] = 'Embedding Provider';
$string['settings:embed_provider_desc'] = 'The API provider used to generate text embeddings for RAG indexing and retrieval.';
$string['settings:embed_provider_ollama'] = 'Ollama (local, e.g. nomic-embed-text)';
$string['settings:embed_provider_openai'] = 'OpenAI (text-embedding-3-small)';
$string['settings:hide_on_quiz_for_staff'] = 'Hide SOLA on quiz pages for staff';
$string['settings:hide_on_quiz_for_staff_desc'] = 'Completely hide the SOLA widget on all quiz pages for teachers and administrators.';
$string['settings:hide_on_quiz_for_students'] = 'Hide SOLA on quiz pages for students';
$string['settings:hide_on_quiz_for_students_desc'] = 'Completely hide the SOLA widget on all quiz pages (view, attempt, review) for students.';
$string['settings:institution_name'] = 'Institution Name';
$string['settings:institution_name_desc'] = 'The name of the institution displayed in the system prompt, avatar labels, and demo content. Change this when rebranding.';
$string['settings:model_desc_dynamic'] = 'Leave blank to use the provider\'s default model automatically. Each provider has a built-in default that stays current (e.g. gpt-4o for OpenAI, claude-sonnet-4 for Claude, mistral-large-latest for Mistral). Only enter a model name if you want to override the default. If a model is misspelled or deprecated, SOLA will automatically fall back to the provider\'s default.';
$string['settings:provider_gemini'] = 'Google Gemini';
$string['settings:quiz_hide_heading'] = 'Quiz Page Visibility';
$string['settings:quiz_hide_heading_desc'] = 'Control whether the SOLA widget appears on Moodle quiz pages. This is stricter than the built-in summative quiz lock, which only disables chat during graded quizzes. These settings completely hide the widget on all quiz pages.';
$string['settings:rag_chunksize'] = 'Chunk Size (words)';
$string['settings:rag_chunksize_desc'] = 'Target number of words per content chunk when indexing course material. Smaller chunks are more precise; larger chunks provide more context.';
$string['settings:rag_enabled'] = 'Enable RAG (Semantic Search)';
$string['settings:rag_enabled_desc'] = 'When enabled, the AI tutor uses semantic search to retrieve relevant course content for each query instead of stuffing all content into the system prompt.';
$string['settings:rag_heading'] = 'RAG / Semantic Search';
$string['settings:rag_heading_desc'] = 'Retrieval-Augmented Generation: index course content as embeddings and retrieve only the most relevant chunks at query time. Reduces token usage and supports all content types. Requires an embedding API.';
$string['settings:rag_topk'] = 'Top-K Chunks';
$string['settings:rag_topk_desc'] = 'Number of most relevant chunks to retrieve per user query and inject into the system prompt.';
$string['settings:realtime_apikey'] = 'OpenAI API Key (Voice & TTS)';
$string['settings:realtime_apikey_desc'] = 'Used for Voice Mode and the TTS speak button on messages. Leave blank to fall back to the main API key when provider is set to OpenAI.';
$string['settings:realtime_enabled'] = 'Enable Voice Mode';
$string['settings:realtime_enabled_desc'] = 'Allows students to have real-time voice conversations with SOLA. Requires an OpenAI API key.';
$string['settings:realtime_heading'] = 'Voice Mode (OpenAI Realtime)';
$string['settings:realtime_voice'] = 'SOLA Voice';
$string['settings:realtime_voice_desc'] = 'Voice used for both Voice Mode and the TTS speak button (OpenAI voices: Shimmer, Alloy, Echo, Fable, Onyx, Nova).';
$string['settings:wellbeing_enabled'] = 'Enable Wellbeing Support';
$string['settings:wellbeing_enabled_desc'] = 'When enabled, SOLA will detect signs of emotional distress and provide empathetic responses with links to global crisis resources. Disable this if your institution provides its own crisis response and does not want SOLA to surface external resources.';
$string['settings:wellbeing_heading'] = 'Wellbeing & Safety';
$string['settings:wellbeing_heading_desc'] = 'When enabled, SOLA detects expressions of distress or crisis and responds with empathy and globally-applicable support resources (findahelpline.com, Crisis Text Line, Befrienders Worldwide). SOLA is NOT a counselor — it acknowledges feelings, directs students to human support, and never attempts diagnosis or therapy.';
$string['starters:add_new'] = 'Add new starter';
$string['starters:admin_desc'] = 'Configure the conversation starter chips shown to students when they open the SOLA chat. Drag to reorder, toggle to enable/disable, or add custom starters with your own AI prompts.';
$string['starters:admin_title'] = 'Conversation Starter Settings';
$string['starters:back_settings'] = 'Back to settings';
$string['starters:course_desc'] = 'Enable or disable individual starters for this course.';
$string['starters:course_section'] = 'Conversation starters';
$string['starters:reset_confirm'] = 'Reset all starters to built-in defaults? Custom starters will be deleted.';
$string['starters:reset_defaults'] = 'Reset to defaults';
$string['starters:reset_done'] = 'Starters reset to defaults.';
$string['starters:save'] = 'Save changes';
$string['starters:saved'] = 'Starter configuration saved.';
$string['task:index_course_content'] = 'Index course content for RAG semantic search';
$string['task:run_integrity_checks'] = 'Run daily SOLA plugin integrity checks';
$string['update:available'] = 'Update Available';
$string['update:back_to_settings'] = 'Back to Settings';
$string['update:changelog'] = 'Release Notes';
$string['update:check'] = 'Check for Updates';
$string['update:confirm'] = 'Install this update? A backup of the current version will be created automatically.';
$string['update:current_version'] = 'Installed Version';
$string['update:desc'] = 'Check for and install SOLA plugin updates directly from GitHub releases.';
$string['update:download_failed'] = 'Failed to download the update. Please try again or install manually.';
$string['update:github_error'] = 'Could not reach GitHub. Check your connection or add a GitHub token in settings.';
$string['update:github_token'] = 'GitHub Token (optional)';
$string['update:github_token_desc'] = 'Personal access token for accessing private GitHub repositories. Leave blank for public repos.';
$string['update:install'] = 'Install Update';
$string['update:latest_version'] = 'Latest Available';
$string['update:title'] = 'Plugin Updates';
$string['update:up_to_date'] = 'Up to Date';

// Testing Environment admin page and TOC quick links (v3.9.4+).
$string['demo:title'] = 'Testmiljø';
$string['demo:heading'] = 'Testmiljø';
$string['demo:intro'] = 'Denne siden oppretter et testkurs som er <strong>skjult for studenter</strong> (visible=0), og fyller det med fiktive studenter, AI-samtaler, vurderinger og tilbakemeldinger. Nyttig for å forhåndsvise Analytics Dashboard eller validere plugin-endringer uten å påvirke noen reelt påmeldte studenter.';
$string['demo:step1'] = 'Trinn 1: testkurs';
$string['demo:step2'] = 'Trinn 2: legg til fiktive studenter og AI-samtaler';
$string['demo:course_exists'] = 'Testkurs finnes: <strong>{$a->fullname}</strong> (kortnavn <code>{$a->shortname}</code>, id {$a->id})';
$string['demo:badge_hidden'] = 'skjult';
$string['demo:badge_visible'] = 'synlig for studenter';
$string['demo:no_course'] = 'Ingen testkurs funnet. Klikk nedenfor for å opprette ett.';
$string['demo:create_btn'] = 'Opprett skjult testkurs';
$string['demo:open_course'] = 'Åpne kurs &rarr;';
$string['demo:seed_intro'] = 'Oppretter demo_student_001, demo_student_002, ..., melder dem på testkurset og setter inn syntetiske samtaler, meldinger, vurderinger og tilbakemeldinger. Kjør igjen for å legge til mer data, eller kryss av for „tøm først“ for å starte på nytt.';
$string['demo:users_label'] = 'Brukere';
$string['demo:weeks_label'] = 'Uker';
$string['demo:clear_label'] = 'Tøm eksisterende demo_*-brukere først';
$string['demo:seed_btn'] = 'Legg til studenter og samtaler';
$string['demo:view_analytics'] = 'Vis analyser for dette kurset &rarr;';
$string['demo:footer'] = 'Data som opprettes her ligger i standard Moodle-tabellene for bruker / påmelding og i pluginens egne samtaletabeller. Alle fiktive brukere har brukernavn som begynner med <code>demo_student_</code>, slik at de er enkle å filtrere eller fjerne. For å fjerne dem, kjør seed-trinnet på nytt med „Tøm eksisterende demo_*-brukere først“ avkrysset.';
$string['demo:course_fullname'] = 'SOLA Testkurs (skjult)';
$string['demo:notify_created'] = 'Testkurs klar: {$a->fullname} (id {$a->id}).';
$string['demo:notify_create_fail'] = 'Klarte ikke å opprette kurs: {$a}';
$string['demo:notify_seeded'] = 'Lagt til: {$a->users} brukere, {$a->conversations} samtaler, {$a->messages} meldinger, {$a->ratings} vurderinger, {$a->feedback} tilbakemeldinger.';
$string['demo:notify_seed_fail'] = 'Klarte ikke å legge til data: {$a}';
$string['toc:analytics'] = 'Analytics Dashboard &rarr;';
$string['toc:tokenanalytics'] = 'Tokenkostnader og analyser &rarr;';
$string['toc:testing'] = 'Testmiljø &rarr;';
$string['toc:back_to_course'] = '&larr; Tilbake til {$a}';

// RAG extractor status strings (v3.9.6+).
$string['rag:pdftotext_missing'] = 'pdftotext-binærfilen ble ikke funnet; PDF-uttrekking er deaktivert.';
$string['rag:pdftotext_available'] = 'pdftotext oppdaget på {$a}.';
$string['rag:docx_unavailable'] = 'PHP ZipArchive-utvidelsen er ikke tilgjengelig; DOCX-uttrekking er deaktivert.';
$string['rag:h5p_unavailable'] = 'H5P-innhold kunne ikke leses; hopper over.';
$string['rag:scorm_too_large'] = 'SCORM-pakken overskrider den konfigurerte størrelsesgrensen ({$a} MB); hopper over.';
$string['rag:scorm_unzip_failed'] = 'SCORM-pakken kunne ikke pakkes ut; hopper over.';
$string['rag:transcript_fetch_failed'] = 'Kunne ikke hente transkripsjon fra {$a}.';
$string['rag:transcript_cf_challenge'] = 'Transkripsjons-URL blokkert av Cloudflare-utfordring: {$a}.';
$string['rag:embed_detected'] = 'Oppdaget innebygd media: {$a}';
$string['rag:embed_transcript_attached'] = 'Transkripsjon vedlagt for {$a}';

// v3.9.10–v3.9.14 new strings (English verbatim; translate later).
$string['usersettings:download'] = 'Last ned mine {$a}-data';
$string['usersettings:download_help'] = 'Last ned en fullstendig JSON-kopi av alle {$a}-oppføringer knyttet til kontoen din: samtaler, meldinger, vurderinger, studieplaner, påminnelser, øvingsresultater, undersøkelsessvar, profil og revisjonsoppføringer.';
$string['usersettings:privacy_notice_link'] = 'Les personvernerklæringen for {$a}';
$string['privacy:title'] = 'Personvernerklæring for {$a}';
$string['admin:user_data:title'] = '{$a} — eksport og sletting av elevdata';
$string['admin:user_data:intro'] = 'Operativ rute for en GDPR Artikkel 15-forespørsel (innsyn) eller Artikkel 17-forespørsel (sletting). Slå opp en elev etter Moodle-bruker-id, gjennomgå radene denne tilleggsmodulen lagrer for vedkommende, og eksporter eller slett.';
$string['admin:user_data:search_label'] = 'Moodle-bruker-id';
$string['admin:user_data:lookup'] = 'Slå opp';
$string['admin:user_data:not_found'] = 'Ingen bruker funnet med den id-en.';
$string['admin:user_data:download'] = 'Last ned alle elevdata som JSON';
$string['admin:user_data:purge'] = 'Slett alle elevdata for denne brukeren';
$string['admin:user_data:confirm_purge'] = 'Slette permanent hver eneste oppføring for {$a}? Dette går igjennom samtaler, meldinger, vurderinger, studieplaner, påminnelser, profiler, øvingsresultater, undersøkelser, revisjonsoppføringer og tilbakemeldinger. Handlingen kan ikke angres.';
$string['admin:user_data:purged'] = 'Alle data for valgt bruker er slettet.';
$string['chat:consent_heading'] = 'Før du chatter med {$a->product}';
$string['chat:consent_body'] = '{$a->product} er en AI-drevet læringsassistent. Meldingene dine og {$a->product}s svar lagres i {$a->institution}s Moodle-database, og de ti nyeste turene sendes til en godkjent leverandør av AI-modeller for å svare på spørsmålene dine. Fornavnet ditt deles for personalisering; ingen annen identifiserende informasjon sendes til AI-leverandøren. Hvis du ber om menneskelig hjelp og spørsmålet ditt eskaleres, kan denne samtalen (inkludert navnet og e-postadressen din) deles med kundestøtteteamet vårt. Du kan laste ned, slette eller slutte å bruke {$a->product} når som helst.';
$string['chat:consent_accept'] = 'Jeg forstår, start {$a}';
$string['chat:consent_privacy_link'] = 'Les hele personvernerklæringen';
$string['task:audit_cleanup'] = 'Opprydding av AI Course Assistant revisjonstabell';
$string['task:conversation_retention'] = 'AI Course Assistant samtalebevarings-feier';
$string['settings:audit_retention_days'] = 'Lagring av revisjonslogg (dager)';
$string['settings:audit_retention_days_desc'] = 'Daglig planlagt oppgave fjerner revisjonsrader som er eldre enn dette. 0 deaktiverer. Standard 365.';
$string['settings:conversation_retention_days'] = 'Lagring av samtaler (dager)';
$string['settings:conversation_retention_days_desc'] = 'Daglig planlagt oppgave fjerner samtalerader hvor sist endret-tidsstempelet er eldre enn dette. 0 deaktiverer. Standard 730.';
$string['settings:ssrf_trusted_endpoints'] = 'Klarerte SSRF-endepunkter';
$string['settings:ssrf_trusted_endpoints_desc'] = 'Én URL per linje. Oppførte verter omgår loopback / privat-IP / kun-https-kontrollene i SOLAs SSRF-validator. Bruk dette kun for selvhostede LLM-er på et nettverk du kontrollerer — for eksempel <code>http://localhost:11434</code> for lokal Ollama, <code>http://10.0.0.5:8000</code> for en vLLM-pod i samme VPC. Sammenligning matcher scheme + host + port; enhver bane ignoreres. Standard tom (blokkerer alt internt). Linjer som starter med <code>#</code> er kommentarer.';
$string['task:learner_weekly_digest']    = 'AI-kursassistent - Lærerens ukentlige oppsummering';
$string['learner_digest:subject']        = 'Din uke med {$a->course} - {$a->product}';
$string['learner_digest:optin_offer']    = 'Vil du ha en kort ukentlig e-post med hva du skal fokusere på neste?';
$string['next_best_action:get_started']           = 'Start med {$a->title}. Åpne meg og spør "hjelp meg med {$a->title}".';
$string['next_best_action:get_started_with_module'] = 'Start med {$a->title}. Modulen "{$a->module}" dekker det.';
$string['next_best_action:review']                = 'Se gjennom det grunnleggende i {$a->title} — åpne meg og spør "forklar {$a->title} som om jeg er ny".';
$string['next_best_action:review_with_module']    = 'Se gjennom det grunnleggende i {$a->title} i "{$a->module}", åpne meg så med spørsmål.';
$string['next_best_action:practice']              = 'Bygg på det du har i {$a->title}. Åpne meg og spør "gi meg et løst eksempel for {$a->title}".';
$string['next_best_action:practice_with_module']  = 'Øv på {$a->title} sammen med "{$a->module}". Åpne meg for løste eksempler.';
$string['next_best_action:quiz']                  = 'Lås {$a->title} med en rask quiz. Åpne meg og velg "Test meg — adaptiv".';
$string['next_best_action:quiz_with_module']      = 'Lås {$a->title} med en rask quiz. Modulen "{$a->module}" er der den bor.';
$string['next_best_action:empty_state']           = 'Du gjør det flott på hvert mål akkurat nå — ingenting å minne om. Fortsett.';
$string['next_best_action:header']                = 'Her er {$a} ting å fokusere på neste:';
$string['learner_digest:unsubscribe_done_title']  = 'Avmeldt';
$string['learner_digest:unsubscribe_done_body']   = 'Ferdig — du vil ikke motta flere ukentlige e-poster for dette kurset fra {$a->product}. Du kan abonnere på nytt når som helst fra chatten i kurset.';
$string['learner_digest:unsubscribe_invalid_title'] = 'Avmeldingslenken er ikke lenger gyldig';
$string['learner_digest:unsubscribe_invalid_body']  = 'Denne avmeldingslenken har utløpt eller er feilaktig. Du kan administrere e-postpreferanser fra kursinnstillingene.';
$string['active_learners:line']                   = '{$a} andre studerer dette kurset akkurat nå.';
$string['active_learners:line_global']             = '{$a} andre studerer akkurat nå.';
$string['settings:active_learners_scope']          = 'Omfang av aktive elever-indikator';
$string['settings:active_learners_scope_desc']     = 'Om "andre studerer akkurat nå"-indikatoren over chatstartere teller elever bare på samme kurs eller elever på tvers av hele nettstedet. Standard <strong>global</strong>.';
$string['settings:active_learners_scope_global']   = 'Global (hvilket som helst kurs)';
$string['settings:active_learners_scope_course']   = 'Kun per kurs';
$string['learner_digest:optin_yes']      = 'Ja, send meg den ukentlige e-posten';
$string['learner_digest:optin_no']       = 'Nei takk';
$string['learner_digest:optin_thanks']   = 'Forstått. Du får en ukentlig oppsummering hver mandag.';
$string['learner_digest:optin_declined'] = 'Forstått. Ingen e-poster - åpne meg bare når du vil ha en sjekk.';
$string['settings:xai_proxy_url'] = 'xAI Realtime proxy-URL';
$string['settings:xai_proxy_url_desc'] = 'Offentlig wss-URL til SOLA xAI Realtime proxy-tjenesten (for eksempel wss://voice.example.com/xai-rt/rt). Når denne er satt sammen med JWT-hemmeligheten, rutes xAI-stemme gjennom proxyen og hoved-API-nøkkelen for xAI når aldri nettleseren. La stå tom for å falle tilbake til direkte tilkobling (ikke anbefalt for produksjon).';
$string['settings:xai_proxy_jwt_secret'] = 'xAI Realtime proxy JWT-hemmelighet';
$string['settings:xai_proxy_jwt_secret_desc'] = 'HS256 delt hemmelighet brukt til å signere kortlivede økt-tokens for xAI Realtime-proxyen. Må samsvare med MOODLE_JWT_SECRET-hemmeligheten på Cloudflare Worker. Roter periodisk.';
$string['admin:vendor_dpa:title'] = '{$a} — Status for leverandør-DPA';
$string['admin:vendor_dpa:intro'] = 'Status for opt-out av trening, DPA og lagring for hver AI-leverandør-driver. Bruk dette til å bestemme hvilke drivere som skal aktiveres på nettstedet ditt. Tier 2 og høyere ruting krever en signert DPA og en kontraktfestet opt-out fra trening.';
$string['admin:vendor_dpa:maintenance_note'] = 'Denne tabellen vedlikeholdes i classes/vendor_registry.php. Oppdater den når en endring i leverandørens vilkår blir tilgjengelig.';
$string['settings:contact_email'] = 'Contact email';
$string['settings:contact_email_desc'] = 'General contact email shown on the learner facing privacy notice under "Contact". Defaults to contact@saylor.org. Leave empty to hide the line.';
$string['settings:dpo_email'] = 'E-post til personvernombud';
$string['settings:dpo_email_desc'] = 'Kontakt-e-post som vises på den læring-rettede personvernerklæringen under "Kontakt". La stå tom for å skjule linjen. Re-merkede installasjoner bør peke denne mot sitt eget personvernombud.';
$string['settings:privacy_external_url'] = 'URL til institusjonens personvernside';
$string['settings:privacy_external_url_desc'] = 'Lenke til personvernsiden på institusjonsnivå, vist på den læring-rettede personvernerklæringen under "Kontakt". La stå tom for å skjule linjen.';
$string['settings:privacy_notice_override'] = 'Overstyring av personvernerklæring (HTML)';
$string['settings:privacy_notice_override_desc'] = 'Hvis satt, erstatter denne HTML-en standard merket personvernerklæring som vises på /local/ai_course_assistant/privacy.php. Bruk dette til å sette inn den juridisk-godkjente teksten for institusjonen din uten å redigere kode. La stå tom for å bruke standarderklæringen, som henter teksten fra de syv merkevare-konfigurasjonsnøklene.';
$string['objectives:title'] = 'Læringsmål og mestring';
$string['objectives:toggles_heading'] = 'Mestringssporing';
$string['objectives:toggle_master'] = 'Aktiver mestringssporing for dette emnet';
$string['objectives:toggle_chip'] = 'Vis Læringsmestring-chipen til elevene';
$string['objectives:toggle_chip_help'] = 'Valgfritt. Når slått av, styrer mestring fortsatt assistenten i bakgrunnen, men eleven ser ingen indikator.';
$string['objectives:toggled'] = 'Innstilling oppdatert.';
$string['objectives:detected_heading'] = 'Oppdaget {$a->count} læringsmål fra {$a->source}.';
$string['objectives:source_competency'] = 'Moodle-kompetanser';
$string['objectives:source_summary'] = 'emnesammendrag';
$string['objectives:source_section'] = 'seksjons- eller førstesideinnhold';
$string['objectives:source_page'] = 'emneside';
$string['objectives:source_llm'] = 'AI-uttrekk';
$string['objectives:source_manual'] = 'manuell oppføring';
$string['objectives:source_none'] = 'ingen automatisk kilde';
$string['objectives:import_detected'] = 'Importer disse oppdagede målene';
$string['objectives:import_llm'] = 'Trekk ut mål med AI';
$string['objectives:llm_empty'] = 'AI-uttrekket returnerte ingen mål. Prøv igjen senere eller skriv dem inn manuelt.';
$string['objectives:imported'] = 'Importerte {$a} mål.';
$string['objectives:none_detected'] = 'Ingen læringsmål oppdaget automatisk. Skriv dem inn manuelt nedenfor, eller bruk AI-uttrekk.';
$string['objectives:list_heading'] = 'Gjeldende mål';
$string['objectives:col_code'] = 'Kode';
$string['objectives:col_title'] = 'Tittel';
$string['objectives:col_source'] = 'Kilde';
$string['objectives:col_actions'] = 'Handlinger';
$string['objectives:add_heading'] = 'Legg til et mål';
$string['objectives:add_submit'] = 'Legg til mål';
$string['objectives:saved'] = 'Mål lagret.';
$string['objectives:deleted'] = 'Mål slettet.';
$string['objectives:delete_confirm'] = 'Slette dette målet og all forsøkshistorikk for det?';
$string['objectives:delete_all'] = 'Slett alle mål for dette emnet';
$string['objectives:delete_all_confirm'] = 'Slette alle mål og all forsøkshistorikk for dette emnet? Kan ikke angres.';
$string['objectives:deleted_all'] = 'Alle mål for dette emnet er slettet.';
$string['mastery:chip_aria'] = 'Status for læringsmestring';
$string['mastery:popover_aria'] = 'Detaljer om læringsmestring';
$string['mastery:chip_label'] = '{$a->mastered} av {$a->total} mestret';
$string['mastery:status_mastered'] = 'mestret';
$string['mastery:status_learning'] = 'pågår';
$string['mastery:status_not_started'] = 'ikke startet';
$string['mastery:popover_empty'] = 'Ingen læringsmål er konfigurert for dette emnet.';
$string['settings:mastery_heading'] = 'Mestringssporing';
$string['settings:mastery_heading_desc'] = 'Opt-in funksjon per emne som merker quiz-svar og samtaleturer mot emnets læringsmål, og deretter mater et kompakt mestrings-snapshot tilbake i systemledeteksten for å styre spørsmålene. Diskret som standard: elever ser ingenting med mindre chip-bryteren per emne er på.';
$string['settings:mastery_threshold'] = 'Mestret-terskel';
$string['settings:mastery_threshold_desc'] = 'Rullende nøyaktighet på eller over dette nivået anses som mestret. 0,0 til 1,0. Standard 0,85.';
$string['settings:mastery_window'] = 'Forsøksvindu';
$string['settings:mastery_window_desc'] = 'Antall siste forsøk per mål som vektes inn i den rullende nøyaktigheten. Standard 8.';
$string['settings:mastery_decay_enabled']        = 'Aktiver mestrings-forfall';
$string['settings:mastery_decay_enabled_desc']   = 'Når på, faller mestrings-poeng over tid i forhold til siste forsøks tidsstempel. Et tidligere mestret mål går tilbake til "lærer" etter tilstrekkelig tid. Faller ikke under "lærer". <strong>Standard av i v4.0.</strong>';
$string['settings:mastery_decay_half_life_days'] = 'Halveringstid for mestrings-forfall (dager)';
$string['settings:mastery_decay_half_life_days_desc'] = 'Halveringstid i dager. Poeng multipliseres med 0.5 ^ (dager siden siste forsøk / halveringstid). Standard 30. Brukes bare når forfall er aktivert.';
$string['settings:mastery_classifier_model'] = 'Klassifiseringsmodell';
$string['settings:mastery_classifier_model_desc'] = 'Modell brukt til å klassifisere assistentens turer mot mål. La stå tom for å arve standard AI-leverandørmodell; ellers angi en rimelig modell som gpt-4o-mini.';
$string['settings:mastery_classifier_weight'] = 'Klassifiseringsvekt';
$string['settings:mastery_classifier_weight_desc'] = 'Hvor mye et samtaleforsøk teller i forhold til et quizforsøk (1,0). Standard 0,3.';
$string['settings:mastery_classifier_threshold'] = 'Klassifisererens konfidensgrense';
$string['settings:mastery_classifier_threshold_desc'] = 'Minste klassifiseringskonfidens som kreves for å registrere et samtaleforsøk. 0,0 til 1,0. Standard 0,7.';
$string['chat:mode_progress'] = 'Fremdrift';
$string['objectives:toggle_dashboard'] = 'Vis fremdriftspanel-fanen til elevene';
$string['objectives:toggle_dashboard_help'] = 'Valgfritt. Legger til en Fremdrift-fane ved siden av Chat / Stemme / Historikk inne i widgeten. Fanen viser eleven hvilke mål som er mestret, hvilke som er underveis, og hvilke som ikke er startet.';
$string['mastery:dashboard_title'] = 'Din læringsfremdrift';
$string['mastery:dashboard_subtitle'] = 'Mestring måles ut fra dine quiz-svar og chat-trening. Fortsett — dybde slår bredde.';
$string['mastery:dashboard_refresh'] = 'Oppdater';
$string['mastery:section_mastered'] = 'Mestret';
$string['mastery:section_learning'] = 'Pågår';
$string['mastery:section_not_started'] = 'Ikke startet ennå';
$string['mastery:summary_label'] = '{$a->mastered} av {$a->total} mål mestret';
$string['mastery:ask_about'] = 'Spør om dette';
$string['mastery:celebrate'] = 'Du har mestret hvert eneste mål for dette emnet. Strålende arbeid.';
$string['mastery:ask_template'] = 'Hjelp meg å øve og fordype min forståelse av dette målet: {$a}.';
$string['instructor_dashboard:title'] = '{$a} — Course Instructor & Designer Dashboard';
$string['instructor_dashboard:short']            = 'Instructor & Designer Dashboard';
$string['coursepicker:title']                    = 'Select a course — {$a}';
$string['coursepicker:intro']                    = 'Choose a course from the list below to open this page in that course\'s context. Direct links with a courseid parameter still work as before.';
$string['coursepicker:nocourses']                = 'You do not have access to any courses where this page applies. Contact your site administrator if you believe this is incorrect.';
$string['instructor_dashboard:link'] = 'Course Instructor & Designer Dashboard';
$string['instructor_dashboard:intro'] = 'Per-course usage, mastery, and content-revision signals. Aggregate-only by default; click Show real names to bind aggregate rows to specific learners (writes a FERPA audit row).';
$string['instructor_dashboard:period'] = 'Period';
$string['instructor_dashboard:period_all'] = 'all';
$string['instructor_dashboard:gap_days'] = 'Inactive after (days)';
$string['instructor_dashboard:show_names'] = 'Show real names';
$string['instructor_dashboard:hide_names'] = 'Hide real names';
$string['instructor_dashboard:active_learners'] = 'Active learners';
$string['instructor_dashboard:total_messages'] = 'Total messages';
$string['instructor_dashboard:avg_per_learner'] = 'Avg messages / learner';
$string['instructor_dashboard:last_activity'] = 'Last activity';
$string['instructor_dashboard:mastery_heading'] = 'Mastery aggregate';
$string['instructor_dashboard:mastery_off'] = 'Mastery tracking is not enabled for this course. Turn it on from the Learning objectives & mastery page.';
$string['instructor_dashboard:topics_heading'] = 'Most-asked topics';
$string['instructor_dashboard:topics_empty'] = 'No topic data yet. Topics are extracted by a daily scheduled task; check back tomorrow.';
$string['instructor_dashboard:confusion_heading'] = 'Confusion heatmap';
$string['instructor_dashboard:confusion_empty'] = 'No per-module question data yet for this period.';
$string['instructor_dashboard:ratings_heading'] = 'Helpful / unhelpful rates';
$string['instructor_dashboard:ratings_summary'] = '{$a->positive} thumbs up, {$a->negative} thumbs down ({$a->pct}% positive). {$a->hallucinations} flagged as hallucinations.';
$string['instructor_dashboard:ratings_low_module'] = 'Low-rated assistant responses by module';
$string['instructor_dashboard:gap_heading'] = 'Engagement gap';
$string['instructor_dashboard:gap_summary'] = '{$a->not_seen} of {$a->enrolled} enrolled learners have not used the assistant in the last {$a->days} day(s).';
$string['instructor_dashboard:gap_show_sample'] = 'Show learners (sample)';
$string['instructor_dashboard:col_objective'] = 'Objective';
$string['instructor_dashboard:col_mastered'] = 'Mastered';
$string['instructor_dashboard:col_learning'] = 'In progress';
$string['instructor_dashboard:col_not_started'] = 'Not started';
$string['instructor_dashboard:col_attempts'] = 'Attempts';
$string['instructor_dashboard:col_module'] = 'Module';
$string['instructor_dashboard:col_questions'] = 'Questions asked';
$string['instructor_dashboard:col_distinct_learners'] = 'Distinct learners';
$string['instructor_dashboard:col_low_rated'] = 'Low-rated count';
$string['socratic:title'] = 'Socratic mode';
$string['socratic:toggle'] = 'Enable Socratic mode for this course';
$string['socratic:toggle_help'] = 'When on, the assistant leads with guiding questions instead of giving direct answers. Pedagogical lift; no UI change for learners. Off by default.';
$string['digest:title'] = 'Weekly digest emails';
$string['digest:toggle'] = 'Email a weekly digest to anyone with analytics access on this course';
$string['digest:toggle_help'] = 'Mondays 09:00 server time. Aggregate-only — no learner names appear in the email body. Off by default.';
$string['digest:subject'] = '{$a->product} weekly digest — {$a->course}';
$string['task:instructor_weekly_digest'] = 'AI Course Assistant weekly digest email';
$string['settings:math_render_heading'] = 'Math rendering';
$string['settings:math_render_heading_desc'] = 'Math expressions in assistant replies (LaTeX, e.g. $E=mc^2$) render via Moodle\'s built-in MathJax filter — enable filter_mathjaxloader in Site administration → Plugins → Filters → Manage filters for the math to render. Without it, expressions display as raw LaTeX text.';
$string['flashcards:title'] = 'Flashcards';
$string['flashcards:link'] = 'Flashcards (review now)';
$string['flashcards:intro'] = 'Spaced-repetition review. Reveal the answer, then self-grade with Again, Hard, or Easy. Cards you found hard come back sooner; cards you found easy spread out.';
$string['flashcards:question'] = 'Question';
$string['flashcards:answer'] = 'Answer';
$string['flashcards:reveal'] = 'Reveal answer';
$string['flashcards:again'] = 'Again';
$string['flashcards:hard'] = 'Hard';
$string['flashcards:easy'] = 'Easy';
$string['flashcards:no_due'] = 'Nothing to review right now. Generate flashcards from a course page in the assistant widget, or check back later.';
$string['flashcards:session_complete'] = 'Session complete. Good work.';
$string['flashcards:disabled'] = 'Flashcards are not enabled for this course.';
$string['flashcards:toggle'] = 'Enable flashcards for this course';
$string['flashcards:toggle_help'] = 'Adds a Generate-flashcards starter to the assistant widget and a learner review page at /local/ai_course_assistant/flashcards.php?courseid=X. Off by default.';
$string['flashcards:starter_generate'] = 'Generate flashcards from this page';
$string['flashcards:generated'] = 'Saved {$a} flashcards. Open the review page to study them.';
$string['worked_examples:toggle'] = 'Enable Worked Examples starter for this course';
$string['worked_examples:toggle_help'] = 'Adds a "Show me a worked example" starter that asks the assistant to walk through a fully solved example, then guide the learner through similar problems with progressively less scaffolding (worked → partial → blank).';
$string['worked_examples:starter'] = 'Show me a worked example';
$string['objectives:prereqs_label'] = 'prerequisites';
$string['objectives:prereqs_summary'] = 'Prerequisites: {$a}';
$string['objectives:prereqs_none'] = 'none yet — click to edit';
$string['essay_feedback:title'] = 'Essay feedback';
$string['essay_feedback:link'] = 'Essay feedback';
$string['essay_feedback:disabled'] = 'Essay feedback is not enabled for this course.';
$string['essay_feedback:intro'] = 'Paste your draft below and the assistant will score it against a rubric and suggest three concrete revisions. Aim for at least 80 words. Your essay text is only used for this feedback run — it is not saved.';
$string['essay_feedback:rubric_label'] = 'Rubric (optional)';
$string['essay_feedback:rubric_help'] = 'Paste a rubric as a bulleted list of criteria, or leave blank to use a default four-criterion rubric (thesis, evidence, organisation, mechanics).';
$string['essay_feedback:essay_label'] = 'Your essay draft';
$string['essay_feedback:submit'] = 'Get feedback';
$string['essay_feedback:scoring'] = 'Scoring your draft…';
$string['essay_feedback:too_short'] = 'Please paste at least 80 words so the assistant has something to score.';
$string['essay_feedback:error'] = 'Could not score this draft right now. Try again in a moment.';
$string['essay_feedback:result_heading'] = 'Rubric scores';
$string['essay_feedback:overall_heading'] = 'Overall';
$string['essay_feedback:revisions_heading'] = 'Top 3 revision suggestions';
$string['essay_feedback:col_criterion'] = 'Criterion';
$string['essay_feedback:col_score'] = 'Score';
$string['essay_feedback:col_feedback'] = 'Feedback';
$string['essay_feedback:toggle'] = 'Enable Essay feedback for this course';
$string['essay_feedback:toggle_help'] = 'Learners get a dedicated page to paste a draft and receive rubric-scored feedback with revision suggestions. Off by default.';
$string['sandbox:title'] = 'Python sandbox';
$string['sandbox:link'] = 'Python sandbox';
$string['sandbox:disabled'] = 'The Python sandbox is not enabled for this course.';
$string['sandbox:intro'] = 'Write and run Python entirely in your browser. The runtime is Pyodide (Python compiled to WebAssembly); your code never leaves this device. Use it to try ideas, work through course exercises, or check small scripts before submitting them.';
$string['sandbox:loading'] = 'Loading the Python runtime — this is a one-time download of about 10 MB. Future runs will be instant.';
$string['sandbox:ready'] = 'Ready. Write some code and click Run.';
$string['sandbox:load_error'] = 'Could not load the Python runtime. Check your network connection and refresh.';
$string['sandbox:code_label'] = 'Code';
$string['sandbox:run'] = 'Run';
$string['sandbox:running'] = 'Running…';
$string['sandbox:clear'] = 'Clear output';
$string['sandbox:output_heading'] = 'Output';
$string['sandbox:privacy_note'] = 'Code and output stay in your browser. Nothing is sent to any server. The runtime is loaded from a public CDN the first time only and is cached for subsequent visits.';
$string['sandbox:toggle'] = 'Enable the Python sandbox for this course';
$string['sandbox:toggle_help'] = 'Adds a learner-facing page where students can write and run Python entirely in their browser via Pyodide. Off by default. Enable for courses with code work; leave off for courses without.';

// v4.2: courses_admin page.
$string['courses_admin:title']             = 'AI Course Assistant — Courses';
$string['courses_admin:lede']              = 'Enable or disable AI Assistant per course, manage Usability Testing, or run bulk actions across many courses.';
$string['courses_admin:back_to_analytics'] = '← Back to Analytics';
$string['courses_admin:plugin_settings']   = 'Plugin Settings';
$string['courses_admin:enabled_count']     = '{$a->enabled} of {$a->total} courses have AI Assistant enabled';
$string['courses_admin:search_placeholder']= 'Search courses…';
$string['courses_admin:filter_status']     = 'AI Assistant status';
$string['courses_admin:filter_enabled']    = 'Enabled';
$string['courses_admin:filter_disabled']   = 'Disabled';
$string['courses_admin:filter_ut']         = 'Usability Testing';
$string['courses_admin:filter_ut_on']      = 'UT On';
$string['courses_admin:filter_ut_off']     = 'UT Off';
$string['courses_admin:filter_ut_inherit'] = 'UT Inherit';
$string['courses_admin:select_all']        = 'Select all';
$string['courses_admin:selected_zero']     = '(0 selected)';
$string['courses_admin:ai_assistant']      = 'AI Assistant';
$string['courses_admin:usability_testing'] = 'Usability Testing';
$string['courses_admin:enable']            = 'Enable';
$string['courses_admin:disable']           = 'Disable';
$string['courses_admin:inherit']           = 'Inherit';
$string['courses_admin:column_course']     = 'Course';
$string['courses_admin:column_has_data']   = 'Has Data';
$string['courses_admin:enabled']           = 'Enabled';
$string['courses_admin:disabled']          = 'Disabled';
$string['courses_admin:click_to_enable']   = 'Click to enable';
$string['courses_admin:click_to_disable']  = 'Click to disable';
$string['courses_admin:on']                = 'On';
$string['courses_admin:off']               = 'Off';
$string['courses_admin:global_on']         = 'Global: On';
$string['courses_admin:global_off']        = 'Global: Off';
$string['courses_admin:yes']               = 'Yes';
$string['courses_admin:no_courses']        = 'No visible courses on this site yet.';

// v4.2: anomaly digest scheduled task.
$string['task:run_anomaly_digest'] = 'Run SOLA anomaly digest';

// v4.2.3: external resources (admin + per-course).
$string['settings:external_resources_heading']      = 'External resources';
$string['settings:external_resources_heading_desc'] = 'Optional opt-in: when on, SOLA may include one or two links to reputable open educational resources alongside its course-grounded answer. Restricted to the allowlist below to keep recommendations defensible. Per-course override available on the course settings page. Default off.';
$string['settings:external_resources_enabled']      = 'Enable external resources (site-wide default)';
$string['settings:external_resources_enabled_desc'] = 'When on, SOLA may suggest links to the allowlisted external resources. Per-course "force on" / "force off" overrides this. Default off.';
$string['settings:external_resources_allowlist']    = 'External resources allowlist';
$string['settings:external_resources_allowlist_desc'] = 'One resource per line, in the format "Display Name (domain)". SOLA will only suggest links to these sites. Defaults to a small set of widely respected open-resource hosts; replace or extend as needed.';
$string['external_resources:title']      = 'External resources';
$string['external_resources:inherit']    = 'Inherit site default ({$a})';
$string['external_resources:force_on']   = 'Force on for this course';
$string['external_resources:force_off']  = 'Force off for this course';
$string['external_resources:on']         = 'on';
$string['external_resources:off']        = 'off';
$string['external_resources:toggle_help']= 'When on, SOLA may include up to two links to allowlisted open educational resources alongside its course-grounded answer. Course material always leads.';

// v4.3.0: real Redash push integration.
$string['settings:redash_base_url']           = 'Redash base URL';
$string['settings:redash_base_url_desc']      = 'Base URL of your Redash instance, e.g. https://redash.example.com. Required for the "Send to Redash" action in Learning Radar.';
$string['settings:redash_user_api_key']       = 'Redash user API key';
$string['settings:redash_user_api_key_desc']  = 'API key of a Redash user with permission to create queries against the chosen data source. Found under your Redash user profile. Different from the SOLA Redash API key (which controls inbound auth on redash_export.php).';
$string['settings:redash_data_source_id']     = 'Redash data source ID';
$string['settings:redash_data_source_id_desc']= 'Numeric id of the Redash JSON data source pointed at SOLA\'s redash_export.php. Visible in the Redash data source URL after saving.';

$string['instructor_dashboard:nav_back_course']  = '← Back to course';
$string['instructor_dashboard:nav_settings']     = 'AI Course Assistant settings';
$string['instructor_dashboard:nav_analytics']    = 'AI Course Assistant analytics';

// v4.4.0: course-page CSP setting.
$string['settings:csp_course_pages_mode']      = 'Course-page Content-Security-Policy';
$string['settings:csp_course_pages_mode_desc'] = 'Optional CSP header on course pages where the AI Course Assistant widget is active. <strong>Off</strong>: no header (default). <strong>Report-only</strong>: send <code>Content-Security-Policy-Report-Only</code> — browsers log violations but do not block. Useful for a one-week observation pass. <strong>Enforce</strong>: send <code>Content-Security-Policy</code> — browsers block off-allowlist iframe sources, fetches, and other risky loads. Helps contain the impact of arbitrary scripts pasted into Additional HTML site config (the IBL AI / Raison incident on 2026-04-29). Does not affect SOLA endpoints, which always send a stricter CSP.';
$string['settings:csp_mode_off']               = 'Off (no header on course pages)';
$string['settings:csp_mode_report_only']       = 'Report-only (log violations, do not block)';
$string['settings:csp_mode_enforce']           = 'Enforce (block off-allowlist loads)';

// v4.5.0: site-wide pedagogy defaults.
$string['settings:pedagogy_defaults_heading']      = 'Pedagogy defaults';
$string['settings:pedagogy_defaults_heading_desc'] = 'Site-wide default state for each pedagogy feature. Flip a feature on here and every course inherits it unless that course has an explicit override on its SOLA course settings page (force on / force off). On upgrade to v4.5.0, every per-course "force off" override that was set to the legacy default-off value of <code>0</code> is cleared so the new global defaults take effect cleanly. Default off — upgrades from v4.4.x are a no-op until an admin flips a feature on.';
$string['pedagogy:mastery']                = 'Mastery tracking on by default';
$string['pedagogy:mastery_desc']           = 'When on, every course inherits mastery tracking unless the course has its own override. Mastery requires curated learning objectives — courses without objectives fall back gracefully, no error.';
$string['pedagogy:socratic_mode']          = 'Socratic mode on by default';
$string['pedagogy:socratic_mode_desc']     = 'When on, SOLA leads with questions instead of direct answers in every course unless the course has its own override.';
$string['pedagogy:worked_examples']        = 'Worked examples starter on by default';
$string['pedagogy:worked_examples_desc']   = 'When on, the "Show me a worked example" conversation starter appears in every course unless the course has its own override.';
$string['pedagogy:flashcards']             = 'Flashcards on by default';
$string['pedagogy:flashcards_desc']        = 'When on, spaced-repetition flashcards are available in every course unless the course has its own override.';
$string['pedagogy:code_sandbox']           = 'Python code sandbox on by default';
$string['pedagogy:code_sandbox_desc']      = 'When on, the in-browser Python sandbox is available in every course unless the course has its own override.';
$string['pedagogy:essay_feedback']         = 'Essay feedback on by default';
$string['pedagogy:essay_feedback_desc']    = 'When on, AI essay feedback is available in every course unless the course has its own override.';
$string['pedagogy:per_course_inherit']     = 'Inherit site default ({$a})';
$string['pedagogy:per_course_force_on']    = 'Force on for this course';
$string['pedagogy:per_course_force_off']   = 'Force off for this course';
$string['pedagogy:on']                     = 'on';
$string['pedagogy:off']                    = 'off';

// v4.6.0: vendor DPA gating + override editors.
$string['settings:vendor_data_heading']      = 'Vendor & cost data';
$string['settings:vendor_data_heading_desc'] = 'Controls for the optional Vendor DPA admin page and the override editors that let admins keep the bundled vendor table and LLM rate card current without a code edit. Both override editors are JSON; an empty value falls back to the hardcoded defaults shipped with the plugin.';
$string['settings:vendor_dpa_admin_page_enabled']      = 'Show Vendor DPA admin page';
$string['settings:vendor_dpa_admin_page_enabled_desc'] = 'When on, "Vendor DPA Status" appears under Site administration → Plugins → Local plugins → AI Course Assistant. The page renders the vendor table merged with the override below. Default off — most admins do not need this surface.';
$string['settings:vendor_dpa_overrides']      = 'Vendor DPA overrides (JSON)';
$string['settings:vendor_dpa_overrides_desc'] = 'JSON object keyed by vendor id. Each value is an object whose fields override the hardcoded vendor row. Fields you do not specify fall through to the default. A new vendor key in the override is added to the table; edits apply per field. Malformed JSON is ignored at runtime — fix the parse error here when the saved value does not appear in the Vendor DPA page.';
$string['settings:rate_card_overrides']      = 'LLM rate card overrides (JSON)';
$string['settings:rate_card_overrides_desc'] = 'JSON object keyed by model name prefix. Each value is {"input": float, "output": float} in USD per 1,000,000 tokens. Replaces the bundled rate card entry for that prefix. A community-maintained source of vendor pricing JSON lives at github.com/BerriAI/litellm — multiply the input_cost_per_token / output_cost_per_token values by 1,000,000 to match this format. Auto-fetch from a configurable upstream URL is on the v4.7 roadmap.';

// v4.7.0: rate-card auto-refresh.
$string['settings:rate_card_auto_refresh']      = 'Auto-refresh from upstream';
$string['settings:rate_card_auto_refresh_desc'] = 'When on, a weekly scheduled task fetches the upstream pricing JSON, transforms it to SOLA\'s rate-card schema, and writes it to the override field above. Default on. Failures keep the previous override in place.';
$string['settings:rate_card_upstream_url']      = 'Upstream pricing URL';
$string['settings:rate_card_upstream_url_desc'] = 'URL of a JSON manifest in LiteLLM\'s schema. Default points at the community-maintained file in the LiteLLM GitHub repo. URL is checked against the SSRF allowlist before fetch.';
$string['settings:rate_card_refresh_now']        = 'Refresh now';
$string['settings:rate_card_refresh_now_label']  = 'Refresh rate card from upstream';
$string['settings:rate_card_refresh_success']    = 'Rate card refreshed: {$a} entries written.';
$string['settings:rate_card_refresh_error']      = 'Rate card refresh failed: {$a}';
$string['settings:rate_card_last_refresh_at']    = 'Last refresh: {$a}';
$string['settings:rate_card_last_refresh_success']= 'Last fetch succeeded.';
$string['settings:rate_card_never_refreshed']    = 'Never refreshed.';
$string['task:refresh_rate_card']                = 'Refresh SOLA LLM rate card from upstream';

// v4.8.0: runtime validators + RAG drift + needs-review queue.
$string['settings:validators_runtime_mode']      = 'Runtime validators';
$string['settings:validators_runtime_mode_desc'] = 'Apply the same pipeline that gates releases (PII echo, credential leak, hallucination, second-person) to every assistant response in real time. Off (default), Annotate, or Block.';
$string['settings:validators_runtime_off']       = 'Off (no runtime checks)';
$string['settings:validators_runtime_annotate']  = 'Annotate (append warning line on fail)';
$string['settings:validators_runtime_block']     = 'Block (replace with safe fallback on fail)';
$string['settings:rag_auto_reindex_drifted']      = 'Auto-reindex drifted RAG content';
$string['settings:rag_auto_reindex_drifted_desc'] = 'Daily scheduled task that re-indexes course modules whose source content was edited after the last indexed-at time. Default on.';
$string['task:auto_reindex_rag_drifted']          = 'Re-index drifted RAG content';
$string['instructor_dashboard:review_heading']     = 'Needs review';
$string['instructor_dashboard:review_intro']       = 'Pending items from this course that an instructor or course designer should look at: thumbs-down ratings, off-topic conversations, and integrity flags.';
$string['instructor_dashboard:review_empty']       = 'No items pending review. Course is clean.';
$string['instructor_dashboard:review_col_when']    = 'When';
$string['instructor_dashboard:review_col_source']  = 'Source';
$string['instructor_dashboard:review_col_who']     = 'Who';
$string['instructor_dashboard:review_col_summary'] = 'Summary';
$string['instructor_dashboard:review_resolve']     = 'Mark resolved';
$string['instructor_dashboard:review_resolved']    = 'Marked resolved.';
$string['instructor_dashboard:review_source_rating']    = 'Negative rating';
$string['instructor_dashboard:review_source_offtopic']  = 'Off-topic';
$string['instructor_dashboard:review_source_integrity'] = 'Integrity';

// v4.8.1: talking avatar (placeholder, default off, provider-neutral).
$string['pedagogy:talking_avatar']         = 'Talking avatar on by default';
$string['pedagogy:talking_avatar_desc']    = 'When on, the talking-avatar surface is enabled in every course unless the course has its own override. Requires a configured provider (D-ID, HeyGen, Tavus, or Synthesia Agents) below; otherwise the widget shows a "configure a provider" notice and the avatar does not animate.';
$string['settings:talking_avatar_heading']      = 'Talking avatar';
$string['settings:talking_avatar_heading_desc'] = 'Pick which talking-avatar vendor SOLA opens for students when the avatar surface is enabled. SOLA ships drivers for D-ID (cheapest WebRTC streaming), HeyGen (LiveKit-backed interactive avatars), Tavus (drop-in iframable Conversational Video Interface), and Synthesia Agents (real-time agent product, configured in the Synthesia dashboard). Per-provider key + persona id appear below; only the chosen provider needs to be filled in. Every outbound call is SSRF-checked.';
$string['settings:talking_avatar_provider_url']      = 'Provider API base URL (legacy)';
$string['settings:talking_avatar_provider_url_desc'] = 'v4.8.1 placeholder, kept for upgrade safety. The active drivers in v4.9.0 read their own per-provider settings; this field is only used as a fallback when an admin upgraded mid-release.';
$string['settings:talking_avatar_provider_api_key']      = 'Provider API key (legacy)';
$string['settings:talking_avatar_provider_api_key_desc'] = 'v4.8.1 placeholder, kept for upgrade safety. The active drivers in v4.9.0 read their own per-provider settings; this field is only used as a fallback when an admin upgraded mid-release.';
$string['settings:talking_avatar_provider']      = 'Talking avatar provider';
$string['settings:talking_avatar_provider_desc'] = 'Pick the vendor whose key + persona id are filled in below. Leave as <em>None</em> until the institution has signed off; the pedagogy default still appears in <em>Pedagogy defaults</em> but the widget shows a configuration notice instead of an avatar.';
$string['settings:talking_avatar_provider_none']      = 'None (avatar disabled)';
$string['settings:talking_avatar_provider_did']       = 'D-ID Streaming Talks';
$string['settings:talking_avatar_provider_heygen']    = 'HeyGen Interactive Avatar';
$string['settings:talking_avatar_provider_tavus']     = 'Tavus Conversational Video';
$string['settings:talking_avatar_provider_synthesia'] = 'Synthesia Agents (real-time)';
$string['settings:talking_avatar_did_api_key']         = 'D-ID API key';
$string['settings:talking_avatar_did_api_key_desc']    = 'Base64-encoded <code>email:api-key</code> string from <a href="https://studio.d-id.com/account-settings" target="_blank" rel="noopener">D-ID Studio → Account → API keys</a>. Sent as <code>Authorization: Basic …</code>.';
$string['settings:talking_avatar_did_persona_id']      = 'D-ID source image URL';
$string['settings:talking_avatar_did_persona_id_desc'] = 'Public HTTPS URL of the still image D-ID animates (or a Studio presenter URL such as <code>https://create-images-results.d-id.com/…</code>). Required for every stream.';
$string['settings:talking_avatar_heygen_api_key']         = 'HeyGen API key';
$string['settings:talking_avatar_heygen_api_key_desc']    = 'API key from <a href="https://app.heygen.com/settings?nav=API" target="_blank" rel="noopener">HeyGen → Settings → API</a>. Sent as <code>X-Api-Key</code>.';
$string['settings:talking_avatar_heygen_persona_id']      = 'HeyGen interactive avatar id';
$string['settings:talking_avatar_heygen_persona_id_desc'] = 'Avatar id from the HeyGen Streaming Avatar dashboard (e.g. <code>Tyler-incasualsuit-20220721</code>).';
$string['settings:talking_avatar_tavus_api_key']         = 'Tavus API key';
$string['settings:talking_avatar_tavus_api_key_desc']    = 'API key from <a href="https://platform.tavus.io/api-keys" target="_blank" rel="noopener">Tavus platform → API keys</a>. Sent as <code>x-api-key</code>.';
$string['settings:talking_avatar_tavus_persona_id']      = 'Tavus replica id';
$string['settings:talking_avatar_tavus_persona_id_desc'] = 'Replica id (the trained likeness) you want SOLA to converse as. Combine with a persona id by appending it to the API key field if needed; SOLA will pass <code>persona_id</code> through.';
$string['settings:talking_avatar_synthesia_api_key']         = 'Synthesia API key';
$string['settings:talking_avatar_synthesia_api_key_desc']    = 'API key from <a href="https://app.synthesia.io/#/account/api" target="_blank" rel="noopener">Synthesia → Account → API</a>. Sent as <code>Authorization</code> header (Synthesia accepts the raw key).';
$string['settings:talking_avatar_synthesia_persona_id']      = 'Synthesia agent id';
$string['settings:talking_avatar_synthesia_persona_id_desc'] = 'Agent id created in the Synthesia Agents dashboard. Knowledge, persona, and allowed origins are configured agent-side; SOLA only opens a session against this id.';
$string['talking_avatar:disabled']        = 'Talking avatar is not enabled for this course.';
$string['talking_avatar:unconfigured']    = 'Talking avatar is enabled but no provider has been configured. An administrator must pick a provider and supply credentials in plugin settings.';
$string['talking_avatar:session_failed']  = 'The talking-avatar provider declined the session request. Check the provider configuration or try again in a moment.';
$string['talking_avatar:viewer_title']    = 'SOLA talking avatar';
$string['talking_avatar:bundle_required'] = 'The talking-avatar viewer requires the SOLA CDN bundle to be configured. Ask an administrator to set the CDN bundle URL in plugin settings.';
$string['talking_avatar:open']            = 'Open avatar';
$string['talking_avatar:close']           = 'Close avatar';
$string['settings:avatar_rate_card_overrides']      = 'Avatar rate card overrides (JSON)';
$string['settings:avatar_rate_card_overrides_desc'] = 'JSON object keyed by avatar provider with a single per-minute USD rate. Replaces the bundled rate for that provider. Example: <pre>{ "did": 0.18, "heygen": 0.40, "tavus": 0.25 }</pre> Empty = use the v4.10.0 bundled defaults: D-ID $0.30/min, HeyGen $0.50/min, Tavus $0.30/min, Synthesia $0.40/min. Set this to your contracted rate so the analytics dashboard reflects the institution\'s actual cost.';
$string['settings:talking_avatar_did_webhook_secret']         = 'D-ID webhook signing secret';
$string['settings:talking_avatar_did_webhook_secret_desc']    = 'Optional. When set, D-ID can POST session-end events to <code>{wwwroot}/local/ai_course_assistant/talking_avatar_webhook.php?provider=did</code> signed with this secret as <code>X-DID-Signature</code> (hex HMAC-SHA256). Webhook rows take precedence over the frontend heartbeat. Empty = handler off, the heartbeat + hourly sweeper handle session-end exclusively.';
$string['settings:talking_avatar_heygen_webhook_secret']      = 'HeyGen webhook signing secret';
$string['settings:talking_avatar_heygen_webhook_secret_desc'] = 'Optional. When set, HeyGen can POST session-end events signed with this secret as <code>X-HeyGen-Signature</code> (hex HMAC-SHA256). Empty = handler off.';
$string['settings:talking_avatar_tavus_webhook_secret']       = 'Tavus webhook signing secret';
$string['settings:talking_avatar_tavus_webhook_secret_desc']  = 'Optional. When set, Tavus can POST conversation-end events signed with this secret as <code>X-Tavus-Signature</code> (hex HMAC-SHA256). Empty = handler off.';
$string['settings:talking_avatar_synthesia_webhook_secret']   = 'Synthesia webhook signing secret';
$string['settings:talking_avatar_synthesia_webhook_secret_desc'] = 'Optional. When set, Synthesia can POST agent session-end events signed with this secret as <code>X-Synthesia-Signature</code> (hex HMAC-SHA256). Empty = handler off.';
$string['analytics:avatar_cost_heading']     = 'Talking-avatar usage';
$string['analytics:avatar_cost_provider']    = 'Provider';
$string['analytics:avatar_cost_sessions']    = 'Sessions';
$string['analytics:avatar_cost_minutes']     = 'Minutes';
$string['analytics:avatar_cost_rate']        = 'Per-minute rate';
$string['analytics:avatar_cost_total']       = 'Estimated total';
$string['analytics:avatar_cost_empty']       = 'No talking-avatar sessions in the selected period.';
$string['task:sweep_avatar_sessions']        = 'Close stale talking-avatar sessions';
$string['settings:prompt_debug_enabled']      = 'Log assembled system prompt to file';
$string['settings:prompt_debug_enabled_desc'] = 'When on, every chat-turn writes the full assembled system prompt and per-section character counts to <code>moodledata/temp/sola_prompt_debug.log</code> (rolling at ~1MB). Default off. Use to measure prompt size empirically and audit which sections contribute the most tokens. The log contains the system prompt only (no learner input or PII).';
$string['settings:socratic_verbose']      = 'Verbose Socratic mode prompt';
$string['settings:socratic_verbose_desc'] = 'When on, Socratic-mode courses receive the full ~600-token do/don\'t directive originally added in v3.9.30. When off (default), they receive a single-line directive that modern hosted models follow reliably and saves ~600 tokens per turn. Turn this on if a course is running on a weaker self-hosted model that needs the explicit scaffolding.';
$string['settings:prompt_budget_chars']      = 'System prompt character budget';
$string['settings:prompt_budget_chars_desc'] = 'Maximum total size of the assembled system prompt before the user message, in characters. The structured prompt builder organises sections by category (identity, course context, learner state, behaviour, markers, safety) and drops or truncates the lowest-priority sections when the budget is exceeded. Safety guidance is always preserved in full. Default 8,000 characters (~2,000 tokens). Lower values reduce per-turn cost; higher values allow more course content to land in-prompt.';
$string['settings:current_page_content_maxchars']      = 'Current page content cap (characters)';
$string['settings:current_page_content_maxchars_desc'] = 'Maksimalt antall tegn fra den gjeldende sidens tekst som settes inn i systemforespørselen som "Current Page Content"-seksjonen, når RAG er av. Standardverdien 8,000 forankrer sidespesifikke spørsmål godt samtidig som den lar det være budsjett til struktur og instruksjoner. (Med RAG aktivert forankres siden i stedet av sine egne mest relevante tekstbiter, vektet mot gjeldende side, så denne grensen gjelder ikke.) En svært lang side avkortes fra starten til dette antallet tegn, så halen av en ekstremt lang side blir kanskje ikke sitert; å aktivere RAG unngår dette. Kostnadsbevisste nettsteder kan begrense den lavere (f.eks. 3,000-4,000). Begrenset til området 500-8,000. Uavhengig av <code>prompt_budget_chars</code>: denne begrenser bare sideseksjonen; budsjettet begrenser hele forespørselen.';
$string['settings:prompt_verbosity']      = 'Prompt verbosity';
$string['settings:prompt_verbosity_desc'] = 'Default verbosity for instruction blocks (Socratic mode, external resources). Concise (default) is what modern hosted models follow reliably; standard adds explicit scaffolding for mid-tier models; verbose keeps the heavyweight v3.9.30-era guidance for weaker self-hosted models. Per-course override available via <code>prompt_verbosity_course_&lt;id&gt;</code>.';
$string['settings:prompt_verbosity_concise']  = 'Concise (recommended for hosted models)';
$string['settings:prompt_verbosity_standard'] = 'Standard';
$string['settings:prompt_verbosity_verbose']  = 'Verbose (for weaker self-hosted models)';
$string['settings:prompt_metrics_enabled']      = 'Capture per-section prompt metrics';
$string['settings:prompt_metrics_enabled_desc'] = 'When on (default), every chat turn writes one JSON line per assembled prompt to <code>moodledata/sola_prompt_metrics/YYYY-MM-DD.log</code> with per-category char counts. Last 7 days kept. The metrics admin page aggregates these for the budget recommendation. No PII is recorded — only section sizes. Turn off if your institution prefers no metrics file at all.';
$string['settings:prompt_budget_auto_tune']      = 'Auto-tune system prompt budget daily';
$string['settings:prompt_budget_auto_tune_desc'] = 'When on, a daily cron task (03:20 server time) applies the budget recommendation surfaced on the <a href="/local/ai_course_assistant/prompt_metrics.php">Prompt metrics</a> admin page. Default off — the recommendation always shows on the page; auto-apply only fires when the institution opts in. Manual "Apply recommendation" button is unaffected by this toggle.';
$string['task:auto_tune_prompt_budget']          = 'Auto-tune SOLA prompt budget from observed metrics';
$string['prompt_metrics:title']                  = 'Prompt metrics + budget recommendation';
$string['prompt_metrics:subtitle']               = 'Per-section prompt sizes captured over the last 7 days. Used to recommend a value for the System prompt character budget setting.';
$string['prompt_metrics:no_data']                = 'No prompt metrics recorded yet. Send a few chat turns from a learner account, then refresh this page. (If the metrics capture flag is off in plugin settings, no data will accumulate.)';
$string['prompt_metrics:headline']               = 'Headline';
$string['prompt_metrics:samples']                = 'Samples (chat turns over last 7 days)';
$string['prompt_metrics:avg_total']              = 'Average total prompt size';
$string['prompt_metrics:max_total']              = 'Maximum prompt size observed';
$string['prompt_metrics:avg_budget']             = 'Budget at time of capture';
$string['prompt_metrics:pct_truncated']          = 'Turns where any section was truncated';
$string['prompt_metrics:pct_dropped']            = 'Turns where any section was dropped';
$string['prompt_metrics:last_seen']              = 'Most recent sample';
$string['prompt_metrics:by_category']            = 'Average chars per category';
$string['prompt_metrics:category']               = 'Category';
$string['prompt_metrics:avg_chars']              = 'Avg chars';
$string['prompt_metrics:recommendation']         = 'Budget recommendation';
$string['prompt_metrics:rec_insufficient_data']  = 'Need at least 30 chat turns of data to make a confident recommendation. Keep collecting samples and check back.';
$string['prompt_metrics:rec_optimal']            = 'Current budget looks well-tuned for the observed traffic. No change recommended.';
$string['prompt_metrics:current_budget']         = 'Current budget';
$string['prompt_metrics:recommended']            = 'Recommended budget';
$string['prompt_metrics:apply']                  = 'Apply recommendation';
$string['prompt_metrics:applied']                = 'Applied: budget changed from {$a->old} to {$a->new}. {$a->reason}';
$string['prompt_metrics:noop']                   = 'No change applied: {$a}';
$string['prompt_metrics:auto_tune_heading']      = 'Daily auto-tune';
$string['prompt_metrics:auto_tune_on']           = 'Daily auto-tune is ON. The recommendation will be applied automatically every night at 03:20 server time.';
$string['prompt_metrics:auto_tune_off']          = 'Daily auto-tune is OFF. The recommendation is shown here for review; nothing is applied automatically. Toggle on in plugin settings if you want unattended daily tuning.';
$string['prompt_metrics:settings_link']          = 'Open plugin settings to toggle auto-tune.';

// v5.3.0: Empathetic communications + carryover memory (English fallback).
$string['task:milestone_check'] = 'Send daily milestone reflection emails (v5.3.0)';
$string['task:struggle_signal_review'] = 'Review struggle signals into private learner memory (v5.3.0)';
$string['empathy:title'] = 'Empathetic communications and carryover memory (v5.3.0)';
$string['empathy:desc'] = 'Three coordinated features that make SOLA feel more like a coach who listens. Goals capture why the learner is here. Carryover memory remembers what has been hard before so SOLA can offer a different angle. Milestones celebrate streaks and completions by email. Each feature has an independent kill switch and learner opt-in. Struggle signals never leave the chat — no email is ever sent about a difficult session.';
$string['empathy:outreach_master_enabled'] = 'Master outreach kill switch';
$string['empathy:outreach_master_enabled_desc'] = 'Off by default on a fresh install. When off, NO empathetic email of any kind ever fires, regardless of the per-feature switches below. Turn this on once you have reviewed the per-feature defaults and per-learner consent flow.';
$string['empathy:goals_enabled'] = 'Enable career goal conversations';
$string['empathy:goals_enabled_desc'] = 'Lets learners volunteer two short answers (why they are here, what they want to become) that feed personalisation. In-chat only; no emails. Safe to leave on.';
$string['empathy:milestones_enabled'] = 'Enable milestone reflection emails';
$string['empathy:milestones_enabled_desc'] = 'Sends a short warm email when a learner reaches a 7-day streak, 30-day streak, or course completion. Requires the master switch above plus per-learner consent. Hard cap of one email per learner per 7 days across all channels.';
$string['empathy:memory_enabled'] = 'Enable carryover personalisation memory';
$string['empathy:memory_enabled_desc'] = 'Lets SOLA carry small private notes about what has been hard for a learner across sessions, so the next reply can offer a different angle. Bounded (max 5 notes per learner per course, 90-day TTL). Learner-editable. Never visible to instructors.';
$string['empathy:struggle_enabled'] = 'Enable struggle classifier';
$string['empathy:struggle_enabled_desc'] = 'Off by default. Lets SOLA detect sustained frustration over multiple turns and quietly record a sticking-point note in the carryover memory above. Output is in-chat only; no email is ever sent about a struggle session. Auto-purges signal data after 7 days.';
$string['empathy:outreach_dryrun'] = 'Dry-run outreach (log without sending)';
$string['empathy:outreach_dryrun_desc'] = 'When on, the milestone scheduled task records audit rows as if it sent emails but does not actually email anyone. Use this on a fresh install to verify the cooldown and consent logic before going live.';
$string['goals:starter_title'] = 'Set my learning goals';
$string['goals:starter_intro'] = "Mind sharing why you are taking this course? It helps me give you better answers.";
$string['goals:q1_label'] = 'What brought you to this course?';
$string['goals:q2_label'] = "What's the bigger thing this is helping you toward? A degree, a job, a project of your own, something else?";
$string['goals:q3_label'] = 'Anything I should keep in mind while we work together?';
$string['goals:save'] = 'Save my goals';
$string['goals:dismiss'] = 'Not now';
$string['goals:edit'] = 'Edit goals';
$string['goals:clear'] = 'Clear my goals';
$string['goals:cleared'] = 'Your goals have been cleared.';
$string['goals:saved'] = 'Thanks for sharing.';
$string['comms:title'] = 'My communications';
$string['comms:desc'] = 'Choose which automated emails SOLA may send you. Off by default. You can change this any time.';
$string['comms:milestones_label'] = 'Email me when I reach a milestone (7-day streak, 30-day streak, course completion).';
$string['comms:audit_log_title'] = 'What SOLA has sent me';
$string['comms:audit_log_empty'] = 'SOLA has not sent you any emails.';
$string['comms:memory_title'] = "What SOLA has remembered about how I learn";
$string['comms:memory_desc'] = 'These notes are private to your chat with SOLA. They help SOLA pick a different angle when a topic is hard. Clear any time.';
$string['comms:memory_clear'] = 'Clear all memory notes';
$string['milestone:streak_subject'] = '{$a->days}-day streak in {$a->coursename}';
$string['milestone:streak_body_text'] = "Hi {\$a->firstname},\n\nYou have shown up {\$a->days} days in a row in {\$a->coursename}. That kind of consistency is the part of learning that is hardest to fake.\n\nWhenever you are ready, SOLA is here.\n\n— {\$a->institution}";
$string['milestone:completion_subject'] = 'You finished {$a->coursename}';
$string['milestone:completion_body_text'] = "Hi {\$a->firstname},\n\nYou finished {\$a->coursename}. That is a real thing you did.\n\nIf you want to keep going, SOLA can help you pick a related next course or revisit a topic you found interesting.\n\n— {\$a->institution}";
$string['milestone:trigger_streak7'] = '7-day activity streak reached';
$string['milestone:trigger_streak30'] = '30-day activity streak reached';
$string['milestone:trigger_completion'] = 'Course completion recorded';
// v5.3.17: privacy metadata strings for tables added in v5.0–v5.3 that
// had not been declared in the privacy provider. Each table requires
// at least one summary string and one per-userid-field string so the
// core_privacy table-coverage test passes.
$string['privacy:metadata:msg_ratings'] = 'Stores per-message thumbs-up/down ratings.';
$string['privacy:metadata:msg_ratings:userid'] = 'The user who rated the message.';
$string['privacy:metadata:profiles'] = 'Generated learner profiles used to personalise replies.';
$string['privacy:metadata:profiles:userid'] = 'The user the profile describes.';
$string['privacy:metadata:obj_att'] = 'Per-learner mastery attempts on course objectives.';
$string['privacy:metadata:obj_att:userid'] = 'The learner whose attempt is recorded.';
$string['privacy:metadata:flashcards'] = 'Per-learner flashcard state.';
$string['privacy:metadata:flashcards:userid'] = 'The learner the flashcards belong to.';
$string['privacy:metadata:review_res'] = 'Audit resolution log for needs-review queue items.';
$string['privacy:metadata:review_res:resolved_by'] = 'The reviewer who resolved the entry.';
$string['privacy:metadata:radar_sched'] = 'Scheduled Learning Radar deliveries.';
$string['privacy:metadata:radar_sched:creator'] = 'The user who created the schedule.';
$string['privacy:metadata:avatar_sess'] = 'Talking-avatar streaming session log.';
$string['privacy:metadata:avatar_sess:userid'] = 'The learner whose session was logged.';
$string['privacy:metadata:learner_goals'] = 'Volunteered learner goals (why-here, what-becoming).';
$string['privacy:metadata:learner_goals:userid'] = 'The learner who provided the goals.';
$string['privacy:metadata:learner_memory'] = 'Bounded carryover personalisation notes.';
$string['privacy:metadata:learner_memory:userid'] = 'The learner the memory describes.';
$string['privacy:metadata:streak'] = 'Per-learner activity streak counter.';
$string['privacy:metadata:streak:userid'] = 'The learner whose streak is tracked.';
$string['privacy:metadata:struggle_signal'] = 'Auto-purged struggle classifier signals (private to chat).';
$string['privacy:metadata:struggle_signal:userid'] = 'The learner whose chat session was scored.';
$string['privacy:metadata:outreach_log'] = 'Audit log of empathetic outreach emails.';
$string['privacy:metadata:outreach_log:userid'] = 'The learner the outreach was sent to.';

$string['messageprovider:study_reminder'] = 'Study reminders';

// v5.3.19: error strings caught missing by lang_completeness_test.
$string['attachment:error_provider_no_images'] = 'The current AI provider does not support image attachments. Please remove the attachment and try again.';
$string['attachment:error_disabled'] = 'Attachment uploads are currently disabled by your administrator.';
$string['attachment:error_no_file'] = 'No file was attached to your message.';
$string['attachment:error_upload_failed'] = 'The file could not be uploaded. Please try again.';
$string['attachment:error_too_large'] = 'The file is larger than the maximum allowed size.';
$string['attachment:error_type'] = 'This file type is not allowed. Please attach an image or a PDF.';
$string['attachment:error_save_failed'] = 'The file was uploaded but could not be saved. Please try again.';

// Cross-course mastery rollup (v5.7.0).
$string['pedagogy:crossmastery'] = 'Sammenstilling av mestring på tvers av kurs på som standard';
$string['pedagogy:crossmastery_desc'] = 'Når dette er på, gjenkjenner SOLA når en deltaker allerede har mestret et læringsmål i et annet kurs (matchet på kompetansereferanse eller tittel), og anerkjenner den tidligere kompetansen i stedet for å øve den inn på nytt. Krever mestringssporing; kurs uten læringsmål håndteres på en smidig måte. Kun veiledende – det endrer aldri en deltakers lagrede mestringsscore i noe kurs.';
$string['pedagogy:mastery_starter'] = 'Mestringsbevisst samtalestarter på som standard';
$string['pedagogy:mastery_starter_desc'] = 'Når dette er på, blir samtalestarteren «Hva bør jeg fokusere på?» personlig tilpasset slik at den navngir deltakerens svakeste læringsmål (og eventuell kompetanse som allerede er mestret andre steder). Krever mestringssporing; faller tilbake til den generiske starteren når det ennå ikke finnes mestringsdata.';
$string['task:rebuild_objective_links'] = 'Bygg om læringsmålkoblinger på tvers av kurs for mestringssammenstilling (v5.7.0)';
$string['mastery_starter:practice_label'] = 'Øv: {$a}';
$string['objectives:rebuild_links_heading'] = 'Mestringskoblinger på tvers av kurs';
$string['objectives:rebuild_links_help'] = 'SOLA kobler sammen læringsmål som samsvarer på tvers av kurs (etter kompetansereferanse eller tittel), slik at en deltaker som har mestret et tema andre steder, ikke øver det inn på nytt. Koblingene bygges om automatisk hver natt; bruk denne knappen for å bygge om nå etter at du har redigert læringsmål.';
$string['objectives:rebuild_links_button'] = 'Bygg om koblinger nå';
$string['objectives:rebuild_links_done'] = 'Mestringskoblinger på tvers av kurs ble bygd om: {$a->total} totalt ({$a->ref} etter referanse, {$a->exact} nøyaktig tittel, {$a->fuzzy} omtrentlig tittel).';

// Forward learning-path awareness (v5.8.0).
$string['pedagogy:program_path'] = 'Bevissthet om videre læringssti på som standard';
$string['pedagogy:program_path_desc'] = 'Når dette er på, kan SOLA fortelle en student hvor det gjeldende kurset leder videre i programmet (grad eller sertifikat) og hvordan dagens begreper bygger bro til senere kurs. Leser Moodle Programs-tillegget (Degrees og Learn) og navngir et bestemt neste kurs bare der programmet definerer en forkunnskap eller en påkrevd rekkefølge; ellers angir det studentens plassering i stien. Kun veiledende — det endrer aldri påmelding eller mestring, og bruker alltid bare den gjeldende studentens egen programtildeling. Gjør stille ingenting der ingen program gjelder.';

// Learning path map + next-course nudge (v5.9.0).
$string['pedagogy:learning_path'] = 'Kart over læringssti og nudge til neste kurs på som standard';
$string['pedagogy:learning_path_desc'] = 'Når dette er på, legger SOLA til et visuelt læringssti-panel (en "min sti"-knapp i widget-toppen) som viser deltakerens program som en rekke kurs, der hvert kurs kan utvides for å vise målene og deltakerens mestring. Når deltakeren har nådd kravet for det gjeldende kurset (fullføring eller en høy andel mestrede mål), viser SOLA også et vennlig "klar for neste kurs"-banner og nevner det i samtalen. Kun veiledende; bruker deltakerens egen programtildeling; gjør stille ingenting der ingen program gjelder.';
$string['settings:learning_path_mastery_threshold'] = 'Terskel for læringssti-beredskap (%)';
$string['settings:learning_path_mastery_threshold_desc'] = 'Prosentandel av et kurs sine sporede mål en deltaker må mestre før læringssti-nudgen behandler dem som klare for neste kurs. Moodle-kursfullføring er den andre utløseren; det som inntreffer først, utløser nudgen. Standard 80.';
$string['pathpanel_title'] = 'Min læringssti';
$string['pathpanel_open'] = 'Min læringssti';
$string['pathpanel_empty'] = 'Ingen programsti er tilgjengelig for dette kurset ennå.';
$string['path_position'] = 'Kurs {$a->index} av {$a->total}';
$string['path_status_done'] = 'Fullført';
$string['path_status_current'] = 'Du er her';
$string['path_status_upcoming'] = 'Kommende';
$string['path_mastery_mastered'] = 'Mestret';
$string['path_mastery_in_progress'] = 'Pågår';
$string['path_mastery_not_started'] = 'Ikke startet';
$string['path_mastery_demonstrated_elsewhere'] = 'Vist i et annet kurs';
$string['nudge_ready_title'] = 'Klar til å gå videre';
$string['nudge_ready_body'] = 'Bra jobba — du er klar for {$a}.';
$string['nudge_view_path'] = 'Vis min sti';
$string['nudge_dismiss'] = 'Lukk';

// v5.10.x strings (token-aware budgeting, backend retry, self-test, deployment presets, escalation consent, privacy).
$string['settings:backend_context_tokens'] = 'Backend-kontekstvindu (tokens)';
$string['settings:backend_context_tokens_desc'] = 'Den maksimale kontekstlengden (max_model_len) til AI-backenden din, i tokens. Sett til 0 for vertsbaserte modeller med et stort vindu (ingen klemming). Når den settes over 0 (for eksempel 8192 på en selvhostet vLLM-backend), krymper SOLA tegnbudsjettet for systemledeteksten ovenfor slik at ledeteksten pluss reservert utdata og samtalehistorikk får plass i vinduet, selv i tokentette språk. Se wiki-siden Deployment Sizing for hvordan dette tilordnes samtidige brukere.';
$string['settings:backend_retry_attempts'] = 'Backend-gjenforsøk';
$string['settings:backend_retry_attempts_desc'] = 'Hvor mange ganger en forbigående backend-feil (HTTP 429 eller 503) skal forsøkes på nytt før studenten vises en feil. Gjenforsøk skjer bare før noen responstekst er strømmet, så utdata blir aldri duplisert. Rettet mot små selvhostede backender som avviser forespørsler under belastning. Sett til 0 for å deaktivere. Standard 2.';
$string['settings:backend_retry_max_wait'] = 'Maksimal ventetid for backend-gjenforsøk (sekunder)';
$string['settings:backend_retry_max_wait_desc'] = 'Øvre grense, i sekunder, for hvor lenge en Retry-After-header fra backenden skal etterleves før et nytt forsøk. Når backenden ikke sender noen Retry-After, bruker SOLA i stedet en kort eksponentiell tilbaketrekking. Standard 5.';
$string['prompt:truncation_hint'] = 'MERK: Hele kursinnholdet kunne ikke søkes gjennom denne gangen på grunn av lengdebegrensninger. Hvis studenten spør om noe du ikke finner i det oppgitte innholdet, si at du ikke kunne søke gjennom hele kurset og foreslå at de åpner den spesifikke siden eller aktiviteten der emnet er dekket, i stedet for å påstå at det mangler i kurset.';
$string['selftest:title'] = 'Backend-selvtest';
$string['selftest:intro'] = 'Kjør en sanntidssjekk av den konfigurerte AI-backenden din: en liten chat-tur-retur, automatisk gjenkjenning av kontekstvinduet (max_model_len) og en sammenligning mot innstillingen for backend-kontekstvindu, gulvet for systemledetekstbudsjettet, og (når RAG er på) en innbyggings-tur-retur. Nettverkskall kjøres bare når du trykker Kjør.';
$string['selftest:run'] = 'Kjør backend-selvtest';
$string['selftest:check'] = 'Sjekk';
$string['selftest:status'] = 'Status';
$string['selftest:detail'] = 'Detalj';
$string['selftest:link'] = 'Side for backend-selvtest';
$string['selftest:link_desc'] = 'Åpne siden <a href="{$a}">Backend-selvtest</a> for å bekrefte at AI-backenden din fungerer og er riktig dimensjonert. Nyttig rett etter at du har konfigurert en selvhostet backend.';
$string['profile:title'] = 'Distribusjonsforhåndsinnstillinger';
$string['profile:intro'] = 'Bruk en anbefalt pakke med innstillinger for distribusjonstypen din. Verdiene skrives inn i de vanlige plugin-innstillingene og forblir individuelt redigerbare etterpå. Å bruke en forhåndsinnstilling overskriver de oppførte innstillingene.';
$string['profile:current'] = 'Sist anvendte forhåndsinnstilling: {$a}';
$string['profile:setting'] = 'Innstilling';
$string['profile:value'] = 'Verdi';
$string['profile:self_hosted_small'] = 'Selvhostet liten kontekst (enkelt GPU, f.eks. A30 24GB / vLLM på 8K)';
$string['profile:hosted_large'] = 'Vertsbasert stor kontekst (standard)';
$string['profile:apply_self_hosted_small'] = 'Bruk forhåndsinnstilling for selvhostet liten kontekst';
$string['profile:apply_hosted_large'] = 'Bruk standarder for vertsbasert stor kontekst';
$string['profile:applied'] = 'Brukte {$a}-forhåndsinnstillingen. Verdiene er nå i plugin-innstillingene dine.';
$string['profile:unknown'] = 'Ukjent distribusjonsforhåndsinnstilling.';
$string['profile:link'] = 'Side for distribusjonsforhåndsinnstillinger';
$string['profile:link_desc'] = 'Åpne siden <a href="{$a}">Distribusjonsforhåndsinnstillinger</a> for å bruke en anbefalt pakke med innstillinger for en vertsbasert eller selvhostet backend.';
$string['settings:zendesk_require_consent'] = 'Krev samtykke før eskalering til kundestøtte';
$string['settings:zendesk_require_consent_desc'] = 'Når den er på (anbefalt), eskalerer SOLA en samtale til Zendesk-kundestøtten først etter at den lærende har akseptert samtykkevarselet ved første kjøring, som opplyser om at å be om menneskelig hjelp deler samtalen (inkludert navn og e-post) med kundestøtte. Slå dette av bare hvis du innhenter samtykket på en annen måte; med det av sendes eskaleringer umiddelbart. Har ingen effekt med mindre Zendesk-eskalering er aktivert.';
$string['chat:escalation_needs_consent'] = 'Det ser ut til at dette trenger et medlem av kundestøtteteamet vårt. For å sende det videre til dem måtte jeg dele denne samtalen, inkludert navnet og e-postadressen din, med kundestøtten. Du har ikke samtykket til det ennå, så jeg har ikke sendt noe. Hvis du ønsker menneskelig hjelp, vennligst aksepter datadelingsvarselet for denne assistenten og spør igjen, eller kontakt kundestøtten direkte.';
$string['privacy:metadata:email_optout'] = 'Innstillinger for e-postreservasjon per mottaker (hvilke e-posttyper en mottaker har meldt seg av).';
$string['privacy:metadata:email_optout:email'] = 'Mottakerens e-postadresse som reservasjonen gjelder for.';
$string['privacy:metadata:email_optout:optout_type'] = 'E-posttypen mottakeren har reservert seg mot.';
$string['privacy:metadata:email_optout:userid'] = 'Moodle-brukeren reservasjonen tilhører, når den er kjent.';
$string['chat:consent_scroll_hint'] = 'Vennligst rull til bunnen for å lese hele meldingen før du fortsetter.';
$string['settings:rag_min_similarity'] = 'Minimum relevans (cosinus)';
$string['settings:rag_min_similarity_desc'] = 'Forkast hentede tekstbiter hvis cosinuslikhet med spørsmålet er under denne verdien, slik at et spørsmål som er utenfor tema eller har lite innhold setter inn færre (eller null) avsnitt i stedet for alltid å fylle opp til Top-K med svake treff. Område 0 til 1; 0 deaktiverer porten. Riktig verdi avhenger av embedding-modellen: 0.25 passer for text-embedding-3-small. Øk den for å være strengere (mindre, mer relevant kontekst), senk den for å være mer tillatende.';
$string['settings:rag_currentpage_boost'] = 'Gjeldende side-bonus';
$string['settings:rag_currentpage_boost_desc'] = 'En liten bonus som legges til relevanspoengsummen for tekstbiter fra siden læreren ser på akkurat nå, slik at spørsmål som "forklar dette" foretrekker den synlige siden når poengsummene er nær hverandre. Kun rekkefølge: den tvinger ikke en irrelevant sidetekstbit forbi minimumsrelevansporten. Sett 0 for å deaktivere.';
$string['settings:history_mode'] = 'Modus for historikkvalg';
$string['settings:history_mode_desc'] = 'Hvordan tidligere samtaleturer velges før de sendes til modellen. <strong>Semantisk</strong> beholder bare de nylige turene som er relevante for det gjeldende spørsmålet (og alltid den siste utvekslingen), slik at en utdatert, tematisk irrelevant tidligere tur ikke blåser opp kostnaden eller fører svaret på avveie; den gjør ett ekstra embedding-kall per melding. <strong>Nylighet</strong> beholder de siste "Max Conversation History"-parene uavhengig av relevans (den langvarige oppførselen, ingen ekstra kall). Hvis embedding ikke er tilgjengelig, faller semantisk modus automatisk tilbake til nylighet.';
$string['settings:history_mode_semantic'] = 'Semantisk (relevante nylige turer)';
$string['settings:history_mode_recency'] = 'Nylighet (siste N par)';
$string['settings:history_semantic_minscore'] = 'Nedre grense for historikkrelevans (cosinus)';
$string['settings:history_semantic_minscore_desc'] = 'I semantisk historikkmodus beholdes en tidligere tur bare hvis dens likhet med det gjeldende spørsmålet er minst denne verdien (den siste utvekslingen beholdes alltid). Område 0 til 1; modellavhengig. Øk for å være strengere (mindre historikk), senk for å beholde mer.';
$string['settings:history_candidates'] = 'Kandidatvindu for historikk';
$string['settings:history_candidates_desc'] = 'I semantisk historikkmodus blir bare de siste så-mange parene poengsatt for relevans (en kostnadsgrense). Par eldre enn dette vinduet sendes ikke. Hold denne verdien på eller over "Max Conversation History".';

// v5.11.0 - v6.2.0 strings (added 2026-06-10).
$string['settings:embed_provider_voyage'] = 'Voyage AI (voyage-3.5 — anbefalt; +4 MTEB vs OpenAI 3-small, 4x kontekst, flerspråklig)';
$string['settings:rerank_heading'] = 'RAG: To-trinns gjenfinning (re-rangering)';
$string['settings:rerank_heading_desc'] = 'Valgfritt andre gjenfinningstrinn: kosinuslikhet velger de N beste kandidatblokkene (standard 50), deretter gir en kryss-enkoder re-ranker score til hvert (spørring, blokk)-par og de beste K-ene går inn i prompten. Av som standard; faller tilbake til enkelt-trinn cosinus hvis re-rankeren ikke er konfigurert eller mislykkes.';
$string['settings:rerank_enabled'] = 'To-trinns gjenfinning (Voyage rerank-2.5)';
$string['settings:rerank_enabled_desc'] = 'Når aktivert blir RAG-gjenfinning to-trinns: kosinuslikhet returnerer de N beste kandidatene (standard 50), deretter gir Voyage rerank-2.5 kryss-enkoder score til hver og de beste K-ene går inn i prompten. Publiserte løft: +15 Recall@10 enterprise, +39% NDCG BEIR. ~$0,05/MTok-fakturering. Krever <code>rerank_apikey</code> nedenfor; faller elegant tilbake til enkelt-trinn cosinus hvis rerank mislykkes eller er ukonfigurert.';
$string['settings:rerank_apikey'] = 'Rerank API-nøkkel';
$string['settings:rerank_apikey_desc'] = 'Voyage AI API-nøkkel for rerank-2.5. La stå tomt for å gjenbruke Embedding API-nøkkelen ovenfor (typiske Voyage-distribusjoner deler én nøkkel på tvers av embed + rerank).';
$string['settings:rerank_model'] = 'Rerank-modell';
$string['settings:rerank_model_desc'] = 'Standard <code>rerank-2.5</code>. Nyere Voyage rerank-modeller kan spesifiseres her.';
$string['settings:rerank_apibaseurl'] = 'Rerank API-basis-URL';
$string['settings:rerank_apibaseurl_desc'] = 'Overstyr Voyage rerank-basis-URL. La stå tomt for å bruke Embedding API-basis-URL ovenfor, eller Voyage-standard (<code>https://api.voyageai.com/v1</code>).';
$string['settings:rerank_candidates'] = 'Rerank-kandidatvindu';
$string['settings:rerank_candidates_desc'] = 'Hvor mange cosinus topp-N-kandidater som mater re-ranktrinnet. Standard 50. Større vinduer gir re-rankeren mer materiale å jobbe med til liten ekstra kostnad (~10k tokens per rerank-operasjon).';
$string['settings:stt_selfhosted_heading'] = 'Selvhostet transkripsjon (Whisper)';
$string['settings:stt_selfhosted_heading_desc'] = 'Kjør tale-til-tekst på din egen maskinvare til null kostnad per minutt. Pek SOLA mot en hvilken som helst OpenAI-kompatibel transkripsjonserver: <code>whisper-server</code> Docker, <code>speaches</code> (faster-whisper), eller <code>whisper.cpp</code> server. Når en server-URL er satt her, blir det standard STT-sti; velg en betalt leverandør i Aktiv STT-leverandør ovenfor for å overstyre. Hvis serveren er på et privat nettverk eller vanlig http, legg også til verten i SSRF-tillatte endepunkter i Sikkerhet-seksjonen.';
$string['settings:stt_selfhosted_url'] = 'Selvhostet STT-server-URL';
$string['settings:stt_selfhosted_url_desc'] = 'Basis-URL for den OpenAI-kompatible transkripsjonserveren, for eksempel <code>http://10.0.0.5:8000</code>. SOLA legger automatisk til <code>/v1/audio/transcriptions</code>; en full endepunktsti aksepteres også. La stå tomt for å deaktivere selvhostet STT.';
$string['settings:stt_selfhosted_model'] = 'Selvhostet STT-modell';
$string['settings:stt_selfhosted_model_desc'] = 'Modellnavn som sendes til serveren, samsvarende med Whisper-modellen den har lastet — for eksempel <code>Systran/faster-whisper-small</code> for speaches eller <code>large-v3</code>. La stå tomt for å sende <code>whisper-1</code>, som de fleste selvhostede servere aksepterer eller ignorerer.';
$string['settings:stt_selfhosted_apikey'] = 'Selvhostet STT API-nøkkel';
$string['settings:stt_selfhosted_apikey_desc'] = 'Valgfritt. De fleste selvhostede servere er nøkkelfrie bak et betrodd nettverk; sett dette kun hvis serveren din krever et bærertoken.';
$string['emergency:title'] = 'SOLAs nødkontroller';
$string['emergency:page_warning'] = 'Disse bryterne trer i kraft umiddelbart for alle lærende på nettstedet. Hver handling skriver en revisjonsrad. Granulære brytere lar resten av SOLA kjøre; masterkillet fjerner widgeten fullstendig.';
$string['emergency:back_to_settings'] = 'SOLA-innstillinger';
$string['emergency:state_disabled'] = 'DEAKTIVERT';
$string['emergency:state_active'] = 'Aktiv';
$string['emergency:confirm_label'] = 'Jeg forstår at dette påvirker alle lærende umiddelbart';
$string['emergency:confirm_required'] = 'Huk av bekreftelsesavkrysningsboksen før du deaktiverer et delsystem.';
$string['emergency:reason_placeholder'] = 'Årsak (registrert i revisjonsloggen)';
$string['emergency:disable_button'] = 'Deaktiver';
$string['emergency:restore_button'] = 'Gjenopprett';
$string['emergency:disabled_notice'] = 'Delsystem "{$a->flag}" deaktivert. Konfigurasjon berørt: {$a->touched}';
$string['emergency:restored_notice'] = 'Delsystem "{$a->flag}" gjenopprettet. Konfigurasjon berørt: {$a->touched}';
$string['emergency:cli_reference'] = 'De samme kontrollene er tilgjengelige fra vaktskallet:';
$string['emergency:flag_chat'] = 'Chat';
$string['emergency:flag_chat_desc'] = 'Blokkerer chat-trafikk via det dedikerte kill-flagget (v5.13-fiks). Widgeten fortsetter å vises; lærende ser den vennlige "SOLA pauset"-meldingen. Bruk når en LLM-leverandør oppfører seg feil eller en kostnadstipp pågår.';
$string['emergency:flag_voice'] = 'Stemme';
$string['emergency:flag_voice_desc'] = 'Fjerner den aktive sanntidsstemmeleverandøren (lagret for nøyaktig gjenoppretting). Tekst-chat fortsetter å fungere.';
$string['emergency:flag_rag'] = 'RAG';
$string['emergency:flag_rag_desc'] = 'Deaktiverer gjenfinning og indeksering. Chat fortsetter uten kursinnholdsforankring.';
$string['emergency:flag_outreach'] = 'Oppsøking';
$string['emergency:flag_outreach_desc'] = 'Stopper digest-, milepæl- og påminnelses-e-poster. Chat er upåvirket.';
$string['emergency:flag_all'] = 'MASTERKILL';
$string['emergency:flag_all_desc'] = 'Deaktiverer hele plugin-en: widget borte fra alle sider, planlagte oppgaver stopper, stemme fjernet, RAG av, oppsøking av. Den sterkeste bryteren — bruk ved en sikkerhetshendelse eller når SOLA må tas offline umiddelbart.';
$string['emergency:settings_link'] = 'Nødkontroller';
$string['emergency:settings_link_desc'] = 'Per-delsystem kill-brytere (chat / stemme / RAG / oppsøking / master) med revisjonslogging — webvarianten av <code>admin/cli/emergency_disable.php</code>. Åpne <a href="{$a}">SOLAs nødkontroller</a>.';
$string['email_unsubscribe:done_title'] = 'Avmeldt';
$string['email_unsubscribe:done_body'] = 'Ferdig — {$a->email} vil ikke lenger motta denne typen e-post fra {$a->product}. Hvis du ombestemmer deg, be en {$a->product}-administrator om å reaktivere abonnementet, eller send en ny innmelding via SOLA-mottakernes administrasjonsside.';
$string['email_unsubscribe:invalid_title'] = 'Avmeldingslenken er ikke lenger gyldig';
$string['email_unsubscribe:invalid_body'] = 'Denne avmeldingslenken er utløpt eller feilformatert. Se etter en nyere e-post fra oss, eller kontakt en nettstedsadministrator for å bli fjernet manuelt.';
$string['settings:prompt_proportions_heading'] = 'Promptseksjonsandeler (v5.6.0)';
$string['settings:prompt_proportions_heading_desc'] = 'Fordel systemprompte-budsjettet på fire bøtter: sikkerhet + identitet, kursstruktur, kursinnhold og gjeldende side. Vektene er prosentandeler som summerer til 100. Empirisk innstilte standarder (10 / 10 / 40 / 40) kommer fra v5.6.0-vektinnstillingsbenchmarken; å la tekstfeltet stå tomt bruker disse standardene. Den automatiske boosteren justerer fordelingen per tur avhengig av om en bestemt side er i scope.';
$string['settings:prompt_section_weights'] = 'Baseseksjonsvekter (JSON)';
$string['settings:prompt_section_weights_desc'] = 'Valgfritt JSON-objekt som kartlegger hver bøtte til en prosentandel. La stå tomt for å bruke de benchmarkede standardene (10 / 10 / 40 / 40). Eksempel: <code>{"safety_identity": 10, "course_structure": 10, "course_content": 40, "current_page": 40}</code>. Vektene må summere til 100 (±5%). <code>safety_identity</code> har et gulv på 10% slik at jailbreak-motstand og utdataformat-markører alltid lander fullt ut. <code>current_page + course_content</code> må være minst 40% slik at modellen har substansielt materiale å forankre seg i. Verdier utenfor området faller stille tilbake til de benchmarkede standardene; administratorer bør validere ved å sjekke prompt-debug-loggen etter lagring.';
$string['settings:prompt_context_boost_mode'] = 'Kontekstboost-modus';
$string['settings:prompt_context_boost_mode_desc'] = 'Automatisk justering som forskyver vekten mot gjeldende side-seksjonen når en bestemt side er i scope, og mot kursinnhold når ingen side er valgt. <strong>page_focus</strong> (standard) forskyver omtrent 15 vektpoeng. <strong>aggressive</strong> forskyver 25 poeng og er best når lærende konsekvent stiller sidesspesifikke spørsmål. <strong>off</strong> deaktiverer boosteren; administratorinnstilte basisvekter gjelder på hver tur uavhengig av sidekontekst.';
$string['settings:prompt_context_boost_off'] = 'Av (bruk basisvekter på hver tur)';
$string['settings:prompt_context_boost_page_focus'] = 'Sidefokus (standard, ~15 poeng forskyvning)';
$string['settings:prompt_context_boost_aggressive'] = 'Aggressiv (~25 poeng forskyvning)';
$string['settings:prompt_section_weights_coach'] = 'Coach-modus-overstyring (JSON, valgfritt)';
$string['settings:prompt_section_weights_coach_desc'] = 'Valgfritt JSON-objekt som overstyrer baseseksjonsvektene spesifikt under vurdert-quiz-coach-modus (når <code>quizmode=coach</code>). Nyttig for å tvinge en tyngre <code>current_page</code>-fordeling under quizer uten å påvirke normal chat. La stå tomt for å arve basisvektene. Samme valideringsregler som basisinnstillingen.';
$string['prompt_debug_view:title'] = 'Prompt-debug-loggviser';
$string['prompt_debug_view:subtitle'] = 'Per-tur sammenstilt systemprompt + per-seksjons breakdown + samtalehistorikk + gjeldende brukermelding + vedleggsmetadata, nøyaktig slik modellen mottok dem. Bruk dette til å verifisere om en seksjon som Gjeldende sideinnhold faktisk havnet i prompten og for å feilsøke svarkvalitetsproblemer uten å SSH-e inn på serveren.';
$string['prompt_debug_view:disabled'] = 'Prompt-debug-logging er for øyeblikket AV. Ingen nye oppføringer vil bli skrevet før det er aktivert.';
$string['prompt_debug_view:enable_link'] = 'Åpne plugin-innstillinger for å aktivere "Logg sammenstilt systemprompt til fil".';
$string['prompt_debug_view:no_log_yet'] = 'Ingen loggfil ennå. Send minst én chat-tur etter å ha aktivert debug-loggen; filen opprettes ved første skriving.';
$string['prompt_debug_view:empty'] = 'Loggfil finnes, men er tom. Send en chat-tur og oppdater.';
$string['prompt_debug_view:file_status'] = 'Loggfilstørrelse';
$string['prompt_debug_view:showing'] = 'Viser nyeste oppføringer (nyeste først), grense';
$string['prompt_debug_view:total'] = 'Total prompt';
$string['prompt_debug_view:budget'] = 'Budsjett ved opptak';
$string['prompt_debug_view:sections'] = 'Seksjoner (etter kategori)';
$string['prompt_debug_view:assembled_prompt'] = 'Sammenstilt systemprompt';
$string['prompt_debug_view:history'] = 'Samtalehistorikk sendt til modellen';
$string['prompt_debug_view:current_message'] = 'Gjeldende brukermelding';
$string['prompt_debug_view:attachment'] = 'Vedleggsmetadata';
$string['prompt_debug_view:show_more'] = 'Vis flere oppføringer';
$string['settings:mastery_classifier_provider'] = 'Klassifiseringsleverandør';
$string['settings:mastery_classifier_provider_desc'] = 'Leverandør-ID brukt for per-tur mestringsklassifiserer. La stå tomt for å arve standard AI-leverandør. Standard <code>openai</code> pares med <code>gpt-4o-mini</code>-klassifiseringsmodellen nedenfor — det billigste TIER 1-alternativet for strukturert-output-klassifisering (~$220/mnd sparing ved 100k MAU vs chat-nivå). Når satt, leverer raden i Sammenligningsleverandører med denne leverandør-ID API-nøkkel, basis-URL og temperatur.';
$string['settings:mastery_classifier_model'] = 'Klassifiseringsmodell';
$string['settings:mastery_classifier_model_desc'] = 'Modell brukt til å klassifisere assistenturer mot mål. La stå tomt for å arve standard AI-leverandørmodell; ellers spesifiser en billig modell som gpt-4o-mini. Standard <code>gpt-4o-mini</code>.';
$string['settings:mastery_classifier_weight'] = 'Klassifiseringsvekt';
$string['settings:mastery_classifier_weight_desc'] = 'Hvor mye et samtaleforsøk teller relativt til et quizforsøk (1,0). Standard 0,3.';
$string['settings:mastery_classifier_threshold'] = 'Klassifiseringskonfidens-terskel';
$string['settings:mastery_classifier_threshold_desc'] = 'Minimum klassifiseringskonfidens som kreves for å registrere et samtaleforsøk. 0,0 til 1,0. Standard 0,7.';
$string['settings:spend_cap_per_course_default'] = 'Standard forbruksgrense per kurs (USD)';
$string['settings:spend_cap_per_course_default_desc'] = 'Defensiv grense brukt på hvert kurs som ikke har sin egen per-kurs forbruksgrense konfigurert. Sett til f.eks. <code>30</code> for å begrense et enkelt kurs månedlige forbruk til $30 uten å måtte konfigurere individuelle kurs. <code>0</code> = ingen standard (bare siteomfattende og per-kurs-overstyring-grenser gjelder). Når et kurs krysser 80% / 95% / 100% av denne grensen, sender den eksisterende spend-guard-varslingsrørledningen administratorvarslingen (mottakerliste: <code>spend_notify_emails</code>, faller tilbake til nettstedsadministratorer). Et bestemt kurs kan alltid heve sitt eget tak ved å sette en høyere per-kurs-overstyring.';
$string['settings:premium_escalation_heading'] = 'Premium eskalasjonsnivå (A.10)';
$string['settings:premium_escalation_heading_desc'] = 'Valgfri per-tur ruting til en premium-modell (Claude Opus 4.8 som standard) for prompts der arbeidshest-chat-nivået tydelig sliter — typisk flertrinnssmatematikk, CS og naturvitenskapelig resonnering. Avgjort av A.10-bake-off 2026-06-09: Opus 4.8 vant med 14,97/15 mot gpt-4os 12,68/15 på vanskelige prompts. To utløserstigar: regex-treff på brukermeldingen, ELLER en kursallowlist som eskalerer alle turer. Av som standard. Med ~5% eskalasjon, forventes ~$700/mnd ved 100k Saylor MAU i tillegg til grunnlinjeforbruket.';
$string['settings:premium_escalation_enabled'] = 'Aktiver premium eskalasjonruting';
$string['settings:premium_escalation_enabled_desc'] = 'Når på, sjekker per-tur-ruteren utløser-regex-listen og kursallowlisten for hvert chat-kall; matchende turer rutes til premium-leverandøren. Faller tilbake til arbeidshest-leverandøren hvis premium-raden mangler eller ikke klarer å instansiere. Administrator-LLM-velger-overstyringer vinner alltid uansett.';
$string['settings:premium_escalation_provider'] = 'Premium-leverandør';
$string['settings:premium_escalation_provider_desc'] = 'Leverandør-ID for å rute premium-kall gjennom. Må matche en rad i Sammenligningsleverandører (slik at API-nøkkel, basis-URL og temperatur kommer fra samme sted administratorer allerede administrerer). Standard <code>claude</code>.';
$string['settings:premium_escalation_model'] = 'Premium-modell';
$string['settings:premium_escalation_model_desc'] = 'Modellnavn sendt til premium-leverandøren. Standard <code>claude-opus-4-8</code> per A.10-bake-off-kjennelsen.';
$string['settings:premium_escalation_triggers'] = 'Premium-utløser-regexer';
$string['settings:premium_escalation_triggers_desc'] = 'En PCRE-regex per linje (uten avgrensere; store/små-bokstav-ufølsom matching brukes automatisk). Linjer som starter med # er kommentarer. La stå tomt for å bruke det kurerte standard-settet fra A.10-bake-off (flertrinn-STEM-markører: "derive", "prove that", "step by step", LaTeX-matte, gjerdet kodeblokker, big-O, integraler, optimalisering osv.).';
$string['settings:premium_escalation_course_tags'] = 'Premium kursallowlist';
$string['settings:premium_escalation_course_tags_desc'] = 'Et kurs-kortnavn eller idnummer-prefiks per linje. Alle turer i et samsvarende kurs eskaleres automatisk uavhengig av meldings-regexen (bruk for STEM-tunge kurs der eskalasjon bør være standard). Matching er store/små-bokstav-ufølsom prefiks — "MATH" matcher MATH121, MATH205 osv.';
$string['settings:cost_anomaly_heading'] = 'Kostnadsavviksdetektoren (v6.0)';
$string['settings:cost_anomaly_heading_desc'] = 'Daglig planlagt oppgave (<code>cost_anomaly_check</code>) som sammenligner dagens siteomfattende SOLA-forbruk mot den rullende 7-dagers medianen. Sender e-post til <code>spend_notify_emails</code>-mottakerlisten (faller tilbake til nettstedsadministratorer) når dagens forbruk overskrider den konfigurerte multiplikatoren × median. Fanger tre feilmodi som de eksisterende 80% / 95% / 100% forbruksgrense-tersklene ikke fanger: (1) løpsk kurs der det absolutte taket ikke krysses men et enkelt kurs plutselig driver 10x sitt vanlige volum, (2) utilsiktet aktivering av premium-nivå, (3) leverandørfeilruting. Av som standard; in-SOLA-ekvivalenten til Redash-spørringen på <code>.drafts/sola-redash-cost-anomaly-2026-06-09.md</code>.';
$string['settings:cost_anomaly_enabled'] = 'Aktiver kostnadsavviksdetektor';
$string['settings:cost_anomaly_enabled_desc'] = 'Når på evaluerer den daglige planlagte oppgaven dagens forbruk mot den rullende 7-dagers medianen og sender e-post til administratorer ved avvik. De første 7 dagene etter aktivering gir en <code>insufficient_history</code>-status (ingen historisk grunnlinje ennå) og sender aldri et varsel. Idempotent per dag: et flagg i <code>config_plugins</code> stopper gjentatte e-poster hvis cron kjører flere ganger.';
$string['settings:cost_anomaly_multiplier'] = 'Avviksmultiplikator';
$string['settings:cost_anomaly_multiplier_desc'] = 'Dagens forbruk må overskride denne multiplikatoren × 7-dagers medianen for å utløse et varsel. Standard <code>2.0</code>. Senk til <code>1.5</code> for tidlige advarsler (flere falske positiver under påmeldingsbølger). Hev til <code>3.0</code> hvis Saylors bruk er uregelmessig nok til at 2x-topper er rutine.';
$string['settings:prompt_debug_enabled'] = 'Logg sammenstilt systemprompt til fil';
$string['settings:prompt_debug_enabled_desc'] = 'Når på skriver hver chat-tur den fullstendige sammenstilte systemprompten og per-seksjon tegntellinger til <code>moodledata/temp/sola_prompt_debug.log</code> (rullende ved ~1MB). Av som standard. Bruk til å måle promptstørrelse empirisk og revidere hvilke seksjoner som bidrar mest med tokens. Loggen inneholder kun systemprompten (ingen lærende-input eller PII).';
$string['task:cost_anomaly_check'] = 'SOLA kostnadsavvikssjekk (daglig)';
// v6.4.0 signed policy bundle strings (added 2026-06-11).
$string['settings:policy_bundle_heading'] = 'Signert retningslinjepakke (fjernoppdateringer av atferd)';
$string['settings:policy_bundle_heading_desc'] = 'Bruk atferdsinnstillinger (ledetekster, ruting, eskaleringsutløsere, RAG-justering, forbrukspolicy) fra en kryptografisk signert JSON-fil uten kodeutrulling. En daglig planlagt oppgave henter pakke-URL-en, verifiserer Ed25519-signaturen mot offentlig nøkkel nedenfor og bruker innstillingene bare hvis alle nøkler er på den innebygde tillatelseslisten og pakkeversjon er nyere enn den sist brukte. API-nøkler, URL-er, webhooks og sikkerhetsinnstillinger kan aldri angis av en pakke. Opprett og signer pakker med <code>admin/cli/policy_bundle_tool.php</code> (keygen, sign, verify, status, sync).';
$string['settings:policy_bundle_enabled'] = 'Aktiver synkronisering av retningslinjepakke';
$string['settings:policy_bundle_enabled_desc'] = 'Når aktivert henter den daglige oppgaven og bruker signerte pakker. Deaktivert som standard. Deaktivering stopper alle synkroniseringer umiddelbart; allerede brukte innstillinger beholder verdiene sine.';
$string['settings:policy_bundle_url'] = 'URL til retningslinjepakke';
$string['settings:policy_bundle_url_desc'] = 'HTTPS URL til den signerte pakke-JSON-en (for eksempel et S3-objekt eller GitHub raw URL). URL-en gjennomgår samme SSRF-validering som AI-leverandørenes endepunkter; private nettverks- eller plain-http-verter trenger en oppføring i SSRF-listen over klarerte endepunkter.';
$string['settings:policy_bundle_pubkey'] = 'Offentlig nøkkel for retningslinjepakke';
$string['settings:policy_bundle_pubkey_desc'] = 'Base64 Ed25519 offentlig nøkkel som brukes til å verifisere pakkesignaturer. Generer nøkkelparet med <code>policy_bundle_tool.php --keygen</code>; den private nøkkelen forblir hos pakkeforfatteren og må aldri lastes opp noe sted.';
$string['settings:policy_bundle_status'] = 'Siste synkronisering';
$string['settings:policy_bundle_applied_version'] = 'brukt versjon';
$string['task:policy_bundle_sync'] = 'SOLA signert retningslinjepakke-synkronisering';
$string['policy_bundle:invalid'] = 'Retningslinjepakke avvist: {$a}';
$string['prompt_debug_view:retrieved_chunks'] = 'Hentede deler (RAG-utvalg)';
$string['prompt_debug_view:retrieved_chunks_hint'] = 'Avsnittene som retrieveren valgte for dette spørsmålet, i rangert rekkefølge med relevansscore og kilde (cmid). Bruk dette til å bekrefte at modellen mottok det best samsvarende kursinnholdet.';
$string['settings:avatar_animation_enabled'] = 'Avatar-animasjon';
$string['settings:avatar_animation_enabled_desc'] = 'Animer den genererte SVG-avataren: tomgangsblinking samt munnbevegelse synkronisert med tekst-til-tale-lyd mens assistenten snakker. Respekterer lærens enhets preferanse for redusert bevegelse. Kursvis overstyring for A/B-måling: sett konfigurasjonsverdien avatar_animation_course_COURSEID til 0 eller 1.';
$string['analytics:exp_heading'] = 'Sammenligning av A/B-eksperiment';
$string['analytics:exp_desc'] = 'Sammenlign engasjement mellom to kurs over det valgte tidsintervallet. Bygget for per-kurs eksperimenter (for eksempel avatar-animasjonsprøven): plasser overstyringen i ett kurs, la det andre fungere som kontroll, og les forskjellen her.';
$string['analytics:exp_course_a'] = 'Kurs A';
$string['analytics:exp_course_b'] = 'Kurs B';
$string['analytics:exp_compare'] = 'Sammenlign';
$string['analytics:exp_metric'] = 'Metrikk';
$string['analytics:exp_delta'] = 'B vs A';
$string['analytics:exp_enrolled'] = 'Påmeldte elever';
$string['analytics:exp_active_users'] = 'Aktive SOLA-brukere';
$string['analytics:exp_usage_rate'] = 'Bruksrate (%)';
$string['analytics:exp_sessions'] = 'Økter';
$string['analytics:exp_messages'] = 'Meldinger';
$string['analytics:exp_avg_msgs_session'] = 'Gj.sn. meldinger per økt';
$string['analytics:exp_avg_session_minutes'] = 'Gj.sn. øktlengde (minutter)';
$string['analytics:exp_return_rate'] = 'Tilbakevendende brukere (%)';
$string['analytics:exp_tts_plays'] = 'TTS-avspillinger';
$string['analytics:exp_tts_per_active'] = 'TTS-avspillinger per aktiv bruker';

$string['settings:redash_allowed_origin'] = 'Tillatt opprinnelse for Redash (CORS)';
$string['settings:redash_allowed_origin_desc'] = 'La feltet stå tomt (anbefalt): eksporten hentes server-til-server av Redash og trenger ingen CORS-header i nettleseren. Angi kun én nøyaktig opprinnelse (for eksempel https://redash.example.org) hvis et nettleserbasert dashbord må lese eksporten direkte. Bruk aldri et jokertegn.';

// Soapbox speech practice (v6.7.0).
$string['privacy:metadata:local_ai_course_assistant_practice_scores:session_meta'] = 'Valgfrie metadata du oppga for økten, som navn, tema og mållengde for en Soapbox-tale. Inkluderer aldri lyd eller en transkripsjon.';
$string['pedagogy:soapbox'] = 'Tilbakemelding på Soapbox-tale på som standard';
$string['pedagogy:soapbox_desc'] = 'Når dette er på, er Soapbox-taleøvingsverktøyet tilgjengelig i alle emner med mindre emnet har sin egen overstyring. La det stå av, og slå det på bare i emnene som trenger det (vanligvis tale- og kommunikasjonsemner).';
$string['settings:soapbox_stt_mode'] = 'Soapbox-transkripsjonsmodus';
$string['settings:soapbox_stt_mode_desc'] = 'Hvordan Soapbox gjør en innspilt tale om til tekst. Server bruker den konfigurerte Whisper-leverandøren (selvhostet er gratis; hostet OpenAI koster omtrent USD 0.006 per minutt). Nettleser bruker den innebygde talegjenkjenningen til den lærende (gratis, ingen server, fungerer bare i Chrome og Safari). Server anbefales slik at transkripsjonskvaliteten ikke avhenger av nettleseren til den lærende.';
$string['settings:soapbox_stt_mode_server'] = 'Server (Whisper-leverandør)';
$string['settings:soapbox_stt_mode_browser'] = 'Nettleser (gratis, ingen server)';
$string['soapbox:title'] = 'Soapbox';
$string['soapbox:link'] = 'Soapbox-taleøving';
$string['soapbox:disabled'] = 'Soapbox er ikke aktivert for dette emnet.';
$string['soapbox:intro'] = 'Hold en tale og få veiledning. Sett eventuelt et navn, et tema og en mållengde, og spill deg selv inn mens du snakker. Soapbox transkriberer talen din, vurderer den mot en talerubrikk og gir deg konkrete tips. Lyden og transkripsjonen din lagres aldri, bare poengsummene og tilbakemeldingene dine.';
$string['soapbox:optional'] = 'valgfritt';
$string['soapbox:name_label'] = 'Gi denne talen et navn';
$string['soapbox:topic_label'] = 'Tema';
$string['soapbox:time_label'] = 'Mållengde';
$string['soapbox:no_target'] = 'Ingen mållengde';
$string['soapbox:record'] = 'Spill inn tale';
$string['soapbox:stop'] = 'Stopp og få tilbakemelding';
$string['soapbox:recording'] = 'Spiller inn. Snakk naturlig; klikk stopp når du er ferdig.';
$string['soapbox:transcribing'] = 'Transkriberer talen din…';
$string['soapbox:scoring'] = 'Vurderer talen din…';
$string['soapbox:too_short'] = 'Det opptaket var for kort til å vurdere. Sikt på minst en setning eller to, og prøv igjen.';
$string['soapbox:mic_denied'] = 'Mikrofontilgang trengs for å spille inn. Tillat mikrofontilgang og prøv igjen.';
$string['soapbox:no_browser_stt'] = 'Denne nettleseren støtter ikke talegjenkjenning i nettleseren. Prøv Chrome eller Safari, eller be administratoren din om å bytte Soapbox til servertranskripsjon.';
$string['soapbox:browser_note'] = 'Denne talen transkriberes i nettleseren din. Ingenting lastes opp. Fungerer best i Chrome og Safari.';
$string['soapbox:server_note'] = 'Opptaket ditt lastes opp bare for transkripsjon og lagres ikke.';
$string['soapbox:error'] = 'Kunne ikke vurdere denne talen akkurat nå. Prøv igjen om et øyeblikk.';
$string['soapbox:audio_too_large'] = 'Det opptaket er for stort. Hold taler under omtrent 25 MB (rundt 20 minutter).';
$string['soapbox:no_stt'] = 'Ingen transkripsjonsleverandør er konfigurert. Be administratoren din om å sette opp Whisper eller aktivere nettlesertranskripsjon.';
$string['soapbox:result_heading'] = 'Rubrikkpoeng';
$string['soapbox:overall_heading'] = 'Totalt';
$string['soapbox:tips_heading'] = 'Tips til neste gang';
$string['soapbox:col_criterion'] = 'Kriterium';
$string['soapbox:col_score'] = 'Poeng';
$string['soapbox:col_feedback'] = 'Tilbakemelding';
$string['soapbox:history_heading'] = 'Mine taler';
$string['soapbox:history_empty'] = 'Du har ikke spilt inn en tale ennå. Spill inn en ovenfor for å begynne å bygge opp historikken din.';
$string['soapbox:untitled'] = 'Tale uten tittel';
$string['soapbox:overall_badge'] = 'Totalt {$a}';
$string['soapbox:toggle'] = 'Aktiver Soapbox for dette emnet';
$string['soapbox:toggle_help'] = 'Lærende får en egen side for å spille inn en tale og motta rubrikkvurdert taletilbakemelding med tips. Lyd og transkripsjoner lagres aldri. Av som standard.';
