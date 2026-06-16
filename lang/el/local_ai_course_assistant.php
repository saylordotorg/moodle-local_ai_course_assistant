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
 * Language strings for local_ai_course_assistant — Greek.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Βοηθός Μαθήματος με Τεχνητή Νοημοσύνη';
$string['attachment:attach'] = 'Επισύναψη';
$string['attachment:attach_image_or_pdf'] = 'Επισύναψη εικόνας ή PDF';
$string['privacy:metadata:local_ai_course_assistant_convs'] = 'Αποθηκεύει τις συνομιλίες του AI βοηθού ανά χρήστη και μάθημα.';
$string['privacy:metadata:local_ai_course_assistant_convs:userid'] = 'Το αναγνωριστικό του χρήστη που κατέχει τη συνομιλία.';
$string['privacy:metadata:local_ai_course_assistant_convs:courseid'] = 'Το αναγνωριστικό του μαθήματος στο οποίο ανήκει η συνομιλία.';
$string['privacy:metadata:local_ai_course_assistant_convs:title'] = 'Ο τίτλος της συνομιλίας.';
$string['privacy:metadata:local_ai_course_assistant_convs:timecreated'] = 'Η ώρα δημιουργίας της συνομιλίας.';
$string['privacy:metadata:local_ai_course_assistant_convs:timemodified'] = 'Η ώρα τελευταίας τροποποίησης της συνομιλίας.';
$string['privacy:metadata:local_ai_course_assistant_msgs'] = 'Αποθηκεύει μεμονωμένα μηνύματα στις συνομιλίες του AI βοηθού.';
$string['privacy:metadata:local_ai_course_assistant_msgs:userid'] = 'Το αναγνωριστικό του χρήστη που έστειλε το μήνυμα.';
$string['privacy:metadata:local_ai_course_assistant_msgs:courseid'] = 'Το αναγνωριστικό του μαθήματος στο οποίο ανήκει το μήνυμα.';
$string['privacy:metadata:local_ai_course_assistant_msgs:role'] = 'Ο ρόλος του αποστολέα του μηνύματος (χρήστης ή βοηθός).';
$string['privacy:metadata:local_ai_course_assistant_msgs:message'] = 'Το περιεχόμενο του μηνύματος.';
$string['privacy:metadata:local_ai_course_assistant_msgs:tokens_used'] = 'Ο αριθμός των tokens που χρησιμοποιήθηκαν για το μήνυμα.';
$string['privacy:metadata:local_ai_course_assistant_msgs:timecreated'] = 'Η ώρα δημιουργίας του μηνύματος.';

// Capabilities.
$string['ai_course_assistant:use'] = 'Χρήση του AI βοηθού';
$string['ai_course_assistant:viewanalytics'] = 'Προβολή αναλυτικών στοιχείων του AI βοηθού';
$string['ai_course_assistant:manage'] = 'Διαχείριση ρυθμίσεων του AI βοηθού (Ρόλος Διαχειριστή)';
$string['task:run_meta_ai_query'] = 'Εκτέλεση προγραμματισμένου ερωτήματος αναλυτικών Ραντάρ Μάθησης';

// Settings.
$string['settings:enabled'] = 'Ενεργοποίηση Βοηθού Μαθήματος AI';
$string['settings:enabled_desc'] = 'Ενεργοποίηση ή απενεργοποίηση του widget Βοηθού Μαθήματος AI στις σελίδες μαθημάτων.';
$string['settings:default_course_mode'] = 'Προεπιλογή για νέα μαθήματα';
$string['settings:default_course_mode_desc'] = 'Ελέγχει αν το [[tutorshort]] εμφανίζεται σε ένα μάθημα όταν δεν έχει γίνει επιλογή ανά μάθημα. Οι νέες εγκαταστάσεις είναι εξ ορισμού ρυθμισμένες σε "Απενεργοποιημένο εξ ορισμού" ώστε οι διαχειριστές να μπορούν να ενεργοποιούν μάθημα προς μάθημα από τη σελίδα Analytics ή τη σελίδα Course AI Settings.';
$string['settings:default_course_mode_per_course'] = 'Απενεργοποιημένο εξ ορισμού (ενεργοποίηση ανά μάθημα)';
$string['settings:default_course_mode_all'] = 'Ενεργοποιημένο σε όλα τα μαθήματα';
$string['settings:auto_open'] = 'Αυτόματο άνοιγμα στην πρώτη επίσκεψη';
$string['settings:auto_open_desc'] = 'Όταν είναι ενεργοποιημένο, το συρτάρι του [[tutorshort]] ανοίγει αυτόματα την πρώτη φορά που ένας σπουδαστής φτάνει σε κάθε μάθημα. Μεταγενέστερες φορτώσεις σελίδων στο ίδιο μάθημα δεν ανοίγουν ξανά το συρτάρι — η κατάσταση παρακολουθείται ανά μάθημα στον περιηγητή του σπουδαστή μέσω localStorage. Ισχύει σε υπολογιστή και κινητό. Μπορεί να παρακαμφθεί ανά μάθημα από τη σελίδα Course AI Settings.';
$string['settings:comparison_providers'] = 'Πάροχοι σύγκρισης (επιλογέας LLM)';
$string['settings:comparison_providers_desc'] = 'Προσθέστε επιπλέον παρόχους AI στον ενσωματωμένο επιλογέα LLM ώστε οι διαχειριστές να μπορούν να συγκρίνουν απαντήσεις μεταξύ παρόχων. Χρησιμοποιήστε τον παρακάτω πίνακα για να προσθέσετε γραμμές. Η στήλη θερμοκρασίας είναι προαιρετική (αφήστε την κενή για να χρησιμοποιηθεί η καθολική θερμοκρασία). Μορφή αποθήκευσης: provider_id|api_key|model1,model2|temperature. Ο κύριος πάροχος που έχει ρυθμιστεί παραπάνω περιλαμβάνεται πάντα αυτόματα. Μόνο οι διαχειριστές με δικαίωμα διαχείρισης βλέπουν τον επιλογέα· οι φοιτητές δεν τον βλέπουν ποτέ. Έγκυρα provider IDs: openai, claude, deepseek, gemini, ollama, minimax, mistral, openrouter, xai, coreai, custom.';
$string['settings:provider'] = 'Προεπιλεγμένος πάροχος AI';
$string['settings:provider_desc'] = 'Επιλέξτε τον πάροχο AI που θα χρησιμοποιηθεί για τις ολοκληρώσεις συνομιλίας. Επιλέξτε "Moodle AI (core_ai subsystem)" για να δρομολογείτε τα αιτήματα μέσω της ενσωματωμένης διαμόρφωσης AI του Moodle στο Site admin > AI· τα πεδία API key, μοντέλου και base URL παρακάτω αγνοούνται σε αυτή τη λειτουργία. Το streaming, η χρήση εργαλείων και το prompt caching δεν είναι διαθέσιμα μέσω του core_ai — οι απαντήσεις παραδίδονται ως ένα ενιαίο τμήμα. Χρησιμοποιήστε έναν απευθείας πάροχο για την καλύτερη εμπειρία μαθητή.';
$string['settings:provider_claude'] = 'Claude (Anthropic)';
$string['settings:provider_openai'] = 'OpenAI';
$string['settings:provider_deepseek'] = 'DeepSeek';
$string['settings:provider_ollama'] = 'Ollama (Τοπικά)';
$string['settings:provider_minimax'] = 'MiniMax';
$string['settings:provider_gemini'] = 'Google Gemini';
$string['settings:provider_custom'] = 'Προσαρμοσμένο (συμβατό με OpenAI)';
$string['settings:apikey'] = 'Κλειδί API';
$string['settings:apikey_desc'] = 'Κλειδί API για τον επιλεγμένο πάροχο. Δεν απαιτείται για Ollama.';
$string['settings:model'] = 'Όνομα Μοντέλου';
$string['settings:model_desc'] = 'Το μοντέλο προς χρήση. Η προεπιλογή εξαρτάται από τον πάροχο.';
$string['settings:apibaseurl'] = 'Βασικό URL API';
$string['settings:apibaseurl_desc'] = 'Βασικό URL για το API. Αυτόματη συμπλήρωση ανά πάροχο, αλλά μπορεί να αντικατασταθεί.';
$string['settings:systemprompt'] = 'Πρότυπο Μηνύματος Συστήματος';
$string['settings:systemprompt_desc'] = 'Μήνυμα συστήματος που αποστέλλεται στο AI. Χρήση θέσεων: {{coursename}}, {{userrole}}, {{coursetopics}}.';
$string['settings:temperature'] = 'Θερμοκρασία';
$string['settings:temperature_desc'] = 'Ελέγχει την τυχαιότητα. Χαμηλότερες τιμές είναι πιο εστιασμένες. Εύρος: 0.0 έως 2.0.';
$string['settings:maxhistory'] = 'Μέγιστο Ιστορικό Συνομιλίας';
$string['settings:maxhistory_desc'] = 'Μέγιστος αριθμός ζευγών μηνυμάτων που περιλαμβάνονται στα αιτήματα API.';
$string['settings:avatar'] = 'Εικονίδιο Συνομιλίας';
$string['settings:avatar_desc'] = 'Επιλέξτε το εικονίδιο avatar για το κουμπί widget.';
$string['settings:avatar_color'] = 'Χρώμα Περιγράμματος Avatar';
$string['settings:avatar_color_desc'] = 'Χρώμα περιγράμματος του κουμπιού avatar. Χρήση τιμής hex.';
$string['settings:avatar_fill'] = 'Χρώμα Φόντου Avatar';
$string['settings:avatar_fill_desc'] = 'Χρώμα φόντου μέσα στο κουμπί avatar. Χρήση τιμής hex.';
$string['settings:display_mode'] = 'Λειτουργία Εμφάνισης';
$string['settings:display_mode_desc'] = 'Πώς εμφανίζεται ο [[tutorshort]] στη σελίδα.';
$string['settings:display_mode_widget'] = 'Widget (αιωρούμενο κουμπί)';
$string['settings:display_mode_drawer'] = 'Πλαϊνό συρτάρι (δεξιά πλευρά)';
$string['settings:position'] = 'Θέση Widget';
$string['settings:position_desc'] = 'Θέση του widget στη σελίδα (ισχύει μόνο σε λειτουργία Widget).';
$string['settings:position_br'] = 'Κάτω δεξιά';
$string['settings:position_bl'] = 'Κάτω αριστερά';
$string['settings:position_tr'] = 'Πάνω δεξιά';
$string['settings:position_tl'] = 'Πάνω αριστερά';
$string['chat:settings'] = 'Ρυθμίσεις πρόσθετου';
$string['analytics:viewdashboard'] = 'Προβολή πίνακα αναλυτικών';

// Course settings.
$string['coursesettings:title'] = 'Ρυθμίσεις AI Μαθήματος';
$string['coursesettings:enabled'] = 'Ενεργοποίηση παρακάμψεων μαθήματος';
$string['coursesettings:enabled_desc'] = 'Όταν ενεργοποιηθεί, οι παρακάτω ρυθμίσεις υπερισχύουν της καθολικής διαμόρφωσης AI.';
$string['coursesettings:sola_enabled'] = '[[tutorshort]] σε αυτό το μάθημα';
$string['coursesettings:sola_enabled_toggle'] = 'Εμφάνιση του widget [[tutorshort]] σε αυτό το μάθημα';
$string['coursesettings:sola_enabled_desc'] = 'Ελέγχει αν το widget συνομιλίας [[tutorshort]] εμφανίζεται σε αυτό το μάθημα. Η προεπιλεγμένη ρύθμιση για όλον τον ιστότοπο ορίζεται στις ρυθμίσεις του πρόσθετου στην ενότητα General > Default for new courses.';
$string['coursesettings:using_global'] = 'Χρήση καθολικής ρύθμισης';
$string['coursesettings:saved'] = 'Οι ρυθμίσεις AI μαθήματος αποθηκεύτηκαν.';
$string['coursesettings:ell_pronunciation'] = 'Λειτουργία Εξάσκησης Προφοράς';
$string['coursesettings:ell_pronunciation_desc'] = 'Εμφάνιση του chip «Εξάσκηση Προφοράς» για φοιτητές σε αυτό το μάθημα.';
$string['coursesettings:ell_pronunciation_enable'] = 'Ενεργοποίηση chip Εξάσκησης Προφοράς';
$string['coursesettings:rag'] = 'Σημασιολογική Αναζήτηση (RAG)';
$string['coursesettings:rag_desc'] = 'Ενεργοποίηση ανάκτησης αυξημένης γενέσεως για αυτό το μάθημα.';
$string['coursesettings:rag_enable'] = 'Ενεργοποίηση RAG για αυτό το μάθημα';
$string['coursesettings:speaking_practice'] = 'Εξάσκηση Ομιλίας';
$string['coursesettings:speaking_practice_desc'] = 'Εμφάνιση του chip «Εξάσκηση Ομιλίας» για φοιτητές.';
$string['coursesettings:speaking_practice_enable'] = 'Ενεργοποίηση chip Εξάσκησης Ομιλίας';
$string['coursesettings:global_settings_link'] = 'Καθολικές ρυθμίσεις AI';
$string['coursesettings:token_usage'] = 'Χρήση Token & Κόστος';
$string['coursesettings:token_usage_desc'] = 'Προβολή χρήσης token, εκτιμήσεων κόστους και αναλύσεων ανά φοιτητή.';

// v5.2.0: per-quiz [[tutorshort]] assistance level controls.
$string['quizsettings:title'] = 'Quiz Assistance Levels';
$string['quizsettings:desc'] = 'Choose how much help [[tutorshort]] gives on each quiz. "Default" uses the legacy rule: ungraded quizzes get full help, graded quizzes hide the widget. Use "Coach" to keep [[tutorshort]] available on a graded quiz but block direct answers.';
$string['quizsettings:colquiz'] = 'Quiz';
$string['quizsettings:colgrade'] = 'Max grade';
$string['quizsettings:collevel'] = 'Assistance level';
$string['quizsettings:coleffective'] = 'Effective';
$string['quizsettings:level_default'] = 'Default (by grade)';
$string['quizsettings:level_full'] = 'Full help';
$string['quizsettings:level_coach'] = 'Coach (Socratic only)';
$string['quizsettings:level_hidden'] = 'Hidden';
$string['quizsettings:ungraded'] = 'Ungraded';

// Language.
$string['lang:switch'] = 'Ναι, αλλαγή';
$string['lang:dismiss'] = 'Όχι, ευχαριστώ';
$string['lang:change'] = 'Αλλαγή γλώσσας';
$string['lang:english'] = 'Αγγλικά';

// Chat widget.
$string['chat:title'] = '[[tutorshort]]';
$string['chat:placeholder'] = 'Κάντε μια ερώτηση...';
$string['chat:send'] = 'Αποστολή';
$string['chat:close'] = 'Κλείσιμο συνομιλίας';
$string['chat:open'] = 'Άνοιγμα [[tutorshort]]';
$string['chat:change_avatar'] = 'Αλλαγή avatar';
$string['chat:clear'] = 'Εκκαθάριση οθόνης';
$string['chat:clear_confirm'] = 'Να γίνει εκκαθάριση των ορατών μηνυμάτων; Το πλήρες ιστορικό συνομιλίας σας παραμένει αποθηκευμένο και μπορεί να φορτωθεί ξανά ανοίγοντας πάλι το widget.';
$string['chat:copy'] = 'Αντιγραφή συνομιλίας';
$string['chat:copied'] = 'Η συνομιλία αντιγράφηκε στο πρόχειρο';
$string['chat:copy_failed'] = 'Αποτυχία αντιγραφής';
$string['chat:greeting'] = 'Γεια σου, {$a}! Είμαι ο [[tutorshort]]. Πώς μπορώ να σε βοηθήσω σήμερα;';
$string['chat:thinking'] = 'Σκέφτομαι...';
$string['chat:error'] = 'Λυπάμαι, κάτι πήγε στραβά. Παρακαλώ δοκιμάστε ξανά.';
$string['chat:error_auth'] = 'Σφάλμα ταυτοποίησης. Επικοινωνήστε με τον διαχειριστή.';
$string['chat:error_ratelimit'] = 'Πάρα πολλά αιτήματα. Περιμένετε λίγο και δοκιμάστε ξανά.';
$string['chat:error_unavailable'] = 'Η υπηρεσία AI δεν είναι προσωρινά διαθέσιμη.';
$string['chat:error_notconfigured'] = 'Ο [[tutorshort]] δεν έχει ρυθμιστεί ακόμα. Επικοινωνήστε με τον διαχειριστή.';
$string['chat:mic'] = 'Πείτε την ερώτησή σας';
$string['chat:mic_error'] = 'Σφάλμα μικροφώνου. Ελέγξτε τα δικαιώματα του προγράμματος περιήγησης.';
$string['chat:mic_unsupported'] = 'Η φωνητική εισαγωγή δεν υποστηρίζεται σε αυτό το πρόγραμμα περιήγησης.';
$string['chat:newline_hint'] = 'Shift+Enter για νέα γραμμή';
$string['chat:you'] = 'Εσείς';
$string['chat:assistant'] = '[[tutorshort]]';
$string['chat:history_loaded'] = 'Η προηγούμενη συνομιλία φορτώθηκε.';
$string['chat:history_cleared'] = 'Το ιστορικό συνομιλίας εκκαθαρίστηκε.';
$string['chat:offtopic_warning'] = 'Η ερώτησή σας δεν σχετίζεται με αυτό το μάθημα. Προσπαθήστε να μείνετε στο θέμα!';
$string['chat:offtopic_ended'] = 'Η πρόσβαση στον AI βοηθό έχει ανασταλεί προσωρινά για {$a} λεπτά.';
$string['chat:offtopic_locked'] = 'Η πρόσβασή σας είναι προσωρινά σε αναστολή. Δοκιμάστε ξανά σε {$a} λεπτά.';
$string['chat:escalated_to_support'] = 'Δεν μπόρεσα να απαντήσω πλήρως στην ερώτησή σας, οπότε δημιουργήθηκε ένα αίτημα υποστήριξης. Αναφορά: {$a}';
$string['chat:studyplan_intro'] = 'Μπορώ να σας βοηθήσω να δημιουργήσετε ένα πρόγραμμα μελέτης! Πείτε μου πόσες ώρες εβδομαδιαίως μπορείτε να αφιερώσετε.';

// Quiz.
$string['chat:quiz'] = 'Δώστε ένα τεστ εξάσκησης';
$string['chat:quiz_setup_title'] = 'Τεστ Εξάσκησης';
$string['chat:quiz_questions'] = 'Αριθμός ερωτήσεων';
$string['chat:quiz_topic'] = 'Θέμα';
$string['chat:quiz_topic_guided'] = 'Καθοδηγούμενο από AI (βάσει της προόδου σας)';
$string['chat:quiz_topic_adaptive']      = 'Προσαρμοστικό — επικέντρωση στα αδύναμα σημεία μου';
$string['chat:quiz_topic_default'] = 'Τρέχον περιεχόμενο μαθήματος';
$string['chat:quiz_topic_custom'] = 'Προσαρμοσμένο θέμα…';
$string['chat:quiz_custom_placeholder'] = 'Εισάγετε ένα θέμα ή ερώτηση...';
$string['chat:quiz_start'] = 'Έναρξη Τεστ';
$string['chat:quiz_cancel'] = 'Ακύρωση';
$string['chat:quiz_loading'] = 'Δημιουργία τεστ…';
$string['chat:quiz_error'] = 'Δεν ήταν δυνατή η δημιουργία τεστ. Δοκιμάστε ξανά.';
$string['chat:quiz_correct'] = 'Σωστό!';
$string['chat:quiz_wrong'] = 'Λάθος.';
$string['chat:quiz_next'] = 'Επόμενη ερώτηση';
$string['chat:quiz_finish'] = 'Δείτε αποτελέσματα';
$string['chat:quiz_score'] = 'Τεστ ολοκληρώθηκε! Βαθμολογία: {$a->score} από {$a->total}.';
$string['chat:quiz_summary'] = 'Ολοκλήρωσα ένα τεστ εξάσκησης {$a->total} ερωτήσεων στο «{$a->topic}» και πήρα {$a->score}/{$a->total}.';
$string['chat:quiz_topic_objectives'] = 'Μαθησιακοί Στόχοι';
$string['chat:quiz_topic_modules'] = 'Θέμα Μαθήματος';
$string['chat:quiz_subtopic_select'] = 'Επιλέξτε ένα συγκεκριμένο στοιχείο…';
$string['chat:quiz_topic_sections'] = 'Ενότητες Μαθήματος';
$string['chat:quiz_score_great'] = 'Εξαιρετική δουλειά! Γνωρίζετε πραγματικά αυτό το υλικό.';
$string['chat:quiz_score_good'] = 'Καλή προσπάθεια! Συνεχίστε τη μελέτη για να ενδυναμώσετε την κατανόησή σας.';
$string['chat:quiz_score_practice'] = 'Συνεχίστε να εξασκείστε. Ανατρέξτε στο σχετικό υλικό και ξαναδοκιμάστε.';
$string['chat:quiz_review_heading'] = 'Ανασκόπηση';
$string['chat:quiz_retake'] = 'Επανάληψη Τεστ';
$string['chat:quiz_exit'] = 'Έξοδος από Τεστ';
$string['chat:quiz_your_answer'] = 'Η απάντησή σας';
$string['chat:quiz_correct_answer'] = 'Σωστή απάντηση';

