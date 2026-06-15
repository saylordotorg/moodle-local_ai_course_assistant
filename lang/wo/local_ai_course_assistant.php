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
 * Language strings for local_ai_course_assistant — Wolof.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname'] = 'Jëkkër AI bi ci Cours bi';
$string['attachment:attach'] = 'Boole';
$string['attachment:attach_image_or_pdf'] = 'Boole nataal walla PDF';
$string['privacy:metadata:local_ai_course_assistant_convs'] = 'Dëkk ay wax-ak-wax yu AI jàng ak ay jëkkëram ak cours yépp.';
$string['privacy:metadata:local_ai_course_assistant_convs:userid'] = 'ID bi bu nekkee nit ki am wax-ak-wax bi.';
$string['privacy:metadata:local_ai_course_assistant_convs:courseid'] = 'ID bi bu nekkee cours bi moo moom wax-ak-wax bi.';
$string['privacy:metadata:local_ai_course_assistant_convs:title'] = 'Xel wax-ak-wax bi.';
$string['privacy:metadata:local_ai_course_assistant_convs:timecreated'] = 'Xeetu wax-ak-wax bi dañ ko def.';
$string['privacy:metadata:local_ai_course_assistant_convs:timemodified'] = 'Xeetu yegeel bu mujj wax-ak-wax bi.';
$string['privacy:metadata:local_ai_course_assistant_msgs'] = 'Dëkk ay bataaxal yu nekk ci wax-ak-wax yi.';
$string['privacy:metadata:local_ai_course_assistant_msgs:userid'] = 'ID bi bu nekkee nit ki yónni bataaxal bi.';
$string['privacy:metadata:local_ai_course_assistant_msgs:courseid'] = 'ID bi bu nekkee cours bi moo moom bataaxal bi.';
$string['privacy:metadata:local_ai_course_assistant_msgs:role'] = 'Njëkk ku yónn bataaxal bi (jëkkër walla jëkkëram).';
$string['privacy:metadata:local_ai_course_assistant_msgs:message'] = 'Ndigël bataaxal bi.';
$string['privacy:metadata:local_ai_course_assistant_msgs:tokens_used'] = 'Jom tokens yi jëfandikoo bataaxal bi.';
$string['privacy:metadata:local_ai_course_assistant_msgs:timecreated'] = 'Xeetu bataaxal bi dañ ko def.';

// Capabilities.
$string['ai_course_assistant:use'] = 'Jëfandikoo wax-ak-wax AI jàng';
$string['ai_course_assistant:viewanalytics'] = 'Xool analytics yu wax-ak-wax AI jàng';
$string['ai_course_assistant:manage'] = 'Gérer réglages wax-ak-wax AI jàng (Rôle Administrateur)';

// Settings.
$string['settings:enabled'] = 'Sopp Jëkkër AI bi ci Cours bi';
$string['settings:enabled_desc'] = 'Sopp walla tëj widget Jëkkër AI bi ci Cours bi ci say xët cours yi.';
$string['settings:default_course_mode'] = 'Fàww ci njàng yu bees';
$string['settings:default_course_mode_desc'] = 'Dafay kontrole ndax SOLA dina feeñ ci njàng ba saa yu tànnul dañu tànn ci njàng. Installasioŋ yu bees ñoo ngi ag "Tëjoon ci fàww" ngir ndawalu yi mën a jàpp njàng bu nekk ci xëtu Analytics walla xëtu Course AI Settings.';
$string['settings:default_course_mode_per_course'] = 'Tëjoon ci fàww (ubbil ci njàng bu nekk)';
$string['settings:default_course_mode_all'] = 'Ubbi ci njàng yépp';
$string['settings:auto_open'] = 'Ubbi ko ci sa njëkk a dem';
$string['settings:auto_open_desc'] = 'Bu ko ñu tàmbalee, drawer bu SOLA dafay ubbiku boppam bu njëkk bi jàngkat bi dikkee ci kër kursu kër. Yokk yu topp ci page yi ci kursu bi dañu ubbiku drawer bi dara — anam wi ñu koy toppal ci kursu ku nekk ci browser bu jàngkat bi jaar ci localStorage. Mu ngi jëf ci desktop ak telefon. Mën nañu ko soppi ci kursu ku nekk ci page Course AI Settings.';
$string['settings:comparison_providers'] = 'Yónent yi ñu mën a faral (tànn LLM)';
$string['settings:comparison_providers_desc'] = 'Yokk yónent yi AI yu sàkk ci tànn LLM bi ci widget bi ngir administrater yi mën a faral tontu yi diggante yónent yi. Jëfandikool taabal bi ci suuf ngir yokk ay rëddi. Kolonn bu tàngay mooy lu ñu nangu (bàyyi ko dara ngir jëfandikoo tàngayu àdduna bi). Format bi ñu denc: provider_id|api_key|model1,model2|temperature. Yónent bu njëk bi ñu teg ci kaw moo ngi ci ci kàttan lu bees. Administrater yi am manage capability rekk moo gën a gis tànn bi; jàngalekat yi du ko gis mukk. Provider IDs yu baax: openai, claude, deepseek, gemini, ollama, minimax, mistral, openrouter, xai, coreai, custom.';
$string['settings:provider'] = 'Default Fournisseur AI';
$string['settings:provider_desc'] = 'Tànn jukki AI bi ngay jëfandikoo ngir mat-waxtaan yi. Tànn "Moodle AI (core_ai subsystem)" ngir yóbbu ñaan yi ci ndefaru AI bu nekk ci biir Moodle ci Site admin > AI; tolof-tolof yu caabiu API, mudel ak URL bu njëkk dañu leen di dugal ci jëfi yooyu. Streaming, jëfandikoo nu jumtukaay yi, ak prompt caching amul ci core_ai — tontu yi ñoo leen di jox ci benn boor. Jëfandikoo jukkikat bu jub ngir jafandu jàngkat bu gën a baax.';
$string['settings:provider_claude'] = 'Claude (Anthropic)';
$string['settings:provider_openai'] = 'OpenAI';
$string['settings:provider_deepseek'] = 'DeepSeek';
$string['settings:provider_ollama'] = 'Ollama (Bu dëkk)';
$string['settings:provider_minimax'] = 'MiniMax';
$string['settings:provider_custom'] = 'Mba bu xam (Compatible ak OpenAI)';
$string['settings:apikey'] = 'Clé API';
$string['settings:apikey_desc'] = 'Clé API fournisseur bi la tann. Dafa amul solo ci Ollama.';
$string['settings:model'] = 'Tur Modèle bi';
$string['settings:model_desc'] = 'Modèle bi ngay jëfandikoo. Valeur par défaut dafay depann ci fournisseur bi (xam-xam claude-sonnet-4-5-20250929, gpt-4o, llama3, MiniMax-Text-01).';
$string['settings:apibaseurl'] = 'URL de Base API';
$string['settings:apibaseurl_desc'] = 'URL de base API bi. Dañ ko rempli automatiquement ci fournisseur yi waye dafay mën a yegeel. Samp sax valeur par défaut fournisseur bi.';
$string['settings:systemprompt'] = 'Modèle Invite Système';
$string['settings:systemprompt_desc'] = 'Invite dañ ko yónn ci AI bi. Jëfandikoo ay substituts: {{coursename}}, {{userrole}}, {{coursetopics}}.';
$string['settings:systemprompt_default'] = 'Yàgg nga jël rôle wu professeur AI ci cours "{{coursename}}". Rôle élève bi dafa {{userrole}}.

Sujets cours bi ñu dakkal:
{{coursetopics}}

Ndimm élève bi jàng matière cours bi. Yëgël, wëjj, te jàng ci yoon bu baax.';
$string['settings:temperature'] = 'Température';
$string['settings:temperature_desc'] = 'Kontrole aléatoire bi. Valeurs yu yëëf dañ focus, valeurs yu dëkk dañ créatif. Plaage: 0.0 ci 2.0.';
$string['settings:maxhistory'] = 'Historique Wax-ak-wax bu Màgg';
$string['settings:maxhistory_desc'] = 'Jom yu màgg ak ay kuple bataaxal ngay mël ci requêtes API yi. Bataaxal yu gëna mujj dañ ko tass.';
$string['settings:avatar'] = 'Avatar Wax-ak-wax bi';
$string['settings:avatar_desc'] = 'Tann icône avatar bi ci bouton widget wax-ak-wax bi.';
$string['settings:avatar_saylor'] = 'Logo {$a} (Par défaut)';
$string['settings:position'] = 'Woon Widget bi';
$string['settings:position_desc'] = 'Woon widget wax-ak-wax bi ci xët bi.';
$string['settings:position_br'] = 'Kanam jigéen';
$string['settings:position_bl'] = 'Kanam góor';
$string['settings:position_tr'] = 'Kanam jigéen ci kanam';
$string['settings:position_tl'] = 'Kanam góor ci kanam';
$string['chat:settings'] = 'Réglages plugin bi';
$string['analytics:viewdashboard'] = 'Xool tableau de bord analytics bi';

// Course settings (per-course AI provider override).
$string['coursesettings:title'] = 'Réglages AI Cours bi';
$string['coursesettings:enabled'] = 'Sopp substitutions cours bi';
$string['coursesettings:enabled_desc'] = 'Su soppi la, réglages yi ci suuf dañy tekk réglages fournisseur AI bi bu ñu xam xam ci cours boobu rekk. Samp champs yi vides pour hériter valeur globale bi.';
$string['coursesettings:sola_enabled'] = 'SOLA ci njàng bii';
$string['coursesettings:sola_enabled_toggle'] = 'Wone widget bu SOLA ci njàng bii';
$string['coursesettings:sola_enabled_desc'] = 'Dafay kontrole ndax widget-waxtaanu SOLA dina feeñ ci njàng bii. Fàww bu sit bi yépp dañu koy tànn ci tàggat-yu-plugin ci kanam General > Default for new courses.';
$string['coursesettings:using_global'] = 'Jëfandikoo réglage global bi';
$string['coursesettings:saved'] = 'Réglages AI cours bi dañ ko sauvegarder.';
$string['coursesettings:global_settings_link'] = 'Réglages AI globaux';

// Language detection and preference.
$string['lang:switch'] = 'Waaw, tekk';
$string['lang:dismiss'] = 'Deedéet jërejëf';
$string['lang:change'] = 'Soppal làkk bi';
$string['lang:english'] = 'Angale';

// Chat widget.
$string['chat:title'] = 'Professeur AI';
$string['chat:placeholder'] = 'Laaj ay laaj...';
$string['chat:send'] = 'Yónn';
$string['chat:close'] = 'Tëj wax-ak-wax bi';
$string['chat:open'] = 'Ubbi wax-ak-wax AI jàng bi';
$string['chat:clear'] = 'Clear screen';
$string['chat:clear_confirm'] = 'Clear the visible messages? Your full chat history stays saved and can be reloaded by reopening the widget.';
$string['chat:copy'] = 'Copie wax-ak-wax bi';
$string['chat:copied'] = 'Wax-ak-wax bi dañ ko copié ci clipboard bi';
$string['chat:copy_failed'] = 'Copie wax-ak-wax bi defoo kaay';
$string['chat:thinking'] = 'Xam xamam...';
$string['chat:error'] = 'Baal ma, dafa am problème. Jëf alal ci kanam.';
$string['chat:error_auth'] = 'Erreur authentification. Jooy administrateur bi.';
$string['chat:error_ratelimit'] = 'Ay demande yu bari lool. Dëgël ci wëkër ak jëf alal.';
$string['chat:error_unavailable'] = 'Service AI bi dafay nekk ci sa xel bu yegeel. Jëf alal ci kanam.';
$string['chat:error_notconfigured'] = 'Professeur AI bi amul encore configuration. Jooy administrateur bi.';
$string['chat:mic'] = 'Wax sa laaj';
$string['chat:mic_error'] = 'Erreur microphone. Seeti ay autorisations navigateur bi.';
$string['chat:mic_unsupported'] = 'Entrée vocale bi dafa supporté ci navigateur bi.';
$string['chat:newline_hint'] = 'Shift+Enter ci kàttan bu bees';
$string['chat:you'] = 'Yow';
$string['chat:assistant'] = 'Professeur AI';
$string['chat:history_loaded'] = 'Wax-ak-wax yi wëkër dañ ko charger.';
$string['chat:history_cleared'] = 'Historique wax-ak-wax bi dañ ko fay.';
$string['chat:offtopic_warning'] = 'Dëgg la laaj bi dafoo dakkal cours boobu. Jëf alal ci sujet bi ngir mënuma la ndimmal ci yoon bu baax!';
$string['chat:offtopic_ended'] = 'Jëfandikoo professeur AI bi dafa suspendu {$a} simili ci nu wax-ak-wax bi dooloo ci sujet bu bari. Jëfandikoo waxtu boobu xëy matériels cours bi, mënula ko feek ci kanam.';
$string['chat:offtopic_locked'] = 'Jëfandikoo professeur AI bi dafa suspendu ci waxtu bu nekk. Mënula ko feek ci {$a} simili. Dëkk ci ay laaj yu rapport ak cours bi su döödaat.';
$string['chat:escalated_to_support'] = 'Amuma liggéey ay réponse bu dëgg ci sa laaj, léegi dañ ko créer ticket support bi fii ak yow. Ñëpp ci team support bi dinañu ko suivre. Référence ticket bi dafa: {$a}';
$string['chat:studyplan_intro'] = 'Mënuma la ndimmal créer kàlàam jàng ci cours boobu! Xam ma jom waxtu ci semaine mënula am ci jàng, dinaa la ndimmal bëgg plan bu strukturé.';

// FAQ & Support settings.
$string['settings:faq_heading'] = 'FAQ & Support';
$string['settings:faq_heading_desc'] = 'Configure FAQ centralisé bi ak intégration ticket support Zendesk bi.';
$string['settings:faq_content'] = 'Ndigël FAQ bi';
$string['settings:faq_content_desc'] = 'Bind entrées FAQ yi (ñëpp ci kàttan ci format: Q: laaj | A: réponse). Dañ ko donner AI bi pour répondre ay laaj support yi mu am.';
$string['settings:zendesk_enabled'] = 'Sopp Escalade Zendesk';
$string['settings:zendesk_enabled_desc'] = 'Su AI bi amul réponse bu dëgg ci laaj support bi, créer ticket Zendesk automatiquement ak résumé wax-ak-wax bi.';
$string['settings:zendesk_subdomain'] = 'Sous-domaine Zendesk';
$string['settings:zendesk_subdomain_desc'] = 'Sa sous-domaine Zendesk (xam-xam "mycompany" ci mycompany.zendesk.com).';
$string['settings:zendesk_email'] = 'Email API Zendesk';
$string['settings:zendesk_email_desc'] = 'Adresse email utilisateur Zendesk ci authentification API (ak suffixe /token).';
$string['settings:zendesk_token'] = 'Token API Zendesk';
$string['settings:zendesk_token_desc'] = 'Token API ci authentification Zendesk.';