// Conversation starters.
$string['chat:starters_label'] = 'Αρχικές ερωτήσεις';
$string['chat:starter_help_page'] = 'Βοήθεια με αυτό';
$string['chat:starter_quiz'] = 'Εξέτασέ με σε αυτό';
$string['chat:starter_study_plan'] = 'Σχέδιο Μελέτης';
$string['chat:starter_ask_anything'] = 'Ρωτήστε ό,τι θέλετε';
$string['chat:starter_review_practice'] = 'Επανάληψη και Εξάσκηση';
$string['chat:starter_ai_project_coach'] = 'AI Project Coach';
$string['chat:starter_ell_practice'] = 'Εξάσκηση Συνομιλίας';
$string['chat:starter_ell_pronunciation'] = 'Εξάσκηση Προφοράς';
$string['chat:starter_speak'] = 'Πείτε μια εισαγωγική φράση';
$string['chat:starter_explain'] = 'Εξήγησε αυτό';
$string['chat:starter_key_concepts'] = 'Βασικές Έννοιες';
$string['chat:starter_help_me'] = 'Βοήθεια AI';
$string['chat:starter_ai_coach'] = 'AI Coach';
$string['chat:starter_quick_study'] = 'Γρήγορη Μελέτη';
$string['chat:starter_help_lesson'] = 'Εξήγησε αυτό';
$string['chat:starter_prompt_coach'] = 'AI Prompt Coach';
$string['chat:starter_help_lesson_prompt'] = 'Μπορείτε να με βοηθήσετε να κατανοήσω το τρέχον μάθημα; Δώστε μου μια σύνοψη των βασικών εννοιών.';
$string['chat:starter_study_plan_prompt'] = 'Θα ήθελα να σχεδιάσω τη σημερινή μελέτη μου. Ρωτήστε με: (1) τι θέλω να πετύχω σήμερα, και (2) πόσο χρόνο έχω.';
$string['chat:starter_explain_prompt'] = 'Μπορείτε να εξηγήσετε την πιο σημαντική έννοια σε αυτό το μάθημα μέχρι τώρα;';

// Reset.
$string['chat:reset'] = 'Ξεκινήστε από την αρχή';

// Starter admin settings.
$string['starters:admin_title'] = 'Ρυθμίσεις Αρχικών Ερωτήσεων';
$string['starters:admin_desc'] = 'Ρυθμίστε τα chips αρχικών ερωτήσεων που εμφανίζονται στους φοιτητές.';
$string['starters:add_new'] = 'Προσθήκη νέας';
$string['starters:save'] = 'Αποθήκευση αλλαγών';
$string['starters:saved'] = 'Η διαμόρφωση αποθηκεύτηκε.';
$string['starters:reset_defaults'] = 'Επαναφορά προεπιλογών';
$string['starters:reset_confirm'] = 'Επαναφορά όλων στις προεπιλογές; Τα προσαρμοσμένα θα διαγραφούν.';
$string['starters:reset_done'] = 'Επαναφορά στις προεπιλογές.';
$string['starters:back_settings'] = 'Πίσω στις ρυθμίσεις';
$string['starters:course_section'] = 'Αρχικές ερωτήσεις';
$string['starters:course_desc'] = 'Ενεργοποιήστε ή απενεργοποιήστε μεμονωμένα starters για αυτό το μάθημα.';

// Topic picker.
$string['chat:topic_picker_title'] = 'Σε τι θα θέλατε να εστιάσετε;';
$string['chat:topic_picker_title_help'] = 'Σε τι χρειάζεστε βοήθεια;';
$string['chat:topic_picker_title_explain'] = 'Τι θα θέλατε να σας εξηγήσω;';
$string['chat:topic_picker_title_study'] = 'Σε ποιο τομέα θα θέλατε να εστιάσετε;';
$string['chat:topic_start'] = 'Συνέχεια';

// Expand.
$string['chat:fullscreen'] = 'Πλήρης οθόνη';
$string['chat:exitfullscreen'] = 'Έξοδος πλήρους οθόνης';

// Settings panel.
$string['chat:language'] = 'Αλλαγή γλώσσας';
$string['chat:settings_panel'] = 'Ρυθμίσεις';
$string['chat:settings_language'] = 'Γλώσσα';
$string['chat:settings_avatar'] = 'Avatar';
$string['chat:settings_voice'] = 'Φωνή';
$string['chat:settings_voice_admin'] = 'Οι ρυθμίσεις φωνής διαχειρίζονται στον πίνακα διαχείρισης.';

// Voice mode.
$string['chat:voice_mode'] = 'Λειτουργία φωνής';
$string['chat:voice_title'] = 'Μιλήστε με τον [[tutorshort]]';
$string['chat:voice_copy'] = 'Κάντε μια φυσική φωνητική συνομιλία με τον βοηθό μάθησής σας.';
$string['chat:voice_ready'] = 'Έτοιμο για έναρξη';
$string['chat:voice_start'] = 'Έναρξη συνομιλίας';
$string['chat:voice_end'] = 'Τέλος φωνητικής συνεδρίας';
$string['chat:voice_connecting'] = 'Σύνδεση...';
$string['chat:voice_listening'] = 'Ακούω...';
$string['chat:voice_speaking'] = 'Ο [[tutorshort]] μιλάει...';
$string['chat:voice_idle'] = 'Έτοιμο';
$string['chat:voice_error'] = 'Η φωνητική σύνδεση απέτυχε. Ελέγξτε τις ρυθμίσεις σας.';
$string['chat:quiz_locked'] = 'Ο [[tutorshort]] είναι σε παύση κατά τη διάρκεια εξετάσεων. Καλή επιτυχία!';

// Bottom nav.
$string['chat:mode_nav'] = 'Πλοήγηση λειτουργιών';
$string['chat:mode_chat'] = 'Συνομιλία';
$string['chat:mode_voice'] = 'Φωνή';
$string['chat:mode_history'] = 'Σημειώσεις';

// History panel.
$string['chat:history_title'] = 'Σημειώσεις και ιστορικό συνομιλίας';
$string['chat:history_subtitle'] = 'Τα πρόσφατα μηνύματά σας σε αυτό το μάθημα.';
$string['chat:history_empty'] = 'Δεν υπάρχουν συνομιλίες ακόμα.';
$string['chat:history_refresh'] = 'Ανανέωση';

// Debug panel.
$string['chat:debug_context'] = 'Αποσφαλμάτωση Πλαισίου';
$string['chat:debug_context_toggle'] = 'Εναλλαγή ελεγκτή αποσφαλμάτωσης πλαισίου';
$string['chat:debug_context_copy'] = 'Αντιγραφή';
$string['chat:debug_context_browser'] = 'Στιγμιότυπο Προγράμματος Περιήγησης';
$string['chat:debug_context_request'] = 'Τελευταίο Αίτημα SSE';
$string['chat:debug_context_prompt'] = 'Απάντηση Διακομιστή';

// Quiz hide settings.
$string['settings:quiz_hide_heading'] = 'Ορατότητα Σελίδας Εξέτασης';
$string['settings:quiz_hide_heading_desc'] = 'Ελέγξτε αν το widget [[tutorshort]] εμφανίζεται στις σελίδες εξετάσεων του Moodle.';
$string['settings:hide_on_quiz_for_students'] = 'Απόκρυψη [[tutorshort]] σε σελίδες εξετάσεων για φοιτητές';
$string['settings:hide_on_quiz_for_students_desc'] = 'Πλήρης απόκρυψη του widget [[tutorshort]] σε όλες τις σελίδες εξετάσεων για φοιτητές.';
$string['settings:hide_on_quiz_for_staff'] = 'Απόκρυψη [[tutorshort]] σε σελίδες εξετάσεων για προσωπικό';
$string['settings:hide_on_quiz_for_staff_desc'] = 'Πλήρης απόκρυψη του widget [[tutorshort]] σε όλες τις σελίδες εξετάσεων για καθηγητές και διαχειριστές.';

// Wellbeing.
$string['settings:wellbeing_heading'] = 'Ευεξία & Ασφάλεια';
$string['settings:wellbeing_heading_desc'] = 'Ο [[tutorshort]] ανιχνεύει εκφράσεις δυσφορίας και ανταποκρίνεται με ενσυναίσθηση και πόρους υποστήριξης.';
$string['settings:wellbeing_enabled'] = 'Ενεργοποίηση Υποστήριξης Ευεξίας';
$string['settings:wellbeing_enabled_desc'] = 'Ο [[tutorshort]] θα ανιχνεύει σημάδια συναισθηματικής δυσφορίας και θα παρέχει πόρους κρίσης.';

// Voice mode settings.
$string['settings:realtime_heading'] = 'Λειτουργία Φωνής (OpenAI Realtime)';
$string['settings:realtime_enabled'] = 'Ενεργοποίηση Λειτουργίας Φωνής';
$string['settings:realtime_enabled_desc'] = 'Επιτρέπει στους φοιτητές να κάνουν φωνητικές συνομιλίες σε πραγματικό χρόνο.';
$string['settings:realtime_apikey'] = 'Κλειδί API OpenAI (Φωνή & TTS)';
$string['settings:realtime_apikey_desc'] = 'Χρησιμοποιείται για τη Λειτουργία Φωνής και το κουμπί TTS στα μηνύματα.';
$string['settings:realtime_voice'] = 'Φωνή [[tutorshort]]';
$string['settings:realtime_voice_desc'] = 'Φωνή για τη Λειτουργία Φωνής και το κουμπί TTS.';

// Mobile.
$string['mobile_welcome'] = 'Γεια σου, {$a}!';
$string['mobile_welcome_sub'] = 'Είμαι ο [[tutorshort]], ο βοηθός μάθησής σας. Πώς μπορώ να σας βοηθήσω σήμερα;';
$string['mobile_placeholder'] = 'Κάντε μια ερώτηση...';
$string['mobile_clear'] = 'Εκκαθάριση ιστορικού';
$string['mobile_disabled'] = 'Ο [[tutorshort]] δεν είναι διαθέσιμος για αυτό το μάθημα.';
$string['mobile_chip_concepts'] = 'Βασικές Έννοιες';
$string['mobile_chip_studyplan'] = 'Σχέδιο Μελέτης';
$string['mobile_chip_quiz'] = 'Δοκίμασέ με';

// CDN settings.
$string['settings:cdn_heading'] = 'CDN / Παράδοση Frontend';
$string['settings:cdn_heading_desc'] = 'Εξυπηρέτηση των frontend στοιχείων [[tutorshort]] (JS/CSS) από εξωτερικό CDN αντί του συστήματος αρχείων Moodle. Αυτό επιτρέπει ενημερώσεις frontend χωρίς αναβάθμιση πρόσθετου. Αφήστε το CDN URL κενό για χρήση τοπικών αρχείων πρόσθετου.';
$string['settings:cdn_url'] = 'Βασικό CDN URL';
$string['settings:cdn_url_desc'] = 'Βασικό URL όπου φιλοξενούνται τα sola.min.js και sola.min.css. Παράδειγμα: https://your-org.github.io/sola-cdn. Αφήστε κενό για χρήση τοπικών αρχείων πρόσθετου.';
$string['settings:cdn_version'] = 'Έκδοση CDN στοιχείων';
$string['settings:cdn_version_desc'] = 'Συμβολοσειρά έκδοσης που προστίθεται στα CDN URLs για cache busting. Ενημερώστε μετά από κάθε CDN ανάπτυξη (π.χ. 3.2.4 ή commit hash).';

// Analytics dashboard.
$string['analytics:tab_overall'] = 'Συνολική χρήση';
$string['analytics:tab_bycourse'] = 'Ανά μάθημα';
$string['analytics:tab_comparison'] = 'AI έναντι μη χρηστών';
$string['analytics:tab_byunit'] = 'Ανά ενότητα';
$string['analytics:tab_usagetypes'] = 'Τύποι χρήσης';
$string['analytics:tab_themes'] = 'Θέματα';
$string['analytics:tab_feedback'] = 'Αξιολόγηση AI';
$string['analytics:total_students'] = 'Σύνολο φοιτητών';
$string['analytics:active_users'] = 'Ενεργοί χρήστες AI';
$string['analytics:msgs_per_student'] = 'Μηνύματα ανά φοιτητή';
$string['analytics:avg_session'] = 'Μέση διάρκεια συνεδρίας';
$string['analytics:return_rate'] = 'Ποσοστό επιστροφής';
$string['analytics:total_sessions'] = 'Σύνολο συνεδριών';
$string['analytics:thumbs_up'] = 'Θετικό';
$string['analytics:thumbs_down'] = 'Αρνητικό';
$string['analytics:hallucination_flags'] = 'Σημάνσεις ανακρίβειας';
$string['analytics:msgs_to_resolution'] = 'Μηνύματα μέχρι επίλυση';
$string['analytics:helpful'] = 'Χρήσιμο';
$string['analytics:not_helpful'] = 'Μη χρήσιμο';
$string['analytics:flag_hallucination'] = 'Αυτή η απάντηση περιέχει ανακριβείς πληροφορίες';
$string['analytics:submit_rating'] = 'Υποβολή';
$string['analytics:thanks_feedback'] = 'Ευχαριστούμε για την αξιολόγησή σας';

// LLM provider names.
$string['settings:provider_mistral'] = 'Mistral AI';
$string['settings:provider_openrouter'] = 'OpenRouter';
$string['settings:provider_together'] = 'Together AI (Llama 3.1 8B/70B/405B Turbo, Mistral, Qwen)';
$string['settings:provider_xai'] = 'xAI (Grok)';

$string['settings:provider_coreai'] = 'Moodle AI (core_ai subsystem)';
// Strings added by update_langs.py.
$string['chat:history_saved_subtitle'] = 'Οι αποθηκευμένες απαντήσεις παραμένουν σε αυτή τη συσκευή για αυτό το μάθημα.';
$string['chat:history_saved_empty'] = 'Αποθηκεύστε μια απάντηση AI για να τη δείτε εδώ.';
$string['chat:history_views_label'] = 'Προβολές ιστορικού';
$string['chat:history_view_saved'] = 'Αποθηκευμένες';
$string['chat:history_view_recent'] = 'Ιστορικό';
$string['chat:debug_refresh'] = 'Ανανέωση';
$string['chat:debug_copy_all'] = 'Αντιγραφή όλων';
$string['chat:debug_close'] = 'Κλείσιμο';
$string['chat:language_switch'] = 'Αλλαγή γλώσσας';
$string['chat:language_dismiss'] = 'Απόρριψη πρότασης γλώσσας';
$string['chat:llm_label'] = 'LLM';
$string['chat:llm_provider_select'] = 'Επιλογή παρόχου LLM';
$string['chat:llm_model_label'] = 'Μοντέλο';
$string['chat:llm_model_select'] = 'Επιλογή μοντέλου LLM';
$string['chat:footer_usertesting'] = 'Δοκιμή ευχρηστίας';
$string['chat:footer_feedback'] = 'Σχόλια';
$string['chat:voice_panel_title']       = 'Talk with {$a}';

// Additional translated strings.
$string['analytics:active_students'] = 'Active students';
$string['analytics:all_time'] = 'All time';
$string['analytics:avg_messages_per_student'] = 'Avg messages per student';
$string['analytics:avg_response_length'] = 'Avg response length';
$string['analytics:avg_tokens'] = 'Avg tokens / response';
$string['analytics:common_prompts'] = 'Common Prompt Patterns';
$string['analytics:common_prompts_desc'] = 'Frequently recurring question patterns from students. Review these to identify systemic gaps in course content.';
$string['analytics:daily_messages'] = 'Daily message volume';
$string['analytics:escalation_count'] = 'Escalated to support';
$string['analytics:export'] = 'Export data';
$string['analytics:frequency'] = 'Frequency';
$string['analytics:hotspots'] = 'Course Hotspots';
$string['analytics:hotspots_desc'] = 'Course sections most frequently referenced in student questions. Higher counts may indicate areas where students need more support.';
$string['analytics:last_30_days'] = 'Last 30 days';
$string['analytics:last_7_days'] = 'Last 7 days';
$string['analytics:mention_count'] = 'Mentions';
$string['analytics:no_data'] = 'No analytics data available yet. Data will appear once students begin using the AI tutor.';
$string['analytics:offtopic_rate'] = 'Off-topic rate';
$string['analytics:overview'] = 'Overview';
$string['analytics:prompt_pattern'] = 'Pattern';
$string['analytics:provider'] = 'Provider';
$string['analytics:provider_comparison'] = 'AI Provider Comparison';
$string['analytics:provider_comparison_desc'] = 'Compare performance across AI providers used in this course.';
$string['analytics:recent_activity'] = 'Recent Activity';
$string['analytics:response_count'] = 'Responses';
$string['analytics:section'] = 'Section';
$string['analytics:studyplan_adoption'] = 'Students with study plans';
$string['analytics:timerange'] = 'Time range';
$string['analytics:title'] = 'AI Tutor Analytics';
$string['analytics:total_conversations'] = 'Total conversations';
$string['analytics:total_messages'] = 'Total messages';
$string['analytics:total_tokens'] = 'Total tokens';
$string['analytics:usage_trends'] = 'Usage Trends';
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
$string['messageprovider:integrity_report'] = '[[tutorshort]] integrity check failure report';
$string['messageprovider:study_notes'] = 'Study session notes';
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
$string['privacy:metadata:local_ai_course_assistant_plans'] = 'Stores student study plans.';
$string['privacy:metadata:local_ai_course_assistant_plans:courseid'] = 'The course the study plan belongs to.';
$string['privacy:metadata:local_ai_course_assistant_plans:hours_per_week'] = 'Hours per week the student plans to study.';
$string['privacy:metadata:local_ai_course_assistant_plans:plan_data'] = 'The study plan details in JSON format.';
$string['privacy:metadata:local_ai_course_assistant_plans:userid'] = 'The ID of the user who owns the study plan.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores'] = 'Stores practice session scores and AI feedback.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:ai_feedback'] = 'AI-generated feedback on the practice session.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:courseid'] = 'The course the practice session belongs to.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:overall_score'] = 'The overall score achieved.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:scores'] = 'Per-criterion scores in JSON format.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:session_type'] = 'The type of practice session (conversation or pronunciation).';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:timecreated'] = 'The time the score was recorded.';
$string['privacy:metadata:local_ai_course_assistant_practice_scores:userid'] = 'The ID of the user who completed the practice.';
$string['privacy:metadata:local_ai_course_assistant_reminders'] = 'Stores study reminder preferences and subscriptions.';
$string['privacy:metadata:local_ai_course_assistant_reminders:channel'] = 'The reminder channel (email or whatsapp).';
$string['privacy:metadata:local_ai_course_assistant_reminders:country_code'] = 'The user\'s country code for regulatory compliance.';
$string['privacy:metadata:local_ai_course_assistant_reminders:destination'] = 'The email address or phone number for reminders.';
$string['privacy:metadata:local_ai_course_assistant_reminders:userid'] = 'The ID of the user subscribed to reminders.';
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
$string['reminder:email_body'] = 'Hi {$a->firstname},

This is your study reminder for "{$a->coursename}".

{$a->message}

Your study plan suggests {$a->hours_per_week} hours per week for this course.

Keep up the great work!