// Off-topic detection settings.
$string['settings:offtopic_heading'] = 'Détection Hors Sujet';
$string['settings:offtopic_heading_desc'] = 'Configure soo traitante wax-ak-wax bi ci ay wax-ak-wax yu nekk hors sujet.';
$string['settings:offtopic_enabled'] = 'Sopp Détection Hors Sujet';
$string['settings:offtopic_enabled_desc'] = 'Lere AI bi ngir détecte ak redirige ay wax-ak-wax yu nekk hors sujet.';
$string['settings:offtopic_max'] = 'Jom bu Màgg Bataaxal Hors Sujet';
$string['settings:offtopic_max_desc'] = 'Jom bataaxal hors sujet yi ku nekk ci su dëkk ak sa takk kanam action bi.';
$string['settings:offtopic_action'] = 'Action Hors Sujet';
$string['settings:offtopic_action_desc'] = 'Lan ngay def su tëgële limite hors sujet bi.';
$string['settings:offtopic_action_warn'] = 'Avertir ak redirige';
$string['settings:offtopic_action_end'] = 'Bloquer accès ci waxtu bu nekk';
$string['settings:offtopic_lockout_duration'] = 'Durée Blocage (simili)';
$string['settings:offtopic_lockout_duration_desc'] = 'Naka ndaw (ci simili) élève bi am accès ci professeur AI bi su depasse limite hors sujet bi. Par défaut: 30 simili.';

// Study planning & reminders settings.
$string['settings:studyplan_heading'] = 'Planification Jàng & Rappels';
$string['settings:studyplan_heading_desc'] = 'Configure fonctionnalités planification jàng ak notifications rappel yi.';
$string['settings:studyplan_enabled'] = 'Sopp Planification Jàng';
$string['settings:studyplan_enabled_desc'] = 'Jox professeur AI bi ak ndimmal élèves yi créer ay plans jàng personnalisés yu dëgg ci waxtu yu ñu am.';
$string['settings:reminders_email_enabled'] = 'Sopp Rappels Email';
$string['settings:reminders_email_enabled_desc'] = 'Jox élèves yi dëgg ak rappels jàng ci email.';
$string['settings:reminders_whatsapp_enabled'] = 'Sopp Rappels WhatsApp';
$string['settings:reminders_whatsapp_enabled_desc'] = 'Jox élèves yi dëgg ak rappels jàng ci WhatsApp (dafa soxor configuration API WhatsApp).';
$string['settings:whatsapp_api_url'] = 'URL API WhatsApp';
$string['settings:whatsapp_api_url_desc'] = 'Endpoint API ci envoi messages WhatsApp yi (xam-xam Twilio, MessageBird). Fëkk POST ak corps JSON bu am "to", "from", ak "body".';
$string['settings:whatsapp_api_token'] = 'Token API WhatsApp';
$string['settings:whatsapp_api_token_desc'] = 'Token authentification ci API WhatsApp bi.';
$string['settings:whatsapp_from_number'] = 'Numéro Expéditeur WhatsApp';
$string['settings:whatsapp_from_number_desc'] = 'Numéro téléphone bi ci yónn messages WhatsApp (ak code pays, xam-xam +14155238886).';
$string['settings:whatsapp_blocked_countries'] = 'Pays WhatsApp Bloqués';
$string['settings:whatsapp_blocked_countries_desc'] = 'Codes pays ISO 3166-1 alpha-2 séparés virgule fu rappels WhatsApp dañu dul autorisé ci réglementations locales (xam-xam "CN,IR,KP").';

// Reminder messages.
$string['reminder:email_subject'] = 'Rappel Jàng: {$a}';
$string['reminder:email_body'] = 'Salaam {$a->firstname},

Voici sa rappel jàng ci "{$a->coursename}".

{$a->message}

Sa plan jàng dafa soo jël {$a->hours_per_week} waxtu ci semaine ci cours boobu.

Jàng ci kanam!

---
Pour arrêter rappels yooy, cliquer ici: {$a->unsubscribe_url}';
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
$string['reminder:whatsapp_body'] = 'Rappel Jàng ci {$a->coursename}: {$a->message} (Désabonner: {$a->unsubscribe_url})';
$string['reminder:study_tip_prefix'] = 'Focus jàng tey: ';

// Unsubscribe page.
$string['unsubscribe:title'] = 'Se désabonner ci Rappels Jàng';
$string['unsubscribe:success'] = 'Dañ la désabonner ak succès ci rappels jàng ci cours boobu.';
$string['unsubscribe:already'] = 'Dañ la déjà désabonner ci rappels yooy.';
$string['unsubscribe:invalid'] = 'Lien désabonnement bu dëgëer walla bu dem.';
$string['unsubscribe:resubscribe'] = 'Soppalal sa xel? Mënula sopp rappels yi ci wax-ak-wax professeur AI bi.';

// Scheduled task.
$string['task:send_reminders'] = 'Yónn rappels jàng professeur AI';

// Privacy - additional tables.
$string['privacy:metadata:local_ai_course_assistant_plans'] = 'Dëkk ay plans jàng élèves yi.';
$string['privacy:metadata:local_ai_course_assistant_plans:userid'] = 'ID bi bu nekkee utilisateur bi am plan jàng bi.';
$string['privacy:metadata:local_ai_course_assistant_plans:courseid'] = 'Cours bi plan jàng bi moo moom.';
$string['privacy:metadata:local_ai_course_assistant_plans:hours_per_week'] = 'Waxtu ci semaine élève bi xëy jàng.';
$string['privacy:metadata:local_ai_course_assistant_plans:plan_data'] = 'Détails plan jàng bi ci format JSON.';
$string['privacy:metadata:local_ai_course_assistant_reminders'] = 'Dëkk ay préférences rappel ak abonnements.';
$string['privacy:metadata:local_ai_course_assistant_reminders:userid'] = 'ID bi bu nekkee utilisateur bi abonné ci rappels.';
$string['privacy:metadata:local_ai_course_assistant_reminders:channel'] = 'Canal rappel bi (email walla whatsapp).';
$string['privacy:metadata:local_ai_course_assistant_reminders:destination'] = 'Adresse email walla numéro téléphone rappels.';
$string['privacy:metadata:local_ai_course_assistant_reminders:country_code'] = 'Code pays utilisateur ci conformité réglementaire.';

// Analytics dashboard.
$string['analytics:title'] = 'Analytics Professeur AI';
$string['analytics:overview'] = 'Vue d\'ensemble';
$string['analytics:total_conversations'] = 'Total wax-ak-wax yi';
$string['analytics:total_messages'] = 'Total bataaxal yi';
$string['analytics:active_students'] = 'Élèves actifs';
$string['analytics:avg_messages_per_student'] = 'Moy bataaxal ci élève';
$string['analytics:offtopic_rate'] = 'Taux hors sujet';
$string['analytics:escalation_count'] = 'Escaladé ci support';
$string['analytics:studyplan_adoption'] = 'Élèves ak plans jàng';
$string['analytics:usage_trends'] = 'Tendances Utilisation';
$string['analytics:daily_messages'] = 'Volume bataaxal journalier';
$string['analytics:hotspots'] = 'Points Chauds Cours bi';
$string['analytics:hotspots_desc'] = 'Sections cours yi ñu gëna xam-xam ci laaj élèves yi. Comptages yu dëkk mënuy wax sections yi fu élèves yi soxor ndimmal yi gëna bari.';
$string['analytics:section'] = 'Section';
$string['analytics:mention_count'] = 'Mentions';
$string['analytics:common_prompts'] = 'Modèles Invite Communs';
$string['analytics:common_prompts_desc'] = 'Modèles laaj yi dañu repeteer soo ci élèves yi. Réexaminer yooy pour identifier lacunes systémiques ci ndigël cours bi.';
$string['analytics:prompt_pattern'] = 'Modèle';
$string['analytics:frequency'] = 'Fréquence';
$string['analytics:recent_activity'] = 'Activité Récente';
$string['analytics:no_data'] = 'Amul encore données analytics. Données yi dinañu soo di jël ngëm manque mujjub élèves yi soo jëfandikoo professeur AI bi.';
$string['analytics:timerange'] = 'Plage horaire';
$string['analytics:last_7_days'] = '7 fan yi mu gëna mujjub';
$string['analytics:last_30_days'] = '30 fan yi mu gëna mujjub';
$string['analytics:all_time'] = 'Ci fan bu dëkk';
$string['analytics:export'] = 'Exporter données';
$string['analytics:provider_comparison'] = 'Comparaison Fournisseur AI';
$string['analytics:provider_comparison_desc'] = 'Comparer performances ci fournisseurs AI yi jëfandikoo ci cours boobu.';
$string['analytics:provider'] = 'Fournisseur';
$string['analytics:response_count'] = 'Réponses';
$string['analytics:avg_response_length'] = 'Longueur moy réponse';
$string['analytics:total_tokens'] = 'Total tokens';
$string['analytics:avg_tokens'] = 'Moy tokens / réponse';

// User settings.
$string['usersettings:title'] = 'Jëkkër AI ci Cours bi - Say Données';
$string['usersettings:intro'] = 'Gérer sa données wax-ak-wax professeur AI ak réglages vie privée';
$string['usersettings:privacy_info'] = 'Say wax-ak-wax ak professeur AI dañ ko stocker pour fournir ndimmal continu ci sa cours. Am nga contrôle complète ci données yooy te mënula ko effacer ci waxtu bu la nexxee.';
$string['usersettings:usage_stats'] = 'Say Statistiques Utilisation';
$string['usersettings:total_messages'] = 'Total bataaxal yi';
$string['usersettings:total_conversations'] = 'Wax-ak-wax yi';
$string['usersettings:messages'] = 'Bataaxal yi';
$string['usersettings:last_activity'] = 'Dernier activité';
$string['usersettings:delete_course_data'] = 'Effacer données cours bi';
$string['usersettings:no_data'] = 'Dëgëer nga jëfandikoo professeur AI. Say données utilisation dinañu soo nekk fii su jëf ula wax-ak-wax.';
$string['usersettings:delete_all_title'] = 'Effacer Dëgg Say Données';
$string['usersettings:delete_all_warning'] = 'Looyu effacera définitivement dëgg say wax-ak-wax professeur AI ci cours yépp. Action yooy dëgëer dina mën a yegeel.';
$string['usersettings:delete_all_button'] = 'Effacer Dëgg Say Données';
$string['usersettings:confirm_delete_course'] = 'Mbaa dëgg nga bëgg a effacer définitivement dëgg say données professeur AI ci cours "{$a}"? Action yooy dëgëer dina mën a yegeel.';
$string['usersettings:confirm_delete_all'] = 'Mbaa dëgg nga bëgg a effacer définitivement DËGG say données professeur AI ci cours yépp? Action yooy dëgëer dina mën a yegeel.';
$string['usersettings:data_deleted'] = 'Say données dañ ko effacer.';

// === SOLA v1.0.12 — updated/new strings ===

$string['chat:greeting'] = 'Mangi fi, {$a}! Maa ngi di SOLA. Naka laa lay mën dimbalee tey?';
$string['chat:title'] = 'SOLA';
$string['chat:assistant'] = 'SOLA';
$string['chat:open'] = 'Ubbi SOLA';
$string['chat:change_avatar'] = 'Soppal avatar bi';

$string['chat:quiz'] = 'Jëf ëntërviu jàng';
$string['chat:quiz_setup_title'] = 'Ëntërviu Jàng';
$string['chat:quiz_questions'] = 'Njomu laaj yi';
$string['chat:quiz_topic'] = 'Sujet bi';
$string['chat:quiz_topic_guided'] = 'AI dëkk (ci kanam sa yeg-yeg bi)';
$string['chat:quiz_topic_adaptive']      = 'Adaptiv — gëstu sama wenn yu xiif';
$string['chat:quiz_topic_default'] = 'Ndigël cours bi ci waxtu bi';
$string['chat:quiz_topic_custom'] = 'Sujet bu la neexee…';
$string['chat:quiz_custom_placeholder'] = 'Bind sujet walla laaj...';
$string['chat:quiz_start'] = 'Jëf Ëntërviu Bi';
$string['chat:quiz_cancel'] = 'Annuler';
$string['chat:quiz_loading'] = 'Dañuy génère ëntërviu bi…';
$string['chat:quiz_error'] = 'Mënuñu génère ëntërviu bi. Jëf alal ci kanam.';
$string['chat:quiz_correct'] = 'Dëgëer!';
$string['chat:quiz_wrong'] = 'Dafa faw.';
$string['chat:quiz_next'] = 'Laaj bi ci kanam';
$string['chat:quiz_finish'] = 'Xool résultats yi';
$string['chat:quiz_score'] = 'Ëntërviu bi jeex! Dañ la jàpp {$a->score} ci {$a->total}.';
$string['chat:quiz_summary'] = 'Mu ngi jëf ëntërviu jàng bu am {$a->total} laaj ci "{$a->topic}" te am {$a->score}/{$a->total}.';
$string['chat:quiz_topic_objectives'] = 'Xelam Jàng Yi';
$string['chat:quiz_topic_modules'] = 'Sujet Cours Bi';
$string['chat:quiz_subtopic_select'] = 'Tann benn élément bu dëgëer…';
$string['chat:quiz_topic_sections'] = 'Sections Cours Bi';
$string['chat:quiz_score_great'] = 'Baax na lool! Dëgëer nga xam matière bi.';
$string['chat:quiz_score_good'] = 'Jéf baax! Continues a reviser ngir dëgal sa xam-xam bi.';
$string['chat:quiz_score_practice'] = 'Continues a jéf — seeti matériels cours bi yu rapport, ba ci kanam jëf ëntërviu bi.';
$string['chat:quiz_review_heading'] = 'Révision';
$string['chat:quiz_retake'] = 'Jëf Ëntërviu Bi Ci Kanam';
$string['chat:quiz_exit'] = 'Dem Ëntërviu Bi';
$string['chat:quiz_your_answer'] = 'Sa réponse';
$string['chat:quiz_correct_answer'] = 'Réponse bu dëgëer bi';

$string['chat:starters_label'] = 'Jëge wax-ak-wax';
$string['chat:starter_quiz'] = 'Ëntërviy Ma ci Bii';
$string['chat:starter_explain'] = 'Fëkk Li';
$string['chat:starter_key_concepts'] = 'Xelam Yu Xorom';
$string['chat:starter_study_plan'] = 'Plan Jàng';
$string['chat:starter_help_me'] = 'Ndimmal AI';
$string['chat:starter_ai_project_coach'] = 'AI Projet Njiit';
$string['chat:starter_ell_practice'] = 'Jéef Waxtaan';
$string['chat:starter_ell_pronunciation'] = 'Liggéey Yégël ELL';
$string['chat:starter_ai_coach'] = 'Coach AI';
$string['chat:starter_speak'] = 'Wax jëge bi';

$string['chat:reset'] = 'Jëge ci kanam';

$string['chat:topic_picker_title'] = 'Lan la neexee a tëgël ci?';
$string['chat:topic_picker_title_help'] = 'Ci lan la neexee ndimmal?';
$string['chat:topic_picker_title_explain'] = 'Lan la neexee ma fëkk?';
$string['chat:topic_picker_title_study'] = 'Ci domaine bu lan la neexee a tëgël?';
$string['chat:topic_start'] = 'Dem ci kanam';

$string['chat:fullscreen'] = 'Xët bu sell';
$string['chat:exitfullscreen'] = 'Dem ci xët bu sell';

$string['chat:language'] = 'Soppal làkk bi';
$string['chat:settings_panel'] = 'Réglages';
$string['chat:settings_language'] = 'Làkk';
$string['chat:settings_avatar'] = 'Avatar';
$string['chat:settings_voice'] = 'Jàng';
$string['chat:settings_voice_admin'] = 'Réglages jàng yi dañ ko gérer ci panneau administration site bi.';

$string['chat:voice_mode'] = 'Mode jàng';
$string['chat:voice_end'] = 'Jeex séance jàng bi';
$string['chat:voice_connecting'] = 'Dañuy connecter...';
$string['chat:voice_listening'] = 'Dañuy dégg...';
$string['chat:voice_speaking'] = 'SOLA dañuy wax...';
$string['chat:voice_idle'] = 'Jëm';
$string['chat:voice_error'] = 'Connexion jàng bi defoo kaay. Seeti sa réglages yi.';
$string['chat:quiz_locked'] = 'SOLA dafa suspendu ci kanam ëntërviu yi ngir soxor intégrité académique bi. Yëgël sa jàng!';

// Bottom nav.
$string['chat:mode_nav'] = 'Mode navigation';
$string['chat:mode_chat'] = 'Chat';
$string['chat:mode_voice'] = 'Voice';
$string['chat:mode_history'] = 'Xam-xam';

// History panel.
$string['chat:history_title'] = 'Xam-xam ak Waxtaan bi';
$string['task:send_inactivity_reminders'] = 'Yónne bataaxal ci ayu-bis yu ngën-ngën yi ngir bañ liggéey';
$string['messageprovider:study_notes'] = 'Téere yi ci diggante jàng bi';
$string['task:send_inactivity_reminders'] = 'Yónnal bataaxal xalaat ci at ci biir ayu-bés';
$string['task:run_meta_ai_query'] = 'Doxal laaj bu Learning Radar ci waxtu bu ñu tànn';
$string['messageprovider:study_notes'] = 'Bindu ci jàng yi';

// CDN settings.
$string['settings:cdn_heading'] = 'CDN / Yónnee ci kanam';
$string['settings:cdn_heading_desc'] = 'Joxe njëlbéen kanam yi SOLA (JS/CSS) ci CDN bu biti ba, du ci Moodle files yi. Lii mën na may yeesal kanam bi te yeesal-atul plugin bi. Bayyi CDN URL bi neen ngir jëfandikoo plugin files yi ci biir.';
$string['settings:cdn_url'] = 'CDN URL bu njëkk';
$string['settings:cdn_url_desc'] = 'URL bu njëkk fu sola.min.js ak sola.min.css di dëkkee. Misaal: https://your-org.github.io/sola-cdn. Bayyi ko neen ngir jëfandikoo plugin files yi ci biir.';
$string['settings:cdn_version'] = 'Version njëlbéen CDN';
$string['settings:cdn_version_desc'] = 'Baat bu version bii ñu dugg ci CDN URLs yi ngir cache busting. Yeesal ko gannaaw CDN deploy bu nekk (misaal 3.2.4 walla commit hash).';

// Analytics dashboard.
$string['analytics:tab_overall'] = 'Jëfandikoo bu mat';
$string['analytics:tab_bycourse'] = 'Bu kuur bi';
$string['analytics:tab_comparison'] = 'AI ak ñi jëfandikuul';
$string['analytics:tab_byunit'] = 'Bu xët bi';
$string['analytics:tab_usagetypes'] = 'Yeneeni jëfandikoo';
$string['analytics:tab_themes'] = 'Njëkk';
$string['analytics:tab_feedback'] = 'Tekki AI';
$string['analytics:total_students'] = 'Ñépp ndongo yi';
$string['analytics:active_users'] = 'Ñi jëfandikoo AI';
$string['analytics:msgs_per_student'] = 'Bataaxal bu ndongo bi';
$string['analytics:avg_session'] = 'Diir bu njël bi';
$string['analytics:return_rate'] = 'Tolluwaay delloo';
$string['analytics:total_sessions'] = 'Ñépp njël yi';
$string['analytics:thumbs_up'] = 'Baaraam bu kow';
$string['analytics:thumbs_down'] = 'Baaraam bu suuf';
$string['analytics:hallucination_flags'] = 'Xàmme njuumte';
$string['analytics:msgs_to_resolution'] = 'Bataaxal ba faj';
$string['analytics:helpful'] = 'Dimbali na';
$string['analytics:not_helpful'] = 'Dimbaliwul';
$string['analytics:flag_hallucination'] = 'Tontu bii am na xibaar yu baaxul';
$string['analytics:submit_rating'] = 'Yónne';
$string['analytics:thanks_feedback'] = 'Jërëjëf ci sa tekki';

// LLM provider names.
$string['settings:provider_mistral'] = 'Mistral AI';
$string['settings:provider_openrouter'] = 'OpenRouter';
$string['settings:provider_together'] = 'Together AI (Llama 3.1 8B/70B/405B Turbo, Mistral, Qwen)';
$string['settings:provider_xai'] = 'xAI (Grok)';

$string['settings:provider_coreai'] = 'Moodle AI (core_ai subsystem)';
// Strings added by update_langs.py.
$string['chat:starter_help_page'] = 'Xamal bii xët';
$string['chat:starter_ask_anything'] = 'Laaj lu la neexe';
$string['chat:starter_review_practice'] = 'Seet ak jëfandikoo';
$string['chat:history_saved_subtitle'] = 'Tontu yi ñu denc ñoo féete ci masiin bi ngir kurs bi.';
$string['chat:history_saved_empty'] = 'Dencal benn tontu AI ngir gis ko fi.';
$string['chat:history_views_label'] = 'Xoolu taariix';
$string['chat:history_view_saved'] = 'Dencu';
$string['chat:history_view_recent'] = 'Taariix';
$string['chat:debug_refresh'] = 'Yeesalaat';
$string['chat:debug_copy_all'] = 'Duppee yépp';
$string['chat:debug_close'] = 'Tëj';
$string['chat:language_switch'] = 'Soppi làkk';
$string['chat:language_dismiss'] = 'Bañ xalaat làkk';
$string['chat:llm_label'] = 'LLM';
$string['chat:llm_provider_select'] = 'Tànn jotkatu LLM';
$string['chat:llm_model_label'] = 'Modél';
$string['chat:llm_model_select'] = 'Tànn modél LLM';
$string['chat:footer_usertesting'] = 'Testu jëfandikoo';
$string['chat:footer_feedback'] = 'Xalaat';
$string['chat:voice_panel_title']       = 'Talk with {$a}';

// Additional translated strings.
$string['chat:debug_context'] = 'Fegal ci contexte bi';
$string['chat:debug_context_browser'] = 'Nataal navigateur';
$string['chat:debug_context_copy'] = 'Tëgg';
$string['chat:debug_context_prompt'] = 'Tontu serveur bi';
$string['chat:debug_context_request'] = 'Laajub SSE bi mujj';
$string['chat:debug_context_toggle'] = 'Weccii inspecteur';
$string['chat:history_empty'] = 'Amul waxtaan.';
$string['chat:history_refresh'] = 'Yeesal';
$string['chat:history_subtitle'] = 'Sa bataaxal yi mujj.';
$string['chat:starter_explain_prompt'] = 'Nettali xel bi gën a am solo?';
$string['chat:starter_help_lesson'] = 'Nettali li';
$string['chat:starter_help_lesson_prompt'] = 'May ma xam leçon bi. Résumé yi am solo.';
$string['chat:starter_prompt_coach'] = 'Coach AI';
$string['chat:starter_quick_study'] = 'Jàng bu gaaw';
$string['chat:starter_study_plan_prompt'] = 'Bëgg naa planifie jàng. Laaj: (1) but, (2) njëkk. Yeesal plan.';
$string['chat:voice_copy'] = 'Waxtaan bu bees ak adjoint bi.';
$string['chat:voice_ready'] = 'Pare';
$string['chat:voice_start'] = 'Tàmbalee';
$string['chat:voice_title'] = 'Waxtu ak SOLA';
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
$string['mobile_chip_concepts'] = 'Xel yi am solo';
$string['mobile_chip_quiz'] = 'Quiz';
$string['mobile_chip_studyplan'] = 'Plan jàng';
$string['mobile_clear'] = 'Far tariix bi';
$string['mobile_disabled'] = 'SOLA amul ci cours bii.';
$string['mobile_placeholder'] = 'Laaj laaj...';
$string['mobile_welcome'] = 'Dalal jam, {$a}!';
$string['mobile_welcome_sub'] = 'Maangi tudd SOLA, sa adjoint jàng bi. Nan la la mën a dimbali?';
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
$string['rubric:done'] = 'Jeex na';
$string['rubric:encourage_high'] = 'Liggéey bu baax! Kontaan!';
$string['rubric:encourage_low'] = 'Tambalee bu baax! Pratique bu tollu dina la dimbal.';
$string['rubric:encourage_mid'] = 'Jëf bu baax! Kontaan.';
$string['rubric:overall'] = 'Mbooleem';
$string['rubric:practice_again'] = 'Def pratique kenn yoon';
$string['rubric:score_title_conversation'] = 'Mbiri pratique waxtaan';
$string['rubric:score_title_pronunciation'] = 'Mbiri pratique tekki';
$string['rubric:scoring'] = 'Ci jokkoo ci évaluation...';
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
$string['demo:title'] = 'Testing Environment';
$string['demo:heading'] = 'Testing Environment';
$string['demo:intro'] = 'This page creates a testing course that is <strong>hidden from students</strong> (visible=0) and seeds it with fake students, AI conversations, ratings, and feedback. Useful for previewing the Analytics Dashboard or validating plugin changes without affecting any real enrolled student.';
$string['demo:step1'] = 'Step 1: testing course';
$string['demo:step2'] = 'Step 2: seed fake students and AI chats';
$string['demo:course_exists'] = 'Testing course exists: <strong>{$a->fullname}</strong> (shortname <code>{$a->shortname}</code>, id {$a->id})';
$string['demo:badge_hidden'] = 'hidden';
$string['demo:badge_visible'] = 'visible to students';
$string['demo:no_course'] = 'No testing course found. Click below to create one.';
$string['demo:create_btn'] = 'Create hidden testing course';
$string['demo:open_course'] = 'Open course &rarr;';
$string['demo:seed_intro'] = 'Creates demo_student_001, demo_student_002, ... enrols them in the testing course, and inserts synthetic conversations, messages, ratings, and feedback. Run again to add more data, or tick "clear first" to start over.';
$string['demo:users_label'] = 'Users';
$string['demo:weeks_label'] = 'Weeks';
$string['demo:clear_label'] = 'Clear existing demo_* users first';
$string['demo:seed_btn'] = 'Seed students and chats';
$string['demo:view_analytics'] = 'View Analytics for this course &rarr;';
$string['demo:footer'] = 'Data created here lives in the standard Moodle user / enrolment tables and the plugin\'s own conversation tables. The fake users all have usernames starting with <code>demo_student_</code> so they are easy to filter or remove. To remove them, run the seed step again with "Clear existing demo_* users first" checked.';
$string['demo:course_fullname'] = 'SOLA Testing Course (hidden)';
$string['demo:notify_created'] = 'Testing course ready: {$a->fullname} (id {$a->id}).';
$string['demo:notify_create_fail'] = 'Failed to create course: {$a}';
$string['demo:notify_seeded'] = 'Seeded: {$a->users} users, {$a->conversations} conversations, {$a->messages} messages, {$a->ratings} ratings, {$a->feedback} feedback entries.';
$string['demo:notify_seed_fail'] = 'Failed to seed data: {$a}';
$string['toc:analytics'] = 'Analytics Dashboard &rarr;';
$string['toc:tokenanalytics'] = 'Token Cost &amp; Analytics &rarr;';
$string['toc:testing'] = 'Testing Environment &rarr;';
$string['toc:back_to_course'] = '&larr; Back to {$a}';

// RAG extractor status strings (v3.9.6+).
$string['rag:pdftotext_missing'] = 'pdftotext binary not found; PDF extraction disabled.';
$string['rag:pdftotext_available'] = 'pdftotext binary detected at {$a}.';
$string['rag:docx_unavailable'] = 'PHP ZipArchive extension not available; DOCX extraction disabled.';
$string['rag:h5p_unavailable'] = 'H5P content could not be read; skipping.';
$string['rag:scorm_too_large'] = 'SCORM package exceeds the configured size limit ({$a} MB); skipping.';
$string['rag:scorm_unzip_failed'] = 'SCORM package could not be unzipped; skipping.';
$string['rag:transcript_fetch_failed'] = 'Could not fetch transcript from {$a}.';
$string['rag:transcript_cf_challenge'] = 'Transcript URL blocked by Cloudflare challenge: {$a}.';
$string['rag:embed_detected'] = 'Detected embedded media: {$a}';
$string['rag:embed_transcript_attached'] = 'Transcript attached for {$a}';