---
To stop receiving these reminders, click here: {$a->unsubscribe_url}';
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
$string['studyplan:hours_out_of_range'] = 'Hours per week must be between {$a->min} and {$a->max}. Got {$a->got}. Please tell [[tutorshort]] a different number and it will save your plan.';
$string['reminder:email_subject'] = 'Study Reminder: {$a}';
$string['reminder:study_tip_prefix'] = 'Today\'s study focus: ';
$string['reminder:whatsapp_body'] = 'Study Reminder for {$a->coursename}: {$a->message} (Opt out: {$a->unsubscribe_url})';
$string['remoteconfigurl'] = 'Remote config URL';
$string['remoteconfigurl_desc'] = 'URL to a JSON file containing remotely-managed [[tutorshort]] configuration (system prompt, instruction blocks, model default). Must be HTTPS. Leave blank to use the default GitHub URL. Local admin settings always take priority over remote config values.';
$string['rubric:done'] = 'Ολοκληρώθηκε';
$string['rubric:encourage_high'] = 'Εξαιρετικά! Συνεχίστε!';
$string['rubric:encourage_low'] = 'Καλή αρχή! Η τακτική εξάσκηση θα βοηθήσει.';
$string['rubric:encourage_mid'] = 'Καλή προσπάθεια! Συνεχίστε.';
$string['rubric:overall'] = 'Συνολικά';
$string['rubric:practice_again'] = 'Εξασκηθείτε ξανά';
$string['rubric:score_title_conversation'] = 'Βαθμολογία συνομιλίας';
$string['rubric:score_title_pronunciation'] = 'Βαθμολογία προφοράς';
$string['rubric:scoring'] = 'Αξιολόγηση...';
$string['settings:avatar_saylor'] = '{$a} Logo (Default)';
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
$string['settings:faq_content'] = 'FAQ Content';
$string['settings:faq_content_desc'] = 'Enter FAQ entries (one per line in the format: Q: question | A: answer). These will be provided to the AI to answer common support questions.';
$string['settings:faq_heading'] = 'FAQ & Support';
$string['settings:faq_heading_desc'] = 'Configure the centralized FAQ and Zendesk support ticket integration.';
$string['settings:institution_name'] = 'Institution Name';
$string['settings:institution_name_desc'] = 'The name of the institution displayed in the system prompt, avatar labels, and demo content. Change this when rebranding.';
$string['settings:model_desc_dynamic'] = 'Leave blank to use the provider\'s default model automatically. Each provider has a built-in default that stays current (e.g. gpt-4o for OpenAI, claude-sonnet-4 for Claude, mistral-large-latest for Mistral). Only enter a model name if you want to override the default. If a model is misspelled or deprecated, [[tutorshort]] will automatically fall back to the provider\'s default.';
$string['settings:offtopic_action'] = 'Off-topic Action';
$string['settings:offtopic_action_desc'] = 'What to do when the off-topic limit is reached.';
$string['settings:offtopic_action_end'] = 'Temporarily lock access';
$string['settings:offtopic_action_warn'] = 'Warn and redirect';
$string['settings:offtopic_enabled'] = 'Enable Off-topic Detection';
$string['settings:offtopic_enabled_desc'] = 'Instruct the AI to detect and redirect off-topic conversations.';
$string['settings:offtopic_heading'] = 'Off-topic Detection';
$string['settings:offtopic_heading_desc'] = 'Configure how the chat handles off-topic conversations.';
$string['settings:offtopic_lockout_duration'] = 'Lockout Duration (minutes)';
$string['settings:offtopic_lockout_duration_desc'] = 'How long (in minutes) a student loses access to the AI tutor after exceeding the off-topic limit. Default: 30 minutes.';
$string['settings:offtopic_max'] = 'Max Off-topic Messages';
$string['settings:offtopic_max_desc'] = 'Number of consecutive off-topic messages before taking action.';
$string['settings:rag_chunksize'] = 'Chunk Size (words)';
$string['settings:rag_chunksize_desc'] = 'Target number of words per content chunk when indexing course material. Smaller chunks are more precise; larger chunks provide more context.';
$string['settings:rag_enabled'] = 'Enable RAG (Semantic Search)';
$string['settings:rag_enabled_desc'] = 'When enabled, the AI tutor uses semantic search to retrieve relevant course content for each query instead of stuffing all content into the system prompt.';
$string['settings:rag_heading'] = 'RAG / Semantic Search';
$string['settings:rag_heading_desc'] = 'Retrieval-Augmented Generation: index course content as embeddings and retrieve only the most relevant chunks at query time. Reduces token usage and supports all content types. Requires an embedding API.';
$string['settings:rag_topk'] = 'Top-K Chunks';
$string['settings:rag_topk_desc'] = 'Number of most relevant chunks to retrieve per user query and inject into the system prompt.';
$string['settings:reminders_email_enabled'] = 'Enable Email Reminders';
$string['settings:reminders_email_enabled_desc'] = 'Allow students to opt in to study reminders via email.';
$string['settings:reminders_whatsapp_enabled'] = 'Enable WhatsApp Reminders';
$string['settings:reminders_whatsapp_enabled_desc'] = 'Allow students to opt in to study reminders via WhatsApp (requires WhatsApp API configuration).';
$string['settings:studyplan_enabled'] = 'Enable Study Planning';
$string['settings:studyplan_enabled_desc'] = 'Allow the AI tutor to help students create personalized study plans based on their available time.';
$string['settings:studyplan_heading'] = 'Study Planning & Reminders';
$string['settings:studyplan_heading_desc'] = 'Configure study planning features and reminder notifications.';
$string['settings:systemprompt_default'] = 'You are [[tutorshort]] (Online Learning Assistant), an AI learning coach for {{institution}} students enrolled in "{{coursename}}". The student\'s role is {{userrole}}.

## Role
Provide supportive, course-aligned academic help that encourages learning, practice, motivation, and responsible AI use. You complement faculty-designed courses but do not replace instructors.

## Core Rules
- Ground all academic responses in approved course materials or institutional information.
- Do not invent content or go beyond course scope.
- Redirect learners back to course materials when questions fall outside the course. After two off-topic requests, steer the conversation back to learning.
- When generating practice questions, draw them directly from the course material.

## Course Structure
{{coursetopics}}

## Course Content
The following is the actual text of the course pages and materials. This is your primary knowledge source for this course.

{{coursecontent}}

## What [[tutorshort]] Can Help With
- Explain concepts and summarize lessons
- Give examples and practice questions
- Suggest study strategies
- Encourage persistence and progress

## What [[tutorshort]] Will Not Do
- Make academic or policy decisions
- Provide medical, legal, or mental health counseling
- Assist with academic dishonesty or bypassing learning

## Tone and Style
Communicate in a friendly, caring, encouraging, witty, and motivating way. Be concise, supportive, and respectful.

## Safety
Do not engage in abusive, hateful, discriminatory, or inappropriate conversations. Set firm but kind boundaries and redirect to productive topics.';
$string['settings:whatsapp_api_token'] = 'WhatsApp API Token';
$string['settings:whatsapp_api_token_desc'] = 'Authentication token for the WhatsApp API.';
$string['settings:whatsapp_api_url'] = 'WhatsApp API URL';
$string['settings:whatsapp_api_url_desc'] = 'The API endpoint for sending WhatsApp messages (e.g. Twilio, MessageBird). Must accept POST with JSON body containing "to", "from", and "body" fields.';
$string['settings:whatsapp_blocked_countries'] = 'WhatsApp Blocked Countries';
$string['settings:whatsapp_blocked_countries_desc'] = 'Comma-separated ISO 3166-1 alpha-2 country codes where WhatsApp reminders are not allowed due to local regulations (e.g. "CN,IR,KP").';
$string['settings:whatsapp_from_number'] = 'WhatsApp Sender Number';
$string['settings:whatsapp_from_number_desc'] = 'The phone number to send WhatsApp messages from (with country code, e.g. +14155238886).';
$string['settings:zendesk_email'] = 'Zendesk API Email';
$string['settings:zendesk_email_desc'] = 'Email address of the Zendesk user for API authentication (with /token suffix).';
$string['settings:zendesk_enabled'] = 'Enable Zendesk Escalation';
$string['settings:zendesk_enabled_desc'] = 'When the AI cannot resolve a support question, automatically create a Zendesk ticket with a conversation summary.';
$string['settings:zendesk_subdomain'] = 'Zendesk Subdomain';
$string['settings:zendesk_subdomain_desc'] = 'Your Zendesk subdomain (e.g. "mycompany" for mycompany.zendesk.com).';
$string['settings:zendesk_token'] = 'Zendesk API Token';
$string['settings:zendesk_token_desc'] = 'API token for Zendesk authentication.';
$string['task:index_course_content'] = 'Index course content for RAG semantic search';
$string['task:run_integrity_checks'] = 'Run daily [[tutorshort]] plugin integrity checks';
$string['task:send_inactivity_reminders'] = 'Send weekly inactivity reminder emails';
$string['task:send_reminders'] = 'Send AI tutor study reminders';
$string['unsubscribe:already'] = 'You have already been unsubscribed from these reminders.';
$string['unsubscribe:invalid'] = 'Invalid or expired unsubscribe link.';
$string['unsubscribe:resubscribe'] = 'Changed your mind? You can re-enable reminders through the AI tutor chat.';
$string['unsubscribe:success'] = 'You have been successfully unsubscribed from study reminders for this course.';
$string['unsubscribe:title'] = 'Unsubscribe from Study Reminders';
$string['update:available'] = 'Update Available';
$string['update:back_to_settings'] = 'Back to Settings';
$string['update:changelog'] = 'Release Notes';
$string['update:check'] = 'Check for Updates';
$string['update:confirm'] = 'Install this update? A backup of the current version will be created automatically.';
$string['update:current_version'] = 'Installed Version';
$string['update:desc'] = 'Check for and install [[tutorshort]] plugin updates directly from GitHub releases.';
$string['update:download_failed'] = 'Failed to download the update. Please try again or install manually.';
$string['update:github_error'] = 'Could not reach GitHub. Check your connection or add a GitHub token in settings.';
$string['update:github_token'] = 'GitHub Token (optional)';
$string['update:github_token_desc'] = 'Personal access token for accessing private GitHub repositories. Leave blank for public repos.';
$string['update:install'] = 'Install Update';
$string['update:latest_version'] = 'Latest Available';
$string['update:title'] = 'Plugin Updates';
$string['update:up_to_date'] = 'Up to Date';
$string['usersettings:confirm_delete_all'] = 'Are you sure you want to permanently delete ALL your AI tutor data across all courses? This action cannot be undone.';
$string['usersettings:confirm_delete_course'] = 'Are you sure you want to permanently delete all your AI tutor data for the course "{$a}"? This action cannot be undone.';
$string['usersettings:data_deleted'] = 'Your data has been deleted.';
$string['usersettings:delete_all_button'] = 'Delete All My Data';
$string['usersettings:delete_all_title'] = 'Delete All Your Data';
$string['usersettings:delete_all_warning'] = 'This will permanently delete all your AI tutor conversations across all courses. This action cannot be undone.';
$string['usersettings:delete_course_data'] = 'Delete course data';
$string['usersettings:intro'] = 'Manage your AI tutor chat data and privacy settings';
$string['usersettings:last_activity'] = 'Last activity';
$string['usersettings:messages'] = 'Messages';
$string['usersettings:no_data'] = 'You haven\'t used the AI tutor yet. Your usage data will appear here once you start chatting.';
$string['usersettings:privacy_info'] = 'Your conversations with the AI tutor are stored to provide you with continuous support throughout your course. You have full control over this data and can delete it at any time.';
$string['usersettings:title'] = 'AI Course Assistant - Your Data';
$string['usersettings:total_conversations'] = 'Conversations';
$string['usersettings:total_messages'] = 'Total messages';
$string['usersettings:usage_stats'] = 'Your Usage Statistics';

// Testing Environment admin page and TOC quick links (v3.9.4+).
$string['demo:title'] = 'Περιβάλλον δοκιμών';
$string['demo:heading'] = 'Περιβάλλον δοκιμών';
$string['demo:intro'] = 'Αυτή η σελίδα δημιουργεί ένα μάθημα δοκιμών που είναι <strong>κρυφό από τους μαθητές</strong> (visible=0) και το γεμίζει με ψεύτικους μαθητές, συνομιλίες AI, αξιολογήσεις και σχόλια. Χρήσιμο για προεπισκόπηση του Analytics Dashboard ή επικύρωση αλλαγών του plugin χωρίς να επηρεαστεί κανείς πραγματικά εγγεγραμμένος μαθητής.';
$string['demo:step1'] = 'Βήμα 1: μάθημα δοκιμών';
$string['demo:step2'] = 'Βήμα 2: προσθήκη ψεύτικων μαθητών και συνομιλιών AI';
$string['demo:course_exists'] = 'Το μάθημα δοκιμών υπάρχει: <strong>{$a->fullname}</strong> (σύντομο όνομα <code>{$a->shortname}</code>, id {$a->id})';
$string['demo:badge_hidden'] = 'κρυφό';
$string['demo:badge_visible'] = 'ορατό σε μαθητές';
$string['demo:no_course'] = 'Δεν βρέθηκε μάθημα δοκιμών. Κάντε κλικ παρακάτω για να δημιουργήσετε ένα.';
$string['demo:create_btn'] = 'Δημιουργία κρυφού μαθήματος δοκιμών';
$string['demo:open_course'] = 'Άνοιγμα μαθήματος &rarr;';
$string['demo:seed_intro'] = 'Δημιουργεί demo_student_001, demo_student_002, ... τους εγγράφει στο μάθημα δοκιμών και εισάγει συνθετικές συνομιλίες, μηνύματα, αξιολογήσεις και σχόλια. Εκτελέστε ξανά για προσθήκη περισσότερων δεδομένων ή τικάρετε το „καθαρισμός πρώτα“ για να ξεκινήσετε από την αρχή.';
$string['demo:users_label'] = 'Χρήστες';
$string['demo:weeks_label'] = 'Εβδομάδες';
$string['demo:clear_label'] = 'Καθαρισμός υπαρχόντων χρηστών demo_* πρώτα';
$string['demo:seed_btn'] = 'Προσθήκη μαθητών και συνομιλιών';
$string['demo:view_analytics'] = 'Προβολή στατιστικών για αυτό το μάθημα &rarr;';
$string['demo:footer'] = 'Τα δεδομένα που δημιουργούνται εδώ βρίσκονται στους τυπικούς πίνακες χρηστών / εγγραφών του Moodle και στους δικούς πίνακες συνομιλιών του plugin. Όλοι οι ψεύτικοι χρήστες έχουν ονόματα χρήστη που ξεκινούν με <code>demo_student_</code>, ώστε να φιλτράρονται ή να αφαιρούνται εύκολα. Για να τους αφαιρέσετε, εκτελέστε ξανά το βήμα προσθήκης με επιλεγμένο το „Καθαρισμός υπαρχόντων χρηστών demo_* πρώτα“.';
$string['demo:course_fullname'] = 'Μάθημα δοκιμών [[tutorshort]] (κρυφό)';
$string['demo:notify_created'] = 'Το μάθημα δοκιμών είναι έτοιμο: {$a->fullname} (id {$a->id}).';
$string['demo:notify_create_fail'] = 'Αποτυχία δημιουργίας μαθήματος: {$a}';
$string['demo:notify_seeded'] = 'Προστέθηκαν: {$a->users} χρήστες, {$a->conversations} συνομιλίες, {$a->messages} μηνύματα, {$a->ratings} αξιολογήσεις, {$a->feedback} καταχωρήσεις σχολίων.';
$string['demo:notify_seed_fail'] = 'Αποτυχία προσθήκης δεδομένων: {$a}';
$string['toc:analytics'] = 'Analytics Dashboard &rarr;';
$string['toc:tokenanalytics'] = 'Κόστος Token & Στατιστικά &rarr;';
$string['toc:testing'] = 'Περιβάλλον δοκιμών &rarr;';
$string['toc:back_to_course'] = '&larr; Πίσω στο {$a}';

// RAG extractor status strings (v3.9.6+).
$string['rag:pdftotext_missing'] = 'Το εκτελέσιμο pdftotext δεν βρέθηκε· η εξαγωγή PDF απενεργοποιήθηκε.';
$string['rag:pdftotext_available'] = 'Το pdftotext εντοπίστηκε στο {$a}.';
$string['rag:docx_unavailable'] = 'Η επέκταση PHP ZipArchive δεν είναι διαθέσιμη· η εξαγωγή DOCX απενεργοποιήθηκε.';
$string['rag:h5p_unavailable'] = 'Το περιεχόμενο H5P δεν μπόρεσε να διαβαστεί· παράλειψη.';
$string['rag:scorm_too_large'] = 'Το πακέτο SCORM υπερβαίνει το ρυθμισμένο όριο μεγέθους ({$a} MB)· παράλειψη.';
$string['rag:scorm_unzip_failed'] = 'Το πακέτο SCORM δεν μπόρεσε να αποσυμπιεστεί· παράλειψη.';
$string['rag:transcript_fetch_failed'] = 'Δεν ήταν δυνατή η λήψη απομαγνητοφώνησης από το {$a}.';
$string['rag:transcript_cf_challenge'] = 'Το URL της απομαγνητοφώνησης αποκλείστηκε από την πρόκληση Cloudflare: {$a}.';
$string['rag:embed_detected'] = 'Εντοπίστηκαν ενσωματωμένα πολυμέσα: {$a}';
$string['rag:embed_transcript_attached'] = 'Η απομαγνητοφώνηση επισυνάφθηκε για το {$a}';