// v3.9.10–v3.9.14 new strings.
$string['usersettings:download'] = 'Yebal sama daata yu {$a}';
$string['usersettings:download_help'] = 'Yebal benn copie JSON bu mat sëkk ci bépp rekord {$a} bu mëlni ak sa kont: waxtaan, mesaas, jaay, plan njàng, fàttaliku, not yu jangale, tontu enquête, profil, ak entrée audit.';
$string['usersettings:privacy_notice_link'] = 'Jàng yégle privacy bu {$a}';
$string['privacy:title'] = 'Yégle Privacy bu {$a}';
$string['admin:user_data:title'] = '{$a} — Génne ak fey daata bu njàngalekat';
$string['admin:user_data:intro'] = 'Yoon wu liggéey ngir benn ñaan ci kaaraange GDPR Article 15 (jot) walla Article 17 (fey). Seet benn njàngalekat ci id user bu Moodle, xool reew yi plugin bi denc ci moom, te génne walla fey.';
$string['admin:user_data:search_label'] = 'Id user bu Moodle';
$string['admin:user_data:lookup'] = 'Seet';
$string['admin:user_data:not_found'] = 'Amul benn user ak id boobu.';
$string['admin:user_data:download'] = 'Yebal lépp daata bu njàngalekat ni JSON';
$string['admin:user_data:purge'] = 'Fey lépp daata bu njàngalekat ngir user bii';
$string['admin:user_data:confirm_purge'] = 'Fey ba fàww bépp rekord bu {$a}? Lii dafay jàll waxtaan, mesaas, jaay, plan njàng, fàttaliku, profil, not jangale, enquête, entrée audit, ak feedback. Mënul ñu ko delloo gannaaw.';
$string['admin:user_data:purged'] = 'Lépp daata bu user bi ñu tànn ñu fey ko.';
$string['chat:consent_heading'] = 'Bala ngay waxtaan ak {$a->product}';
$string['chat:consent_body'] = '{$a->product} benn asistan bu njàng la bu AI di doxal. Say bataaxal ak tontu yu {$a->product} ñu leen denc ci base de données Moodle bu {$a->institution} te fukki tuur yi gën a bees ñu leen yónni benn fournisseur bu model AI bu ñu nangu ngir tontu say laaj. Say tur bu njëkk ñu koy séddoo ngir personnalisation; amul beneen xibaar bu ràññee bu ñu yónni fournisseur AI bi. Su nga laajee ndimbalu nit te say laaj ñu ko yokk, waxtaan wii (boole ci sa tur ak email) ñu mën koo séddoo ak sunu ekibu ndimbal. Mën nga télécharge, far, walla bàyyi jëfandikoo {$a->product} ci saa su nekk.';
$string['chat:consent_accept'] = 'Damaa ko déggal, tàmbalil {$a}';
$string['chat:consent_privacy_link'] = 'Jàng yégle privacy bu mat sëkk';
$string['task:audit_cleanup'] = 'Setal taabal audit bu AI Course Assistant';
$string['task:conversation_retention'] = 'Tàrjuma deencal waxtaan bu AI Course Assistant';
$string['settings:audit_retention_days'] = 'Deencal log audit (fan)';
$string['settings:audit_retention_days_desc'] = 'Liggéey bu àndoo bés-bu-nekk dafay fey reew audit yi gën a màgg ci lii. 0 dafay teyel. Du tëj 365.';
$string['settings:conversation_retention_days'] = 'Deencal waxtaan (fan)';
$string['settings:conversation_retention_days_desc'] = 'Liggéey bu àndoo bés-bu-nekk dafay fey reew waxtaan yu gën màgg ci tampon bu mujj soppi. 0 dafay teyel. Du tëj 730.';
$string['settings:ssrf_trusted_endpoints'] = 'SSRF endpoint yu wóor';
$string['settings:ssrf_trusted_endpoints_desc'] = 'Benn URL ci benn ligne. Hosts yi nu liste defay weccu loopback / IP-privée / https-rekk ci validateur SSRF bi SOLA. Jëfandikoo kii rekk ci LLM yu sa-bopp-héberger ci réseau bi nga doxal — misaal <code>http://localhost:11434</code> ngir Ollama lokaal, <code>http://10.0.0.5:8000</code> ngir vLLM pod ci VPC bi. Comparaison di ànd ak scheme + host + port; ay path lépp ñu yàqaaye. Defóot dafa neen (blocer lépp luy biir). Ligne yi tàmbalee ak <code>#</code> ay commentaire la.';
$string['task:learner_weekly_digest']    = 'Jumtukaay Kurs AI - Sumb bu yor bu jàngalekat';
$string['learner_digest:subject']        = 'Sa simon ak {$a->course} - {$a->product}';
$string['learner_digest:optin_offer']    = 'Da nga bëgg benn email bu gàtt yor bi nga di sukk ci li ñu war a topp?';
$string['next_best_action:get_started']           = 'Tàmblee ak {$a->title}. Bañal ma te ñaan "may ma ndimbal ci {$a->title}".';
$string['next_best_action:get_started_with_module'] = 'Tàmblee ak {$a->title}. Moduul "{$a->module}" mu ngi ko boole.';
$string['next_best_action:review']                = 'Defaral seet bu yor {$a->title} — bañal ma te ñaan "wax ma {$a->title} ni dama bees".';
$string['next_best_action:review_with_module']    = 'Defaral seet bu yor {$a->title} ci "{$a->module}", ginnaaw bañal ma ak ñaan.';
$string['next_best_action:practice']              = 'Defar nag li nga am ci {$a->title}. Bañal ma te ñaan "may ma misal bu nu jub-jub ci {$a->title}".';
$string['next_best_action:practice_with_module']  = 'Jangu {$a->title} ak "{$a->module}". Bañal ma ngir misaal yu nu jub-jub.';
$string['next_best_action:quiz']                  = 'Tàmm {$a->title} ak nataal bu gàtt. Bañal ma te tàll "Nattu ma — yokkutè".';
$string['next_best_action:quiz_with_module']      = 'Tàmm {$a->title} ak nataal bu gàtt. Moduul "{$a->module}" mooy fi mu dëkk.';
$string['next_best_action:empty_state']           = 'Yaa ngi ko def bu rafet ci jëmm bu nekk fi mu nekk — dara amul, ñu fattali la. Topp.';
$string['next_best_action:header']                = '{$a} njariñ yi ngeen war a yegg ci kanam:';
$string['learner_digest:unsubscribe_done_title']  = 'Bayyiwoon';
$string['learner_digest:unsubscribe_done_body']   = 'Mat na — du nga jot leeg gan e-mail bu yor ci njàng wii ci {$a->product}. Mënnga waxi ndar leeg-leeg ci palanteer bi ci sa njàng.';
$string['learner_digest:unsubscribe_invalid_title'] = 'Lëkkalekaay bi nga bayyiwoon dafa jeex';
$string['learner_digest:unsubscribe_invalid_body']  = 'Lëkkalekaay bii dafa jeex walla mu yàqu. Mënnga doxal sa ndimbal e-mail ci sa konfigurasion njàng.';
$string['active_learners:line']                   = '{$a} yeneen ñu ngi jàng njàng wii sii.';
$string['active_learners:line_global']             = '{$a} ñeneen ñu ngi jàng leegi.';
$string['settings:active_learners_scope']          = 'Réew bu indikatoor jàngalekat yi sax-sax';
$string['settings:active_learners_scope_desc']     = 'Ndax indikatoor "ñeneen ñu ngi jàng leegi" ci kaw chat starters wax na rekk jàngalekat yi nekk ci njàng wii ngir di yall, walla jàngalekat yi nekk ci site bi yépp. Defóot <strong>fépp</strong>.';
$string['settings:active_learners_scope_global']   = 'Fépp (njàng wu mu mën a doon)';
$string['settings:active_learners_scope_course']   = 'Njàng-njàng rekk';
$string['learner_digest:optin_yes']      = 'Waaw, yónni ma email bi yor';
$string['learner_digest:optin_no']       = 'Déédéet, jërëjëf';
$string['learner_digest:optin_thanks']   = 'Mat na. Dinga jot sumb bu yor benn altine bu nekk.';
$string['learner_digest:optin_declined'] = 'Mat na. Amul email - ubbi ma bes bu nga bëgg a checki.';
$string['settings:xai_proxy_url'] = 'URL proxy xAI Realtime';
$string['settings:xai_proxy_url_desc'] = 'URL wss bu mbubbu bu service proxy xAI Realtime bu SOLA (misaal wss://voice.example.com/xai-rt/rt). Bu loolu ñu ko def ak kaaraange JWT, baat xAI dafay jaar ci proxy bi te clé API xAI bu màggat du jàll ci navigateur bi. Bàyyi ko amul ngir delloo ci connexion direct bi (du jëfandikoo ci production).';
$string['settings:xai_proxy_jwt_secret'] = 'Kaaraange JWT bu proxy xAI Realtime';
$string['settings:xai_proxy_jwt_secret_desc'] = 'Kaaraange séddoo HS256 bi ñu jëfandikoo ngir signe token session yu gàtt ngir proxy xAI Realtime. War na tax ak kaaraange MOODLE_JWT_SECRET ci Cloudflare Worker bi. Soppi ko ay yoon.';
$string['admin:vendor_dpa:title'] = '{$a} — Doxalin DPA bu fournisseur';
$string['admin:vendor_dpa:intro'] = 'Bañal training, DPA, ak deencal ngir bépp pilote fournisseur AI. Jëfandikoo lii ngir tànn pilote yi ngay door ci sa site. Routage Tier 2 ak ci kaw dafay laaj DPA bu ñu signe ak bañal training bu kontra.';
$string['admin:vendor_dpa:maintenance_note'] = 'Taabal jii ñu ngi koy denc ci classes/vendor_registry.php. Soppi ko bu ToS bu fournisseur soppikoo.';
$string['settings:contact_email'] = 'Contact email';
$string['settings:contact_email_desc'] = 'General contact email shown on the learner facing privacy notice under "Contact". Defaults to contact@saylor.org. Leave empty to hide the line.';
$string['settings:dpo_email'] = 'Email bu Cudd Kaaraange Daata';
$string['settings:dpo_email_desc'] = 'Email contact bi ñu wone ci yégle privacy bu njàngalekat ci "Contact". Bàyyi ko amul ngir nëbb liñ. Installation yu soppi marque dañu war a sukkandi ko ci seen DPO.';
$string['settings:privacy_external_url'] = 'URL pajaay privacy bu institution';
$string['settings:privacy_external_url_desc'] = 'Lëkkalekaay ci pajaay privacy bu njeexital institution, ñu ngi koy wone ci yégle privacy bu njàngalekat ci "Contact". Bàyyi ko amul ngir nëbb liñ.';
$string['settings:privacy_notice_override'] = 'Soppi yégle privacy (HTML)';
$string['settings:privacy_notice_override_desc'] = 'Bu ñu ko def, HTML bii dafay wuutu yégle privacy bu marque bu standard ñu ngi koy wone ca /local/ai_course_assistant/privacy.php. Jëfandikoo ko ngir bokksi mbind mu Yoon-saytu bu sa institution ci suuf changement code. Bàyyi ko amul ngir jëfandikoo yégle bu standard, mi sukkandi ci juróom-ñaari kaaraange config branding.';
$string['objectives:title'] = 'Yitte njàng ak mastery';
$string['objectives:toggles_heading'] = 'Topp mastery';
$string['objectives:toggle_master'] = 'Tàmbalil topp mastery ngir cours bii';
$string['objectives:toggle_chip'] = 'Wone tëgg Mastery Njàng ngir njàngalekat yi';
$string['objectives:toggle_chip_help'] = 'Tànn. Bu fofu, mastery dafay topp ndimbal bi ci kumpa waaye njàngalekat yi duñu gis indicateur.';
$string['objectives:toggled'] = 'Configuration ñu ko soppi.';
$string['objectives:detected_heading'] = 'Gis nañu {$a->count} yitte njàng ci {$a->source}.';
$string['objectives:source_competency'] = 'kompetan Moodle';
$string['objectives:source_summary'] = 'résumé cours';
$string['objectives:source_section'] = 'section walla peggal njëkk';
$string['objectives:source_page'] = 'peggal cours';
$string['objectives:source_llm'] = 'génne ak AI';
$string['objectives:source_manual'] = 'duggal ak loxo';
$string['objectives:source_none'] = 'amul source automatique';
$string['objectives:import_detected'] = 'Bokksi yitte yi ñu gis';
$string['objectives:import_llm'] = 'Génne yitte ak AI';
$string['objectives:llm_empty'] = 'Génne ak AI delloowul yitte. Jéemaat walla bind leen ak loxo.';
$string['objectives:imported'] = 'Bokksi nañu {$a} yitte.';
$string['objectives:none_detected'] = 'Amul yitte njàng yu ñu gis automatiquement. Bind leen ak loxo ci suuf, walla jëfandikoo génne ak AI.';
$string['objectives:list_heading'] = 'Yitte yi am';
$string['objectives:col_code'] = 'Kod';
$string['objectives:col_title'] = 'Tur';
$string['objectives:col_source'] = 'Source';
$string['objectives:col_actions'] = 'Jëf';
$string['objectives:add_heading'] = 'Yokk benn yitte';
$string['objectives:add_submit'] = 'Yokk yitte';
$string['objectives:saved'] = 'Yitte ñu ko denc.';
$string['objectives:deleted'] = 'Yitte ñu ko fey.';
$string['objectives:delete_confirm'] = 'Fey yitte bii ak lépp historique jéem ngir moom?';
$string['objectives:delete_all'] = 'Fey lépp yitte ngir cours bii';
$string['objectives:delete_all_confirm'] = 'Fey bépp yitte ak lépp historique jéem ngir cours bii? Mënul ñu ko delloo gannaaw.';
$string['objectives:deleted_all'] = 'Lépp yitte ngir cours bii ñu leen fey.';
$string['mastery:chip_aria'] = 'Doxalin mastery njàng';
$string['mastery:popover_aria'] = 'Détay mastery njàng';
$string['mastery:chip_label'] = '{$a->mastered} ci {$a->total} mastered';
$string['mastery:status_mastered'] = 'mastered';
$string['mastery:status_learning'] = 'ci yoon';
$string['mastery:status_not_started'] = 'tàmbaliwul';
$string['mastery:popover_empty'] = 'Amul yitte njàng yu ñu config ngir cours bii.';
$string['settings:mastery_heading'] = 'Topp mastery';
$string['settings:mastery_heading_desc'] = 'Fonction bu opt-in cours-bu-nekk biy bind tontu quiz ak waxtaan ndimbal ak yitte njàng bu cours, te delloo benn ngirte mastery bu gàtt ci system prompt ngir jiitee laaj. Suufe ci default: njàngalekat duñu gis dara su toggle tëgg cours-bu-nekk amul ko.';
$string['settings:mastery_threshold'] = 'Seuil mastered';
$string['settings:mastery_threshold_desc'] = 'Précision biy daw ci kaw walla yem ak lii la, yitte ñu koy bind ni mastered. 0.0 ba 1.0. Du tëj 0.85.';
$string['settings:mastery_window'] = 'Palanteer jéem';
$string['settings:mastery_window_desc'] = 'Limu jéem yi gën a yees ngir yitte yu nekk yu ñu pesee ci précision biy daw. Du tëj 8.';
$string['settings:mastery_decay_enabled']        = 'Tàmblee yokkuteb mekkuwaay';
$string['settings:mastery_decay_enabled_desc']   = 'Bu sotti, point mekkuwaay yi dañu fenku ci jamono ci tey ci jamono bi nu ko jëfe waxtu. Bopp bu nu ko mekku ba pare dafay dellu ci "jàng" su waxtu doy. Du wàcc ci jëkk "jàng". <strong>Defóot temm na ci v4.0.</strong>';
$string['settings:mastery_decay_half_life_days'] = 'Doomu jamono mekkuwaay (fan)';
$string['settings:mastery_decay_half_life_days_desc'] = 'Doomu jamono ci fan. Point bi day yokk ak 0,5 ^ (fan yi ci atum laata / doomu jamono). Defóot 30. Tey jëfandikoo soo bañ tàmblee.';
$string['settings:mastery_classifier_model'] = 'Modèle classifieur';
$string['settings:mastery_classifier_model_desc'] = 'Modèle bi ñu jëfandikoo ngir bind waxtaan ndimbal ci yitte. Bàyyi ko amul ngir donn modèle fournisseur AI bu standard; lu mu doon, tànn benn modèle bu yomb ni gpt-4o-mini.';
$string['settings:mastery_classifier_weight'] = 'Pees classifieur';
$string['settings:mastery_classifier_weight_desc'] = 'Lu jéem waxtaan dëgg-dëgg ci jéem quiz (1.0). Du tëj 0.3.';
$string['settings:mastery_classifier_threshold'] = 'Seuil dëgg-dëgg classifieur';
$string['settings:mastery_classifier_threshold_desc'] = 'Limu dëgg-dëgg classifieur bi ñu laaj ngir denc benn jéem waxtaan. 0.0 ba 1.0. Du tëj 0.7.';
$string['chat:mode_progress'] = 'Yokkute';
$string['objectives:toggle_dashboard'] = 'Wone onglet tablo Yokkute ngir njàngalekat yi';
$string['objectives:toggle_dashboard_help'] = 'Tànn. Yokk benn onglet Yokkute ci wetu Waxtaan / Baat / Taariix ci widget bi. Onglet bii dafay wone njàngalekat yi yitte yu ñu mastered, yi ñu nekk ci yoon, ak yi ñu tàmbaliwul.';
$string['mastery:dashboard_title'] = 'Sa yokkute njàng';
$string['mastery:dashboard_subtitle'] = 'Mastery, ñu koy peese ci sa tontu quiz ak ci sa luy waxtaan. Daldi ko jëf — xeel mooy raw yaatu.';
$string['mastery:dashboard_refresh'] = 'Yeesalat';
$string['mastery:section_mastered'] = 'Mastered';
$string['mastery:section_learning'] = 'Ci yoon';
$string['mastery:section_not_started'] = 'Tàmbaliwul';
$string['mastery:summary_label'] = '{$a->mastered} ci {$a->total} yitte yu mastered';
$string['mastery:ask_about'] = 'Laaj ci lii';
$string['mastery:celebrate'] = 'Master nga bépp yitte ngir cours bii. Liggéey bu rafet.';
$string['mastery:ask_template'] = 'Dimbalil ma jangale ak gën a xam yitte bii: {$a}.';
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
$string['settings:current_page_content_maxchars_desc'] = 'Lim bu mag bu araf yu mbindu xët wi nekk fii mu nekk yu ñuy duggal ci system prompt bi ni xaaj bu "Current Page Content", su RAG dee teg. Njëg mu jiitu 8,000 dafay sukkandiku bu baax ci laaj yu jëm ci xët wi te bàyyi njëg ngir aada ak ndigal yi. (Su RAG ubbiku, xët wi dañ koy sukkandiku ci ay xët yam yu gën a jëm ci mbir mi, yu jëm ci xët bi nekk leegi, kon dig bii du jëfe.) Xët bu gudd lool dañ koy gor ci njureef ba lim bii araf, kon mujju xët bu gudd lool mën nañ koo bañ a wax; ubbi RAG dafay moytu loolu. Site yu jël njëg ci xel mën nañ wàññi ko (ci misaal 3,000-4,000). Tegtu ci diir 500-8,000. Bokkul ak <code>prompt_budget_chars</code>: lii dafay teg rekk xaaju xët bi; njëg mi dafay teg prompt bi yépp.';
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
$string['pedagogy:crossmastery'] = 'Ycoon ëmbu ci diggante kurs yi nafter ci ñu-am';
$string['pedagogy:crossmastery_desc'] = 'Bu nekkee dox, SOLA dafay xam su nekkee ne jàngkat bi mën na ba pare benn pàcc ci beneen kurs (jàppante ci wàllu ndëgg mbaa ci tur) te dafay nangu xam-xam boobu jëkk ci lu dul ko delloo jàngat. Dafa soxla toppantéeg ëmbu; kurs yu amul pàcc yi dañuy delloo ci anam yu jaadu. Tegtal rekk la — du tax mukk ñu soppi nattukaayu ëmbu bu jàngkat bi ci benn kurs.';
$string['pedagogy:mastery_starter'] = 'Tàmbalikat bu xam ëmbu ci nekk dox ci ñu-am';
$string['pedagogy:mastery_starter_desc'] = 'Bu nekkee dox, tàmbalikatu waxtaan bi di "Lan laa war a sàmm sama xel?" dañu koy jagleel jàngkat bi ngir tudd pàcc bi gënal néew dooley (ak pàcc bu mu ba pare am ci beneen bërëb). Dafa soxla toppantéeg ëmbu; dafay dellu ci tàmbalikat bu yemale bi su amul benn xibaar ci ëmbu ba léegi.';
$string['task:rebuild_objective_links'] = 'Tabaxaat ay liggéeyu pàcc ci diggante kurs yi ngir ëmbu (v5.7.0)';
$string['mastery_starter:practice_label'] = 'Jàngat: {$a}';
$string['objectives:rebuild_links_heading'] = 'Liggéeyu ëmbu ci diggante kurs yi';
$string['objectives:rebuild_links_help'] = 'SOLA dafay liggéeyloo pàcc yi mengoo ci diggante kurs yi (ci wàllu ndëgg mbaa ci tur) ngir jàngkat bi ba pare am benn taar ci beneen bërëb bañ koo delloo jàngat. Liggéey yi dañuy tabaxaat boppam guddi gu nekk; jëfandikoo bii butoŋ ngir tabaxaat léegi su nga sopppee pàcc yi.';
$string['objectives:rebuild_links_button'] = 'Tabaxaat liggéey yi léegi';
$string['objectives:rebuild_links_done'] = 'Liggéeyu ëmbu ci diggante kurs yi tabaxaat nañu ko: {$a->total} ñoom ñépp ({$a->ref} ci wàllu ndëgg, {$a->exact} tur bu wóor, {$a->fuzzy} tur bu jege).';