// v3.9.10–v3.9.14 new strings.
$string['usersettings:download'] = 'Λήψη των δεδομένων μου ({$a})';
$string['usersettings:download_help'] = 'Κατεβάστε ένα πλήρες αντίγραφο σε μορφή JSON όλων των εγγραφών {$a} που συνδέονται με τον λογαριασμό σας: συνομιλίες, μηνύματα, αξιολογήσεις, σχέδια μελέτης, υπενθυμίσεις, βαθμολογίες εξάσκησης, απαντήσεις σε έρευνες, προφίλ και εγγραφές ελέγχου.';
$string['usersettings:privacy_notice_link'] = 'Διαβάστε την ειδοποίηση απορρήτου του {$a}';
$string['privacy:title'] = 'Ειδοποίηση απορρήτου του {$a}';
$string['admin:user_data:title'] = '{$a} — Εξαγωγή και διαγραφή δεδομένων εκπαιδευόμενου';
$string['admin:user_data:intro'] = 'Λειτουργική διαδρομή για ένα αίτημα GDPR Άρθρο 15 (πρόσβαση) ή Άρθρο 17 (διαγραφή). Αναζητήστε έναν εκπαιδευόμενο μέσω του Moodle user id, ελέγξτε τις εγγραφές που τηρεί αυτό το πρόσθετο για αυτόν και πραγματοποιήστε εξαγωγή ή διαγραφή.';
$string['admin:user_data:search_label'] = 'Moodle user id';
$string['admin:user_data:lookup'] = 'Αναζήτηση';
$string['admin:user_data:not_found'] = 'Δεν βρέθηκε χρήστης με αυτό το id.';
$string['admin:user_data:download'] = 'Λήψη όλων των δεδομένων του εκπαιδευόμενου σε μορφή JSON';
$string['admin:user_data:purge'] = 'Διαγραφή όλων των δεδομένων αυτού του χρήστη';
$string['admin:user_data:confirm_purge'] = 'Οριστική διαγραφή όλων των εγγραφών για {$a}; Αυτό αφορά συνομιλίες, μηνύματα, αξιολογήσεις, σχέδια μελέτης, υπενθυμίσεις, προφίλ, βαθμολογίες εξάσκησης, έρευνες, εγγραφές ελέγχου και σχόλια. Η ενέργεια δεν μπορεί να αναιρεθεί.';
$string['admin:user_data:purged'] = 'Όλα τα δεδομένα του επιλεγμένου χρήστη διαγράφηκαν.';
$string['chat:consent_heading'] = 'Πριν συνομιλήσετε με τον/την {$a->product}';
$string['chat:consent_body'] = 'Το {$a->product} είναι ένας βοηθός μάθησης που τροφοδοτείται από AI. Τα μηνύματά σας και οι απαντήσεις του {$a->product} αποθηκεύονται στη βάση δεδομένων Moodle του {$a->institution} και οι πιο πρόσφατοι δέκα γύροι αποστέλλονται σε έναν εγκεκριμένο πάροχο μοντέλου AI για να απαντήσει στις ερωτήσεις σας. Το μικρό σας όνομα μοιράζεται για εξατομίκευση· καμία άλλη πληροφορία ταυτοποίησης δεν αποστέλλεται στον πάροχο AI. Αν ζητήσετε ανθρώπινη βοήθεια και η ερώτησή σας κλιμακωθεί, αυτή η συνομιλία (συμπεριλαμβανομένων του ονόματος και του email σας) ενδέχεται να μοιραστεί με την ομάδα υποστήριξής μας. Μπορείτε να κατεβάσετε, να διαγράψετε ή να σταματήσετε να χρησιμοποιείτε το {$a->product} ανά πάσα στιγμή.';
$string['chat:consent_accept'] = 'Κατάλαβα, εκκίνηση του/της {$a}';
$string['chat:consent_privacy_link'] = 'Διαβάστε την πλήρη ειδοποίηση απορρήτου';
$string['task:audit_cleanup'] = 'AI Course Assistant – εκκαθάριση πίνακα ελέγχου';
$string['task:conversation_retention'] = 'AI Course Assistant – εκκαθάριση διατήρησης συνομιλιών';
$string['settings:audit_retention_days'] = 'Διατήρηση αρχείου ελέγχου (ημέρες)';
$string['settings:audit_retention_days_desc'] = 'Καθημερινή προγραμματισμένη εργασία διαγράφει εγγραφές ελέγχου παλαιότερες από αυτό. Το 0 απενεργοποιεί. Προεπιλογή 365.';
$string['settings:conversation_retention_days'] = 'Διατήρηση συνομιλιών (ημέρες)';
$string['settings:conversation_retention_days_desc'] = 'Καθημερινή προγραμματισμένη εργασία διαγράφει συνομιλίες των οποίων η τελευταία τροποποίηση είναι παλαιότερη από αυτό. Το 0 απενεργοποιεί. Προεπιλογή 730.';
$string['settings:ssrf_trusted_endpoints'] = 'Αξιόπιστα τελικά σημεία SSRF';
$string['settings:ssrf_trusted_endpoints_desc'] = 'Μία URL ανά γραμμή. Οι αναφερόμενοι κεντρικοί υπολογιστές παρακάμπτουν τους ελέγχους loopback / ιδιωτικού-IP / μόνο-https στον επικυρωτή SSRF του [[tutorshort]]. Χρησιμοποιήστε το μόνο για αυτο-φιλοξενούμενα LLM σε δίκτυο που ελέγχετε — για παράδειγμα <code>http://localhost:11434</code> για τοπικό Ollama, <code>http://10.0.0.5:8000</code> για ένα vLLM pod στο ίδιο VPC. Η σύγκριση ταιριάζει με scheme + host + port· οποιαδήποτε διαδρομή αγνοείται. Προεπιλογή κενό (μπλοκάρει τα πάντα εσωτερικά). Οι γραμμές που ξεκινούν με <code>#</code> είναι σχόλια.';
$string['task:learner_weekly_digest']    = 'Βοηθός μαθήματος AI - Εβδομαδιαία περίληψη μαθητή';
$string['learner_digest:subject']        = 'Η εβδομάδα σου με {$a->course} - {$a->product}';
$string['learner_digest:optin_offer']    = 'Θέλεις ένα σύντομο εβδομαδιαίο email με το τι να επικεντρωθείς στη συνέχεια;';
$string['next_best_action:get_started']           = 'Ξεκίνα με {$a->title}. Άνοιξέ με και ρώτα "βοήθησέ με με {$a->title}".';
$string['next_best_action:get_started_with_module'] = 'Ξεκίνα με {$a->title}. Η ενότητα "{$a->module}" το καλύπτει.';
$string['next_best_action:review']                = 'Επανάλαβε τα βασικά του {$a->title} — άνοιξέ με και ρώτα "εξήγησέ μου το {$a->title} σαν να είμαι αρχάριος".';
$string['next_best_action:review_with_module']    = 'Επανάλαβε τα βασικά του {$a->title} στο "{$a->module}", μετά άνοιξέ με με ερωτήσεις.';
$string['next_best_action:practice']              = 'Χτίσε πάνω σε αυτό που έχεις στο {$a->title}. Άνοιξέ με και ρώτα "δώσε μου ένα λυμένο παράδειγμα για το {$a->title}".';
$string['next_best_action:practice_with_module']  = 'Εξασκήσου στο {$a->title} παράλληλα με το "{$a->module}". Άνοιξέ με για λυμένα παραδείγματα.';
$string['next_best_action:quiz']                  = 'Σταθεροποίησε το {$a->title} με ένα γρήγορο κουίζ. Άνοιξέ με και επίλεξε "Δοκίμασέ με — προσαρμοστικό".';
$string['next_best_action:quiz_with_module']      = 'Σταθεροποίησε το {$a->title} με ένα γρήγορο κουίζ. Η ενότητα "{$a->module}" είναι εκεί που ζει.';
$string['next_best_action:empty_state']           = 'Πας υπέροχα σε κάθε στόχο τώρα — τίποτα να υπενθυμίσω. Συνέχισε.';
$string['next_best_action:header']                = 'Εδώ είναι {$a} πράγματα να εστιάσεις στη συνέχεια:';
$string['learner_digest:unsubscribe_done_title']  = 'Καταργήθηκε η εγγραφή';
$string['learner_digest:unsubscribe_done_body']   = 'Έγινε — δεν θα λαμβάνεις άλλα εβδομαδιαία email για αυτό το μάθημα από το {$a->product}. Μπορείς να εγγραφείς ξανά οποιαδήποτε στιγμή από το παράθυρο συνομιλίας στο μάθημά σου.';
$string['learner_digest:unsubscribe_invalid_title'] = 'Ο σύνδεσμος κατάργησης εγγραφής δεν ισχύει πλέον';
$string['learner_digest:unsubscribe_invalid_body']  = 'Αυτός ο σύνδεσμος έχει λήξει ή είναι λανθασμένος. Μπορείς να διαχειριστείς τις προτιμήσεις email από τις ρυθμίσεις του μαθήματος.';
$string['active_learners:line']                   = '{$a} άλλοι μελετούν αυτό το μάθημα τώρα.';
$string['active_learners:line_global']             = '{$a} άλλοι μελετούν τώρα.';
$string['settings:active_learners_scope']          = 'Εύρος δείκτη ενεργών μαθητών';
$string['settings:active_learners_scope_desc']     = 'Εάν ο δείκτης "άλλοι μελετούν τώρα" πάνω από τους εκκινητές συνομιλίας μετρά μαθητές μόνο στο ίδιο μάθημα ή μαθητές σε όλο τον ιστότοπο. Προεπιλογή <strong>καθολικό</strong>.';
$string['settings:active_learners_scope_global']   = 'Καθολικό (οποιοδήποτε μάθημα)';
$string['settings:active_learners_scope_course']   = 'Μόνο ανά μάθημα';
$string['learner_digest:optin_yes']      = 'Ναι, στείλε μου το εβδομαδιαίο email';
$string['learner_digest:optin_no']       = 'Όχι, ευχαριστώ';
$string['learner_digest:optin_thanks']   = 'Εντάξει. Θα λαμβάνεις εβδομαδιαία περίληψη κάθε Δευτέρα.';
$string['learner_digest:optin_declined'] = 'Εντάξει. Χωρίς email - απλά άνοιξέ με όταν θέλεις έναν έλεγχο.';
$string['settings:xai_proxy_url'] = 'URL του proxy xAI Realtime';
$string['settings:xai_proxy_url_desc'] = 'Δημόσια wss URL της υπηρεσίας [[tutorshort]] xAI Realtime proxy (για παράδειγμα wss://voice.example.com/xai-rt/rt). Όταν ορίζεται μαζί με το JWT secret, η φωνή xAI δρομολογείται μέσω του proxy και το κύριο API κλειδί xAI δεν φτάνει ποτέ στον περιηγητή. Αφήστε κενό για επιστροφή σε άμεση σύνδεση (δεν συνιστάται για παραγωγή).';
$string['settings:xai_proxy_jwt_secret'] = 'JWT secret του proxy xAI Realtime';
$string['settings:xai_proxy_jwt_secret_desc'] = 'Κοινόχρηστο μυστικό HS256 που χρησιμοποιείται για την υπογραφή βραχύβιων session tokens για το xAI Realtime proxy. Πρέπει να ταιριάζει με το μυστικό MOODLE_JWT_SECRET στον Cloudflare Worker. Εναλλάσσετε περιοδικά.';
$string['admin:vendor_dpa:title'] = '{$a} — Κατάσταση DPA προμηθευτή';
$string['admin:vendor_dpa:intro'] = 'Κατάσταση εξαίρεσης από εκπαίδευση, DPA και διατήρησης για κάθε driver παρόχου τεχνητής νοημοσύνης. Χρησιμοποιήστε το για να αποφασίσετε ποιους drivers θα ενεργοποιήσετε στον ιστότοπό σας. Η δρομολόγηση Tier 2 και άνω απαιτεί υπογεγραμμένη DPA και συμβατική εξαίρεση από εκπαίδευση.';
$string['admin:vendor_dpa:maintenance_note'] = 'Αυτός ο πίνακας συντηρείται στο classes/vendor_registry.php. Ενημερώστε τον όταν αλλάζουν οι όροι κάποιου προμηθευτή.';
$string['settings:contact_email'] = 'Contact email';
$string['settings:contact_email_desc'] = 'General contact email shown on the learner facing privacy notice under "Contact". Defaults to contact@saylor.org. Leave empty to hide the line.';
$string['settings:dpo_email'] = 'Email Υπευθύνου Προστασίας Δεδομένων';
$string['settings:dpo_email_desc'] = 'Email επικοινωνίας που εμφανίζεται στην ειδοποίηση απορρήτου προς τους εκπαιδευόμενους κάτω από την ενότητα «Επικοινωνία». Αφήστε κενό για να αποκρύψετε τη γραμμή. Οι rebranded εγκαταστάσεις πρέπει να ορίσουν εδώ τον δικό τους DPO.';
$string['settings:privacy_external_url'] = 'URL σελίδας απορρήτου του ιδρύματος';
$string['settings:privacy_external_url_desc'] = 'Σύνδεσμος προς τη σελίδα απορρήτου σε επίπεδο ιδρύματος, που εμφανίζεται στην ειδοποίηση απορρήτου προς τους εκπαιδευόμενους κάτω από την ενότητα «Επικοινωνία». Αφήστε κενό για να αποκρύψετε τη γραμμή.';
$string['settings:privacy_notice_override'] = 'Παράκαμψη ειδοποίησης απορρήτου (HTML)';
$string['settings:privacy_notice_override_desc'] = 'Εάν οριστεί, αυτός ο HTML αντικαθιστά την προεπιλεγμένη επώνυμη ειδοποίηση απορρήτου που αποδίδεται στο /local/ai_course_assistant/privacy.php. Χρησιμοποιήστε το για να τοποθετήσετε το νομικά εγκεκριμένο κείμενο του ιδρύματός σας χωρίς επεξεργασία κώδικα. Αφήστε κενό για χρήση της προεπιλεγμένης ειδοποίησης, η οποία αντλεί το κείμενό της από τα επτά κλειδιά ρυθμίσεων branding.';
$string['objectives:title'] = 'Μαθησιακοί στόχοι και κατάκτηση';
$string['objectives:toggles_heading'] = 'Παρακολούθηση κατάκτησης';
$string['objectives:toggle_master'] = 'Ενεργοποίηση παρακολούθησης κατάκτησης για αυτό το μάθημα';
$string['objectives:toggle_chip'] = 'Εμφάνιση του δείκτη Μαθησιακής Κατάκτησης στους μαθητές';
$string['objectives:toggle_chip_help'] = 'Προαιρετικό. Όταν είναι απενεργοποιημένο, η κατάκτηση εξακολουθεί να καθοδηγεί σιωπηλά τον βοηθό, αλλά οι εκπαιδευόμενοι δεν βλέπουν κανέναν δείκτη.';
$string['objectives:toggled'] = 'Η ρύθμιση ενημερώθηκε.';
$string['objectives:detected_heading'] = 'Εντοπίστηκαν {$a->count} μαθησιακοί στόχοι από: {$a->source}.';
$string['objectives:source_competency'] = 'ικανότητες Moodle';
$string['objectives:source_summary'] = 'περίληψη μαθήματος';
$string['objectives:source_section'] = 'ενότητα ή περιεχόμενο πρώτης σελίδας';
$string['objectives:source_page'] = 'σελίδα μαθήματος';
$string['objectives:source_llm'] = 'εξαγωγή με τεχνητή νοημοσύνη';
$string['objectives:source_manual'] = 'μη αυτόματη καταχώριση';
$string['objectives:source_none'] = 'καμία αυτόματη πηγή';
$string['objectives:import_detected'] = 'Εισαγωγή των εντοπισμένων στόχων';
$string['objectives:import_llm'] = 'Εξαγωγή στόχων με τεχνητή νοημοσύνη';
$string['objectives:llm_empty'] = 'Η εξαγωγή με τεχνητή νοημοσύνη δεν επέστρεψε στόχους. Δοκιμάστε ξανά αργότερα ή καταχωρίστε τους χειροκίνητα.';
$string['objectives:imported'] = 'Εισήχθησαν {$a} στόχοι.';
$string['objectives:none_detected'] = 'Δεν εντοπίστηκαν αυτόματα μαθησιακοί στόχοι. Καταχωρίστε τους χειροκίνητα παρακάτω ή χρησιμοποιήστε εξαγωγή με τεχνητή νοημοσύνη.';
$string['objectives:list_heading'] = 'Τρέχοντες στόχοι';
$string['objectives:col_code'] = 'Κωδικός';
$string['objectives:col_title'] = 'Τίτλος';
$string['objectives:col_source'] = 'Πηγή';
$string['objectives:col_actions'] = 'Ενέργειες';
$string['objectives:add_heading'] = 'Προσθήκη στόχου';
$string['objectives:add_submit'] = 'Προσθήκη στόχου';
$string['objectives:saved'] = 'Ο στόχος αποθηκεύτηκε.';
$string['objectives:deleted'] = 'Ο στόχος διαγράφηκε.';
$string['objectives:delete_confirm'] = 'Διαγραφή αυτού του στόχου και όλου του ιστορικού προσπαθειών του;';
$string['objectives:delete_all'] = 'Διαγραφή όλων των στόχων αυτού του μαθήματος';
$string['objectives:delete_all_confirm'] = 'Διαγραφή όλων των στόχων και όλου του ιστορικού προσπαθειών αυτού του μαθήματος; Δεν μπορεί να αναιρεθεί.';
$string['objectives:deleted_all'] = 'Όλοι οι στόχοι αυτού του μαθήματος διαγράφηκαν.';
$string['mastery:chip_aria'] = 'Κατάσταση μαθησιακής κατάκτησης';
$string['mastery:popover_aria'] = 'Λεπτομέρειες μαθησιακής κατάκτησης';
$string['mastery:chip_label'] = '{$a->mastered} από {$a->total} κατακτήθηκαν';
$string['mastery:status_mastered'] = 'κατακτήθηκε';
$string['mastery:status_learning'] = 'σε εξέλιξη';
$string['mastery:status_not_started'] = 'δεν ξεκίνησε';
$string['mastery:popover_empty'] = 'Δεν έχουν διαμορφωθεί μαθησιακοί στόχοι για αυτό το μάθημα.';
$string['settings:mastery_heading'] = 'Παρακολούθηση κατάκτησης';
$string['settings:mastery_heading_desc'] = 'Προαιρετική λειτουργία ανά μάθημα που αντιστοιχίζει απαντήσεις σε κουίζ και αλληλεπιδράσεις συνομιλίας με τον βοηθό στους μαθησιακούς στόχους του μαθήματος και στη συνέχεια τροφοδοτεί ένα συμπαγές στιγμιότυπο κατάκτησης πίσω στο system prompt για να καθοδηγήσει τις ερωτήσεις. Διακριτική από προεπιλογή: οι εκπαιδευόμενοι δεν βλέπουν τίποτα εκτός εάν έχει ενεργοποιηθεί ο διακόπτης δείκτη ανά μάθημα.';
$string['settings:mastery_threshold'] = 'Κατώφλι κατάκτησης';
$string['settings:mastery_threshold_desc'] = 'Κυλιόμενη ακρίβεια στην οποία ή πάνω από την οποία ένας στόχος θεωρείται κατακτημένος. 0,0 έως 1,0. Προεπιλογή 0,85.';
$string['settings:mastery_window'] = 'Παράθυρο προσπαθειών';
$string['settings:mastery_window_desc'] = 'Αριθμός των πιο πρόσφατων προσπαθειών ανά στόχο που σταθμίζονται στην κυλιόμενη ακρίβεια. Προεπιλογή 8.';
$string['settings:mastery_decay_enabled']        = 'Ενεργοποίηση φθοράς εμπειρίας';
$string['settings:mastery_decay_enabled_desc']   = 'Όταν είναι ενεργοποιημένο, οι βαθμολογίες εμπειρίας μειώνονται με την πάροδο του χρόνου σε σχέση με την πιο πρόσφατη χρονοσφραγίδα προσπάθειας. Ένας προηγουμένως κατακτημένος στόχος επιστρέφει στο "μαθαίνει" μετά από αρκετό χρόνο. Δεν πέφτει κάτω από το "μαθαίνει". <strong>Προεπιλεγμένο απενεργοποιημένο στην v4.0.</strong>';
$string['settings:mastery_decay_half_life_days'] = 'Χρόνος ημιζωής φθοράς εμπειρίας (ημέρες)';
$string['settings:mastery_decay_half_life_days_desc'] = 'Χρόνος ημιζωής σε ημέρες. Η βαθμολογία πολλαπλασιάζεται με 0.5 ^ (ημέρες από την τελευταία προσπάθεια / χρόνος ημιζωής). Προεπιλογή 30. Χρησιμοποιείται μόνο όταν η φθορά είναι ενεργοποιημένη.';
$string['settings:mastery_classifier_model'] = 'Μοντέλο ταξινομητή';
$string['settings:mastery_classifier_model_desc'] = 'Μοντέλο που χρησιμοποιείται για την ταξινόμηση των αλληλεπιδράσεων του βοηθού σε σχέση με στόχους. Αφήστε κενό για χρήση του προεπιλεγμένου μοντέλου του παρόχου τεχνητής νοημοσύνης· διαφορετικά καθορίστε ένα φθηνό μοντέλο όπως το gpt-4o-mini.';
$string['settings:mastery_classifier_weight'] = 'Βάρος ταξινομητή';
$string['settings:mastery_classifier_weight_desc'] = 'Πόσο μετρά μια προσπάθεια συνομιλίας σε σχέση με μια προσπάθεια κουίζ (1,0). Προεπιλογή 0,3.';
$string['settings:mastery_classifier_threshold'] = 'Κατώφλι αξιοπιστίας ταξινομητή';
$string['settings:mastery_classifier_threshold_desc'] = 'Ελάχιστη αξιοπιστία ταξινομητή που απαιτείται για την καταγραφή μιας προσπάθειας συνομιλίας. 0,0 έως 1,0. Προεπιλογή 0,7.';
$string['chat:mode_progress'] = 'Πρόοδος';
$string['objectives:toggle_dashboard'] = 'Εμφάνιση καρτέλας Πρόοδος στους μαθητές';
$string['objectives:toggle_dashboard_help'] = 'Προαιρετικό. Προσθέτει μια καρτέλα Πρόοδος δίπλα στις καρτέλες Συνομιλία / Φωνή / Ιστορικό μέσα στο widget. Η καρτέλα δείχνει στους εκπαιδευόμενους ποιους στόχους έχουν κατακτήσει, ποιοι είναι σε εξέλιξη και ποιοι δεν έχουν ξεκινήσει.';
$string['mastery:dashboard_title'] = 'Η μαθησιακή σας πρόοδος';
$string['mastery:dashboard_subtitle'] = 'Η κατάκτηση μετριέται από τις απαντήσεις σας στα κουίζ και την εξάσκηση στη συνομιλία. Συνεχίστε — το βάθος υπερτερεί της κάλυψης.';
$string['mastery:dashboard_refresh'] = 'Ανανέωση';
$string['mastery:section_mastered'] = 'Κατακτημένα';
$string['mastery:section_learning'] = 'Σε εξέλιξη';
$string['mastery:section_not_started'] = 'Δεν έχουν ξεκινήσει';
$string['mastery:summary_label'] = '{$a->mastered} από {$a->total} στόχους κατακτήθηκαν';
$string['mastery:ask_about'] = 'Ρωτήστε για αυτό';
$string['mastery:celebrate'] = 'Έχετε κατακτήσει όλους τους στόχους αυτού του μαθήματος. Εξαιρετική δουλειά.';
$string['mastery:ask_template'] = 'Βοήθησέ με να εξασκηθώ και να εμβαθύνω την κατανόησή μου σε αυτόν τον στόχο: {$a}.';
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
$string['task:run_anomaly_digest'] = 'Run [[tutorshort]] anomaly digest';