// Forward learning-path awareness (v5.8.0).
$string['pedagogy:program_path'] = 'Xam yoonu jàng bu jëm kanam ci nekk dox ci ñu-am';
$string['pedagogy:program_path_desc'] = 'Bu nekkee dox, SOLA mën na wax jàngkat bi fan la kurs bii mu ci nekk di jëm ci porогraам bi (degree mbaa sertifika) ak ni xam-xam yu tey di jokkale ak kurs yu ñëw. Dafay jàng plugin Programs bu Moodle (Degrees ak Learn) te day tudd benn kurs bu ñëw bu jaar bu porogaraам bi wax benn lu nuy laaj bu jiitu mbaa benn tegtal bu war; bu ko deful, dafay tegtal fan la jàngkat bi nekk ci yoon wi. Tegtal rekk la — du soppi mukk mbooleem mbaa ëmbu, te day jëfandikoo rekk allocation bu porogaraам bu jàngkat bi ci boppam. Bu amul benn porogaraам bu jaar, dafay noppi te du def dara.';

// Learning path map + next-course nudge (v5.9.0).
$string['pedagogy:learning_path'] = 'Kaartu yoonu njàng ak xeetalu kuru bi ci topp dafa ubbi ci anam bu wér';
$string['pedagogy:learning_path_desc'] = 'Bu ubbioon, SOLA dafay yokk benn panel bu yoonu njàng bu gis-gis (benn buton "sama yoon" ci boppu widget bi) bu wonee prograamu jàngkat bi ni ab tëralin kuru, ku nekk mënees na ko yaatal ci ay jubluwaayam ak xam-xamu jàngkat bi. Bu jàngkat bi àggee ci xaaju kuru bi mu nekk (mottali walla wàllu jubluwaay yu bare yu mu xam), SOLA dafay it wone benn baner bu woyof "foog na para kuru bi ci topp" te wax ko ci waxtaan wi. Kërtu rekk la; dafay jëfandikoo allocation prograamu jàngkat bi; ci anam bu ne tekk du def dara fu amul prograam bu jëm.';
$string['settings:learning_path_mastery_threshold'] = 'Wàllu xam-xam bu yoonu njàng (%)';
$string['settings:learning_path_mastery_threshold_desc'] = 'Wàllu jubluwaay yu kuru bi topp yu jàngkat bi war a xam balaa xeetalu yoonu njàng di ko jàppe ni mu foog na para kuru bi ci topp. Mottalig kuru ci Moodle mooy yeneen tëggkat; li jëkk a am mooy jële xeetal bi. Bu wér: 80.';
$string['pathpanel_title'] = 'Sama yoonu njàng';
$string['pathpanel_open'] = 'Sama yoonu njàng';
$string['pathpanel_empty'] = 'Amul benn yoonu prograam bu jëm ci kuru bii ba tey.';
$string['path_position'] = 'Kuru {$a->index} ci {$a->total}';
$string['path_status_done'] = 'Mottali na';
$string['path_status_current'] = 'Fii nga nekk';
$string['path_status_upcoming'] = 'Di ñëw';
$string['path_mastery_mastered'] = 'Xam na ko';
$string['path_mastery_in_progress'] = 'Mu ngi dox';
$string['path_mastery_not_started'] = 'Tàmbalewul';
$string['path_mastery_demonstrated_elsewhere'] = 'Wonees na ko ci beneen kuru';
$string['nudge_ready_title'] = 'Foog na ngir kontine';
$string['nudge_ready_body'] = 'Liggéey bu baax — foog nga para {$a}.';
$string['nudge_view_path'] = 'Seet sama yoon';
$string['nudge_dismiss'] = 'Tëj';

// v5.10.x strings (token-aware budgeting, backend retry, self-test, deployment presets, escalation consent, privacy).
$string['settings:backend_context_tokens'] = 'Palanteeru kontekst bu backend (token)';
$string['settings:backend_context_tokens_desc'] = 'Gëtu kontekst gi gën a yaatu (max_model_len) ci sa backend AI, ci token. Defal ko 0 ngir model yu ñu host yu am palanteer bu mag (amul tënk). Su ñu ko defee ci kaw 0 (misaalu 8192 ci benn backend vLLM bu nga host sa bopp), SOLA dafay wàññi bujet karakter bi ci kaw bu sistem-prompt bi ngir prompt bi ak output bi ñu reserve ak istuwaaru waxtaan wi mën a dugg ci palanteer bi, sax ci làkk yu am token yu bare. Xoolal xët wi Deployment Sizing ci wiki bi ngir xam ni mu nuru ak jëfandikukat yu bokk waxtu.';
$string['settings:backend_retry_attempts'] = 'Jéem yu ñu jéemaat backend';
$string['settings:backend_retry_attempts_desc'] = 'Ñaata yoon lañu war a jéemaat benn njuumte bu backend bu dul yàgg (HTTP 429 walla 503) laata ñu wone njàngalekat bi njuumte. Jéemaat yi day am rekk laata benn mbind mu tontu di streame, kon output bi du jëf ñaari yoon. Ñu ko jëmale ci backend yu ndaw yu ñu host seen bopp yu tanqamlu ngañaay yi su barina liggéey. Defal ko 0 ngir faral ko. Bu defawu 2.';
$string['settings:backend_retry_max_wait'] = 'Xaar bu gën a yaatu ngir jéemaat backend (saa yi)';
$string['settings:backend_retry_max_wait_desc'] = 'Dayo bu kawe, ci saa yi, ñaata lañu war a topp benn entête Retry-After bu jóge ci backend bi laata ñu jéemaat. Su backend bi yónniwul Retry-After, SOLA dafay jëfandikoo ab backoff bu exponential bu gàtt ci dëgg-dëgg. Bu defawu 5.';
$string['prompt:truncation_hint'] = 'JOXE: Ñu mënul woon a seet lépp li nekk ci kurs bi ci wàll wii ndax tënk yu am ci gàttaay. Su njàngalekat bi laajee benn lu nga mënul gis ci sa li ñu jox, waxal ne mënuloo woon a seet kurs bi gépp te jébbal leen ñu ubbi xët walla aktiwite bu jëmmu li wax ci, ndax wax ne amul ci kurs bi.';
$string['selftest:title'] = 'Seetlu bopp bu backend';
$string['selftest:intro'] = 'Doxal ab seetlu bu jëmm ci sa backend AI bi nga configure: benn waxtaan bu ndaw bu dem-dellu, ràññee bu otomatik gëtu kontekst gi (max_model_len) ak benn mengale ak sa paramaatru gëtu kontekst bu backend, suufu bujet bu sistem-prompt bi, ak (su RAG bi ubbeeku) benn dem-dellu bu embedding. Wooteyu rëdd yi day dox rekk su nga bësee Doxal.';
$string['selftest:run'] = 'Doxal seetlu bopp bu backend';
$string['selftest:check'] = 'Seet';
$string['selftest:status'] = 'Doxin';
$string['selftest:detail'] = 'Leeral';
$string['selftest:link'] = 'Xët wu seetlu bopp bu backend';
$string['selftest:link_desc'] = 'Ubbil xët wi <a href="{$a}">Seetlu bopp bu backend</a> ngir dëggal ne sa backend AI dafay dox te am na dayo bu jub. Am na njariñ ci saa si nga configure benn backend bu nga host sa bopp.';
$string['profile:title'] = 'Préset yu déploiement';
$string['profile:intro'] = 'Sàmpal ab mboolemu paramaatru ñu digal ngir sa xeetu déploiement. Liggéeykat yi ñu bind ci paramaatru yu normal yu plugin bi te ñu mën leen a soppi kenn-kenn gannaaw. Sàmp benn préset day bind ci kaw paramaatru yi ñu lim.';
$string['profile:current'] = 'Préset bi mujj ñu sàmp: {$a}';
$string['profile:setting'] = 'Paramaatar';
$string['profile:value'] = 'Njëg';
$string['profile:self_hosted_small'] = 'Kontekst bu ndaw bu nga host sa bopp (benn GPU, misaal A30 24GB / vLLM ci 8K)';
$string['profile:hosted_large'] = 'Kontekst bu mag bu ñu host (bu defawu)';
$string['profile:apply_self_hosted_small'] = 'Sàmp préset bu kontekst bu ndaw bu nga host sa bopp';
$string['profile:apply_hosted_large'] = 'Sàmp njëg yu defawu yu kontekst bu mag bu ñu host';
$string['profile:applied'] = 'Sàmp nañu préset bi {$a}. Njëg yi nekk nañu leegi ci sa paramaatru plugin.';
$string['profile:unknown'] = 'Préset bu déploiement bu ñu xamul.';
$string['profile:link'] = 'Xët wu préset yu déploiement';
$string['profile:link_desc'] = 'Ubbil xët wi <a href="{$a}">Préset yu déploiement</a> ngir sàmp ab mboolemu paramaatru ñu digal ngir benn backend bu ñu host walla bu nga host sa bopp.';
$string['settings:zendesk_require_consent'] = 'Laaj ndigal laata yokk ci ndimbal';
$string['settings:zendesk_require_consent_desc'] = 'Su ubbeeku (ñu ngi ko digal), SOLA dafay yokk benn waxtaan ci biro ndimbal bu Zendesk rekk su janganté bi nangoo woon yégle bu ndigal bu njëkk, bi feeñal ne laaj ndimbalu nit dafay séddoo waxtaan wi (boole ci tur ak email) ak ndimbal. Faralal ko rekk su nga am ndigal googu ci beneen anam; su mu faraloo, yokk yi day yónni ci saa si. Amul njariñ lu mu mënti doon su Zendesk yokk bi ubbeekuwul.';
$string['chat:escalation_needs_consent'] = 'Mel na ni lii dafa soxla benn ku bokk ci sunu ekibu ndimbal. Ngir jébbal ko ñoom, dama war a séddoo woon waxtaan wii, boole ci sa tur ak email, ak biro ndimbal bi. Nangoowuloo ko ba leegi, kon yónniwuma dara. Su nga bëggoon ndimbalu nit, jëfandikool nangu yégle bu séddoo donné yi ngir asistan bii te laajaat, walla jokkoo ak ndimbal bi ci anam bu jub.';
$string['privacy:metadata:email_optout'] = 'Tànneef yu génn ci email ci ku ko jot (ban xeeti email la kuy jot génnee ci abonné).';
$string['privacy:metadata:email_optout:email'] = 'Adres email bu ku ko jot bi génn bi jëm.';
$string['privacy:metadata:email_optout:optout_type'] = 'Xeetu email bi ku ko jot génnee.';
$string['privacy:metadata:email_optout:userid'] = 'Jëfandikukat Moodle bi génn bi am, su ñu ko xam.';
$string['chat:consent_scroll_hint'] = 'Su la neexee, wàcceel ba ci suuf ngir jàng yégle bi yépp balaa ngay dem kanam.';
$string['settings:rag_min_similarity'] = 'Jëmm gu gën a tuuti (cosine)';
$string['settings:rag_min_similarity_desc'] = 'Dindil ay xët yu ñu jëlee yu seen wéeruwaay cosine ci laaj bi ëpp suuf ndax njëg mi, ngir benn laaj bu jëm fenn walla bu am tuuti yokk ay xët yu néew (walla dara) ci kaw rëdd ba Top-K ak ay melokaan yu woyof. Diir 0 ba 1; 0 dafay teg buntu bi. Njëg mu jaadu mi dafa aju ci modelu embedding bi: 0.25 dafa baax ci text-embedding-3-small. Yokkal ko ngir mu gën a dëgër (lëkkaloo gu néew, gu gën a jëm ci mbir mi), wàññi ko ngir mu gën a yombal.';
$string['settings:rag_currentpage_boost'] = 'Yokkute xët wi nekk fii mu nekk';
$string['settings:rag_currentpage_boost_desc'] = 'Benn ndampaay bu tuuti bu ñu yokk ci njëgu jëmm bu ay xët yu jóge ci xët bi ndongo li di gëstu leegi, ngir laaj yu mel ni "firil lii" tànn xët bi ñuy gis bu njëg yi jege. Tegtal rekk: du sonal benn xët bu jëmmadi mu jàll buntu jëmm bu gën a tuuti bi. Tegal 0 ngir teg ko.';
$string['settings:history_mode'] = 'Anamu tànn taariix';
$string['settings:history_mode_desc'] = 'Ni ñuy tànne ay waxtaan yu jàll laata ñu koy yónnee ci modeel bi. <strong>Semantik</strong> dafay denc rekk ay waxtaan yu bees yu jëm ci laaj bi nekk leegi (te dencat waxtaan wi gën a bees), ngir benn waxtaan bu jëkk bu màggat te jëm fenn bañ a yokk njëg walla bañ a dëngal tontu li; dafay def benn woote embedding gu yokku ci kenn-kenn bataaxal. <strong>Bees</strong> dafay denc paar yi mujj yu "Max Conversation History" lu mu mën a doon seen jëmm (jikko ju yàgg, amul woote gu yokku). Su embedding amul, anamu semantik bi dafay dellu ci anamu bees ci anam bu yomb.';
$string['settings:history_mode_semantic'] = 'Semantik (waxtaan yu bees yu jëm ci mbir mi)';
$string['settings:history_mode_recency'] = 'Bees (N paar yu mujj)';
$string['settings:history_semantic_minscore'] = 'Suufu jëmmu taariix (cosine)';
$string['settings:history_semantic_minscore_desc'] = 'Ci anamu taariix semantik, benn waxtaan bu jëkk dañ koy denc rekk su seen wéeruwaay ak laaj bi nekk leegi mat na njëg mii (waxtaan wi gën a bees dañ koy denc lépp). Diir 0 ba 1; dafa aju ci modeel bi. Yokkal ngir mu gën a dëgër (taariix bu néew), wàññi ngir denc lu ëpp.';
$string['settings:history_candidates'] = 'Palanteeru kàndidaa taariix';
$string['settings:history_candidates_desc'] = 'Ci anamu taariix semantik, paar yi gën a bees yu tollu nii rekk lañuy jagleel njëg (benn dig njëg). Paar yu gën a màggat ci palanteer bii duñu leen yónnee. Tegal ko mu yam walla ëpp "Max Conversation History".';