// v4.2.3: external resources (admin + per-course).
$string['settings:external_resources_heading']      = 'External resources';
$string['settings:external_resources_heading_desc'] = 'Optional opt-in: when on, [[tutorshort]] may include one or two links to reputable open educational resources alongside its course-grounded answer. Restricted to the allowlist below to keep recommendations defensible. Per-course override available on the course settings page. Default off.';
$string['settings:external_resources_enabled']      = 'Enable external resources (site-wide default)';
$string['settings:external_resources_enabled_desc'] = 'When on, [[tutorshort]] may suggest links to the allowlisted external resources. Per-course "force on" / "force off" overrides this. Default off.';
$string['settings:external_resources_allowlist']    = 'External resources allowlist';
$string['settings:external_resources_allowlist_desc'] = 'One resource per line, in the format "Display Name (domain)". [[tutorshort]] will only suggest links to these sites. Defaults to a small set of widely respected open-resource hosts; replace or extend as needed.';
$string['external_resources:title']      = 'External resources';
$string['external_resources:inherit']    = 'Inherit site default ({$a})';
$string['external_resources:force_on']   = 'Force on for this course';
$string['external_resources:force_off']  = 'Force off for this course';
$string['external_resources:on']         = 'on';
$string['external_resources:off']        = 'off';
$string['external_resources:toggle_help']= 'When on, [[tutorshort]] may include up to two links to allowlisted open educational resources alongside its course-grounded answer. Course material always leads.';

// v4.3.0: real Redash push integration.
$string['settings:redash_base_url']           = 'Redash base URL';
$string['settings:redash_base_url_desc']      = 'Base URL of your Redash instance, e.g. https://redash.example.com. Required for the "Send to Redash" action in Learning Radar.';
$string['settings:redash_user_api_key']       = 'Redash user API key';
$string['settings:redash_user_api_key_desc']  = 'API key of a Redash user with permission to create queries against the chosen data source. Found under your Redash user profile. Different from the [[tutorshort]] Redash API key (which controls inbound auth on redash_export.php).';
$string['settings:redash_data_source_id']     = 'Redash data source ID';
$string['settings:redash_data_source_id_desc']= 'Numeric id of the Redash JSON data source pointed at [[tutorshort]]\'s redash_export.php. Visible in the Redash data source URL after saving.';

$string['instructor_dashboard:nav_back_course']  = '← Back to course';
$string['instructor_dashboard:nav_settings']     = 'AI Course Assistant settings';
$string['instructor_dashboard:nav_analytics']    = 'AI Course Assistant analytics';

// v4.4.0: course-page CSP setting.
$string['settings:csp_course_pages_mode']      = 'Course-page Content-Security-Policy';
$string['settings:csp_course_pages_mode_desc'] = 'Optional CSP header on course pages where the AI Course Assistant widget is active. <strong>Off</strong>: no header (default). <strong>Report-only</strong>: send <code>Content-Security-Policy-Report-Only</code> — browsers log violations but do not block. Useful for a one-week observation pass. <strong>Enforce</strong>: send <code>Content-Security-Policy</code> — browsers block off-allowlist iframe sources, fetches, and other risky loads. Helps contain the impact of arbitrary scripts pasted into Additional HTML site config (the IBL AI / Raison incident on 2026-04-29). Does not affect [[tutorshort]] endpoints, which always send a stricter CSP.';
$string['settings:csp_mode_off']               = 'Off (no header on course pages)';
$string['settings:csp_mode_report_only']       = 'Report-only (log violations, do not block)';
$string['settings:csp_mode_enforce']           = 'Enforce (block off-allowlist loads)';

// v4.5.0: site-wide pedagogy defaults.
$string['settings:pedagogy_defaults_heading']      = 'Pedagogy defaults';
$string['settings:pedagogy_defaults_heading_desc'] = 'Site-wide default state for each pedagogy feature. Flip a feature on here and every course inherits it unless that course has an explicit override on its [[tutorshort]] course settings page (force on / force off). On upgrade to v4.5.0, every per-course "force off" override that was set to the legacy default-off value of <code>0</code> is cleared so the new global defaults take effect cleanly. Default off — upgrades from v4.4.x are a no-op until an admin flips a feature on.';
$string['pedagogy:mastery']                = 'Mastery tracking on by default';
$string['pedagogy:mastery_desc']           = 'When on, every course inherits mastery tracking unless the course has its own override. Mastery requires curated learning objectives — courses without objectives fall back gracefully, no error.';
$string['pedagogy:socratic_mode']          = 'Socratic mode on by default';
$string['pedagogy:socratic_mode_desc']     = 'When on, [[tutorshort]] leads with questions instead of direct answers in every course unless the course has its own override.';
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
$string['settings:rate_card_auto_refresh_desc'] = 'When on, a weekly scheduled task fetches the upstream pricing JSON, transforms it to [[tutorshort]]\'s rate-card schema, and writes it to the override field above. Default on. Failures keep the previous override in place.';
$string['settings:rate_card_upstream_url']      = 'Upstream pricing URL';
$string['settings:rate_card_upstream_url_desc'] = 'URL of a JSON manifest in LiteLLM\'s schema. Default points at the community-maintained file in the LiteLLM GitHub repo. URL is checked against the SSRF allowlist before fetch.';
$string['settings:rate_card_refresh_now']        = 'Refresh now';
$string['settings:rate_card_refresh_now_label']  = 'Refresh rate card from upstream';
$string['settings:rate_card_refresh_success']    = 'Rate card refreshed: {$a} entries written.';
$string['settings:rate_card_refresh_error']      = 'Rate card refresh failed: {$a}';
$string['settings:rate_card_last_refresh_at']    = 'Last refresh: {$a}';
$string['settings:rate_card_last_refresh_success']= 'Last fetch succeeded.';
$string['settings:rate_card_never_refreshed']    = 'Never refreshed.';
$string['task:refresh_rate_card']                = 'Refresh [[tutorshort]] LLM rate card from upstream';

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
$string['settings:talking_avatar_heading_desc'] = 'Pick which talking-avatar vendor [[tutorshort]] opens for students when the avatar surface is enabled. [[tutorshort]] ships drivers for D-ID (cheapest WebRTC streaming), HeyGen (LiveKit-backed interactive avatars), Tavus (drop-in iframable Conversational Video Interface), and Synthesia Agents (real-time agent product, configured in the Synthesia dashboard). Per-provider key + persona id appear below; only the chosen provider needs to be filled in. Every outbound call is SSRF-checked.';
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
$string['settings:talking_avatar_tavus_persona_id_desc'] = 'Replica id (the trained likeness) you want [[tutorshort]] to converse as. Combine with a persona id by appending it to the API key field if needed; [[tutorshort]] will pass <code>persona_id</code> through.';
$string['settings:talking_avatar_synthesia_api_key']         = 'Synthesia API key';
$string['settings:talking_avatar_synthesia_api_key_desc']    = 'API key from <a href="https://app.synthesia.io/#/account/api" target="_blank" rel="noopener">Synthesia → Account → API</a>. Sent as <code>Authorization</code> header (Synthesia accepts the raw key).';
$string['settings:talking_avatar_synthesia_persona_id']      = 'Synthesia agent id';
$string['settings:talking_avatar_synthesia_persona_id_desc'] = 'Agent id created in the Synthesia Agents dashboard. Knowledge, persona, and allowed origins are configured agent-side; [[tutorshort]] only opens a session against this id.';
$string['talking_avatar:disabled']        = 'Talking avatar is not enabled for this course.';
$string['talking_avatar:unconfigured']    = 'Talking avatar is enabled but no provider has been configured. An administrator must pick a provider and supply credentials in plugin settings.';
$string['talking_avatar:session_failed']  = 'The talking-avatar provider declined the session request. Check the provider configuration or try again in a moment.';
$string['talking_avatar:viewer_title']    = '[[tutorshort]] talking avatar';
$string['talking_avatar:bundle_required'] = 'The talking-avatar viewer requires the [[tutorshort]] CDN bundle to be configured. Ask an administrator to set the CDN bundle URL in plugin settings.';
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
$string['settings:current_page_content_maxchars_desc'] = 'Ο μέγιστος αριθμός χαρακτήρων του κειμένου της τρέχουσας σελίδας που εισάγονται στο system prompt ως η ενότητα "Current Page Content", όταν το RAG είναι απενεργοποιημένο. Η προεπιλογή 8,000 θεμελιώνει καλά τις ερωτήσεις που αφορούν τη σελίδα, αφήνοντας ταυτόχρονα περιθώριο για δομή και οδηγίες. (Με ενεργοποιημένο το RAG, η σελίδα θεμελιώνεται αντ\' αυτού από τα δικά της πιο σχετικά αποσπάσματα, με προτίμηση στην τρέχουσα σελίδα, οπότε αυτό το όριο δεν ισχύει.) Μια πολύ μεγάλη σελίδα περικόπτεται από την αρχή σε τόσους χαρακτήρες, οπότε το τέλος μιας εξαιρετικά μεγάλης σελίδας ενδέχεται να μην παρατεθεί· η ενεργοποίηση του RAG το αποφεύγει αυτό. Οι ιστότοποι που προσέχουν το κόστος μπορούν να ορίσουν χαμηλότερη τιμή (π.χ. 3,000-4,000). Περιορίζεται στο εύρος 500-8,000. Ανεξάρτητο από το <code>prompt_budget_chars</code>: αυτό περιορίζει μόνο την ενότητα της σελίδας· το budget περιορίζει ολόκληρο το prompt.';
$string['settings:prompt_verbosity']      = 'Prompt verbosity';
$string['settings:prompt_verbosity_desc'] = 'Default verbosity for instruction blocks (Socratic mode, external resources). Concise (default) is what modern hosted models follow reliably; standard adds explicit scaffolding for mid-tier models; verbose keeps the heavyweight v3.9.30-era guidance for weaker self-hosted models. Per-course override available via <code>prompt_verbosity_course_&lt;id&gt;</code>.';
$string['settings:prompt_verbosity_concise']  = 'Concise (recommended for hosted models)';
$string['settings:prompt_verbosity_standard'] = 'Standard';
$string['settings:prompt_verbosity_verbose']  = 'Verbose (for weaker self-hosted models)';
$string['settings:prompt_metrics_enabled']      = 'Capture per-section prompt metrics';
$string['settings:prompt_metrics_enabled_desc'] = 'When on (default), every chat turn writes one JSON line per assembled prompt to <code>moodledata/sola_prompt_metrics/YYYY-MM-DD.log</code> with per-category char counts. Last 7 days kept. The metrics admin page aggregates these for the budget recommendation. No PII is recorded — only section sizes. Turn off if your institution prefers no metrics file at all.';
$string['settings:prompt_budget_auto_tune']      = 'Auto-tune system prompt budget daily';
$string['settings:prompt_budget_auto_tune_desc'] = 'When on, a daily cron task (03:20 server time) applies the budget recommendation surfaced on the <a href="/local/ai_course_assistant/prompt_metrics.php">Prompt metrics</a> admin page. Default off — the recommendation always shows on the page; auto-apply only fires when the institution opts in. Manual "Apply recommendation" button is unaffected by this toggle.';
$string['task:auto_tune_prompt_budget']          = 'Auto-tune [[tutorshort]] prompt budget from observed metrics';
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
$string['empathy:desc'] = 'Three coordinated features that make [[tutorshort]] feel more like a coach who listens. Goals capture why the learner is here. Carryover memory remembers what has been hard before so [[tutorshort]] can offer a different angle. Milestones celebrate streaks and completions by email. Each feature has an independent kill switch and learner opt-in. Struggle signals never leave the chat — no email is ever sent about a difficult session.';
$string['empathy:outreach_master_enabled'] = 'Master outreach kill switch';
$string['empathy:outreach_master_enabled_desc'] = 'Off by default on a fresh install. When off, NO empathetic email of any kind ever fires, regardless of the per-feature switches below. Turn this on once you have reviewed the per-feature defaults and per-learner consent flow.';
$string['empathy:goals_enabled'] = 'Enable career goal conversations';
$string['empathy:goals_enabled_desc'] = 'Lets learners volunteer two short answers (why they are here, what they want to become) that feed personalisation. In-chat only; no emails. Safe to leave on.';
$string['empathy:milestones_enabled'] = 'Enable milestone reflection emails';
$string['empathy:milestones_enabled_desc'] = 'Sends a short warm email when a learner reaches a 7-day streak, 30-day streak, or course completion. Requires the master switch above plus per-learner consent. Hard cap of one email per learner per 7 days across all channels.';
$string['empathy:memory_enabled'] = 'Enable carryover personalisation memory';
$string['empathy:memory_enabled_desc'] = 'Lets [[tutorshort]] carry small private notes about what has been hard for a learner across sessions, so the next reply can offer a different angle. Bounded (max 5 notes per learner per course, 90-day TTL). Learner-editable. Never visible to instructors.';
$string['empathy:struggle_enabled'] = 'Enable struggle classifier';
$string['empathy:struggle_enabled_desc'] = 'Off by default. Lets [[tutorshort]] detect sustained frustration over multiple turns and quietly record a sticking-point note in the carryover memory above. Output is in-chat only; no email is ever sent about a struggle session. Auto-purges signal data after 7 days.';
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
$string['comms:desc'] = 'Choose which automated emails [[tutorshort]] may send you. Off by default. You can change this any time.';
$string['comms:milestones_label'] = 'Email me when I reach a milestone (7-day streak, 30-day streak, course completion).';
$string['comms:audit_log_title'] = 'What [[tutorshort]] has sent me';
$string['comms:audit_log_empty'] = '[[tutorshort]] has not sent you any emails.';
$string['comms:memory_title'] = "What [[tutorshort]] has remembered about how I learn";
$string['comms:memory_desc'] = 'These notes are private to your chat with [[tutorshort]]. They help [[tutorshort]] pick a different angle when a topic is hard. Clear any time.';
$string['comms:memory_clear'] = 'Clear all memory notes';
$string['milestone:streak_subject'] = '{$a->days}-day streak in {$a->coursename}';
$string['milestone:streak_body_text'] = "Hi {\$a->firstname},\n\nYou have shown up {\$a->days} days in a row in {\$a->coursename}. That kind of consistency is the part of learning that is hardest to fake.\n\nWhenever you are ready, [[tutorshort]] is here.\n\n— {\$a->institution}";
$string['milestone:completion_subject'] = 'You finished {$a->coursename}';
$string['milestone:completion_body_text'] = "Hi {\$a->firstname},\n\nYou finished {\$a->coursename}. That is a real thing you did.\n\nIf you want to keep going, [[tutorshort]] can help you pick a related next course or revisit a topic you found interesting.\n\n— {\$a->institution}";
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
$string['pedagogy:crossmastery'] = 'Συγκεντρωτική παρακολούθηση μάθησης μεταξύ μαθημάτων ενεργή από προεπιλογή';
$string['pedagogy:crossmastery_desc'] = 'Όταν είναι ενεργό, το [[tutorshort]] αναγνωρίζει πότε ένας εκπαιδευόμενος έχει ήδη κατακτήσει έναν στόχο σε άλλο μάθημα (με αντιστοίχιση βάσει αναφοράς δεξιότητας ή τίτλου) και επιβεβαιώνει αυτή την προϋπάρχουσα δεξιότητα αντί να την εξασκεί ξανά. Απαιτεί παρακολούθηση μάθησης· τα μαθήματα χωρίς στόχους υποχωρούν ομαλά. Αποκλειστικά συμβουλευτικό — δεν αλλάζει ποτέ την αποθηκευμένη βαθμολογία μάθησης ενός εκπαιδευόμενου σε κανένα μάθημα.';
$string['pedagogy:mastery_starter'] = 'Εναρκτήριο μήνυμα με επίγνωση μάθησης ενεργό από προεπιλογή';
$string['pedagogy:mastery_starter_desc'] = 'Όταν είναι ενεργό, το εναρκτήριο μήνυμα συνομιλίας «Σε τι πρέπει να επικεντρωθώ;» εξατομικεύεται ώστε να ονομάζει τον πιο αδύναμο στόχο του εκπαιδευόμενου (καθώς και κάθε δεξιότητα που έχει ήδη κατακτηθεί αλλού). Απαιτεί παρακολούθηση μάθησης· υποχωρεί στο γενικό εναρκτήριο μήνυμα όταν δεν υπάρχουν ακόμη δεδομένα μάθησης.';
$string['task:rebuild_objective_links'] = 'Αναδημιουργία συνδέσμων στόχων μεταξύ μαθημάτων για τη συγκεντρωτική παρακολούθηση μάθησης (v5.7.0)';
$string['mastery_starter:practice_label'] = 'Εξάσκηση: {$a}';
$string['objectives:rebuild_links_heading'] = 'Σύνδεσμοι μάθησης μεταξύ μαθημάτων';
$string['objectives:rebuild_links_help'] = 'Το [[tutorshort]] συνδέει στόχους που αντιστοιχίζονται μεταξύ μαθημάτων (βάσει αναφοράς δεξιότητας ή τίτλου), ώστε ένας εκπαιδευόμενος που έχει κατακτήσει ένα θέμα αλλού να μην το εξασκεί ξανά. Οι σύνδεσμοι αναδημιουργούνται αυτόματα κάθε βράδυ· χρησιμοποιήστε αυτό το κουμπί για άμεση αναδημιουργία μετά την επεξεργασία στόχων.';
$string['objectives:rebuild_links_button'] = 'Αναδημιουργία συνδέσμων τώρα';
$string['objectives:rebuild_links_done'] = 'Οι σύνδεσμοι μάθησης μεταξύ μαθημάτων αναδημιουργήθηκαν: {$a->total} συνολικά ({$a->ref} βάσει αναφοράς, {$a->exact} ακριβής τίτλος, {$a->fuzzy} προσεγγιστικός τίτλος).';

// Forward learning-path awareness (v5.8.0).
$string['pedagogy:program_path'] = 'Επίγνωση της μελλοντικής μαθησιακής διαδρομής ενεργή από προεπιλογή';
$string['pedagogy:program_path_desc'] = 'Όταν είναι ενεργή, το [[tutorshort]] μπορεί να ενημερώσει έναν εκπαιδευόμενο για το πού οδηγεί στη συνέχεια το τρέχον μάθημα μέσα στο πρόγραμμά του (πτυχίο ή πιστοποιητικό) και πώς οι σημερινές έννοιες γεφυρώνονται με μεταγενέστερα μαθήματα. Διαβάζει το πρόσθετο Moodle Programs (Degrees και Learn) και ονομάζει ένα συγκεκριμένο επόμενο μάθημα μόνο όπου το πρόγραμμα ορίζει προαπαιτούμενο ή απαιτούμενη σειρά· διαφορετικά επισημαίνει τη θέση του εκπαιδευόμενου στη διαδρομή. Μόνο συμβουλευτικού χαρακτήρα — δεν αλλάζει ποτέ την εγγραφή ή την κατάκτηση γνώσης και χρησιμοποιεί πάντα μόνο τη δική κατανομή προγράμματος του τρέχοντος εκπαιδευόμενου. Δεν κάνει τίποτα σιωπηρά όπου δεν ισχύει κανένα πρόγραμμα.';

// Learning path map + next-course nudge (v5.9.0).
$string['pedagogy:learning_path'] = 'Ο χάρτης μαθησιακής διαδρομής και η υπενθύμιση επόμενου μαθήματος είναι ενεργοποιημένα από προεπιλογή';
$string['pedagogy:learning_path_desc'] = 'Όταν είναι ενεργό, το [[tutorshort]] προσθέτει ένα οπτικό πάνελ μαθησιακής διαδρομής (ένα κουμπί «η διαδρομή μου» στην κεφαλίδα του widget) που εμφανίζει το πρόγραμμα του εκπαιδευόμενου ως μια ακολουθία μαθημάτων, καθένα από τα οποία μπορεί να αναπτυχθεί στους στόχους του και στην κατάκτηση του εκπαιδευόμενου. Όταν ο εκπαιδευόμενος έχει φτάσει το όριο για το τρέχον μάθημα (ολοκλήρωση ή υψηλό ποσοστό κατακτημένων στόχων), το [[tutorshort]] εμφανίζει επίσης ένα διακριτικό banner «έτοιμος για το επόμενο μάθημα» και το αναφέρει στη συνομιλία. Μόνο συμβουλευτικό· χρησιμοποιεί τη δική του κατανομή προγράμματος του εκπαιδευόμενου· δεν κάνει τίποτα σιωπηλά όπου δεν ισχύει κανένα πρόγραμμα.';
$string['settings:learning_path_mastery_threshold'] = 'Όριο ετοιμότητας μαθησιακής διαδρομής (%)';
$string['settings:learning_path_mastery_threshold_desc'] = 'Ποσοστό των παρακολουθούμενων στόχων ενός μαθήματος που πρέπει να κατακτήσει ένας εκπαιδευόμενος προτού η υπενθύμιση μαθησιακής διαδρομής τον θεωρήσει έτοιμο για το επόμενο μάθημα. Η ολοκλήρωση του μαθήματος στο Moodle είναι ο άλλος ενεργοποιητής· όποιο συμβεί πρώτο πυροδοτεί την υπενθύμιση. Προεπιλογή 80.';
$string['pathpanel_title'] = 'Η μαθησιακή μου διαδρομή';
$string['pathpanel_open'] = 'Η μαθησιακή μου διαδρομή';
$string['pathpanel_empty'] = 'Δεν υπάρχει ακόμη διαθέσιμη διαδρομή προγράμματος για αυτό το μάθημα.';
$string['path_position'] = 'Μάθημα {$a->index} από {$a->total}';
$string['path_status_done'] = 'Ολοκληρώθηκε';
$string['path_status_current'] = 'Είστε εδώ';
$string['path_status_upcoming'] = 'Επερχόμενο';
$string['path_mastery_mastered'] = 'Κατακτημένο';
$string['path_mastery_in_progress'] = 'Σε εξέλιξη';
$string['path_mastery_not_started'] = 'Δεν ξεκίνησε';
$string['path_mastery_demonstrated_elsewhere'] = 'Επιδείχθηκε σε άλλο μάθημα';
$string['nudge_ready_title'] = 'Έτοιμοι να προχωρήσετε';
$string['nudge_ready_body'] = 'Μπράβο — είστε έτοιμοι για {$a}.';
$string['nudge_view_path'] = 'Δείτε τη διαδρομή μου';
$string['nudge_dismiss'] = 'Απόρριψη';

// v5.10.x strings (token-aware budgeting, backend retry, self-test, deployment presets, escalation consent, privacy).
$string['settings:backend_context_tokens'] = 'Παράθυρο περιβάλλοντος backend (tokens)';
$string['settings:backend_context_tokens_desc'] = 'Το μέγιστο μήκος περιβάλλοντος (max_model_len) του AI backend σας, σε tokens. Ορίστε σε 0 για φιλοξενούμενα μοντέλα με μεγάλο παράθυρο (χωρίς περιορισμό). Όταν ορίζεται πάνω από 0 (για παράδειγμα 8192 σε ένα αυτο-φιλοξενούμενο vLLM backend), το [[tutorshort]] συρρικνώνει τον προϋπολογισμό χαρακτήρων του προτρεπτικού συστήματος παραπάνω, ώστε το προτρεπτικό μαζί με τη δεσμευμένη έξοδο και το ιστορικό της συνομιλίας να χωρούν στο παράθυρο, ακόμη και σε γλώσσες με υψηλή πυκνότητα tokens. Δείτε τη σελίδα wiki Deployment Sizing για το πώς αυτό αντιστοιχεί στους ταυτόχρονους χρήστες.';
$string['settings:backend_retry_attempts'] = 'Προσπάθειες επανάληψης backend';
$string['settings:backend_retry_attempts_desc'] = 'Πόσες φορές να επαναληφθεί ένα παροδικό σφάλμα backend (HTTP 429 ή 503) προτού εμφανιστεί σφάλμα στον φοιτητή. Οι επαναλήψεις συμβαίνουν μόνο πριν μεταδοθεί οποιοδήποτε κείμενο απόκρισης, οπότε η έξοδος δεν διπλασιάζεται ποτέ. Στοχεύει σε μικρά αυτο-φιλοξενούμενα backend που απορρίπτουν αιτήματα υπό φόρτο. Ορίστε σε 0 για απενεργοποίηση. Προεπιλογή 2.';
$string['settings:backend_retry_max_wait'] = 'Μέγιστη αναμονή επανάληψης backend (δευτερόλεπτα)';
$string['settings:backend_retry_max_wait_desc'] = 'Άνω όριο, σε δευτερόλεπτα, για το πόσο θα τηρείται μια κεφαλίδα Retry-After από το backend πριν την επανάληψη. Όταν το backend δεν στέλνει Retry-After, το [[tutorshort]] χρησιμοποιεί αντ\' αυτού μια σύντομη εκθετική υποχώρηση. Προεπιλογή 5.';
$string['prompt:truncation_hint'] = 'ΣΗΜΕΙΩΣΗ: Το πλήρες περιεχόμενο του μαθήματος δεν ήταν δυνατό να αναζητηθεί σε αυτόν τον γύρο λόγω περιορισμών μήκους. Αν ο φοιτητής ρωτήσει για κάτι που δεν μπορείτε να βρείτε στο παρεχόμενο περιεχόμενο, πείτε ότι δεν μπορέσατε να αναζητήσετε ολόκληρο το μάθημα και προτείνετε να ανοίξει τη συγκεκριμένη σελίδα ή δραστηριότητα όπου καλύπτεται το θέμα, αντί να δηλώσετε ότι απουσιάζει από το μάθημα.';
$string['selftest:title'] = 'Αυτοέλεγχος backend';
$string['selftest:intro'] = 'Εκτελέστε έναν ζωντανό έλεγχο του ρυθμισμένου AI backend σας: μια μικροσκοπική αμφίδρομη συνομιλία, αυτόματη ανίχνευση του παραθύρου περιβάλλοντος (max_model_len) και σύγκριση με τη ρύθμιση παραθύρου περιβάλλοντος backend, το κατώφλι του προϋπολογισμού προτρεπτικού συστήματος και (όταν το RAG είναι ενεργό) μια αμφίδρομη ενσωμάτωση. Οι κλήσεις δικτύου εκτελούνται μόνο όταν πατήσετε Εκτέλεση.';
$string['selftest:run'] = 'Εκτέλεση αυτοελέγχου backend';
$string['selftest:check'] = 'Έλεγχος';
$string['selftest:status'] = 'Κατάσταση';
$string['selftest:detail'] = 'Λεπτομέρεια';
$string['selftest:link'] = 'Σελίδα αυτοελέγχου backend';
$string['selftest:link_desc'] = 'Ανοίξτε τη σελίδα <a href="{$a}">Αυτοέλεγχος backend</a> για να επαληθεύσετε ότι το AI backend σας λειτουργεί και έχει σωστές διαστάσεις. Χρήσιμο αμέσως μετά τη ρύθμιση ενός αυτο-φιλοξενούμενου backend.';
$string['profile:title'] = 'Προκαθορισμένες ρυθμίσεις ανάπτυξης';
$string['profile:intro'] = 'Εφαρμόστε ένα προτεινόμενο πακέτο ρυθμίσεων για τον τύπο ανάπτυξής σας. Οι τιμές γράφονται στις κανονικές ρυθμίσεις του πρόσθετου και παραμένουν μεμονωμένα επεξεργάσιμες στη συνέχεια. Η εφαρμογή μιας προκαθορισμένης ρύθμισης αντικαθιστά τις αναφερόμενες ρυθμίσεις.';
$string['profile:current'] = 'Τελευταία εφαρμοσμένη προκαθορισμένη ρύθμιση: {$a}';
$string['profile:setting'] = 'Ρύθμιση';
$string['profile:value'] = 'Τιμή';
$string['profile:self_hosted_small'] = 'Αυτο-φιλοξενούμενο μικρό περιβάλλον (μία GPU, π.χ. A30 24GB / vLLM στα 8K)';
$string['profile:hosted_large'] = 'Φιλοξενούμενο μεγάλο περιβάλλον (προεπιλογή)';
$string['profile:apply_self_hosted_small'] = 'Εφαρμογή προκαθορισμένης ρύθμισης αυτο-φιλοξενούμενου μικρού περιβάλλοντος';
$string['profile:apply_hosted_large'] = 'Εφαρμογή προεπιλογών φιλοξενούμενου μεγάλου περιβάλλοντος';
$string['profile:applied'] = 'Εφαρμόστηκε η προκαθορισμένη ρύθμιση {$a}. Οι τιμές βρίσκονται τώρα στις ρυθμίσεις του πρόσθετού σας.';
$string['profile:unknown'] = 'Άγνωστη προκαθορισμένη ρύθμιση ανάπτυξης.';
$string['profile:link'] = 'Σελίδα προκαθορισμένων ρυθμίσεων ανάπτυξης';
$string['profile:link_desc'] = 'Ανοίξτε τη σελίδα <a href="{$a}">Προκαθορισμένες ρυθμίσεις ανάπτυξης</a> για να εφαρμόσετε ένα προτεινόμενο πακέτο ρυθμίσεων για ένα φιλοξενούμενο ή αυτο-φιλοξενούμενο backend.';
$string['settings:zendesk_require_consent'] = 'Απαίτηση συγκατάθεσης πριν την κλιμάκωση υποστήριξης';
$string['settings:zendesk_require_consent_desc'] = 'Όταν είναι ενεργό (συνιστάται), το [[tutorshort]] κλιμακώνει μια συνομιλία στο γραφείο υποστήριξης Zendesk μόνο αφού ο εκπαιδευόμενος έχει αποδεχθεί την ειδοποίηση συγκατάθεσης πρώτης εκτέλεσης, η οποία αποκαλύπτει ότι το αίτημα ανθρώπινης βοήθειας μοιράζεται τη συνομιλία (συμπεριλαμβανομένων ονόματος και email) με την υποστήριξη. Απενεργοποιήστε το μόνο αν λαμβάνετε αυτή τη συγκατάθεση με άλλο τρόπο· όταν είναι απενεργοποιημένο, οι κλιμακώσεις αποστέλλονται αμέσως. Δεν έχει καμία επίδραση εκτός αν είναι ενεργοποιημένη η κλιμάκωση Zendesk.';
$string['chat:escalation_needs_consent'] = 'Φαίνεται ότι αυτό χρειάζεται ένα μέλος της ομάδας υποστήριξής μας. Για να το μεταβιβάσω σε αυτούς, θα έπρεπε να μοιραστώ αυτή τη συνομιλία, συμπεριλαμβανομένων του ονόματος και του email σας, με το γραφείο υποστήριξης. Δεν έχετε συμφωνήσει σε αυτό ακόμη, οπότε δεν έχω στείλει τίποτα. Αν θέλετε ανθρώπινη βοήθεια, παρακαλώ αποδεχθείτε την ειδοποίηση κοινής χρήσης δεδομένων για αυτόν τον βοηθό και ρωτήστε ξανά, ή επικοινωνήστε απευθείας με την υποστήριξη.';
$string['privacy:metadata:email_optout'] = 'Προτιμήσεις εξαίρεσης email ανά παραλήπτη (από ποιους τύπους email έχει απεγγραφεί ένας παραλήπτης).';
$string['privacy:metadata:email_optout:email'] = 'Η διεύθυνση email του παραλήπτη στην οποία ισχύει η εξαίρεση.';
$string['privacy:metadata:email_optout:optout_type'] = 'Ο τύπος email από τον οποίο έχει εξαιρεθεί ο παραλήπτης.';
$string['privacy:metadata:email_optout:userid'] = 'Ο χρήστης Moodle στον οποίο ανήκει η εξαίρεση, όταν είναι γνωστός.';
$string['chat:consent_scroll_hint'] = 'Παρακαλώ μετακινηθείτε προς τα κάτω για να διαβάσετε ολόκληρη την ειδοποίηση πριν συνεχίσετε.';
$string['settings:rag_min_similarity'] = 'Ελάχιστη συνάφεια (cosine)';
$string['settings:rag_min_similarity_desc'] = 'Απορρίπτει τα ανακτημένα αποσπάσματα των οποίων η ομοιότητα cosine με την ερώτηση είναι κάτω από αυτή την τιμή, ώστε μια ερώτηση εκτός θέματος ή με λίγο περιεχόμενο να εισάγει λιγότερα (ή μηδέν) αποσπάσματα αντί να γεμίζει πάντα έως το Top-K με αδύναμες αντιστοιχίσεις. Εύρος 0 έως 1· το 0 απενεργοποιεί την πύλη. Η σωστή τιμή εξαρτάται από το μοντέλο embedding: το 0.25 ταιριάζει στο text-embedding-3-small. Αυξήστε την για να είστε αυστηρότεροι (λιγότερο, πιο σχετικό με το θέμα context), μειώστε την για να είστε πιο επιεικείς.';
$string['settings:rag_currentpage_boost'] = 'Ενίσχυση τρέχουσας σελίδας';
$string['settings:rag_currentpage_boost_desc'] = 'Ένα μικρό μπόνους που προστίθεται στη βαθμολογία συνάφειας των αποσπασμάτων από τη σελίδα που βλέπει αυτή τη στιγμή ο εκπαιδευόμενος, ώστε ερωτήσεις όπως "εξήγησέ μου αυτό" να προτιμούν την ορατή σελίδα όταν οι βαθμολογίες είναι κοντινές. Μόνο για την κατάταξη: δεν αναγκάζει ένα άσχετο απόσπασμα σελίδας να περάσει την πύλη ελάχιστης συνάφειας. Ορίστε 0 για απενεργοποίηση.';
$string['settings:history_mode'] = 'Λειτουργία επιλογής ιστορικού';
$string['settings:history_mode_desc'] = 'Πώς επιλέγονται οι προηγούμενες ανταλλαγές της συνομιλίας πριν σταλούν στο μοντέλο. Η <strong>Semantic</strong> διατηρεί μόνο τις πρόσφατες ανταλλαγές που σχετίζονται με την τρέχουσα ερώτηση (και πάντα την πιο πρόσφατη ανταλλαγή), ώστε μια παλιά, εκτός θέματος προηγούμενη ανταλλαγή να μην διογκώνει το κόστος ούτε να βγάζει την απάντηση εκτός πορείας· κάνει μία επιπλέον κλήση embedding ανά μήνυμα. Η <strong>Recency</strong> διατηρεί τα τελευταία "Max Conversation History" ζεύγη ανεξαρτήτως συνάφειας (η μακροχρόνια συμπεριφορά, χωρίς επιπλέον κλήση). Αν το embedding δεν είναι διαθέσιμο, η σημασιολογική λειτουργία επανέρχεται αυτόματα σε recency.';
$string['settings:history_mode_semantic'] = 'Semantic (σχετικές πρόσφατες ανταλλαγές)';
$string['settings:history_mode_recency'] = 'Recency (τελευταία N ζεύγη)';
$string['settings:history_semantic_minscore'] = 'Κατώφλι συνάφειας ιστορικού (cosine)';
$string['settings:history_semantic_minscore_desc'] = 'Στη σημασιολογική λειτουργία ιστορικού, μια προηγούμενη ανταλλαγή διατηρείται μόνο αν η ομοιότητά της με την τρέχουσα ερώτηση είναι τουλάχιστον αυτή η τιμή (η πιο πρόσφατη ανταλλαγή διατηρείται πάντα). Εύρος 0 έως 1· εξαρτάται από το μοντέλο. Αυξήστε για μεγαλύτερη αυστηρότητα (λιγότερο ιστορικό), μειώστε για να διατηρήσετε περισσότερο.';
$string['settings:history_candidates'] = 'Παράθυρο υποψηφίων ιστορικού';
$string['settings:history_candidates_desc'] = 'Στη σημασιολογική λειτουργία ιστορικού, μόνο τόσα από τα πιο πρόσφατα ζεύγη βαθμολογούνται ως προς τη συνάφεια (ένα όριο κόστους). Ζεύγη παλαιότερα από αυτό το παράθυρο δεν αποστέλλονται. Διατηρήστε αυτή την τιμή ίση ή μεγαλύτερη από το "Max Conversation History".';