// v5.11.0 - v6.2.0 strings (added 2026-06-10).
$string['settings:embed_provider_voyage'] = 'Voyage AI (voyage-3.5 — ñu digal ko; +4 MTEB ba OpenAI 3-small, kontekst bu mag 4 yoon, multilingual)';
$string['settings:rerank_heading'] = 'RAG: Jéem yi ñaari étape (re-ranking)';
$string['settings:rerank_heading_desc'] = 'Étape ñaari bu jéem bi ñu soxor: similarité cosine dafay tann chunk yu candidate yu kawe-N (50 bu defawul), ci kanam cross-encoder re-ranker bi day sore kenn-kenn ci yu (query, chunk) te top-K yu baax yi jóge ci prompt bi. Faraloo ci bu defawul; dëgël ci cosine étape-wern su re-ranker bi configuréewul walla sekkoo.';
$string['settings:rerank_enabled'] = 'Jéem yi ñaari étape (Voyage rerank-2.5)';
$string['settings:rerank_enabled_desc'] = 'Su ubbeeku, RAG retrieval bi dëkkal ñaari étape: cosine similarity day jox N candidate yu kawe (50 bu defawul), ci kanam Voyage rerank-2.5 cross-encoder day sore kenn-kenn te top-K jóge ci prompt bi. Améliorasion yu publiye: +15 Recall@10 entreprise, +39% NDCG BEIR. ~$0.05/MTok ci njëg. Soxor <code>rerank_apikey</code> ci suuf; dëgël ci cosine étape-wern su rerank bi sekkoo walla configuréewul.';
$string['settings:rerank_apikey'] = 'Rerank API key';
$string['settings:rerank_apikey_desc'] = 'Voyage AI API key ngir rerank-2.5. Baaral te jëfandikoo Embedding API Key bi ci kaw (déploiement yu Voyage di jëfandikoo benn key pour embed + rerank).';
$string['settings:rerank_model'] = 'Rerank model';
$string['settings:rerank_model_desc'] = 'Bu defawul <code>rerank-2.5</code>. Mën ngeen sàmm model yu rerank yu Voyage yu muj ci bii.';
$string['settings:rerank_apibaseurl'] = 'Rerank API base URL';
$string['settings:rerank_apibaseurl_desc'] = 'Sàmmal Voyage rerank base URL bi. Baaral te jëfandikoo Embedding API Base URL bi ci kaw, walla bu defawu Voyage (<code>https://api.voyageai.com/v1</code>).';
$string['settings:rerank_candidates'] = 'Palanteer candidat yu rerank';
$string['settings:rerank_candidates_desc'] = 'Ñaata cosine top-N candidat ñu yónniy ci étape rerank bi. 50 bu defawul. Palanteer bu mag dafa jox re-ranker bi lii mu laaj ak njëg bu ndaw (~10k token ci rerank op wern).';
$string['settings:stt_selfhosted_heading'] = 'Transkripsiyon bu nga host sa bopp (Whisper)';
$string['settings:stt_selfhosted_heading_desc'] = 'Doxal speech-to-text ci sa hardware sa bopp ak zéro ñaar ci minit. Saam SOLA ci benn serveur transkripsiyon compatible OpenAI: <code>whisper-server</code> Docker, <code>speaches</code> (faster-whisper), walla serveur <code>whisper.cpp</code>. Su ñu defee benn URL serveur ci bii, dinaa ko jël ngir yoon STT bu defawu; tann benn prestataire bi ñu nëkk di ko jàpp ci kaw ngir sàmmal. Su serveur bi nekk ci réseau privé walla http simpel, yëgle host wii ci SSRF trusted endpoints allowlist ci seksion sécurité bi.';
$string['settings:stt_selfhosted_url'] = 'Selfhosted STT server URL';
$string['settings:stt_selfhosted_url_desc'] = 'Base URL bu serveur transkripsiyon compatible OpenAI, misaalu <code>http://10.0.0.5:8000</code>. SOLA dafay ajoute <code>/v1/audio/transcriptions</code> ci kanam ci waxtelu wàllu; jëf ay full endpoint path. Baaral te faral selfhosted STT.';
$string['settings:stt_selfhosted_model'] = 'Selfhosted STT model';
$string['settings:stt_selfhosted_model_desc'] = 'Tur mu model bi ñu yónniy serveur bi, bës ak model Whisper bi mu load — misaalu <code>Systran/faster-whisper-small</code> ngir speaches walla <code>large-v3</code>. Baaral te yónniy <code>whisper-1</code>, li serveur yu selfhosted yu bari nangoo walla baaralel.';
$string['settings:stt_selfhosted_apikey'] = 'Selfhosted STT API key';
$string['settings:stt_selfhosted_apikey_desc'] = 'Soxoruwul. Serveur yu selfhosted yu bari ñëwul ak key ci bàkkaar réseau bu am jëm; defal ko rekk su sa serveur soxora benn bearer token.';
$string['emergency:title'] = 'Contrôle yu urgence yu SOLA';
$string['emergency:page_warning'] = 'Switch yi am effet ci saa si ngir jëfandikukat yépp ci site bi. Liggéey yi kenn-kenn dafay bind benn ligne audit. Switch yu détayé yi ñu bàyyi SOLA bi dox ba sëriñ; master kill bi day dëkk widget bi yépp.';
$string['emergency:back_to_settings'] = 'Paramètre yu SOLA';
$string['emergency:state_disabled'] = 'FARALOO';
$string['emergency:state_active'] = 'Aktif';
$string['emergency:confirm_label'] = 'Xam naa ne lii am effet ci jëfandikukat yépp ci saa si';
$string['emergency:confirm_required'] = 'Su la neexee, cocher case bi ci confirm laata nga faral benn sous-système.';
$string['emergency:reason_placeholder'] = 'Raison (ñu ko bind ci journal audit bi)';
$string['emergency:disable_button'] = 'Faral';
$string['emergency:restore_button'] = 'Restabli';
$string['emergency:disabled_notice'] = 'Sous-système "{$a->flag}" faralo na. Config bi ñu tëmm: {$a->touched}';
$string['emergency:restored_notice'] = 'Sous-système "{$a->flag}" restabli na. Config bi ñu tëmm: {$a->touched}';
$string['emergency:cli_reference'] = 'Contrôle yi ay jëfandikoo ci shell bi ci on-call:';
$string['emergency:flag_chat'] = 'Chat';
$string['emergency:flag_chat_desc'] = 'Day baaral trafik chat bi ak kill flag bi ñu defal ko (correction v5.13). Widget bi dafay rende; njàngalekat yi di gis message bu jëm "SOLA pausee na". Jëfandikool su benn prestataire LLM dafay sekkoo walla benn hausse njëg dafay dox.';
$string['emergency:flag_voice'] = 'Dëggu';
$string['emergency:flag_voice_desc'] = 'Dafay clear prestataire dëggu realtime bi ñu nëkk aktif (sauvegardé ngir restore bu jëm). Chat texte bi dafay dox ba sëriñ.';
$string['emergency:flag_rag'] = 'RAG';
$string['emergency:flag_rag_desc'] = 'Dafay faral retrieval ak indexation bi. Chat bi dafay dox ak amul ancrage ci contenu kurs bi.';
$string['emergency:flag_outreach'] = 'Outreach';
$string['emergency:flag_outreach_desc'] = 'Dafay tëj email yu digest, milestone, ak reminder yi. Chat bi amul effet.';
$string['emergency:flag_all'] = 'MASTER KILL';
$string['emergency:flag_all_desc'] = 'Dafay faral plugin bi yépp: widget bi dëkk ci xët yi yépp, tâche yi planifié yi tëj, dëggu bi clear, RAG faraloo, outreach faraloo. Switch bu gën a dëgëm — jëfandikool ngir benn incident sécurité walla su war a jaaxal SOLA ci saa si.';
$string['emergency:settings_link'] = 'Contrôle yu urgence';
$string['emergency:settings_link_desc'] = 'Switch yu kill yi ci kenn-kenn sous-système (chat / dëggu / RAG / outreach / master) ak journal audit — équivalent web bu <code>admin/cli/emergency_disable.php</code>. Ubbil <a href="{$a}">Contrôle yu urgence yu SOLA</a>.';
$string['email_unsubscribe:done_title'] = 'Désabonné na';
$string['email_unsubscribe:done_body'] = 'Jëf — {$a->email} dina wuute jàpp xeetu email bii jóge {$a->product}. Su nga bëggoon sopi miñ maa, laaj administrateur {$a->product} ngir ubbi abonnement bi ci kanam, walla yónniy benn consentement bu bees ci xët SOLA Recipients.';
$string['email_unsubscribe:invalid_title'] = 'Lien désabonnement bi amul njariñ ci kanam';
$string['email_unsubscribe:invalid_body'] = 'Lien désabonnement bii expirée na walla amul forma bu jub. Seet benn email bu mujj jóge ci nun, walla jokkoo ak administrateur site ngir ñu ko dëkk ak loxo.';
$string['settings:prompt_proportions_heading'] = 'Proporsiyon yi ci seksion prompt bi (v5.6.0)';
$string['settings:prompt_proportions_heading_desc'] = 'Sëriñal bujet system prompt bi ci ñaari bucket ñett: sécurité + identité, structures kurs bi, contenu kurs bi, ak xët bi ñu nëkk. Ñoomeel yi ay pourcentage yu bokk 100. Bu defawu yi ñu régle ci expérience (10 / 10 / 40 / 40) jóge ci benchmark v5.6.0; baaral textarea bi dafa jëfandikoo bu defawu yii. Boost automatik bi day sëriñal allocation ngir kenn-kenn wàll tëdd ci benn xët bu jëm nekk ci portée wala déewul.';
$string['settings:prompt_section_weights'] = 'Ñoomeel yu seksion yu base (JSON)';
$string['settings:prompt_section_weights_desc'] = 'Objet JSON bu soxoruwul bi day mappe kenn-kenn bucket ci benn pourcentage. Baaral te jëfandikoo bu defawu yi ñu benchmark (10 / 10 / 40 / 40). Misaalu: <code>{"safety_identity": 10, "course_structure": 10, "course_content": 40, "current_page": 40}</code>. Ñoomeel yi war a bokk 100 (±5%). <code>safety_identity</code> am na plancher 10% ngir résistance jailbreak ak marqueur format sortie bi dafa dëkk yépp. <code>current_page + course_content</code> war a nekk 40% ci kanam ngir modèle bi am material bu doy ngir ancrage. Njëg yi nekk ci kanam limit yi dëgëm ci bu defawu yi ñu benchmark; administrateur yi war a vérifier ci setal journal debug prompt bi ci kanam sauvegarder.';
$string['settings:prompt_context_boost_mode'] = 'Mode boost kontekst';
$string['settings:prompt_context_boost_mode_desc'] = 'Réglage automatik bi day wëccel ñoomeel ngir seksion xët bi ñu nëkk su benn xët bu jëm nekk ci portée, ak ngir contenu kurs su amul xët ñu tann. <strong>page_focus</strong> (bu defawul) dafay wëccel ~15 point ñoomeel. <strong>aggressive</strong> dafay wëccel 25 point te gëna baax su njàngalekat yi topp di laaj laajtan xëtu xët yi. <strong>off</strong> dafay faral boost bi; ñoomeel yu base yu ñu set ci administrateur day jëf ci kenn-kenn wàll bess ki xët kontekst.';
$string['settings:prompt_context_boost_off'] = 'Faraloo (jëfandikoo ñoomeel yu base ci kenn-kenn wàll)';
$string['settings:prompt_context_boost_page_focus'] = 'Fokus xët (bu defawul, ~15 point wëccel)';
$string['settings:prompt_context_boost_aggressive'] = 'Aggressive (~25 point wëccel)';
$string['settings:prompt_section_weights_coach'] = 'Override mode coach (JSON, soxoruwul)';
$string['settings:prompt_section_weights_coach_desc'] = 'Objet JSON bu soxoruwul bi day override ñoomeel seksion yu base ci kanam mode coach quiz noté (su <code>quizmode=coach</code>). Am na njariñ ngir seetaan benn allocation <code>current_page</code> bu dëgëm ci kanam quiz yi amul effet ci chat ordinaire bi. Baaral te herit ñoomeel yu base yi. Règle validation bu jëm ak paramètre base bi.';
$string['prompt_debug_view:title'] = 'Visionneuse journal debug prompt';
$string['prompt_debug_view:subtitle'] = 'System prompt bi ñu assemble ngir kenn-kenn wàll + décomposition kenn-kenn seksion + istuwaaru waxtaan bi + message jëfandikukat bi ñu nëkk + métadonnées pièce jointe bi, exaktement ni modèle bi ko jàpp. Jëfandikool ngir vérifier bi seksion bi tëlël Current Page Content wajtoo ci prompt bi ci dëgg-dëgg ak debug problème yi ci kalité réponse yi amul SSH ci serveur.';
$string['prompt_debug_view:disabled'] = 'Journal debug prompt bi kanam FARALOO. Entrée yu bees dina bind laata ñu ko ubbi.';
$string['prompt_debug_view:enable_link'] = 'Ubbil paramètre plugin bi ngir ubbi "Log assembled system prompt to file".';
$string['prompt_debug_view:no_log_yet'] = 'Amul fichier journal ba leegi. Yónniy aw moins benn wàll chat ci kanam ubbi journal debug bi; fichier bi créé na ci bind bu njëkk.';
$string['prompt_debug_view:empty'] = 'Fichier journal bi am na waaye xàllat na. Yónniy benn wàll chat te rafraîchir.';
$string['prompt_debug_view:file_status'] = 'Taille fichier journal';
$string['prompt_debug_view:showing'] = 'Winndi entrée yi gën a mujj (bi gën a bees ci kanam), limite';
$string['prompt_debug_view:total'] = 'Prompt yépp';
$string['prompt_debug_view:budget'] = 'Bujet ci capture';
$string['prompt_debug_view:sections'] = 'Seksion yi (ci catégorie)';
$string['prompt_debug_view:assembled_prompt'] = 'System prompt bi ñu assemble';
$string['prompt_debug_view:history'] = 'Istuwaaru waxtaan bi ñu yónniy modèle bi';
$string['prompt_debug_view:current_message'] = 'Message jëfandikukat bi ñu nëkk';
$string['prompt_debug_view:attachment'] = 'Métadonnées pièce jointe';
$string['prompt_debug_view:show_more'] = 'Winndi entrée yi gën a bari';
$string['settings:mastery_classifier_provider'] = 'Prestataire classifier';
$string['settings:mastery_classifier_provider_desc'] = 'Provider id bi jëfandikoo ngir classifier maîtrise bi ngir kenn-kenn wàll. Baaral te herit prestataire AI bu defawu bi. Bu defawu <code>openai</code> dëkkal ak modèle classifier <code>gpt-4o-mini</code> ci suuf — choix bu gën a dëngël ci TIER 1 ngir classification structured-output (~$220/weer ekonomi ci 100k MAU ba chat tier bi). Su defaalu, rangée ci Comparison providers bi ak provider id bii di jox API key, base URL, ak température.';
$string['settings:premium_escalation_heading'] = 'Palier escalade premium (A.10)';
$string['settings:premium_escalation_heading_desc'] = 'Routage soxoruwul kenn-kenn wàll ngir benn modèle premium (Claude Opus 4.8 bu defawul) ngir prompt yi chat tier bi dafa sekkoo ci yéen — ci kanam mathématiques yu étape bari, CS, ak raisonnement scientifique. Résolu ci A.10 bake-off 2026-06-09: Opus 4.8 dafa dëgëm ci 14.97/15 ba gpt-4o ci 12.68/15 ci prompt yu dëgëm. Yënenteem yu trigger ñaari: correspondance regex ci message jëfandikukat bi, WALLA allowlist kurs bi di escalade kenn-kenn wàll. Faraloo bu defawul. Ci ~5% escalade, kàllar ~$700/weer ci 100k Saylor MAU ci kaw njëg chat bu base.';
$string['settings:premium_escalation_enabled'] = 'Ubbi routage escalade premium';
$string['settings:premium_escalation_enabled_desc'] = 'Su ubbeeku, routeur kenn-kenn wàll bi day vérifier liste regex trigger ak allowlist kurs bi ngir kenn-kenn appel chat; wàll yi correspondance di route ngir prestataire premium bi. Dëgël ci prestataire workhorse bi su rangée premium bi nekkul walla mënulna instantier. Override yu admin-LLM-picker dëgg dëgg dafa dëgëm ci kanam benn.';
$string['settings:premium_escalation_provider'] = 'Prestataire premium';
$string['settings:premium_escalation_provider_desc'] = 'Provider id bi route appel premium yi. War a bës ak benn rangée ci Comparison providers (ndax API key, base URL, ak température jóge ci dëgg-dëgg benn dëkk administrateur yi di gérer). Bu defawul <code>claude</code>.';
$string['settings:premium_escalation_model'] = 'Modèle premium';
$string['settings:premium_escalation_model_desc'] = 'Tur modèle bi ñu pass ci prestataire premium bi. Bu defawul <code>claude-opus-4-8</code> ci kàllar A.10 bake-off.';
$string['settings:premium_escalation_triggers'] = 'Regex trigger premium yi';
$string['settings:premium_escalation_triggers_desc'] = 'Benn PCRE regex ci kenn-kenn raange (amul délimiteur; correspondance case-insensitive day appliqué automatiquement). Raange yi tambali ak # ay commentaire. Baaral te jëfandikoo ensemble bu defawu bi ñu trié jóge A.10 bake-off (marqueur STEM yu étape bari: "derive", "prove that", "step by step", LaTeX math, blocs code, big-O, intégrales, optimisation, w.a.w.).';
$string['settings:premium_escalation_course_tags'] = 'Allowlist kurs premium';
$string['settings:premium_escalation_course_tags_desc'] = 'Benn préfixe shortname walla idnumber kurs ci kenn-kenn raange. Kenn-kenn wàll ci benn kurs bi correspondance dafay escalade automatiquement bess regex message bi (jëfandikool ngir kurs yu dëgëm STEM yu escalade war a yoon bu defawu). Correspondance bi ay préfixe case-insensitive — "MATH" bës ak MATH121, MATH205, w.a.w.';
$string['settings:spend_cap_per_course_default'] = 'Plafond njëg bu defawu kenn-kenn kurs (USD)';
$string['settings:spend_cap_per_course_default_desc'] = 'Plafond défensif bi ñu appliqué ci kenn-kenn kurs amul plafond njëg kenn-kenn kurs bu jëm configuré. Defal ko misaalu <code>30</code> ngir límite njëg bu weer bu kenn-kenn kurs ci $30 amul fégël ci kurs yi kenn-kenn. <code>0</code> = amul bu defawu (plafond kenn-kenn site ak override kenn-kenn kurs rekk day appliqué). Su benn kurs tëmm 80% / 95% / 100% ci plafond bii, pipeline alerte spend-guard bi ñu am di yónniy notification administrateur bi (liste destinataire: <code>spend_notify_emails</code>, dëgël ci administrateur site). Benn kurs bu jëm mën a dëkk sa bopp plafond bi ci kanam ci defal benn override kenn-kenn kurs bu kawe.';
$string['settings:cost_anomaly_heading'] = 'Détecteur anomalie njëg (v6.0)';
$string['settings:cost_anomaly_heading_desc'] = 'Tâche planifié bu fan bu tëdde (<code>cost_anomaly_check</code>) bi day comparer njëg SOLA bi kenn-kenn site bu tëdde ak médiane 7-jour bi ñu roule. Di yónniy email ci liste destinataire <code>spend_notify_emails</code> (dëgël ci administrateur site) su tëdde bi tëmm multiplicateur configuré × médiane bi. Day trouver trois modes défaillance yi plafond 80% / 95% / 100% yi ñu am di rater: (1) kurs bu runaway su plafond absolu tëmmiwul waaye benn kurs bi suiw 10 yoon njëg bu ordinaire, (2) activation accidentelle palier premium, (3) mauvais routage prestataire. Faraloo bu defawul; équivalent SOLA ci requête Redash ci <code>.drafts/sola-redash-cost-anomaly-2026-06-09.md</code>.';
$string['settings:cost_anomaly_enabled'] = 'Ubbi détecteur anomalie njëg';
$string['settings:cost_anomaly_enabled_desc'] = 'Su ubbeeku, tâche planifié bu fan bi day évaluer njëg bu tëdde ba médiane 7 fan bi ñu roule te di yónniy email ci administrateur yi su anomalie. Fan 7 yi njëkk ci kanam ubbi di produire statut <code>insufficient_history</code> (amul encore base historique) te du wootuwul alerte. Idempotent kenn-kenn fan: benn flag ci <code>config_plugins</code> day arrêter email yi ñu woy su cron bi dox ñaari yoon.';
$string['settings:cost_anomaly_multiplier'] = 'Multiplicateur anomalie';
$string['settings:cost_anomaly_multiplier_desc'] = 'Njëg bu tëdde war a tëmm multiplicateur bii × médiane 7 fan ngir déclencher benn alerte. Bu defawul <code>2.0</code>. Wàññi ko ci <code>1.5</code> ngir alerte yi gëna jot (faux positifs yi gëna bari ci inscription burst yi). Yokk ko ci <code>3.0</code> su Saylor di jëfandikoo ci yënenteem bi spike 2x ay routine.';
$string['task:cost_anomaly_check'] = 'Vérification anomalie njëg SOLA (bu fan bu tëdde)';