// v5.11.0 - v6.2.0 strings (added 2026-06-10).
$string['settings:embed_provider_voyage'] = 'Voyage AI (voyage-3.5 — συνιστάται; +4 MTEB έναντι OpenAI 3-small, 4× πλαίσιο, πολύγλωσσο)';
$string['settings:rerank_heading'] = 'RAG: Ανάκτηση δύο σταδίων (επαναξιολόγηση)';
$string['settings:rerank_heading_desc'] = 'Προαιρετικό δεύτερο στάδιο ανάκτησης: η ομοιότητα συνημιτόνου επιλέγει τους top-N υποψήφιους τμήματα (προεπιλογή 50), έπειτα ένας cross-encoder reranker βαθμολογεί κάθε ζεύγος (ερώτημα, τμήμα) και τα καλύτερα top-K εισάγονται στο prompt. Απενεργοποιημένο από προεπιλογή· επιστρέφει σε μονο-στάδιο κόσινο αν ο reranker δεν έχει ρυθμιστεί ή αποτύχει.';
$string['settings:rerank_enabled'] = 'Ανάκτηση δύο σταδίων (Voyage rerank-2.5)';
$string['settings:rerank_enabled_desc'] = 'Όταν είναι ενεργό, η ανάκτηση RAG γίνεται δύο σταδίων: η ομοιότητα συνημιτόνου επιστρέφει τους top-N υποψηφίους (προεπιλογή 50), έπειτα ο cross-encoder Voyage rerank-2.5 βαθμολογεί τον καθένα και τα top-K εισάγονται στο prompt. Δημοσιευμένες βελτιώσεις: +15 Recall@10 enterprise, +39% NDCG BEIR. Χρέωση ~$0,05/MTok. Απαιτεί <code>rerank_apikey</code> παρακάτω· επιστρέφει ομαλά στο μονο-στάδιο κόσινο αν το reranking αποτύχει ή δεν έχει ρυθμιστεί.';
$string['settings:rerank_apikey'] = 'Κλειδί API επαναξιολόγησης';
$string['settings:rerank_apikey_desc'] = 'Κλειδί Voyage AI API για rerank-2.5. Αφήστε κενό για επαναχρησιμοποίηση του Embedding API Key παραπάνω (οι τυπικές εγκαταστάσεις Voyage μοιράζονται ένα κλειδί για embed + rerank).';
$string['settings:rerank_model'] = 'Μοντέλο επαναξιολόγησης';
$string['settings:rerank_model_desc'] = 'Προεπιλογή <code>rerank-2.5</code>. Εδώ μπορούν να οριστούν νεότερα μοντέλα Voyage rerank.';
$string['settings:rerank_apibaseurl'] = 'Βασικό URL API επαναξιολόγησης';
$string['settings:rerank_apibaseurl_desc'] = 'Παράκαμψη βασικής διεύθυνσης URL Voyage rerank. Αφήστε κενό για χρήση του βασικού URL Embedding API παραπάνω ή της προεπιλογής Voyage (<code>https://api.voyageai.com/v1</code>).';
$string['settings:rerank_candidates'] = 'Παράθυρο υποψηφίων επαναξιολόγησης';
$string['settings:rerank_candidates_desc'] = 'Πόσοι υποψήφιοι top-N κόσινου τροφοδοτούν το στάδιο επαναξιολόγησης. Προεπιλογή 50. Μεγαλύτερα παράθυρα δίνουν στον reranker περισσότερο υλικό με μικρό επιπλέον κόστος (~10.000 tokens ανά επαναξιολόγηση).';
$string['settings:stt_selfhosted_heading'] = 'Αυτο-φιλοξενούμενη απομαγνητοφώνηση (Whisper)';
$string['settings:stt_selfhosted_heading_desc'] = 'Εκτελέστε μετατροπή ομιλίας σε κείμενο στο δικό σας υλικό χωρίς χρέωση ανά λεπτό. Κατευθύνετε το [[tutorshort]] σε οποιονδήποτε διακομιστή απομαγνητοφώνησης συμβατό με OpenAI: Docker <code>whisper-server</code>, <code>speaches</code> (faster-whisper) ή διακομιστή <code>whisper.cpp</code>. Όταν οριστεί εδώ μια διεύθυνση URL διακομιστή, αυτή γίνεται η προεπιλεγμένη διαδρομή STT· επιλέξτε έναν επί πληρωμή πάροχο στον Ενεργό πάροχο STT παραπάνω για παράκαμψη. Αν ο διακομιστής βρίσκεται σε ιδιωτικό δίκτυο ή σε απλό http, προσθέστε τον κόμβο του και στη λίστα αξιόπιστων τελικών σημείων SSRF στην ενότητα Ασφάλεια.';
$string['settings:stt_selfhosted_url'] = 'URL διακομιστή STT αυτο-φιλοξένησης';
$string['settings:stt_selfhosted_url_desc'] = 'Βασική διεύθυνση URL του διακομιστή απομαγνητοφώνησης συμβατού με OpenAI, π.χ. <code>http://10.0.0.5:8000</code>. Το [[tutorshort]] προσαρτά αυτόματα <code>/v1/audio/transcriptions</code>· γίνεται δεκτό και πλήρες path τελικού σημείου. Αφήστε κενό για απενεργοποίηση αυτο-φιλοξενούμενου STT.';
$string['settings:stt_selfhosted_model'] = 'Μοντέλο STT αυτο-φιλοξένησης';
$string['settings:stt_selfhosted_model_desc'] = 'Όνομα μοντέλου που αποστέλλεται στον διακομιστή, αντίστοιχο με το φορτωμένο μοντέλο Whisper — π.χ. <code>Systran/faster-whisper-small</code> για speaches ή <code>large-v3</code>. Αφήστε κενό για αποστολή <code>whisper-1</code>, που οι περισσότεροι αυτο-φιλοξενούμενοι διακομιστές αποδέχονται ή αγνοούν.';
$string['settings:stt_selfhosted_apikey'] = 'Κλειδί API STT αυτο-φιλοξένησης';
$string['settings:stt_selfhosted_apikey_desc'] = 'Προαιρετικό. Οι περισσότεροι αυτο-φιλοξενούμενοι διακομιστές λειτουργούν χωρίς κλειδί πίσω από αξιόπιστο δίκτυο· ορίστε το μόνο αν ο διακομιστής σας απαιτεί Bearer token.';
$string['emergency:title'] = 'Έκτακτοι έλεγχοι [[tutorshort]]';
$string['emergency:page_warning'] = 'Αυτοί οι διακόπτες τίθενται σε ισχύ αμέσως για όλους τους εκπαιδευόμενους στον ιστότοπο. Κάθε ενέργεια γράφει μια γραμμή ελέγχου. Οι λεπτομερείς διακόπτες αφήνουν το υπόλοιπο [[tutorshort]] να λειτουργεί· ο κύριος διακόπτης απενεργοποίησης αφαιρεί πλήρως το widget από κάθε σελίδα.';
$string['emergency:back_to_settings'] = 'Ρυθμίσεις [[tutorshort]]';
$string['emergency:state_disabled'] = 'ΑΠΕΝΕΡΓΟΠΟΙΗΜΕΝΟ';
$string['emergency:state_active'] = 'Ενεργό';
$string['emergency:confirm_label'] = 'Κατανοώ ότι αυτό επηρεάζει αμέσως όλους τους εκπαιδευόμενους';
$string['emergency:confirm_required'] = 'Επιλέξτε το πλαίσιο επιβεβαίωσης πριν απενεργοποιήσετε ένα υποσύστημα.';
$string['emergency:reason_placeholder'] = 'Αιτία (καταγράφεται στο ημερολόγιο ελέγχου)';
$string['emergency:disable_button'] = 'Απενεργοποίηση';
$string['emergency:restore_button'] = 'Επαναφορά';
$string['emergency:disabled_notice'] = 'Το υποσύστημα "{$a->flag}" απενεργοποιήθηκε. Διαμόρφωση που άλλαξε: {$a->touched}';
$string['emergency:restored_notice'] = 'Το υποσύστημα "{$a->flag}" επαναφέρθηκε. Διαμόρφωση που άλλαξε: {$a->touched}';
$string['emergency:cli_reference'] = 'Οι ίδιοι έλεγχοι είναι διαθέσιμοι από το κέλυφος εφημερίας:';
$string['emergency:flag_chat'] = 'Συνομιλία';
$string['emergency:flag_chat_desc'] = 'Αποκλείει την κίνηση συνομιλίας μέσω ειδικής σημαίας kill (διόρθωση v5.13). Το widget συνεχίζει να εμφανίζεται· οι εκπαιδευόμενοι βλέπουν το φιλικό μήνυμα «Το [[tutorshort]] έχει τεθεί σε παύση». Χρησιμοποιήστε όταν ένας πάροχος LLM συμπεριφέρεται εσφαλμένα ή κατά τη διάρκεια έξαρσης κόστους.';
$string['emergency:flag_voice'] = 'Φωνή';
$string['emergency:flag_voice_desc'] = 'Διαγράφει τον ενεργό πάροχο φωνής πραγματικού χρόνου (αποθηκευμένος για ακριβή επαναφορά). Η κειμενική συνομιλία συνεχίζει να λειτουργεί.';
$string['emergency:flag_rag'] = 'RAG';
$string['emergency:flag_rag_desc'] = 'Απενεργοποιεί την ανάκτηση και την ευρετηρίαση. Η συνομιλία συνεχίζεται χωρίς αγκύρωση στο περιεχόμενο του μαθήματος.';
$string['emergency:flag_outreach'] = 'Outreach';
$string['emergency:flag_outreach_desc'] = 'Διακόπτει τα email περίληψης, ορόσημων και υπενθυμίσεων. Η συνομιλία δεν επηρεάζεται.';
$string['emergency:flag_all'] = 'ΚΥΡΙΟΣ ΔΙΑΚΟΠΤΗΣ';
$string['emergency:flag_all_desc'] = 'Απενεργοποιεί ολόκληρο το πρόσθετο: το widget εξαφανίζεται από κάθε σελίδα, οι προγραμματισμένες εργασίες σταματούν, η φωνή διαγράφεται, το RAG είναι απενεργοποιημένο, το outreach είναι απενεργοποιημένο. Ο πιο ισχυρός διακόπτης — χρησιμοποιείστε σε περίπτωση συμβάντος ασφαλείας ή όταν το [[tutorshort]] πρέπει να τεθεί εκτός σύνδεσης άμεσα.';
$string['emergency:settings_link'] = 'Έκτακτοι έλεγχοι';
$string['emergency:settings_link_desc'] = 'Διακόπτες kill ανά υποσύστημα (συνομιλία / φωνή / RAG / outreach / κύριος) με καταγραφή ελέγχου — ισοδύναμο ιστού του <code>admin/cli/emergency_disable.php</code>. Ανοίξτε τους <a href="{$a}">Έκτακτους ελέγχους [[tutorshort]]</a>.';
$string['email_unsubscribe:done_title'] = 'Κατάργηση εγγραφής';
$string['email_unsubscribe:done_body'] = 'Ολοκληρώθηκε — το {$a->email} δεν θα λαμβάνει πλέον αυτόν τον τύπο email από {$a->product}. Αν αλλάξετε γνώμη, ζητήστε από έναν διαχειριστή {$a->product} να επανενεργοποιήσει τη συνδρομή ή στείλτε νέα αποδοχή μέσω της σελίδας διαχείρισης παραληπτών [[tutorshort]].';
$string['email_unsubscribe:invalid_title'] = 'Ο σύνδεσμος κατάργησης εγγραφής δεν είναι πλέον έγκυρος';
$string['email_unsubscribe:invalid_body'] = 'Αυτός ο σύνδεσμος κατάργησης εγγραφής έχει λήξει ή είναι εσφαλμένος. Αναζητήστε πιο πρόσφατο email από εμάς ή επικοινωνήστε με έναν διαχειριστή ιστότοπου για χειροκίνητη αφαίρεση.';
$string['settings:prompt_proportions_heading'] = 'Αναλογίες τμημάτων prompt (v5.6.0)';
$string['settings:prompt_proportions_heading_desc'] = 'Κατανείμετε τον προϋπολογισμό του system prompt σε τέσσερα τμήματα: ασφάλεια + ταυτότητα, δομή μαθήματος, περιεχόμενο μαθήματος και τρέχουσα σελίδα. Τα βάρη είναι ποσοστά με άθροισμα 100. Οι εμπειρικά ρυθμισμένες προεπιλογές (10 / 10 / 40 / 40) προέρχονται από το benchmark ρύθμισης βαρών v5.6.0· το κενό textarea χρησιμοποιεί αυτές τις προεπιλογές. Η αυτόματη ενίσχυση προσαρμόζει την κατανομή ανά γύρο ανάλογα με το αν μια συγκεκριμένη σελίδα βρίσκεται στο πεδίο εφαρμογής.';
$string['settings:prompt_section_weights'] = 'Βασικά βάρη τμημάτων (JSON)';
$string['settings:prompt_section_weights_desc'] = 'Προαιρετικό αντικείμενο JSON που αντιστοιχεί κάθε τμήμα σε ποσοστό. Αφήστε κενό για χρήση των προεπιλεγμένων τιμών benchmark (10 / 10 / 40 / 40). Παράδειγμα: <code>{"safety_identity": 10, "course_structure": 10, "course_content": 40, "current_page": 40}</code>. Τα βάρη πρέπει να αθροίζονται σε 100 (±5%). Το <code>safety_identity</code> έχει κατώτατο όριο 10% ώστε η αντίσταση jailbreak και οι δείκτες μορφής εξόδου να περιλαμβάνονται πάντα πλήρως. Το <code>current_page + course_content</code> πρέπει να είναι τουλάχιστον 40% ώστε το μοντέλο να έχει ουσιαστικό υλικό για αγκύρωση. Τιμές εκτός εύρους επιστρέφουν αθόρυβα στις προεπιλογές benchmark· οι διαχειριστές πρέπει να επαληθεύουν ελέγχοντας το αρχείο καταγραφής αποσφαλμάτωσης prompt μετά την αποθήκευση.';
$string['settings:prompt_context_boost_mode'] = 'Λειτουργία ενίσχυσης πλαισίου';
$string['settings:prompt_context_boost_mode_desc'] = 'Αυτόματη προσαρμογή που μεταφέρει βάρος προς το τμήμα τρέχουσας σελίδας όταν μια συγκεκριμένη σελίδα βρίσκεται στο πεδίο εφαρμογής, και προς το περιεχόμενο μαθήματος όταν δεν έχει επιλεγεί σελίδα. <strong>page_focus</strong> (προεπιλογή) μεταφέρει περίπου 15 μονάδες βάρους. <strong>aggressive</strong> μεταφέρει 25 μονάδες και είναι καλύτερο όταν οι εκπαιδευόμενοι θέτουν σταθερά ερωτήματα ειδικά για σελίδα. <strong>off</strong> απενεργοποιεί την ενίσχυση· τα βασικά βάρη που ορίζει ο διαχειριστής ισχύουν σε κάθε γύρο.';
$string['settings:prompt_context_boost_off'] = 'Απενεργοποιημένο (χρήση βασικών βαρών σε κάθε γύρο)';
$string['settings:prompt_context_boost_page_focus'] = 'Εστίαση σελίδας (προεπιλογή, ~15 μονάδες μετατόπιση)';
$string['settings:prompt_context_boost_aggressive'] = 'Επιθετικό (~25 μονάδες μετατόπιση)';
$string['settings:prompt_section_weights_coach'] = 'Παράκαμψη λειτουργίας coach (JSON, προαιρετικό)';
$string['settings:prompt_section_weights_coach_desc'] = 'Προαιρετικό αντικείμενο JSON που παρακάμπτει τα βασικά βάρη τμημάτων ειδικά κατά τη λειτουργία coach βαθμολογημένου κουίζ (όταν <code>quizmode=coach</code>). Χρήσιμο για επιβολή μεγαλύτερης κατανομής <code>current_page</code> κατά τα κουίζ χωρίς να επηρεάζεται η κανονική συνομιλία. Αφήστε κενό για κληρονόμηση βασικών βαρών. Ίδιοι κανόνες επικύρωσης με τη βασική ρύθμιση.';
$string['prompt_debug_view:title'] = 'Προβολέας αρχείου καταγραφής αποσφαλμάτωσης prompt';
$string['prompt_debug_view:subtitle'] = 'Το συγκεντρωτικό system prompt ανά γύρο + ανάλυση ανά τμήμα + ιστορικό συνομιλίας + τρέχον μήνυμα χρήστη + μεταδεδομένα συνημμένου, ακριβώς όπως τα έλαβε το μοντέλο. Χρησιμοποιήστε το για να επαληθεύσετε αν ένα τμήμα όπως το Περιεχόμενο τρέχουσας σελίδας συμπεριλήφθηκε στο prompt και για την αποσφαλμάτωση ζητημάτων ποιότητας απαντήσεων χωρίς SSH στον διακομιστή.';
$string['prompt_debug_view:disabled'] = 'Η καταγραφή αποσφαλμάτωσης prompt είναι αυτή τη στιγμή ΑΠΕΝΕΡΓΟΠΟΙΗΜΕΝΗ. Δεν θα γράφονται νέες εγγραφές μέχρι να ενεργοποιηθεί.';
$string['prompt_debug_view:enable_link'] = 'Ανοίξτε τις ρυθμίσεις του πρόσθετου για να ενεργοποιήσετε το «Καταγραφή συγκεντρωτικού system prompt σε αρχείο».';
$string['prompt_debug_view:no_log_yet'] = 'Δεν υπάρχει ακόμη αρχείο καταγραφής. Στείλτε τουλάχιστον έναν γύρο συνομιλίας μετά την ενεργοποίηση της καταγραφής αποσφαλμάτωσης· το αρχείο δημιουργείται κατά την πρώτη εγγραφή.';
$string['prompt_debug_view:empty'] = 'Το αρχείο καταγραφής υπάρχει αλλά είναι κενό. Στείλτε έναν γύρο συνομιλίας και ανανεώστε.';
$string['prompt_debug_view:file_status'] = 'Μέγεθος αρχείου καταγραφής';
$string['prompt_debug_view:showing'] = 'Εμφάνιση πιο πρόσφατων εγγραφών (νεότερο πρώτο), όριο';
$string['prompt_debug_view:total'] = 'Συνολικό prompt';
$string['prompt_debug_view:budget'] = 'Προϋπολογισμός κατά τη λήψη';
$string['prompt_debug_view:sections'] = 'Τμήματα (ανά κατηγορία)';
$string['prompt_debug_view:assembled_prompt'] = 'Συγκεντρωτικό system prompt';
$string['prompt_debug_view:history'] = 'Ιστορικό συνομιλίας που εστάλη στο μοντέλο';
$string['prompt_debug_view:current_message'] = 'Τρέχον μήνυμα χρήστη';
$string['prompt_debug_view:attachment'] = 'Μεταδεδομένα συνημμένου';
$string['prompt_debug_view:show_more'] = 'Εμφάνιση περισσότερων εγγραφών';
$string['settings:mastery_classifier_provider'] = 'Πάροχος κατηγοριοποιητή';
$string['settings:mastery_classifier_provider_desc'] = 'Αναγνωριστικό παρόχου που χρησιμοποιείται για τον κατηγοριοποιητή κατάκτησης ανά γύρο. Αφήστε κενό για κληρονόμηση του προεπιλεγμένου παρόχου τεχνητής νοημοσύνης. Η προεπιλογή <code>openai</code> συνδυάζεται με το μοντέλο κατηγοριοποιητή <code>gpt-4o-mini</code> παρακάτω — η φθηνότερη επιλογή TIER 1 για κατηγοριοποίηση δομημένης εξόδου (~$220/μήνα εξοικονόμηση στους 100.000 MAU έναντι του chat tier). Όταν οριστεί, η γραμμή στους Παρόχους σύγκρισης με αυτό το αναγνωριστικό παρέχει το κλειδί API, τη βασική URL και τη θερμοκρασία.';
$string['settings:premium_escalation_heading'] = 'Επίπεδο premium κλιμάκωσης (A.10)';
$string['settings:premium_escalation_heading_desc'] = 'Προαιρετική δρομολόγηση ανά γύρο σε premium μοντέλο (Claude Opus 4.8 από προεπιλογή) για prompts όπου το βασικό chat tier δυσκολεύεται εμφανώς — συνήθως πολυβηματικά μαθηματικά, CS και επιστημονική συλλογιστική. Καθορίστηκε από το bake-off A.10 της 2026-06-09: το Opus 4.8 νίκησε με 14,97/15 έναντι 12,68/15 του gpt-4o σε δύσκολα prompts. Δύο μονοπάτια ενεργοποίησης: ταιριάσματα regex στο μήνυμα χρήστη Ή λίστα επιτρεπόμενων μαθημάτων που κλιμακώνει κάθε γύρο. Απενεργοποιημένο από προεπιλογή. Με ~5% κλιμάκωση, αναμένετε ~$700/μήνα στους 100.000 [[unishort]] MAU επιπλέον της βασικής δαπάνης συνομιλίας.';
$string['settings:premium_escalation_enabled'] = 'Ενεργοποίηση δρομολόγησης premium κλιμάκωσης';
$string['settings:premium_escalation_enabled_desc'] = 'Όταν είναι ενεργό, ο δρομολογητής ανά γύρο ελέγχει τη λίστα regex ενεργοποίησης και τη λίστα επιτρεπόμενων μαθημάτων για κάθε κλήση συνομιλίας· οι αντίστοιχοι γύροι δρομολογούνται στον premium πάροχο. Επιστρέφει στον βασικό πάροχο αν η γραμμή premium λείπει ή δεν μπορεί να παρουσιαστεί. Οι παρακάμψεις Admin-LLM-picker κερδίζουν πάντα.';
$string['settings:premium_escalation_provider'] = 'Premium πάροχος';
$string['settings:premium_escalation_provider_desc'] = 'Αναγνωριστικό παρόχου για δρομολόγηση premium κλήσεων. Πρέπει να αντιστοιχεί σε γραμμή στους Παρόχους σύγκρισης (ώστε το κλειδί API, η βασική URL και η θερμοκρασία να προέρχονται από τον ίδιο τόπο που ήδη διαχειρίζονται οι διαχειριστές). Προεπιλογή <code>claude</code>.';
$string['settings:premium_escalation_model'] = 'Premium μοντέλο';
$string['settings:premium_escalation_model_desc'] = 'Όνομα μοντέλου που διαβιβάζεται στον premium πάροχο. Προεπιλογή <code>claude-opus-4-8</code> σύμφωνα με την απόφαση του bake-off A.10.';
$string['settings:premium_escalation_triggers'] = 'Regex ενεργοποίησης premium';
$string['settings:premium_escalation_triggers_desc'] = 'Ένα PCRE regex ανά γραμμή (χωρίς οριοθέτες· εφαρμόζεται αυτόματα αντιστοίχιση χωρίς διάκριση πεζών-κεφαλαίων). Γραμμές που αρχίζουν με # είναι σχόλια. Αφήστε κενό για χρήση του επιμελημένου προεπιλεγμένου συνόλου από το bake-off A.10 (δείκτες πολυβηματικής STEM: «derive», «prove that», «step by step», μαθηματικά LaTeX, περιφραγμένα μπλοκ κώδικα, big-O, ολοκληρώματα, βελτιστοποίηση κ.λπ.).';
$string['settings:premium_escalation_course_tags'] = 'Λίστα επιτρεπόμενων μαθημάτων premium';
$string['settings:premium_escalation_course_tags_desc'] = 'Ένα σύντομο όνομα μαθήματος ή πρόθεμα idnumber ανά γραμμή. Κάθε γύρος σε αντίστοιχο μάθημα κλιμακώνεται αυτόματα ανεξαρτήτως regex μηνύματος (χρησιμοποιείστε για μαθήματα με βαρύ STEM περιεχόμενο όπου η κλιμάκωση πρέπει να είναι η προεπιλογή). Η αντιστοίχιση είναι πρόθεμα χωρίς διάκριση πεζών-κεφαλαίων — το «MATH» ταιριάζει με MATH121, MATH205 κ.λπ.';
$string['settings:spend_cap_per_course_default'] = 'Προεπιλεγμένο ανώτατο όριο δαπανών ανά μάθημα (USD)';
$string['settings:spend_cap_per_course_default_desc'] = 'Αμυντικό όριο που εφαρμόζεται σε κάθε μάθημα που δεν έχει ρυθμισμένο δικό του ανώτατο όριο δαπανών ανά μάθημα. Ορίστε π.χ. <code>30</code> για να περιορίσετε τις μηνιαίες δαπάνες οποιουδήποτε μαθήματος στα $30 χωρίς να ρυθμίσετε μεμονωμένα μαθήματα. <code>0</code> = χωρίς προεπιλεγμένο όριο (ισχύουν μόνο τα όρια σε επίπεδο ιστότοπου και παρακάμψεις ανά μάθημα). Όταν ένα μάθημα υπερβεί το 80% / 95% / 100% αυτού του ορίου, ο υπάρχων αγωγός ειδοποιήσεων spend-guard στέλνει ειδοποίηση διαχειριστή (λίστα παραληπτών: <code>spend_notify_emails</code>, επιστρέφει στους διαχειριστές ιστότοπου). Ένα συγκεκριμένο μάθημα μπορεί πάντα να αυξήσει το δικό του ανώτατο όριο ορίζοντας μεγαλύτερη παράκαμψη ανά μάθημα.';
$string['settings:cost_anomaly_heading'] = 'Ανιχνευτής ανωμαλίας κόστους (v6.0)';
$string['settings:cost_anomaly_heading_desc'] = 'Καθημερινή προγραμματισμένη εργασία (<code>cost_anomaly_check</code>) που συγκρίνει τις σημερινές δαπάνες [[tutorshort]] σε επίπεδο ιστότοπου με τη μεσοκύλιστη τιμή 7 ημερών. Αποστέλλει email στη λίστα παραληπτών <code>spend_notify_emails</code> (επιστρέφει στους διαχειριστές ιστότοπου) όταν η σημερινή τιμή υπερβαίνει τον ρυθμισμένο πολλαπλασιαστή × τη μεσοκύλιστη τιμή. Ανιχνεύει τρεις τρόπους αποτυχίας που τα υπάρχοντα κατώφλια 80% / 95% / 100% αγνοούν: (1) εκτός ελέγχου μάθημα όπου το απόλυτο ανώτατο όριο δεν παραβιάζεται αλλά ένα μάθημα παράγει ξαφνικά 10× τον συνηθισμένο όγκο του, (2) τυχαία ενεργοποίηση του premium tier, (3) εσφαλμένη δρομολόγηση παρόχου. Απενεργοποιημένο από προεπιλογή· το [[tutorshort]]-εσωτερικό ισοδύναμο του ερωτήματος Redash στο <code>.drafts/sola-redash-cost-anomaly-2026-06-09.md</code>.';
$string['settings:cost_anomaly_enabled'] = 'Ενεργοποίηση ανιχνευτή ανωμαλίας κόστους';
$string['settings:cost_anomaly_enabled_desc'] = 'Όταν είναι ενεργό, η καθημερινή προγραμματισμένη εργασία αξιολογεί τις σημερινές δαπάνες έναντι της μεσοκύλιστης τιμής 7 ημερών και αποστέλλει email στους διαχειριστές σε περίπτωση ανωμαλίας. Οι πρώτες 7 ημέρες μετά την ενεργοποίηση παράγουν κατάσταση <code>insufficient_history</code> (δεν υπάρχει ακόμη ιστορική γραμμή βάσης) και δεν αποστέλλουν ποτέ ειδοποίηση. Idempotent ανά ημέρα: μια σημαία στο <code>config_plugins</code> αποτρέπει επαναλαμβανόμενα emails αν το cron εκτελείται πολλές φορές.';
$string['settings:cost_anomaly_multiplier'] = 'Πολλαπλασιαστής ανωμαλίας';
$string['settings:cost_anomaly_multiplier_desc'] = 'Οι σημερινές δαπάνες πρέπει να υπερβαίνουν αυτόν τον πολλαπλασιαστή × τη μεσοκύλιστη τιμή 7 ημερών για να ενεργοποιηθεί ειδοποίηση. Προεπιλογή <code>2.0</code>. Μειώστε σε <code>1.5</code> για προγενέστερες προειδοποιήσεις (περισσότερα ψευδώς θετικά κατά τις εκρήξεις εγγραφών). Αυξήστε σε <code>3.0</code> αν η χρήση του [[unishort]] είναι αρκετά ευμετάβλητη ώστε οι εκτινάξεις 2× να είναι συνηθισμένες.';
$string['task:cost_anomaly_check'] = 'Έλεγχος ανωμαλίας κόστους [[tutorshort]] (καθημερινός)';

// v6.4.0 signed policy bundle strings (added 2026-06-11).
$string['settings:policy_bundle_heading'] = 'Υπογεγραμμένο πακέτο πολιτικής (απομακρυσμένες ενημερώσεις συμπεριφοράς)';
$string['settings:policy_bundle_heading_desc'] = 'Εφαρμόστε ρυθμίσεις συμπεριφοράς (προτροπές, δρομολόγηση, ενεργοποιητές κλιμάκωσης, ρύθμιση RAG, πολιτική δαπανών) από ένα κρυπτογραφικά υπογεγραμμένο αρχείο JSON χωρίς ανάπτυξη κώδικα. Μια καθημερινή προγραμματισμένη εργασία ανακτά το URL του πακέτου, επαληθεύει την υπογραφή Ed25519 με βάση το παρακάτω δημόσιο κλειδί και εφαρμόζει τις ρυθμίσεις μόνο εάν κάθε κλειδί βρίσκεται στη λίστα επιτρεπόμενων και η έκδοση του πακέτου είναι νεότερη από την τελευταία εφαρμοσμένη. Τα κλειδιά API, τα URL, τα webhooks και οι ρυθμίσεις ασφαλείας δεν μπορούν ποτέ να οριστούν από πακέτο. Δημιουργήστε και υπογράψτε πακέτα με <code>admin/cli/policy_bundle_tool.php</code> (keygen, sign, verify, status, sync).';
$string['settings:policy_bundle_enabled'] = 'Ενεργοποίηση συγχρονισμού πακέτου πολιτικής';
$string['settings:policy_bundle_enabled_desc'] = 'Όταν είναι ενεργοποιημένο, η καθημερινή εργασία ανακτά και εφαρμόζει υπογεγραμμένα πακέτα. Απενεργοποιημένο από προεπιλογή. Η απενεργοποίηση σταματά άμεσα όλους τους συγχρονισμούς· οι ήδη εφαρμοσμένες ρυθμίσεις διατηρούν τις τιμές τους.';
$string['settings:policy_bundle_url'] = 'URL πακέτου πολιτικής';
$string['settings:policy_bundle_url_desc'] = 'URL HTTPS του υπογεγραμμένου JSON πακέτου (για παράδειγμα ένα αντικείμενο S3 ή GitHub raw URL). Το URL υπόκειται στην ίδια επαλήθευση SSRF με τα τελικά σημεία παρόχου AI· οι κεντρικοί υπολογιστές σε ιδιωτικό δίκτυο ή plain-http χρειάζονται εγγραφή στη λίστα αξιόπιστων τελικών σημείων SSRF.';
$string['settings:policy_bundle_pubkey'] = 'Δημόσιο κλειδί πακέτου πολιτικής';
$string['settings:policy_bundle_pubkey_desc'] = 'Δημόσιο κλειδί Base64 Ed25519 που χρησιμοποιείται για την επαλήθευση υπογραφών πακέτου. Δημιουργήστε το ζεύγος κλειδιών με <code>policy_bundle_tool.php --keygen</code>· το ιδιωτικό κλειδί παραμένει στον συντάκτη του πακέτου και δεν πρέπει ποτέ να αναρτηθεί πουθενά.';
$string['settings:policy_bundle_status'] = 'Τελευταίος συγχρονισμός';
$string['settings:policy_bundle_applied_version'] = 'εφαρμοσμένη έκδοση';
$string['task:policy_bundle_sync'] = '[[tutorshort]] συγχρονισμός υπογεγραμμένου πακέτου πολιτικής';
$string['policy_bundle:invalid'] = 'Το πακέτο πολιτικής απορρίφθηκε: {$a}';
$string['prompt_debug_view:retrieved_chunks'] = 'Ανακτηθέντα τμήματα (επιλογή RAG)';
$string['prompt_debug_view:retrieved_chunks_hint'] = 'Τα αποσπάσματα που επέλεξε ο μηχανισμός ανάκτησης για αυτήν την ερώτηση, ταξινομημένα κατά σειρά κατάταξης με τη βαθμολογία συνάφειάς τους και την πηγή (cmid). Χρησιμοποιήστε το για να επαληθεύσετε ότι το μοντέλο έλαβε το περιεχόμενο του μαθήματος που ταιριάζει καλύτερα.';
$string['settings:avatar_animation_enabled'] = 'Κινούμενη εικόνα avatar';
$string['settings:avatar_animation_enabled_desc'] = 'Ενεργοποιεί κινούμενη εικόνα για το SVG avatar που δημιουργήθηκε: ανάβλεψη σε αδρανή κατάσταση, καθώς και κίνηση στόματος συγχρονισμένη με τον ήχο κειμένου σε ομιλία όταν μιλά ο βοηθός. Σέβεται την προτίμηση μειωμένης κίνησης της συσκευής του εκπαιδευόμενου. Παράκαμψη ανά μάθημα για μέτρηση A/B: ορίστε την τιμή ρύθμισης avatar_animation_course_COURSEID σε 0 ή 1.';
$string['analytics:exp_heading'] = 'Σύγκριση πειράματος A/B';
$string['analytics:exp_desc'] = 'Συγκρίνετε τη συμμετοχή μεταξύ δύο μαθημάτων για το επιλεγμένο χρονικό εύρος. Σχεδιασμένο για πειράματα ανά μάθημα (για παράδειγμα η έρευνα κινούμενης εικόνας avatar): τοποθετήστε την παράκαμψη σε ένα μάθημα, αφήστε το άλλο ως έλεγχο και διαβάστε τη διαφορά εδώ.';
$string['analytics:exp_course_a'] = 'Μάθημα A';
$string['analytics:exp_course_b'] = 'Μάθημα B';
$string['analytics:exp_compare'] = 'Σύγκριση';
$string['analytics:exp_metric'] = 'Μετρική';
$string['analytics:exp_delta'] = 'B vs A';
$string['analytics:exp_enrolled'] = 'Εγγεγραμμένοι εκπαιδευόμενοι';
$string['analytics:exp_active_users'] = 'Ενεργοί χρήστες [[tutorshort]]';
$string['analytics:exp_usage_rate'] = 'Ποσοστό χρήσης (%)';
$string['analytics:exp_sessions'] = 'Συνεδρίες';
$string['analytics:exp_messages'] = 'Μηνύματα';
$string['analytics:exp_avg_msgs_session'] = 'Μέσος αριθμός μηνυμάτων ανά συνεδρία';
$string['analytics:exp_avg_session_minutes'] = 'Μέση διάρκεια συνεδρίας (λεπτά)';
$string['analytics:exp_return_rate'] = 'Επιστρέφοντες χρήστες (%)';
$string['analytics:exp_tts_plays'] = 'Αναπαραγωγές TTS';
$string['analytics:exp_tts_per_active'] = 'Αναπαραγωγές TTS ανά ενεργό χρήστη';

$string['settings:redash_allowed_origin'] = 'Επιτρεπόμενη προέλευση για το Redash (CORS)';
$string['settings:redash_allowed_origin_desc'] = 'Αφήστε το κενό (συνιστάται): η εξαγωγή ανακτάται από διακομιστή σε διακομιστή από το Redash και δεν χρειάζεται κεφαλίδα CORS στο πρόγραμμα περιήγησης. Ορίστε μία ακριβή προέλευση (για παράδειγμα https://redash.example.org) μόνο εάν ένας πίνακας ελέγχου που βασίζεται σε πρόγραμμα περιήγησης πρέπει να διαβάσει την εξαγωγή απευθείας. Μην χρησιμοποιείτε ποτέ χαρακτήρα μπαλαντέρ.';

// Soapbox speech practice (v6.7.0).
$string['privacy:metadata:local_ai_course_assistant_practice_scores:session_meta'] = 'Προαιρετικά μεταδεδομένα που παρείχατε για τη συνεδρία, όπως το όνομα, το θέμα και το στοχευόμενο μήκος μιας ομιλίας Soapbox. Δεν περιλαμβάνει ποτέ ήχο ή απομαγνητοφώνηση.';
$string['pedagogy:soapbox'] = 'Η ανατροφοδότηση ομιλίας Soapbox ενεργή από προεπιλογή';
$string['pedagogy:soapbox_desc'] = 'Όταν είναι ενεργό, το εργαλείο εξάσκησης ομιλίας Soapbox είναι διαθέσιμο σε κάθε μάθημα, εκτός αν το μάθημα έχει τη δική του παράκαμψη. Αφήστε το ανενεργό και ενεργοποιήστε το μόνο στα μαθήματα που το χρειάζονται (συνήθως μαθήματα ρητορικής και επικοινωνίας).';
$string['settings:soapbox_stt_mode'] = 'Λειτουργία απομαγνητοφώνησης Soapbox';
$string['settings:soapbox_stt_mode_desc'] = 'Πώς το Soapbox μετατρέπει μια ηχογραφημένη ομιλία σε κείμενο. Η επιλογή Διακομιστής χρησιμοποιεί τον διαμορφωμένο πάροχο Whisper (η αυτοφιλοξενία είναι δωρεάν· η φιλοξενούμενη OpenAI κοστίζει περίπου USD 0,006 ανά λεπτό). Η επιλογή Πρόγραμμα περιήγησης χρησιμοποιεί την ενσωματωμένη αναγνώριση ομιλίας του εκπαιδευόμενου (δωρεάν, χωρίς διακομιστή, λειτουργεί μόνο σε Chrome και Safari). Συνιστάται ο Διακομιστής ώστε η ποιότητα της απομαγνητοφώνησης να μην εξαρτάται από το πρόγραμμα περιήγησης του εκπαιδευόμενου.';
$string['settings:soapbox_stt_mode_server'] = 'Διακομιστής (πάροχος Whisper)';
$string['settings:soapbox_stt_mode_browser'] = 'Πρόγραμμα περιήγησης (δωρεάν, χωρίς διακομιστή)';
$string['soapbox:title'] = 'Soapbox';
$string['soapbox:link'] = 'Εξάσκηση ομιλίας Soapbox';
$string['soapbox:disabled'] = 'Το Soapbox δεν είναι ενεργοποιημένο για αυτό το μάθημα.';
$string['soapbox:intro'] = 'Εκφωνήστε μια ομιλία και λάβετε καθοδήγηση. Προαιρετικά ορίστε όνομα, θέμα και στοχευόμενο μήκος και έπειτα ηχογραφήστε τον εαυτό σας να μιλά. Το Soapbox απομαγνητοφωνεί την ομιλία σας, τη βαθμολογεί με βάση μια ρουμπρίκα ομιλίας και σας δίνει συγκεκριμένες συμβουλές. Ο ήχος σας και η απομαγνητοφώνηση δεν αποθηκεύονται ποτέ, μόνο οι βαθμολογίες και η ανατροφοδότησή σας.';
$string['soapbox:optional'] = 'προαιρετικό';
$string['soapbox:name_label'] = 'Δώστε όνομα σε αυτή την ομιλία';
$string['soapbox:topic_label'] = 'Θέμα';
$string['soapbox:time_label'] = 'Στοχευόμενο μήκος';
$string['soapbox:no_target'] = 'Χωρίς στόχο';
$string['soapbox:record'] = 'Ηχογράφηση ομιλίας';
$string['soapbox:stop'] = 'Διακοπή και λήψη ανατροφοδότησης';
$string['soapbox:recording'] = 'Ηχογράφηση. Μιλήστε φυσικά· κάντε κλικ στη διακοπή όταν τελειώσετε.';
$string['soapbox:transcribing'] = 'Απομαγνητοφώνηση της ομιλίας σας…';
$string['soapbox:scoring'] = 'Βαθμολόγηση της ομιλίας σας…';
$string['soapbox:too_short'] = 'Αυτή η ηχογράφηση ήταν πολύ σύντομη για να βαθμολογηθεί. Στοχεύστε σε τουλάχιστον μία ή δύο προτάσεις και δοκιμάστε ξανά.';
$string['soapbox:mic_denied'] = 'Απαιτείται πρόσβαση στο μικρόφωνο για την ηχογράφηση. Επιτρέψτε την πρόσβαση στο μικρόφωνο και δοκιμάστε ξανά.';
$string['soapbox:no_browser_stt'] = 'Αυτό το πρόγραμμα περιήγησης δεν υποστηρίζει αναγνώριση ομιλίας εντός του προγράμματος περιήγησης. Δοκιμάστε Chrome ή Safari, ή ζητήστε από τον διαχειριστή σας να αλλάξει το Soapbox σε απομαγνητοφώνηση διακομιστή.';
$string['soapbox:browser_note'] = 'Αυτή η ομιλία απομαγνητοφωνείται στο πρόγραμμα περιήγησής σας. Δεν μεταφορτώνεται τίποτα. Λειτουργεί καλύτερα σε Chrome και Safari.';
$string['soapbox:server_note'] = 'Η ηχογράφησή σας μεταφορτώνεται μόνο για απομαγνητοφώνηση και δεν αποθηκεύεται.';
$string['soapbox:error'] = 'Δεν ήταν δυνατή η βαθμολόγηση αυτής της ομιλίας αυτή τη στιγμή. Δοκιμάστε ξανά σε λίγο.';
$string['soapbox:audio_too_large'] = 'Αυτή η ηχογράφηση είναι πολύ μεγάλη. Διατηρήστε τις ομιλίες κάτω από περίπου 25 MB (περίπου 20 λεπτά).';
$string['soapbox:no_stt'] = 'Δεν έχει διαμορφωθεί κανένας πάροχος απομαγνητοφώνησης. Ζητήστε από τον διαχειριστή σας να ρυθμίσει το Whisper ή να ενεργοποιήσει την απομαγνητοφώνηση στο πρόγραμμα περιήγησης.';
$string['soapbox:result_heading'] = 'Βαθμολογίες ρουμπρίκας';
$string['soapbox:overall_heading'] = 'Συνολικά';
$string['soapbox:tips_heading'] = 'Συμβουλές για την επόμενη φορά';
$string['soapbox:col_criterion'] = 'Κριτήριο';
$string['soapbox:col_score'] = 'Βαθμολογία';
$string['soapbox:col_feedback'] = 'Ανατροφοδότηση';
$string['soapbox:history_heading'] = 'Οι ομιλίες μου';
$string['soapbox:history_empty'] = 'Δεν έχετε ηχογραφήσει ακόμη καμία ομιλία. Ηχογραφήστε μία παραπάνω για να αρχίσετε να δημιουργείτε το ιστορικό σας.';
$string['soapbox:untitled'] = 'Ομιλία χωρίς τίτλο';
$string['soapbox:overall_badge'] = 'Συνολικά {$a}';
$string['soapbox:toggle'] = 'Ενεργοποίηση του Soapbox για αυτό το μάθημα';
$string['soapbox:toggle_help'] = 'Οι εκπαιδευόμενοι αποκτούν μια ειδική σελίδα για να ηχογραφήσουν μια ομιλία και να λάβουν ανατροφοδότηση ομιλίας βαθμολογημένη με ρουμπρίκα μαζί με συμβουλές. Ο ήχος και οι απομαγνητοφωνήσεις δεν αποθηκεύονται ποτέ. Ανενεργό από προεπιλογή.';

// Soapbox course-type/level + sample loader (v6.7.0).
$string['soapbox:level_label'] = 'Τύπος μαθήματος / επίπεδο ομιλίας';
$string['soapbox:level_help'] = 'Προσαρμόζει την καθοδήγηση με AI και την προεπιλεγμένη δειγματική ρουμπρίκα στον τύπο του μαθήματος. Τα επίπεδα ESL λαμβάνουν ανατροφοδότηση εκμάθησης γλώσσας· η γενική ομιλία εστιάζει σε δεξιότητες παρουσίασης. Μπορείτε ακόμη να επεξεργαστείτε τη ρουμπρίκα παρακάτω.';
$string['soapbox:level_general'] = 'Γενική ομιλία / παρουσίαση';
$string['soapbox:level_esl_beginner'] = 'ESL (αρχάριοι)';
$string['soapbox:level_esl_advanced'] = 'ESL (προχωρημένοι)';
$string['soapbox:edit_rubric'] = 'Επεξεργασία ρουμπρίκας ομιλίας';
$string['soapbox:sample_label'] = 'Φόρτωση δειγματικής ρουμπρίκας';
$string['soapbox:sample_choose'] = 'Επιλέξτε ένα δείγμα…';
$string['soapbox:sample_hint'] = 'Φορτώνει δειγματικά κριτήρια στον επεξεργαστή παρακάτω. Ελέγξτε και αποθηκεύστε για να τα εφαρμόσετε σε αυτό το εύρος.';
$string['soapbox:level_esl_intermediate'] = 'ESL (μέσοι)';