$string['settings:policy_bundle_heading'] = 'Policy bundle bi signée (yeesal jëf yi ci kanam)';
$string['settings:policy_bundle_heading_desc'] = 'Jëfandikoo yettali jëf yi (prompts, routing, escalation triggers, RAG tuning, spend policy) ci benn fichier JSON bu signée bu cryptographique, mënula dëgg deploy bu code. Benn tâche bi programée ci benn fan rekk jeex URL ji ci bundle bi, verifie sa signature Ed25519 ak clé publique bi ci suuf bi, te apply settings yi seulement su benn key am ci allowlist bi bu defar ci biir ak version ji ci bundle bi buy moy ju bees ngir version ji postée ci kanam. API keys, URLs, webhooks, ak settings sécurité dañuy mën xammal bundle bi. Author ak signer bundles yi ak <code>admin/cli/policy_bundle_tool.php</code> (keygen, sign, verify, status, sync).';
$string['settings:policy_bundle_enabled'] = 'Sàmm policy bundle sync';
$string['settings:policy_bundle_enabled_desc'] = 'Su sàmm na, tâche bi ci benn fan rekk jeex ak apply bundles yu signée yi. Séy na ci sëriñ. Dàkk rekk dañuy sàqq sync yépp tey; settings yi ci kanam bi apply na dañuy mëneel jëfandikoo ba kanam.';
$string['settings:policy_bundle_url'] = 'Policy bundle URL';
$string['settings:policy_bundle_url_desc'] = 'URL HTTPS bi ci bundle JSON bi signée (par exemple benn objet S3 walla GitHub raw URL). URL bi jëm ci validation SSRF bu mel ni ay endpoints yu bixeex AI yi; private-network walla plain-http hosts dañuy soxor benn entrée ci SSRF trusted endpoints allowlist bi.';
$string['settings:policy_bundle_pubkey'] = 'Policy bundle public key';
$string['settings:policy_bundle_pubkey_desc'] = 'Base64 Ed25519 public key bi jëfandikoo ngir verifie signatures yi ci bundle bi. Génère keypair bi ak <code>policy_bundle_tool.php --keygen</code>; private key bi deka ak auteur bundle bi, bëgul télécharge fi ak fi.';
$string['settings:policy_bundle_status'] = 'Sync bi ci kanam';
$string['settings:policy_bundle_applied_version'] = 'version bi postée';
$string['task:policy_bundle_sync'] = 'SOLA signed policy bundle sync';
$string['policy_bundle:invalid'] = 'Policy bundle bi mënul: {$a}';

// v6.4.0 signed policy bundle strings (added 2026-06-11).
$string['settings:policy_bundle_heading'] = 'Policy bundle bu signée (mises à jour comportement yi bu jan)';
$string['settings:policy_bundle_heading_desc'] = 'Appliquer paramètres comportement yi (prompts, routing, déclencheurs escalation, réglage RAG, politique dépenses) ci benn fichier JSON bu signée cryptographiquement sans déploiement code. Tâche planifiée bu fan bi day télécharger URL bundle bi, vérifier sa signature Ed25519 ba clé publique bi ci tëdd bi, te day appliquer paramètres yi seulement bu clé yépp ñu nekk ci liste autorisation bi bu bañal te version bundle bi ñu gëna yees ci ñu codsaday yi ñu dimbëleel. Clés API, URL yi, webhooks yi, ak paramètres sécurité yi dafay baaje askan wá bundle lañu set. Créer te signer bundles yi ak <code>admin/cli/policy_bundle_tool.php</code> (keygen, sign, verify, status, sync).';
$string['settings:policy_bundle_enabled'] = 'Ubbi policy bundle sync';
$string['settings:policy_bundle_enabled_desc'] = 'Su ubbeeku, tâche bu fan bi day télécharger te appliquer bundles yu signées. Bu defawul dafa ferme. Fermeelu dafa arrêter sync yépp dëgg dëgg; paramètres yu déjà appliquées yi day garder valeurs yi.';
$string['settings:policy_bundle_url'] = 'URL policy bundle';
$string['settings:policy_bundle_url_desc'] = 'URL HTTPS bu JSON bundle bu signée (misaal bu bari S3 object wall GitHub raw URL). URL bi day passer ci même validation SSRF yi te endpoints fournisseurs AI yi; hôtes réseau privé wall plain-http yi dañu soxor entrée ci liste autorisation endpoints SSRF yi nu jëm jaam.';
$string['settings:policy_bundle_pubkey'] = 'Clé publique policy bundle';
$string['settings:policy_bundle_pubkey_desc'] = 'Base64 Ed25519 clé publique bu jëfandikoo ngir vérifier signatures bundles yi. Générer paire clés yi ak <code>policy_bundle_tool.php --keygen</code>; clé privée bi day dem ak auteur bundle bi te du wax ni ñu ko upload fenn.';
$string['settings:policy_bundle_status'] = 'Sync bu mujj';
$string['settings:policy_bundle_applied_version'] = 'version bu appliqué';
$string['task:policy_bundle_sync'] = 'SOLA policy bundle bu signée sync';
$string['policy_bundle:invalid'] = 'Policy bundle refusé: {$a}';
$string['prompt_debug_view:retrieved_chunks'] = 'Dégg yi ñu jële (tànneef RAG)';
$string['prompt_debug_view:retrieved_chunks_hint'] = 'Dégg yi jëfandikukat bu wut tànn ngir laaj bii, ci tëraliin gu ñu daje ak seen pwaŋ bu jëm ci laaj bi ak seen géej (cmid). Jëfandikoo ko ngir saytu ne modèl bi jot na li gën a dëppoo ci njàngale mi.';
$string['settings:avatar_animation_enabled'] = 'Animasion avatar bi';
$string['settings:avatar_animation_enabled_desc'] = 'Animeer avatar SVG bi ñu defar: yéegal bi dafa noppi, ak yëgël suuf bi jëm ak jàng tekst-ci-kàlam audio bi tegu ngoon gi dangay wax. Seet bëgg bi jëfandikukat am ci jumtukaay bi. Yeesal kër ak kër ci A/B jëf: seet config bi avatar_animation_course_COURSEID ci 0 walla 1.';
$string['analytics:exp_heading'] = 'Tënk jëf A/B';
$string['analytics:exp_desc'] = 'Tënk yëgël ak jëfandiku ci kanam ci cours yi ñaar ci wakht bi tann. Dafa dëkkee ci xam-xam cours bu dëkk (misaal: yëgël ci jëf bu jox ci avatar): dëkk ci cours bu dëkk ci yëgëleel, dem ak yënn dafa ci contrôle, te jàng tënk bi fii.';
$string['analytics:exp_course_a'] = 'Cours A';
$string['analytics:exp_course_b'] = 'Cours B';
$string['analytics:exp_compare'] = 'Tënk';
$string['analytics:exp_metric'] = 'Mesure';
$string['analytics:exp_delta'] = 'B vs A';
$string['analytics:exp_enrolled'] = 'Jëfandikukat yi dafañu sos';
$string['analytics:exp_active_users'] = 'Jëfandikukat SOLA yi dëkk';
$string['analytics:exp_usage_rate'] = 'Taux bu jëfandiku (%)';
$string['analytics:exp_sessions'] = 'Sessions';
$string['analytics:exp_messages'] = 'Xib yi';
$string['analytics:exp_avg_msgs_session'] = 'Xib yi ak noppy session';
$string['analytics:exp_avg_session_minutes'] = 'Xarit session bu noppy (simili)';
$string['analytics:exp_return_rate'] = 'Jëfandikukat yi dellu (%)';
$string['analytics:exp_tts_plays'] = 'TTS jël yi';
$string['analytics:exp_tts_per_active'] = 'TTS jël yi ci jëfandikukat bu dëkk';

$string['settings:redash_allowed_origin'] = 'Cosaan bu Redash mu nangu (CORS)';
$string['settings:redash_allowed_origin_desc'] = 'Bàyyil ko feem (ñu koy digal): Redash mooy yóbbu mbindum-génn mi ci diggante sarwa yi, te soxlawul benn kàddu CORS bu naróbull. Defal benn cosaan bu jub (misaal https://redash.example.org) rekk bu dashboard bu sukkandiku ci naróbull dafa war a jàng mbindum-génn mi ci taxaw. Bul jëfandikoo joker mukk.';

// Soapbox speech practice (v6.7.0).
$string['privacy:metadata:local_ai_course_assistant_practice_scores:session_meta'] = 'Metadata bu wàññi bi nga joxe ci sesioŋ bi, ni mbind mi, sujet bi, ak gатtaay bi nga bëgg ci ab waxtaan Soapbox. Du am dara ci xewuy benn waxtu walla benn mbind bu génn.';
$string['pedagogy:soapbox'] = 'Soapbox feedback bu waxtaan dafa di ci kanam';
$string['pedagogy:soapbox_desc'] = 'Su yóbboo, jumtukaayu jàngalekat Soapbox dafay teew ci sàrt yépp lu dul ci sàrt bi am benn override bu boppam. Bàyyil ko te yóbb ko rekk ci sàrt yi ko soxla (lu ëpp ci sàrt yu waxtaan ak jokkoo).';
$string['settings:soapbox_stt_mode'] = 'Mooduy bind-mbind bu Soapbox';
$string['settings:soapbox_stt_mode_desc'] = 'Naka la Soapbox di soppi benn waxtaan bu ñu enregistre ci mbind. Sarwa bi dafay jëfandikoo nattukaayu Whisper bi ñu config (bopp-hébergement amul njëg; OpenAI bu héberge dafa tollu ci USD 0.006 ci benn simili). Naróbull bi dafay jëfandikoo xam-baatu naróbull bi ci biir (amul njëg, amul sarwa, dafay liggéey ci Chrome ak Safari rekk). Ñu koy digal Sarwa ngir bu sàqami bind-mbind mi du jaar ci naróbullu jàngalekat bi.';
$string['settings:soapbox_stt_mode_server'] = 'Sarwa (nattukaayu Whisper)';
$string['settings:soapbox_stt_mode_browser'] = 'Naróbull (amul njëg, amul sarwa)';
$string['soapbox:title'] = 'Soapbox';
$string['soapbox:link'] = 'Jàngale waxtaan Soapbox';
$string['soapbox:disabled'] = 'Soapbox yóbbuwul ci sàrt bii.';
$string['soapbox:intro'] = 'Defal benn waxtaan te jot ndimbal. Su soobee, defal benn mbind, benn sujet, ak benn gattaay bu nga bëgg, ba noppi enregistre sa waxtaan. Soapbox dafay bind sa waxtaan, jox ko points ci ab rubrique waxtaan, te jox la cosaan yu leer. Sa xewuy waxtu ak mbind mi du ñu ko denc mukk, sa points ak feedback rekk lañu denc.';
$string['soapbox:optional'] = 'su soobee';
$string['soapbox:name_label'] = 'Tudd waxtaan bii';
$string['soapbox:topic_label'] = 'Sujet';
$string['soapbox:time_label'] = 'Gattaay bu ñu bëgg';
$string['soapbox:no_target'] = 'Amul njëkk';
$string['soapbox:record'] = 'Enregistre waxtaan';
$string['soapbox:stop'] = 'Taxawal te jot feedback';
$string['soapbox:recording'] = 'Mungiy enregistre. Wax na mën; bësal taxawal su nga noppee.';
$string['soapbox:transcribing'] = 'Mungiy bind sa waxtaan…';
$string['soapbox:scoring'] = 'Mungiy jox points sa waxtaan…';
$string['soapbox:too_short'] = 'Enregistrement boobu dafa gàtt lool ngir jox ko points. Jéemal lu yàgg ñaari kàddu walla benn te jéemaat.';
$string['soapbox:mic_denied'] = 'Aksesu micro dafa soxla ngir enregistre. Maayal aksesu micro te jéemaat.';
$string['soapbox:no_browser_stt'] = 'Naróbull bii nanguwul xam-baatu waxtaan ci biir naróbull. Jéemal Chrome walla Safari, walla laaj sa borom-yor ngir mu soppi Soapbox ci bind-mbind bu sarwa.';
$string['soapbox:browser_note'] = 'Waxtaan bii dañu koy bind ci sa naróbull. Dara du génn. Dafa gën a baax ci Chrome ak Safari.';
$string['soapbox:server_note'] = 'Sa enregistrement dañu koy yónnee ngir bind-mbind rekk te du ñu ko denc.';
$string['soapbox:error'] = 'Mënuñu jox points waxtaan bii léegi. Jéemaat ci kanam tuuti.';
$string['soapbox:audio_too_large'] = 'Enregistrement boobu dafa réy lool. Bàyyil waxtaan yi ci suuf 25 MB (lu tollu ci 20 simili).';
$string['soapbox:no_stt'] = 'Amul nattukaayu bind-mbind bu ñu config. Laaj sa borom-yor ngir mu defar Whisper walla yóbb bind-mbind bu naróbull.';
$string['soapbox:result_heading'] = 'Pointu rubrique';
$string['soapbox:overall_heading'] = 'Mbooloo';
$string['soapbox:tips_heading'] = 'Cosaan ngir bés bu ñëw';
$string['soapbox:col_criterion'] = 'Kriteer';
$string['soapbox:col_score'] = 'Points';
$string['soapbox:col_feedback'] = 'Feedback';
$string['soapbox:history_heading'] = 'Samay waxtaan';
$string['soapbox:history_empty'] = 'Enregistrewuloo benn waxtaan ba léegi. Enregistreel benn ci kaw ngir tàmbali defar sa taariix.';
$string['soapbox:untitled'] = 'Waxtaan bu amul tur';
$string['soapbox:overall_badge'] = 'Mbooloo {$a}';
$string['soapbox:toggle'] = 'Yóbbu Soapbox ci sàrt bii';
$string['soapbox:toggle_help'] = 'Jàngalekat yi am nañu benn xët bu jagleel ngir enregistre benn waxtaan te jot feedback waxtaan bu am points ci rubrique ak cosaan. Xewuy waxtu ak mbind du ñu ko denc mukk. Dafa fëɓ ci kanam.';
