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
 * Language strings for local_ai_course_assistant — Vietnamese.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname'] = 'Trợ Lý AI Khóa Học';
$string['attachment:attach'] = 'Đính kèm';
$string['attachment:attach_image_or_pdf'] = 'Đính kèm hình ảnh hoặc PDF';
$string['privacy:metadata:local_ai_course_assistant_convs'] = 'Lưu trữ các cuộc trò chuyện với gia sư AI theo người dùng và khóa học.';
$string['privacy:metadata:local_ai_course_assistant_convs:userid'] = 'ID của người dùng sở hữu cuộc trò chuyện.';
$string['privacy:metadata:local_ai_course_assistant_convs:courseid'] = 'ID của khóa học mà cuộc trò chuyện thuộc về.';
$string['privacy:metadata:local_ai_course_assistant_convs:title'] = 'Tiêu đề của cuộc trò chuyện.';
$string['privacy:metadata:local_ai_course_assistant_convs:timecreated'] = 'Thời gian cuộc trò chuyện được tạo.';
$string['privacy:metadata:local_ai_course_assistant_convs:timemodified'] = 'Thời gian cuộc trò chuyện được chỉnh sửa lần cuối.';
$string['privacy:metadata:local_ai_course_assistant_msgs'] = 'Lưu trữ các tin nhắn trong cuộc trò chuyện với gia sư AI.';
$string['privacy:metadata:local_ai_course_assistant_msgs:userid'] = 'ID của người dùng đã gửi tin nhắn.';
$string['privacy:metadata:local_ai_course_assistant_msgs:courseid'] = 'ID của khóa học mà tin nhắn thuộc về.';
$string['privacy:metadata:local_ai_course_assistant_msgs:role'] = 'Vai trò của người gửi tin nhắn (người dùng hoặc trợ lý).';
$string['privacy:metadata:local_ai_course_assistant_msgs:message'] = 'Nội dung của tin nhắn.';
$string['privacy:metadata:local_ai_course_assistant_msgs:tokens_used'] = 'Số token được sử dụng cho tin nhắn.';
$string['privacy:metadata:local_ai_course_assistant_msgs:timecreated'] = 'Thời gian tin nhắn được tạo.';

// Capabilities.
$string['ai_course_assistant:use'] = 'Sử dụng chat gia sư AI';
$string['ai_course_assistant:viewanalytics'] = 'Xem phân tích chat gia sư AI';
$string['ai_course_assistant:manage'] = 'Quản lý cài đặt chat gia sư AI (Vai trò quản trị viên)';

// Settings.
$string['settings:enabled'] = 'Bật Trợ Lý AI Khóa Học';
$string['settings:enabled_desc'] = 'Bật hoặc tắt widget Trợ Lý AI Khóa Học trên các trang khóa học.';
$string['settings:default_course_mode'] = 'Mặc định cho các khóa học mới';
$string['settings:default_course_mode_desc'] = 'Kiểm soát việc [[tutorshort]] có xuất hiện trên một khóa học hay không khi chưa có lựa chọn theo từng khóa học. Các lần cài đặt mới sẽ mặc định là "Tắt theo mặc định" để quản trị viên có thể bật từng khóa học từ trang Analytics hoặc trang Course AI Settings.';
$string['settings:default_course_mode_per_course'] = 'Tắt theo mặc định (bật cho từng khóa học)';
$string['settings:default_course_mode_all'] = 'Bật trên tất cả các khóa học';
$string['settings:auto_open'] = 'Tự động mở trong lần truy cập đầu tiên';
$string['settings:auto_open_desc'] = 'Khi được bật, ngăn kéo [[tutorshort]] tự động mở vào lần đầu tiên học viên đến mỗi khóa học. Các lần tải trang tiếp theo trong cùng khóa học không mở lại ngăn kéo — trạng thái được theo dõi theo từng khóa học trong trình duyệt của học viên qua localStorage. Áp dụng trên máy tính để bàn và di động. Có thể ghi đè theo từng khóa học từ trang Course AI Settings.';
$string['settings:comparison_providers'] = 'Nhà cung cấp so sánh (bộ chọn LLM)';
$string['settings:comparison_providers_desc'] = 'Thêm các nhà cung cấp AI bổ sung vào bộ chọn LLM trong widget để quản trị viên có thể so sánh phản hồi giữa các nhà cung cấp. Sử dụng bảng bên dưới để thêm hàng. Cột nhiệt độ là tùy chọn (để trống để sử dụng nhiệt độ toàn cục). Định dạng lưu trữ: provider_id|api_key|model1,model2|temperature. Nhà cung cấp chính được cấu hình ở trên luôn được tự động bao gồm. Chỉ quản trị viên có quyền quản lý mới thấy bộ chọn; sinh viên không bao giờ thấy. Provider IDs hợp lệ: openai, claude, deepseek, gemini, ollama, minimax, mistral, openrouter, xai, coreai, custom.';
$string['settings:provider'] = 'Nhà cung cấp AI mặc định';
$string['settings:provider_desc'] = 'Chọn nhà cung cấp AI để sử dụng cho các phần hoàn thành cuộc trò chuyện. Chọn "Moodle AI (core_ai subsystem)" để định tuyến các yêu cầu thông qua cấu hình AI tích hợp sẵn của Moodle tại Site admin > AI; các trường khóa API, mô hình và URL cơ sở bên dưới sẽ bị bỏ qua trong chế độ đó. Streaming, sử dụng công cụ và prompt caching không có sẵn qua core_ai — các phản hồi được gửi dưới dạng một khối duy nhất. Sử dụng nhà cung cấp trực tiếp để có trải nghiệm học viên tốt nhất.';
$string['settings:provider_claude'] = 'Claude (Anthropic)';
$string['settings:provider_openai'] = 'OpenAI';
$string['settings:provider_deepseek'] = 'DeepSeek';
$string['settings:provider_ollama'] = 'Ollama (Cục bộ)';
$string['settings:provider_minimax'] = 'MiniMax';
$string['settings:provider_custom'] = 'Tùy chỉnh (tương thích OpenAI)';
$string['settings:apikey'] = 'API Key';
$string['settings:apikey_desc'] = 'API key cho nhà cung cấp đã chọn. Không cần thiết cho Ollama.';
$string['settings:model'] = 'Tên mô hình';
$string['settings:model_desc'] = 'Mô hình cần sử dụng. Mặc định phụ thuộc vào nhà cung cấp (ví dụ: claude-sonnet-4-5-20250929, gpt-4o, llama3, MiniMax-Text-01).';
$string['settings:apibaseurl'] = 'URL cơ sở API';
$string['settings:apibaseurl_desc'] = 'URL cơ sở cho API. Được tự động điền theo nhà cung cấp nhưng có thể ghi đè. Để trống để dùng mặc định của nhà cung cấp.';
$string['settings:systemprompt'] = 'Mẫu Prompt Hệ thống';
$string['settings:systemprompt_desc'] = 'Prompt hệ thống gửi đến AI. Sử dụng các placeholder: {{coursename}}, {{userrole}}, {{coursetopics}}.';
$string['settings:systemprompt_default'] = 'Bạn là gia sư AI hữu ích cho khóa học "{{coursename}}". Vai trò của học viên là {{userrole}}.

Các chủ đề khóa học được đề cập:
{{coursetopics}}

Hãy giúp học viên hiểu tài liệu khóa học. Hãy khích lệ, rõ ràng và có phương pháp sư phạm tốt.';
$string['settings:temperature'] = 'Nhiệt độ';
$string['settings:temperature_desc'] = 'Kiểm soát tính ngẫu nhiên. Giá trị thấp hơn tập trung hơn, giá trị cao hơn sáng tạo hơn. Phạm vi: 0.0 đến 2.0.';
$string['settings:maxhistory'] = 'Lịch sử hội thoại tối đa';
$string['settings:maxhistory_desc'] = 'Số cặp tin nhắn tối đa để đưa vào yêu cầu API. Các tin nhắn cũ hơn sẽ bị cắt bỏ.';
$string['settings:avatar'] = 'Hình đại diện Chat';
$string['settings:avatar_desc'] = 'Chọn biểu tượng hình đại diện cho nút widget chat.';
$string['settings:avatar_saylor'] = 'Logo {$a} (Mặc định)';
$string['settings:position'] = 'Vị trí Widget';
$string['settings:position_desc'] = 'Vị trí của widget chat trên trang.';
$string['settings:position_br'] = 'Dưới bên phải';
$string['settings:position_bl'] = 'Dưới bên trái';
$string['settings:position_tr'] = 'Trên bên phải';
$string['settings:position_tl'] = 'Trên bên trái';
$string['chat:settings'] = 'Cài đặt plugin';
$string['analytics:viewdashboard'] = 'Xem bảng điều khiển phân tích';

// Course settings.
$string['coursesettings:title'] = 'Cài đặt AI Khóa học';
$string['coursesettings:enabled'] = 'Bật ghi đè khóa học';
$string['coursesettings:enabled_desc'] = 'Khi được bật, các cài đặt bên dưới sẽ ghi đè cấu hình nhà cung cấp AI toàn cục chỉ cho khóa học này. Để trống các trường để kế thừa giá trị toàn cục.';
$string['coursesettings:sola_enabled'] = '[[tutorshort]] trên khóa học này';
$string['coursesettings:sola_enabled_toggle'] = 'Hiển thị widget [[tutorshort]] trên khóa học này';
$string['coursesettings:sola_enabled_desc'] = 'Kiểm soát việc widget trò chuyện [[tutorshort]] có xuất hiện trên khóa học này hay không. Giá trị mặc định toàn trang được đặt trong cài đặt plugin tại General > Default for new courses.';
$string['coursesettings:using_global'] = 'Đang sử dụng cài đặt toàn cục';
$string['coursesettings:saved'] = 'Đã lưu cài đặt AI khóa học.';
$string['coursesettings:global_settings_link'] = 'Cài đặt AI toàn cục';

// Language detection and preference.
$string['lang:switch'] = 'Có, chuyển đổi';
$string['lang:dismiss'] = 'Không, cảm ơn';
$string['lang:change'] = 'Thay đổi ngôn ngữ';
$string['lang:english'] = 'Tiếng Anh';

// Chat widget.
$string['chat:title'] = 'Gia Sư AI';
$string['chat:placeholder'] = 'Đặt câu hỏi...';
$string['chat:send'] = 'Gửi';
$string['chat:close'] = 'Đóng chat';
$string['chat:open'] = 'Mở chat gia sư AI';
$string['chat:clear'] = 'Xóa màn hình';
$string['chat:clear_confirm'] = 'Xóa các tin nhắn hiển thị? Toàn bộ lịch sử chat của bạn vẫn được lưu và có thể tải lại bằng cách mở lại widget.';
$string['chat:copy'] = 'Sao chép cuộc trò chuyện';
$string['chat:copied'] = 'Đã sao chép cuộc trò chuyện vào clipboard';
$string['chat:copy_failed'] = 'Không thể sao chép cuộc trò chuyện';
$string['chat:thinking'] = 'Đang suy nghĩ...';
$string['chat:error'] = 'Xin lỗi, đã có lỗi xảy ra. Vui lòng thử lại.';
$string['chat:error_auth'] = 'Lỗi xác thực. Vui lòng liên hệ quản trị viên.';
$string['chat:error_ratelimit'] = 'Quá nhiều yêu cầu. Vui lòng đợi một chút rồi thử lại.';
$string['chat:error_unavailable'] = 'Dịch vụ AI tạm thời không khả dụng. Vui lòng thử lại sau.';
$string['chat:error_notconfigured'] = 'Gia sư AI chưa được cấu hình. Vui lòng liên hệ quản trị viên.';
$string['chat:mic'] = 'Nói câu hỏi của bạn';
$string['chat:mic_error'] = 'Lỗi microphone. Vui lòng kiểm tra quyền trình duyệt.';
$string['chat:mic_unsupported'] = 'Trình duyệt này không hỗ trợ nhập liệu bằng giọng nói.';
$string['chat:newline_hint'] = 'Shift+Enter để xuống dòng';
$string['chat:you'] = 'Bạn';
$string['chat:assistant'] = 'Gia Sư AI';
$string['chat:history_loaded'] = 'Đã tải cuộc trò chuyện trước.';
$string['chat:history_cleared'] = 'Đã xóa lịch sử chat.';
$string['chat:offtopic_warning'] = 'Có vẻ câu hỏi của bạn không liên quan đến khóa học này. Vui lòng giữ đúng chủ đề để tôi có thể hỗ trợ bạn tốt nhất!';
$string['chat:offtopic_ended'] = 'Quyền truy cập gia sư AI của bạn đã bị tạm ngưng trong {$a} phút vì cuộc trò chuyện đã đi lạc chủ đề quá nhiều lần. Hãy dùng thời gian này ôn lại tài liệu khóa học và thử lại sau.';
$string['chat:offtopic_locked'] = 'Quyền truy cập gia sư AI của bạn đang tạm ngưng. Bạn có thể thử lại sau {$a} phút. Hãy tập trung vào các câu hỏi liên quan đến khóa học khi quay lại.';
$string['chat:escalated_to_support'] = 'Tôi không thể trả lời đầy đủ câu hỏi của bạn, vì vậy tôi đã tạo ticket hỗ trợ cho bạn. Một thành viên nhóm hỗ trợ sẽ liên hệ. Mã ticket của bạn là: {$a}';
$string['chat:studyplan_intro'] = 'Tôi có thể giúp bạn tạo kế hoạch học tập cho khóa học này! Hãy cho tôi biết bạn có thể dành bao nhiêu giờ mỗi tuần để học, và tôi sẽ giúp bạn xây dựng kế hoạch có cấu trúc.';

// FAQ & Support settings.
$string['settings:faq_heading'] = 'Câu hỏi thường gặp & Hỗ trợ';
$string['settings:faq_heading_desc'] = 'Cấu hình FAQ tập trung và tích hợp ticket hỗ trợ Zendesk.';
$string['settings:faq_content'] = 'Nội dung FAQ';
$string['settings:faq_content_desc'] = 'Nhập các mục FAQ (mỗi dòng một mục theo định dạng: Q: câu hỏi | A: câu trả lời). Các mục này sẽ được cung cấp cho AI để trả lời các câu hỏi hỗ trợ phổ biến.';
$string['settings:zendesk_enabled'] = 'Bật leo thang Zendesk';
$string['settings:zendesk_enabled_desc'] = 'Khi AI không thể giải quyết câu hỏi hỗ trợ, tự động tạo ticket Zendesk với tóm tắt cuộc trò chuyện.';
$string['settings:zendesk_subdomain'] = 'Tên miền phụ Zendesk';
$string['settings:zendesk_subdomain_desc'] = 'Tên miền phụ Zendesk của bạn (ví dụ: "mycompany" cho mycompany.zendesk.com).';
$string['settings:zendesk_email'] = 'Email API Zendesk';
$string['settings:zendesk_email_desc'] = 'Địa chỉ email của người dùng Zendesk để xác thực API (với hậu tố /token).';
$string['settings:zendesk_token'] = 'Token API Zendesk';
$string['settings:zendesk_token_desc'] = 'Token API để xác thực Zendesk.';

// Off-topic detection settings.
$string['settings:offtopic_heading'] = 'Phát hiện ngoài chủ đề';
$string['settings:offtopic_heading_desc'] = 'Cấu hình cách chat xử lý các cuộc trò chuyện ngoài chủ đề.';
$string['settings:offtopic_enabled'] = 'Bật phát hiện ngoài chủ đề';
$string['settings:offtopic_enabled_desc'] = 'Hướng dẫn AI phát hiện và chuyển hướng các cuộc trò chuyện ngoài chủ đề.';
$string['settings:offtopic_max'] = 'Số tin nhắn ngoài chủ đề tối đa';
$string['settings:offtopic_max_desc'] = 'Số tin nhắn ngoài chủ đề liên tiếp trước khi thực hiện hành động.';
$string['settings:offtopic_action'] = 'Hành động khi ngoài chủ đề';
$string['settings:offtopic_action_desc'] = 'Phải làm gì khi đạt giới hạn ngoài chủ đề.';
$string['settings:offtopic_action_warn'] = 'Cảnh báo và chuyển hướng';
$string['settings:offtopic_action_end'] = 'Khóa quyền truy cập tạm thời';
$string['settings:offtopic_lockout_duration'] = 'Thời gian khóa (phút)';
$string['settings:offtopic_lockout_duration_desc'] = 'Thời gian (tính bằng phút) học viên mất quyền truy cập gia sư AI sau khi vượt giới hạn ngoài chủ đề. Mặc định: 30 phút.';

// Study planning & reminders settings.
$string['settings:studyplan_heading'] = 'Lập kế hoạch học tập & Nhắc nhở';
$string['settings:studyplan_heading_desc'] = 'Cấu hình tính năng lập kế hoạch học tập và thông báo nhắc nhở.';
$string['settings:studyplan_enabled'] = 'Bật lập kế hoạch học tập';
$string['settings:studyplan_enabled_desc'] = 'Cho phép gia sư AI giúp học viên tạo kế hoạch học tập cá nhân hóa dựa trên thời gian có sẵn.';
$string['settings:reminders_email_enabled'] = 'Bật nhắc nhở qua email';
$string['settings:reminders_email_enabled_desc'] = 'Cho phép học viên đăng ký nhắc nhở học tập qua email.';
$string['settings:reminders_whatsapp_enabled'] = 'Bật nhắc nhở qua WhatsApp';
$string['settings:reminders_whatsapp_enabled_desc'] = 'Cho phép học viên đăng ký nhắc nhở học tập qua WhatsApp (yêu cầu cấu hình API WhatsApp).';
$string['settings:whatsapp_api_url'] = 'URL API WhatsApp';
$string['settings:whatsapp_api_url_desc'] = 'Endpoint API để gửi tin nhắn WhatsApp (ví dụ: Twilio, MessageBird). Phải chấp nhận POST với nội dung JSON chứa các trường "to", "from" và "body".';
$string['settings:whatsapp_api_token'] = 'Token API WhatsApp';
$string['settings:whatsapp_api_token_desc'] = 'Token xác thực cho API WhatsApp.';
$string['settings:whatsapp_from_number'] = 'Số gửi WhatsApp';
$string['settings:whatsapp_from_number_desc'] = 'Số điện thoại để gửi tin nhắn WhatsApp (với mã quốc gia, ví dụ: +14155238886).';
$string['settings:whatsapp_blocked_countries'] = 'Quốc gia bị chặn WhatsApp';
$string['settings:whatsapp_blocked_countries_desc'] = 'Mã quốc gia ISO 3166-1 alpha-2 phân cách bằng dấu phẩy, nơi không được phép nhắc nhở WhatsApp do quy định địa phương (ví dụ: "CN,IR,KP").';

// Reminder messages.
$string['reminder:email_subject'] = 'Nhắc nhở học tập: {$a}';
$string['reminder:email_body'] = 'Xin chào {$a->firstname},

Đây là lời nhắc nhở học tập của bạn cho "{$a->coursename}".

{$a->message}

Kế hoạch học tập của bạn đề xuất {$a->hours_per_week} giờ mỗi tuần cho khóa học này.

Cố lên nhé!

---
Để ngừng nhận những nhắc nhở này, nhấn vào đây: {$a->unsubscribe_url}';
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
$string['reminder:whatsapp_body'] = 'Nhắc nhở học tập cho {$a->coursename}: {$a->message} (Hủy đăng ký: {$a->unsubscribe_url})';
$string['reminder:study_tip_prefix'] = 'Nội dung học tập hôm nay: ';

// Unsubscribe page.
$string['unsubscribe:title'] = 'Hủy đăng ký nhắc nhở học tập';
$string['unsubscribe:success'] = 'Bạn đã hủy đăng ký thành công nhắc nhở học tập cho khóa học này.';
$string['unsubscribe:already'] = 'Bạn đã hủy đăng ký những nhắc nhở này rồi.';
$string['unsubscribe:invalid'] = 'Link hủy đăng ký không hợp lệ hoặc đã hết hạn.';
$string['unsubscribe:resubscribe'] = 'Thay đổi ý kiến? Bạn có thể bật lại nhắc nhở qua chat gia sư AI.';

// Scheduled task.
$string['task:send_reminders'] = 'Gửi nhắc nhở học tập gia sư AI';

// Privacy - additional tables.
$string['privacy:metadata:local_ai_course_assistant_plans'] = 'Lưu trữ kế hoạch học tập của học viên.';
$string['privacy:metadata:local_ai_course_assistant_plans:userid'] = 'ID của người dùng sở hữu kế hoạch học tập.';
$string['privacy:metadata:local_ai_course_assistant_plans:courseid'] = 'Khóa học mà kế hoạch học tập thuộc về.';
$string['privacy:metadata:local_ai_course_assistant_plans:hours_per_week'] = 'Số giờ mỗi tuần học viên dự định học.';
$string['privacy:metadata:local_ai_course_assistant_plans:plan_data'] = 'Chi tiết kế hoạch học tập ở định dạng JSON.';
$string['privacy:metadata:local_ai_course_assistant_reminders'] = 'Lưu trữ tùy chọn và đăng ký nhắc nhở học tập.';
$string['privacy:metadata:local_ai_course_assistant_reminders:userid'] = 'ID của người dùng đã đăng ký nhắc nhở.';
$string['privacy:metadata:local_ai_course_assistant_reminders:channel'] = 'Kênh nhắc nhở (email hoặc whatsapp).';
$string['privacy:metadata:local_ai_course_assistant_reminders:destination'] = 'Địa chỉ email hoặc số điện thoại để nhắc nhở.';
$string['privacy:metadata:local_ai_course_assistant_reminders:country_code'] = 'Mã quốc gia của người dùng để tuân thủ quy định.';

// Analytics dashboard.
$string['analytics:title'] = 'Phân tích Gia Sư AI';
$string['analytics:overview'] = 'Tổng quan';
$string['analytics:total_conversations'] = 'Tổng cuộc trò chuyện';
$string['analytics:total_messages'] = 'Tổng tin nhắn';
$string['analytics:active_students'] = 'Học viên tích cực';
$string['analytics:avg_messages_per_student'] = 'Trung bình tin nhắn mỗi học viên';
$string['analytics:offtopic_rate'] = 'Tỷ lệ ngoài chủ đề';
$string['analytics:escalation_count'] = 'Đã leo thang lên hỗ trợ';
$string['analytics:studyplan_adoption'] = 'Học viên có kế hoạch học tập';
$string['analytics:usage_trends'] = 'Xu hướng sử dụng';
$string['analytics:daily_messages'] = 'Lượng tin nhắn hàng ngày';
$string['analytics:hotspots'] = 'Điểm nóng khóa học';
$string['analytics:hotspots_desc'] = 'Các phần khóa học được tham chiếu nhiều nhất trong câu hỏi của học viên. Số lượng cao hơn có thể chỉ ra những khu vực học viên cần hỗ trợ thêm.';
$string['analytics:section'] = 'Phần';
$string['analytics:mention_count'] = 'Số lần đề cập';
$string['analytics:common_prompts'] = 'Mẫu Prompt Phổ biến';
$string['analytics:common_prompts_desc'] = 'Các mẫu câu hỏi thường xuyên lặp lại từ học viên. Xem xét những điều này để xác định các khoảng trống hệ thống trong nội dung khóa học.';
$string['analytics:prompt_pattern'] = 'Mẫu';
$string['analytics:frequency'] = 'Tần suất';
$string['analytics:recent_activity'] = 'Hoạt động gần đây';
$string['analytics:no_data'] = 'Chưa có dữ liệu phân tích. Dữ liệu sẽ xuất hiện khi học viên bắt đầu sử dụng gia sư AI.';
$string['analytics:timerange'] = 'Phạm vi thời gian';
$string['analytics:last_7_days'] = '7 ngày qua';
$string['analytics:last_30_days'] = '30 ngày qua';
$string['analytics:all_time'] = 'Toàn bộ thời gian';
$string['analytics:export'] = 'Xuất dữ liệu';
$string['analytics:provider_comparison'] = 'So sánh nhà cung cấp AI';
$string['analytics:provider_comparison_desc'] = 'So sánh hiệu suất giữa các nhà cung cấp AI được sử dụng trong khóa học này.';
$string['analytics:provider'] = 'Nhà cung cấp';
$string['analytics:response_count'] = 'Phản hồi';
$string['analytics:avg_response_length'] = 'Độ dài phản hồi trung bình';
$string['analytics:total_tokens'] = 'Tổng token';
$string['analytics:avg_tokens'] = 'Token trung bình / phản hồi';

// User settings.
$string['usersettings:title'] = 'Trợ Lý AI Khóa Học - Dữ liệu của bạn';
$string['usersettings:intro'] = 'Quản lý dữ liệu chat gia sư AI và cài đặt quyền riêng tư của bạn';
$string['usersettings:privacy_info'] = 'Các cuộc trò chuyện của bạn với gia sư AI được lưu trữ để cung cấp cho bạn sự hỗ trợ liên tục trong suốt khóa học. Bạn có toàn quyền kiểm soát dữ liệu này và có thể xóa bất kỳ lúc nào.';
$string['usersettings:usage_stats'] = 'Thống kê sử dụng của bạn';
$string['usersettings:total_messages'] = 'Tổng tin nhắn';
$string['usersettings:total_conversations'] = 'Cuộc trò chuyện';
$string['usersettings:messages'] = 'Tin nhắn';
$string['usersettings:last_activity'] = 'Hoạt động gần đây';
$string['usersettings:delete_course_data'] = 'Xóa dữ liệu khóa học';
$string['usersettings:no_data'] = 'Bạn chưa sử dụng gia sư AI. Dữ liệu sử dụng sẽ xuất hiện ở đây khi bạn bắt đầu trò chuyện.';
$string['usersettings:delete_all_title'] = 'Xóa tất cả dữ liệu của bạn';
$string['usersettings:delete_all_warning'] = 'Thao tác này sẽ xóa vĩnh viễn tất cả các cuộc trò chuyện gia sư AI của bạn trên tất cả khóa học. Không thể hoàn tác.';
$string['usersettings:delete_all_button'] = 'Xóa tất cả dữ liệu của tôi';
$string['usersettings:confirm_delete_course'] = 'Bạn có chắc muốn xóa vĩnh viễn tất cả dữ liệu gia sư AI cho khóa học "{$a}"? Không thể hoàn tác.';
$string['usersettings:confirm_delete_all'] = 'Bạn có chắc muốn xóa vĩnh viễn TẤT CẢ dữ liệu gia sư AI trên tất cả khóa học? Không thể hoàn tác.';
$string['usersettings:data_deleted'] = 'Dữ liệu của bạn đã được xóa.';

// === [[tutorshort]] v1.0.12 — updated/new strings ===

$string['chat:greeting'] = 'Xin chào, {$a}! Tôi là [[tutorshort]]. Hôm nay tôi có thể giúp gì cho bạn?';
$string['chat:title'] = '[[tutorshort]]';
$string['chat:assistant'] = '[[tutorshort]]';
$string['chat:open'] = 'Mở [[tutorshort]]';
$string['chat:change_avatar'] = 'Thay đổi hình đại diện';

$string['chat:quiz'] = 'Làm bài kiểm tra thực hành';
$string['chat:quiz_setup_title'] = 'Bài Kiểm Tra Thực Hành';
$string['chat:quiz_questions'] = 'Số câu hỏi';
$string['chat:quiz_topic'] = 'Chủ đề';
$string['chat:quiz_topic_guided'] = 'AI hướng dẫn (dựa theo tiến độ của bạn)';
$string['chat:quiz_topic_adaptive']      = 'Thích ứng — tập trung vào điểm yếu của tôi';
$string['chat:quiz_topic_default'] = 'Nội dung khóa học hiện tại';
$string['chat:quiz_topic_custom'] = 'Chủ đề tùy chỉnh…';
$string['chat:quiz_custom_placeholder'] = 'Nhập một chủ đề hoặc câu hỏi...';
$string['chat:quiz_start'] = 'Bắt Đầu Kiểm Tra';
$string['chat:quiz_cancel'] = 'Hủy';
$string['chat:quiz_loading'] = 'Đang tạo bài kiểm tra…';
$string['chat:quiz_error'] = 'Không thể tạo bài kiểm tra. Vui lòng thử lại.';
$string['chat:quiz_correct'] = 'Đúng rồi!';
$string['chat:quiz_wrong'] = 'Sai.';
$string['chat:quiz_next'] = 'Câu tiếp theo';
$string['chat:quiz_finish'] = 'Xem kết quả';
$string['chat:quiz_score'] = 'Hoàn thành bài kiểm tra! Bạn đạt {$a->score} trên {$a->total} câu.';
$string['chat:quiz_summary'] = 'Tôi vừa hoàn thành bài kiểm tra thực hành {$a->total} câu về "{$a->topic}" và đạt {$a->score}/{$a->total}.';
$string['chat:quiz_topic_objectives'] = 'Mục Tiêu Học Tập';
$string['chat:quiz_topic_modules'] = 'Chủ Đề Khóa Học';
$string['chat:quiz_subtopic_select'] = 'Chọn một mục cụ thể…';
$string['chat:quiz_topic_sections'] = 'Các Phần Khóa Học';
$string['chat:quiz_score_great'] = 'Xuất sắc! Bạn thực sự nắm vững tài liệu này.';
$string['chat:quiz_score_good'] = 'Cố gắng tốt! Tiếp tục ôn tập để củng cố hiểu biết của bạn.';
$string['chat:quiz_score_practice'] = 'Tiếp tục luyện tập — hãy xem lại tài liệu khóa học liên quan, rồi làm lại bài kiểm tra.';
$string['chat:quiz_review_heading'] = 'Ôn Tập';
$string['chat:quiz_retake'] = 'Làm Lại Bài Kiểm Tra';
$string['chat:quiz_exit'] = 'Thoát Bài Kiểm Tra';
$string['chat:quiz_your_answer'] = 'Câu trả lời của bạn';
$string['chat:quiz_correct_answer'] = 'Câu trả lời đúng';

$string['chat:starters_label'] = 'Gợi ý bắt đầu cuộc trò chuyện';
$string['chat:starter_quiz'] = 'Kiểm Tra Tôi Về Điều Này';
$string['chat:starter_explain'] = 'Giải Thích Điều Này';
$string['chat:starter_key_concepts'] = 'Khái Niệm Chính';
$string['chat:starter_study_plan'] = 'Kế Hoạch Học Tập';
$string['chat:starter_help_me'] = 'Trợ Lý AI';
$string['chat:starter_ai_project_coach'] = 'Huấn Luyện Dự Án AI';
$string['chat:starter_ell_practice'] = 'Luyện Hội Thoại';
$string['chat:starter_ell_pronunciation'] = 'Luyện Phát Âm ELL';
$string['chat:starter_ai_coach'] = 'Huấn Luyện AI';
$string['chat:starter_speak'] = 'Nói một gợi ý';

$string['chat:reset'] = 'Bắt đầu lại';

$string['chat:topic_picker_title'] = 'Bạn muốn tập trung vào điều gì?';
$string['chat:topic_picker_title_help'] = 'Bạn cần giúp đỡ về điều gì?';
$string['chat:topic_picker_title_explain'] = 'Bạn muốn tôi giải thích điều gì?';
$string['chat:topic_picker_title_study'] = 'Bạn muốn tập trung vào lĩnh vực nào?';
$string['chat:topic_start'] = 'Tiếp tục';

$string['chat:fullscreen'] = 'Toàn màn hình';
$string['chat:exitfullscreen'] = 'Thoát toàn màn hình';

$string['chat:language'] = 'Đổi ngôn ngữ';
$string['chat:settings_panel'] = 'Cài đặt';
$string['chat:settings_language'] = 'Ngôn ngữ';
$string['chat:settings_avatar'] = 'Hình đại diện';
$string['chat:settings_voice'] = 'Giọng nói';
$string['chat:settings_voice_admin'] = 'Cài đặt giọng nói được quản lý trong bảng quản trị trang web.';

$string['chat:voice_mode'] = 'Chế độ giọng nói';
$string['chat:voice_end'] = 'Kết thúc phiên giọng nói';
$string['chat:voice_connecting'] = 'Đang kết nối...';
$string['chat:voice_listening'] = 'Đang lắng nghe...';
$string['chat:voice_speaking'] = '[[tutorshort]] đang nói...';
$string['chat:voice_idle'] = 'Sẵn sàng';
$string['chat:voice_error'] = 'Kết nối giọng nói thất bại. Vui lòng kiểm tra cài đặt của bạn.';
$string['chat:quiz_locked'] = '[[tutorshort]] tạm dừng trong khi làm bài kiểm tra để hỗ trợ tính trung thực học thuật. Chúc may mắn!';

// Bottom nav.
$string['chat:mode_nav'] = 'Mode navigation';
$string['chat:mode_chat'] = 'Chat';
$string['chat:mode_voice'] = 'Voice';
$string['chat:mode_history'] = 'Ghi chú';

// History panel.
$string['chat:history_title'] = 'Ghi chú và lịch sử hội thoại';
$string['task:send_inactivity_reminders'] = 'Gửi email nhắc nhở hàng tuần về việc không hoạt động';
$string['messageprovider:study_notes'] = 'Ghi chú phiên học tập';
$string['task:send_inactivity_reminders'] = 'Gửi email nhắc nhở hàng tuần về việc không hoạt động';
$string['task:run_meta_ai_query'] = 'Chạy truy vấn phân tích Radar học tập đã lên lịch';
$string['messageprovider:study_notes'] = 'Ghi chú phiên học tập';

// CDN settings.
$string['settings:cdn_heading'] = 'CDN / Phân phối Frontend';
$string['settings:cdn_heading_desc'] = 'Phục vụ tài nguyên frontend [[tutorshort]] (JS/CSS) từ CDN bên ngoài thay vì hệ thống tệp Moodle. Điều này cho phép cập nhật frontend mà không cần nâng cấp plugin. Để trống URL CDN để sử dụng tệp plugin cục bộ.';
$string['settings:cdn_url'] = 'URL cơ sở CDN';
$string['settings:cdn_url_desc'] = 'URL cơ sở nơi sola.min.js và sola.min.css được lưu trữ. Ví dụ: https://your-org.github.io/sola-cdn. Để trống để sử dụng tệp plugin cục bộ.';
$string['settings:cdn_version'] = 'Phiên bản tài nguyên CDN';
$string['settings:cdn_version_desc'] = 'Chuỗi phiên bản được thêm vào URL CDN cho cache busting. Cập nhật sau mỗi lần triển khai CDN (ví dụ: 3.2.4 hoặc commit hash).';

// Analytics dashboard.
$string['analytics:tab_overall'] = 'Sử dụng tổng thể';
$string['analytics:tab_bycourse'] = 'Theo khóa học';
$string['analytics:tab_comparison'] = 'AI so với người không dùng';
$string['analytics:tab_byunit'] = 'Theo đơn vị';
$string['analytics:tab_usagetypes'] = 'Loại sử dụng';
$string['analytics:tab_themes'] = 'Chủ đề';
$string['analytics:tab_feedback'] = 'Phản hồi AI';
$string['analytics:total_students'] = 'Tổng số sinh viên';
$string['analytics:active_users'] = 'Người dùng AI hoạt động';
$string['analytics:msgs_per_student'] = 'Tin nhắn mỗi sinh viên';
$string['analytics:avg_session'] = 'Thời lượng phiên trung bình';
$string['analytics:return_rate'] = 'Tỷ lệ quay lại';
$string['analytics:total_sessions'] = 'Tổng số phiên';
$string['analytics:thumbs_up'] = 'Thích';
$string['analytics:thumbs_down'] = 'Không thích';
$string['analytics:hallucination_flags'] = 'Đánh dấu thông tin sai';
$string['analytics:msgs_to_resolution'] = 'Tin nhắn đến khi giải quyết';
$string['analytics:helpful'] = 'Hữu ích';
$string['analytics:not_helpful'] = 'Không hữu ích';
$string['analytics:flag_hallucination'] = 'Câu trả lời này chứa thông tin không chính xác';
$string['analytics:submit_rating'] = 'Gửi';
$string['analytics:thanks_feedback'] = 'Cảm ơn phản hồi của bạn';

// LLM provider names.
$string['settings:provider_mistral'] = 'Mistral AI';
$string['settings:provider_openrouter'] = 'OpenRouter';
$string['settings:provider_together'] = 'Together AI (Llama 3.1 8B/70B/405B Turbo, Mistral, Qwen)';
$string['settings:provider_xai'] = 'xAI (Grok)';

$string['settings:provider_coreai'] = 'Moodle AI (core_ai subsystem)';
// Strings added by update_langs.py.
$string['chat:starter_help_page'] = 'Giải thích trang này';
$string['chat:starter_ask_anything'] = 'Hỏi bất cứ điều gì';
$string['chat:starter_review_practice'] = 'Ôn tập và thực hành';
$string['chat:history_saved_subtitle'] = 'Các phản hồi đã lưu sẽ ở lại trên thiết bị này cho khóa học này.';
$string['chat:history_saved_empty'] = 'Lưu một phản hồi AI để xem nó ở đây.';
$string['chat:history_views_label'] = 'Chế độ xem lịch sử';
$string['chat:history_view_saved'] = 'Đã lưu';
$string['chat:history_view_recent'] = 'Lịch sử';
$string['chat:debug_refresh'] = 'Làm mới';
$string['chat:debug_copy_all'] = 'Sao chép tất cả';
$string['chat:debug_close'] = 'Đóng';
$string['chat:language_switch'] = 'Chuyển đổi ngôn ngữ';
$string['chat:language_dismiss'] = 'Bỏ qua đề xuất ngôn ngữ';
$string['chat:llm_label'] = 'LLM';
$string['chat:llm_provider_select'] = 'Chọn nhà cung cấp LLM';
$string['chat:llm_model_label'] = 'Mô hình';
$string['chat:llm_model_select'] = 'Chọn mô hình LLM';
$string['chat:footer_usertesting'] = 'Kiểm tra khả năng sử dụng';
$string['chat:footer_feedback'] = 'Phản hồi';
$string['chat:voice_panel_title']       = 'Talk with {$a}';

// Additional translated strings.
$string['chat:debug_context'] = 'Gỡ lỗi ngữ cảnh';
$string['chat:debug_context_browser'] = 'Ảnh chụp trình duyệt';
$string['chat:debug_context_copy'] = 'Sao chép';
$string['chat:debug_context_prompt'] = 'Phản hồi máy chủ';
$string['chat:debug_context_request'] = 'Yêu cầu SSE gần nhất';
$string['chat:debug_context_toggle'] = 'Chuyển đổi trình kiểm tra';
$string['chat:history_empty'] = 'Chưa có trò chuyện.';
$string['chat:history_refresh'] = 'Làm mới';
$string['chat:history_subtitle'] = 'Tin nhắn gần đây.';
$string['chat:starter_explain_prompt'] = 'Giải thích khái niệm quan trọng nhất?';
$string['chat:starter_help_lesson'] = 'Giải thích điều này';
$string['chat:starter_help_lesson_prompt'] = 'Giúp tôi hiểu bài. Tóm tắt khái niệm chính.';
$string['chat:starter_prompt_coach'] = 'Huấn luyện AI';
$string['chat:starter_quick_study'] = 'Học nhanh';
$string['chat:starter_study_plan_prompt'] = 'Tôi muốn lên kế hoạch học. Hỏi: (1) mục tiêu, (2) thời gian. Cập nhật kế hoạch.';
$string['chat:voice_copy'] = 'Trò chuyện bằng giọng nói với trợ lý.';
$string['chat:voice_ready'] = 'Sẵn sàng';
$string['chat:voice_start'] = 'Bắt đầu';
$string['chat:voice_title'] = 'Nói chuyện với [[tutorshort]]';
$string['coursesettings:ell_pronunciation'] = 'Pronunciation Practice Mode';
$string['coursesettings:ell_pronunciation_desc'] = 'Show the "Pronunciation Practice" chip for students in this course. Uses OpenAI Realtime API for phoneme-level pronunciation feedback. Requires Voice Mode to be enabled in global plugin settings.';
$string['coursesettings:ell_pronunciation_enable'] = 'Enable Pronunciation Practice chip for this course';
$string['coursesettings:rag'] = 'Semantic Search (RAG)';
$string['coursesettings:rag_desc'] = 'Enable retrieval-augmented generation for this course. When enabled, [[tutorshort]] embeds and searches course content to ground its answers. Requires RAG to be enabled globally in plugin settings.';
$string['coursesettings:rag_enable'] = 'Enable RAG for this course';
$string['coursesettings:speaking_practice'] = 'Speaking Practice';
$string['coursesettings:speaking_practice_desc'] = 'Show the "Practice Speaking" chip for students in this course. Uses OpenAI TTS for voice responses. Requires an OpenAI API key in global plugin settings.';
$string['coursesettings:speaking_practice_enable'] = 'Enable Speaking Practice chip for this course';
$string['coursesettings:token_usage'] = 'Token Usage & Cost';
$string['coursesettings:token_usage_desc'] = 'View token usage, cost estimates, and per-student breakdowns for this course.';

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
$string['mobile_chip_concepts'] = 'Khái niệm chính';
$string['mobile_chip_quiz'] = 'Câu đố';
$string['mobile_chip_studyplan'] = 'Kế hoạch học tập';
$string['mobile_clear'] = 'Xóa lịch sử';
$string['mobile_disabled'] = '[[tutorshort]] không khả dụng cho khóa học này.';
$string['mobile_placeholder'] = 'Đặt câu hỏi...';
$string['mobile_welcome'] = 'Xin chào, {$a}!';
$string['mobile_welcome_sub'] = 'Tôi là [[tutorshort]], trợ lý học tập của bạn. Tôi có thể giúp gì?';
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
$string['remoteconfigurl_desc'] = 'URL to a JSON file containing remotely-managed [[tutorshort]] configuration (system prompt, instruction blocks, model default). Must be HTTPS. Leave blank to use the default GitHub URL. Local admin settings always take priority over remote config values.';
$string['rubric:done'] = 'Hoàn tất';
$string['rubric:encourage_high'] = 'Xuất sắc! Tiếp tục nhé!';
$string['rubric:encourage_low'] = 'Khởi đầu tốt! Luyện tập thường xuyên sẽ giúp ích.';
$string['rubric:encourage_mid'] = 'Cố gắng tốt! Tiếp tục luyện tập.';
$string['rubric:overall'] = 'Tổng thể';
$string['rubric:practice_again'] = 'Luyện tập lại';
$string['rubric:score_title_conversation'] = 'Điểm luyện hội thoại';
$string['rubric:score_title_pronunciation'] = 'Điểm luyện phát âm';
$string['rubric:scoring'] = 'Đang đánh giá...';
$string['settings:avatar_color'] = 'Avatar Border Color';
$string['settings:avatar_color_desc'] = 'Border color of the floating avatar button. Use a hex value, e.g. #023e8a.';
$string['settings:avatar_fill'] = 'Avatar Background Color';
$string['settings:avatar_fill_desc'] = 'Fill color inside the floating avatar button (shown behind transparent avatar areas). Use a hex value, e.g. #ffffff.';
$string['settings:display_mode'] = 'Display Mode';
$string['settings:display_mode_desc'] = 'How [[tutorshort]] appears on the page. "Widget" shows a floating avatar button with a popup chat panel. "Side drawer" shows a full-height panel that slides in from the right edge of the screen.';
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
$string['settings:hide_on_quiz_for_staff'] = 'Hide [[tutorshort]] on quiz pages for staff';
$string['settings:hide_on_quiz_for_staff_desc'] = 'Completely hide the [[tutorshort]] widget on all quiz pages for teachers and administrators.';
$string['settings:hide_on_quiz_for_students'] = 'Hide [[tutorshort]] on quiz pages for students';
$string['settings:hide_on_quiz_for_students_desc'] = 'Completely hide the [[tutorshort]] widget on all quiz pages (view, attempt, review) for students.';
$string['settings:institution_name'] = 'Institution Name';
$string['settings:institution_name_desc'] = 'The name of the institution displayed in the system prompt, avatar labels, and demo content. Change this when rebranding.';
$string['settings:model_desc_dynamic'] = 'Leave blank to use the provider\'s default model automatically. Each provider has a built-in default that stays current (e.g. gpt-4o for OpenAI, claude-sonnet-4 for Claude, mistral-large-latest for Mistral). Only enter a model name if you want to override the default. If a model is misspelled or deprecated, [[tutorshort]] will automatically fall back to the provider\'s default.';
$string['settings:provider_gemini'] = 'Google Gemini';
$string['settings:quiz_hide_heading'] = 'Quiz Page Visibility';
$string['settings:quiz_hide_heading_desc'] = 'Control whether the [[tutorshort]] widget appears on Moodle quiz pages. This is stricter than the built-in summative quiz lock, which only disables chat during graded quizzes. These settings completely hide the widget on all quiz pages.';
$string['settings:rag_chunksize'] = 'Chunk Size (words)';
$string['settings:rag_chunksize_desc'] = 'Target number of words per content chunk when indexing course material. Smaller chunks are more precise; larger chunks provide more context.';
$string['settings:rag_return_scope'] = 'Phạm vi trả về khi truy xuất';
$string['settings:rag_return_scope_desc'] = 'Nội dung được chèn vào cho mỗi kết quả truy xuất được. "chunk" (mặc định) chèn đoạn khớp khoảng 400 từ. "window" chèn đoạn khớp cùng với các đoạn lân cận trên cùng một trang. "page" chèn toàn bộ trang mô-đun chứa kết quả khớp. window/page hữu ích khi câu trả lời trải rộng trên cả trang, nhưng phải trả giá bằng một prompt lớn hơn.';
$string['settings:rag_window_size'] = 'Kích thước cửa sổ truy xuất';
$string['settings:rag_window_size_desc'] = 'Đối với phạm vi trả về "window": số đoạn lân cận cần đưa vào ở mỗi bên của đoạn khớp. Mặc định là 1.';
$string['settings:rag_parent_max_chars'] = 'Giới hạn đoạn văn gốc (số ký tự)';
$string['settings:rag_parent_max_chars_desc'] = 'Số ký tự tối đa cho một đoạn văn window/page đã mở rộng. Các trang vượt quá giới hạn sẽ chuyển về dùng window, sau đó chuyển về đoạn đơn lẻ. Mặc định là 6000.';
$string['settings:rag_enabled'] = 'Enable RAG (Semantic Search)';
$string['settings:rag_enabled_desc'] = 'When enabled, the AI tutor uses semantic search to retrieve relevant course content for each query instead of stuffing all content into the system prompt.';
$string['settings:rag_heading'] = 'RAG / Semantic Search';
$string['settings:rag_heading_desc'] = 'Retrieval-Augmented Generation: index course content as embeddings and retrieve only the most relevant chunks at query time. Reduces token usage and supports all content types. Requires an embedding API.';
$string['settings:rag_topk'] = 'Top-K Chunks';
$string['settings:rag_topk_desc'] = 'Number of most relevant chunks to retrieve per user query and inject into the system prompt.';
$string['settings:realtime_apikey'] = 'OpenAI API Key (Voice & TTS)';
$string['settings:realtime_apikey_desc'] = 'Used for Voice Mode and the TTS speak button on messages. Leave blank to fall back to the main API key when provider is set to OpenAI.';
$string['settings:realtime_enabled'] = 'Enable Voice Mode';
$string['settings:realtime_enabled_desc'] = 'Allows students to have real-time voice conversations with [[tutorshort]]. Requires an OpenAI API key.';
$string['settings:realtime_heading'] = 'Voice Mode (OpenAI Realtime)';
$string['settings:realtime_voice'] = '[[tutorshort]] Voice';
$string['settings:realtime_voice_desc'] = 'Voice used for both Voice Mode and the TTS speak button (OpenAI voices: Shimmer, Alloy, Echo, Fable, Onyx, Nova).';
$string['settings:wellbeing_enabled'] = 'Enable Wellbeing Support';
$string['settings:wellbeing_enabled_desc'] = 'When enabled, [[tutorshort]] will detect signs of emotional distress and provide empathetic responses with links to global crisis resources. Disable this if your institution provides its own crisis response and does not want [[tutorshort]] to surface external resources.';
$string['settings:wellbeing_heading'] = 'Wellbeing & Safety';
$string['settings:wellbeing_heading_desc'] = 'When enabled, [[tutorshort]] detects expressions of distress or crisis and responds with empathy and globally-applicable support resources (findahelpline.com, Crisis Text Line, Befrienders Worldwide). [[tutorshort]] is NOT a counselor — it acknowledges feelings, directs students to human support, and never attempts diagnosis or therapy.';
$string['starters:add_new'] = 'Add new starter';
$string['starters:admin_desc'] = 'Configure the conversation starter chips shown to students when they open the [[tutorshort]] chat. Drag to reorder, toggle to enable/disable, or add custom starters with your own AI prompts.';
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
$string['task:run_integrity_checks'] = 'Run daily [[tutorshort]] plugin integrity checks';
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

// Testing Environment admin page and TOC quick links (v3.9.4+).
$string['demo:title'] = 'Môi trường thử nghiệm';
$string['demo:heading'] = 'Môi trường thử nghiệm';
$string['demo:intro'] = 'Trang này tạo một khóa học thử nghiệm được <strong>ẩn với sinh viên</strong> (visible=0) và điền vào đó bằng sinh viên giả, các cuộc trò chuyện AI, đánh giá và phản hồi. Hữu ích để xem trước Analytics Dashboard hoặc xác thực các thay đổi của plugin mà không ảnh hưởng đến bất kỳ sinh viên thực đã đăng ký nào.';
$string['demo:step1'] = 'Bước 1: khóa học thử nghiệm';
$string['demo:step2'] = 'Bước 2: thêm sinh viên giả và trò chuyện AI';
$string['demo:course_exists'] = 'Khóa học thử nghiệm tồn tại: <strong>{$a->fullname}</strong> (tên ngắn <code>{$a->shortname}</code>, id {$a->id})';
$string['demo:badge_hidden'] = 'đã ẩn';
$string['demo:badge_visible'] = 'hiển thị với sinh viên';
$string['demo:no_course'] = 'Không tìm thấy khóa học thử nghiệm. Nhấp bên dưới để tạo.';
$string['demo:create_btn'] = 'Tạo khóa học thử nghiệm ẩn';
$string['demo:open_course'] = 'Mở khóa học &rarr;';
$string['demo:seed_intro'] = 'Tạo demo_student_001, demo_student_002, ..., đăng ký họ vào khóa học thử nghiệm và chèn các cuộc trò chuyện, tin nhắn, đánh giá và phản hồi tổng hợp. Chạy lại để thêm dữ liệu, hoặc chọn "xóa trước" để bắt đầu lại.';
$string['demo:users_label'] = 'Người dùng';
$string['demo:weeks_label'] = 'Tuần';
$string['demo:clear_label'] = 'Xóa người dùng demo_* hiện có trước';
$string['demo:seed_btn'] = 'Thêm sinh viên và trò chuyện';
$string['demo:view_analytics'] = 'Xem phân tích cho khóa học này &rarr;';
$string['demo:footer'] = 'Dữ liệu được tạo ở đây nằm trong các bảng người dùng / đăng ký chuẩn của Moodle và các bảng trò chuyện của riêng plugin. Tất cả người dùng giả có tên người dùng bắt đầu bằng <code>demo_student_</code> để dễ lọc hoặc xóa. Để xóa họ, chạy lại bước seed với "Xóa người dùng demo_* hiện có trước" được đánh dấu.';
$string['demo:course_fullname'] = 'Khóa học thử nghiệm [[tutorshort]] (ẩn)';
$string['demo:notify_created'] = 'Khóa học thử nghiệm sẵn sàng: {$a->fullname} (id {$a->id}).';
$string['demo:notify_create_fail'] = 'Không thể tạo khóa học: {$a}';
$string['demo:notify_seeded'] = 'Đã thêm: {$a->users} người dùng, {$a->conversations} cuộc trò chuyện, {$a->messages} tin nhắn, {$a->ratings} đánh giá, {$a->feedback} mục phản hồi.';
$string['demo:notify_seed_fail'] = 'Không thể thêm dữ liệu: {$a}';
$string['toc:analytics'] = 'Analytics Dashboard &rarr;';
$string['toc:tokenanalytics'] = 'Chi phí Token & Phân tích &rarr;';
$string['toc:testing'] = 'Môi trường thử nghiệm &rarr;';
$string['toc:back_to_course'] = '&larr; Quay lại {$a}';

// RAG extractor status strings (v3.9.6+).
$string['rag:pdftotext_missing'] = 'Không tìm thấy tệp nhị phân pdftotext; đã vô hiệu hóa việc trích xuất PDF.';
$string['rag:pdftotext_available'] = 'Đã phát hiện pdftotext tại {$a}.';
$string['rag:docx_unavailable'] = 'Phần mở rộng PHP ZipArchive không khả dụng; đã vô hiệu hóa việc trích xuất DOCX.';
$string['rag:h5p_unavailable'] = 'Không thể đọc nội dung H5P; đang bỏ qua.';
$string['rag:scorm_too_large'] = 'Gói SCORM vượt quá giới hạn kích thước được cấu hình ({$a} MB); đang bỏ qua.';
$string['rag:scorm_unzip_failed'] = 'Không thể giải nén gói SCORM; đang bỏ qua.';
$string['rag:transcript_fetch_failed'] = 'Không thể lấy bản ghi từ {$a}.';
$string['rag:transcript_cf_challenge'] = 'URL bản ghi bị chặn bởi thử thách Cloudflare: {$a}.';
$string['rag:embed_detected'] = 'Đã phát hiện phương tiện nhúng: {$a}';
$string['rag:embed_transcript_attached'] = 'Đã đính kèm bản ghi cho {$a}';

// v3.9.10–v3.9.14 new strings.
$string['usersettings:download'] = 'Tải xuống dữ liệu {$a} của tôi';
$string['usersettings:download_help'] = 'Tải xuống bản sao JSON đầy đủ của mọi bản ghi {$a} liên kết với tài khoản của bạn: cuộc trò chuyện, tin nhắn, đánh giá, kế hoạch học tập, lời nhắc, điểm luyện tập, phản hồi khảo sát, hồ sơ và mục nhật ký kiểm toán.';
$string['usersettings:privacy_notice_link'] = 'Đọc thông báo về quyền riêng tư của {$a}';
$string['privacy:title'] = 'Thông báo về quyền riêng tư của {$a}';
$string['admin:user_data:title'] = '{$a} — Xuất và xóa dữ liệu người học';
$string['admin:user_data:intro'] = 'Quy trình vận hành cho yêu cầu theo Điều 15 (truy cập) hoặc Điều 17 (xóa bỏ) GDPR. Tra cứu người học theo id người dùng Moodle, xem các bản ghi mà plugin lưu giữ về họ, và xuất hoặc xóa.';
$string['admin:user_data:search_label'] = 'Id người dùng Moodle';
$string['admin:user_data:lookup'] = 'Tra cứu';
$string['admin:user_data:not_found'] = 'Không tìm thấy người dùng với id đó.';
$string['admin:user_data:download'] = 'Tải xuống tất cả dữ liệu người học dưới dạng JSON';
$string['admin:user_data:purge'] = 'Xóa toàn bộ dữ liệu người học cho người dùng này';
$string['admin:user_data:confirm_purge'] = 'Xóa vĩnh viễn mọi bản ghi của {$a}? Thao tác này lan toả qua các cuộc trò chuyện, tin nhắn, đánh giá, kế hoạch học tập, lời nhắc, hồ sơ, điểm luyện tập, khảo sát, mục kiểm toán và phản hồi. Không thể hoàn tác.';
$string['admin:user_data:purged'] = 'Mọi dữ liệu của người dùng đã chọn đã được xoá.';
$string['chat:consent_heading'] = 'Trước khi bạn trò chuyện với {$a->product}';
$string['chat:consent_body'] = '{$a->product} là một trợ lý học tập được hỗ trợ bởi AI. Tin nhắn của bạn và phản hồi của {$a->product} được lưu trữ trong cơ sở dữ liệu Moodle của {$a->institution} và mười lượt gần nhất được gửi đến một nhà cung cấp mô hình AI đã được phê duyệt để trả lời câu hỏi của bạn. Tên của bạn được chia sẻ để cá nhân hóa; không có thông tin nhận dạng nào khác được gửi đến nhà cung cấp AI. Nếu bạn yêu cầu được con người trợ giúp và câu hỏi của bạn được chuyển lên, cuộc trò chuyện này (bao gồm tên và email của bạn) có thể được chia sẻ với nhóm hỗ trợ của chúng tôi. Bạn có thể tải xuống, xóa hoặc ngừng sử dụng {$a->product} bất cứ lúc nào.';
$string['chat:consent_accept'] = 'Tôi đã hiểu, bắt đầu {$a}';
$string['chat:consent_privacy_link'] = 'Đọc thông báo về quyền riêng tư đầy đủ';
$string['task:audit_cleanup'] = 'Dọn dẹp bảng kiểm toán của AI Course Assistant';
$string['task:conversation_retention'] = 'Trình quét giữ lại cuộc trò chuyện của AI Course Assistant';
$string['settings:audit_retention_days'] = 'Thời gian giữ nhật ký kiểm toán (ngày)';
$string['settings:audit_retention_days_desc'] = 'Tác vụ định kỳ hàng ngày sẽ xóa các dòng kiểm toán cũ hơn giá trị này. 0 sẽ tắt tính năng. Mặc định 365.';
$string['settings:conversation_retention_days'] = 'Thời gian giữ cuộc trò chuyện (ngày)';
$string['settings:conversation_retention_days_desc'] = 'Tác vụ định kỳ hàng ngày sẽ xóa các cuộc trò chuyện có dấu thời gian sửa đổi cuối cùng cũ hơn giá trị này. 0 sẽ tắt tính năng. Mặc định 730.';
$string['settings:ssrf_trusted_endpoints'] = 'Các điểm cuối SSRF đáng tin cậy';
$string['settings:ssrf_trusted_endpoints_desc'] = 'Một URL trên mỗi dòng. Các máy chủ được liệt kê bỏ qua các kiểm tra loopback / IP-riêng / chỉ-https trong trình xác thực SSRF của [[tutorshort]]. Chỉ sử dụng điều này cho các LLM tự lưu trữ trên một mạng bạn kiểm soát — ví dụ <code>http://localhost:11434</code> cho Ollama cục bộ, <code>http://10.0.0.5:8000</code> cho một pod vLLM trên cùng VPC. So sánh khớp với scheme + host + port; bất kỳ đường dẫn nào đều bị bỏ qua. Mặc định trống (chặn mọi thứ nội bộ). Các dòng bắt đầu bằng <code>#</code> là chú thích.';
$string['task:learner_weekly_digest']    = 'Trợ lý khóa học AI - Bản tóm tắt tuần của người học';
$string['learner_digest:subject']        = 'Tuần của bạn với {$a->course} - {$a->product}';
$string['learner_digest:optin_offer']    = 'Bạn muốn một email ngắn hàng tuần về những gì cần tập trung tiếp theo?';
$string['next_best_action:get_started']           = 'Bắt đầu với {$a->title}. Mở tôi và hỏi "giúp tôi với {$a->title}".';
$string['next_best_action:get_started_with_module'] = 'Bắt đầu với {$a->title}. Mô-đun "{$a->module}" bao quát nó.';
$string['next_best_action:review']                = 'Xem lại các kiến thức cơ bản của {$a->title} — mở tôi và hỏi "giải thích {$a->title} cho tôi như tôi mới bắt đầu".';
$string['next_best_action:review_with_module']    = 'Xem lại các kiến thức cơ bản của {$a->title} trong "{$a->module}", sau đó mở tôi với các câu hỏi.';
$string['next_best_action:practice']              = 'Xây dựng dựa trên những gì bạn có ở {$a->title}. Mở tôi và hỏi "cho tôi một ví dụ đã giải cho {$a->title}".';
$string['next_best_action:practice_with_module']  = 'Thực hành {$a->title} cùng với "{$a->module}". Mở tôi để xem các ví dụ đã giải.';
$string['next_best_action:quiz']                  = 'Củng cố {$a->title} bằng một bài kiểm tra nhanh. Mở tôi và chọn "Kiểm tra tôi — thích ứng".';
$string['next_best_action:quiz_with_module']      = 'Củng cố {$a->title} bằng một bài kiểm tra nhanh. Mô-đun "{$a->module}" là nơi nó nằm.';
$string['next_best_action:empty_state']           = 'Bạn đang làm rất tốt ở mọi mục tiêu ngay bây giờ — không có gì để nhắc nhở. Tiếp tục.';
$string['next_best_action:header']                = 'Đây là {$a} điều cần tập trung tiếp theo:';
$string['learner_digest:unsubscribe_done_title']  = 'Đã hủy đăng ký';
$string['learner_digest:unsubscribe_done_body']   = 'Xong — bạn sẽ không nhận thêm email hàng tuần cho khóa học này từ {$a->product}. Bạn có thể đăng ký lại bất cứ lúc nào từ cửa sổ trò chuyện trong khóa học của mình.';
$string['learner_digest:unsubscribe_invalid_title'] = 'Liên kết hủy đăng ký không còn hiệu lực';
$string['learner_digest:unsubscribe_invalid_body']  = 'Liên kết hủy đăng ký này đã hết hạn hoặc bị lỗi. Bạn có thể quản lý tùy chọn email từ cài đặt khóa học của mình.';
$string['active_learners:line']                   = '{$a} người khác đang học khóa học này ngay bây giờ.';
$string['active_learners:line_global']             = '{$a} người khác đang học ngay bây giờ.';
$string['settings:active_learners_scope']          = 'Phạm vi của chỉ báo người học đang hoạt động';
$string['settings:active_learners_scope_desc']     = 'Liệu chỉ báo "người khác đang học ngay bây giờ" phía trên các trình khởi động trò chuyện có đếm người học chỉ trong cùng khóa học hay người học trên toàn bộ trang web. Mặc định <strong>toàn cầu</strong>.';
$string['settings:active_learners_scope_global']   = 'Toàn cầu (bất kỳ khóa học nào)';
$string['settings:active_learners_scope_course']   = 'Chỉ theo khóa học';
$string['learner_digest:optin_yes']      = 'Có, gửi email hàng tuần cho tôi';
$string['learner_digest:optin_no']       = 'Không, cảm ơn';
$string['learner_digest:optin_thanks']   = 'Đã hiểu. Bạn sẽ nhận bản tóm tắt mỗi thứ Hai.';
$string['learner_digest:optin_declined'] = 'Đã hiểu. Không có email - chỉ mở tôi khi bạn muốn kiểm tra.';
$string['settings:xai_proxy_url'] = 'URL proxy xAI Realtime';
$string['settings:xai_proxy_url_desc'] = 'URL wss công khai của dịch vụ proxy xAI Realtime của [[tutorshort]] (ví dụ wss://voice.example.com/xai-rt/rt). Khi giá trị này được đặt cùng với khóa bí mật JWT, giọng nói xAI sẽ đi qua proxy và khoá API xAI chính sẽ không bao giờ đến trình duyệt. Để trống để quay về kết nối trực tiếp (không khuyến nghị cho môi trường sản xuất).';
$string['settings:xai_proxy_jwt_secret'] = 'Khóa bí mật JWT của proxy xAI Realtime';
$string['settings:xai_proxy_jwt_secret_desc'] = 'Khóa bí mật chia sẻ HS256 dùng để ký các token phiên ngắn hạn cho proxy xAI Realtime. Phải khớp với khóa bí mật MOODLE_JWT_SECRET trên Cloudflare Worker. Hãy xoay vòng định kỳ.';
$string['admin:vendor_dpa:title'] = '{$a} — Trạng thái DPA của nhà cung cấp';
$string['admin:vendor_dpa:intro'] = 'Tình trạng từ chối huấn luyện, DPA và chính sách giữ dữ liệu của mọi trình điều khiển nhà cung cấp AI. Dùng để quyết định trình điều khiển nào được bật trên trang. Định tuyến từ Cấp 2 trở lên yêu cầu DPA đã ký và điều khoản từ chối huấn luyện theo hợp đồng.';
$string['admin:vendor_dpa:maintenance_note'] = 'Bảng này được duy trì trong classes/vendor_registry.php. Cập nhật khi điều khoản dịch vụ của nhà cung cấp thay đổi.';
$string['settings:contact_email'] = 'Contact email';
$string['settings:contact_email_desc'] = 'General contact email shown on the learner facing privacy notice under "Contact". Defaults to contact@saylor.org. Leave empty to hide the line.';
$string['settings:dpo_email'] = 'Email của Cán bộ bảo vệ dữ liệu';
$string['settings:dpo_email_desc'] = 'Email liên hệ hiển thị trên thông báo quyền riêng tư dành cho người học, mục "Liên hệ". Để trống để ẩn dòng này. Các bản cài đặt được đổi thương hiệu nên trỏ tới DPO của tổ chức mình.';
$string['settings:privacy_external_url'] = 'URL trang quyền riêng tư của tổ chức';
$string['settings:privacy_external_url_desc'] = 'Liên kết đến trang quyền riêng tư cấp tổ chức, hiển thị trên thông báo quyền riêng tư dành cho người học, mục "Liên hệ". Để trống để ẩn dòng này.';
$string['settings:privacy_notice_override'] = 'Ghi đè thông báo quyền riêng tư (HTML)';
$string['settings:privacy_notice_override_desc'] = 'Nếu được đặt, HTML này sẽ thay thế thông báo quyền riêng tư có thương hiệu mặc định hiển thị tại /local/ai_course_assistant/privacy.php. Dùng để chèn văn bản đã được Pháp chế duyệt cho tổ chức của bạn mà không phải sửa mã. Để trống để dùng thông báo mặc định, vốn dựa trên bảy khoá cấu hình thương hiệu.';
$string['objectives:title'] = 'Mục tiêu học tập và mức thuần thục';
$string['objectives:toggles_heading'] = 'Theo dõi mức thuần thục';
$string['objectives:toggle_master'] = 'Bật theo dõi mức thuần thục cho khóa học này';
$string['objectives:toggle_chip'] = 'Hiển thị huy hiệu Mức thuần thục cho học viên';
$string['objectives:toggle_chip_help'] = 'Tuỳ chọn. Khi tắt, mức thuần thục vẫn âm thầm điều hướng trợ lý nhưng học viên không nhìn thấy chỉ báo nào.';
$string['objectives:toggled'] = 'Đã cập nhật cài đặt.';
$string['objectives:detected_heading'] = 'Đã phát hiện {$a->count} mục tiêu học tập từ {$a->source}.';
$string['objectives:source_competency'] = 'năng lực Moodle';
$string['objectives:source_summary'] = 'tóm tắt khoá học';
$string['objectives:source_section'] = 'phần hoặc nội dung trang đầu';
$string['objectives:source_page'] = 'trang khoá học';
$string['objectives:source_llm'] = 'trích xuất bằng AI';
$string['objectives:source_manual'] = 'nhập thủ công';
$string['objectives:source_none'] = 'không có nguồn tự động';
$string['objectives:import_detected'] = 'Nhập các mục tiêu đã phát hiện';
$string['objectives:import_llm'] = 'Trích xuất mục tiêu bằng AI';
$string['objectives:llm_empty'] = 'Trích xuất bằng AI không trả về mục tiêu nào. Hãy thử lại sau hoặc nhập thủ công.';
$string['objectives:imported'] = 'Đã nhập {$a} mục tiêu.';
$string['objectives:none_detected'] = 'Không tự động phát hiện được mục tiêu học tập nào. Hãy nhập thủ công bên dưới hoặc dùng trích xuất bằng AI.';
$string['objectives:list_heading'] = 'Mục tiêu hiện tại';
$string['objectives:col_code'] = 'Mã';
$string['objectives:col_title'] = 'Tiêu đề';
$string['objectives:col_source'] = 'Nguồn';
$string['objectives:col_actions'] = 'Hành động';
$string['objectives:add_heading'] = 'Thêm một mục tiêu';
$string['objectives:add_submit'] = 'Thêm mục tiêu';
$string['objectives:saved'] = 'Đã lưu mục tiêu.';
$string['objectives:deleted'] = 'Đã xoá mục tiêu.';
$string['objectives:delete_confirm'] = 'Xoá mục tiêu này và toàn bộ lịch sử thực hiện cho nó?';
$string['objectives:delete_all'] = 'Xoá tất cả mục tiêu của khoá học này';
$string['objectives:delete_all_confirm'] = 'Xoá mọi mục tiêu và toàn bộ lịch sử thực hiện cho khoá học này? Không thể hoàn tác.';
$string['objectives:deleted_all'] = 'Đã xoá tất cả mục tiêu cho khoá học này.';
$string['mastery:chip_aria'] = 'Trạng thái mức thuần thục học tập';
$string['mastery:popover_aria'] = 'Chi tiết mức thuần thục học tập';
$string['mastery:chip_label'] = 'Đã thuần thục {$a->mastered}/{$a->total}';
$string['mastery:status_mastered'] = 'đã thuần thục';
$string['mastery:status_learning'] = 'đang tiến hành';
$string['mastery:status_not_started'] = 'chưa bắt đầu';
$string['mastery:popover_empty'] = 'Khoá học này chưa cấu hình mục tiêu học tập nào.';
$string['settings:mastery_heading'] = 'Theo dõi mức thuần thục';
$string['settings:mastery_heading_desc'] = 'Tính năng cho phép tham gia theo từng khoá học, gắn nhãn câu trả lời quiz và lượt trò chuyện với trợ lý theo các mục tiêu học tập của khoá, sau đó đưa một ảnh chụp nhanh gọn về mức thuần thục trở lại system prompt để định hướng câu hỏi. Mặc định kín đáo: học viên không thấy gì trừ khi bật công tắc huy hiệu cho từng khoá học.';
$string['settings:mastery_threshold'] = 'Ngưỡng thuần thục';
$string['settings:mastery_threshold_desc'] = 'Độ chính xác trượt từ giá trị này trở lên thì mục tiêu được coi là đã thuần thục. Từ 0.0 đến 1.0. Mặc định 0.85.';
$string['settings:mastery_window'] = 'Cửa sổ lần thử';
$string['settings:mastery_window_desc'] = 'Số lần thử gần nhất cho mỗi mục tiêu được tính trọng số trong độ chính xác trượt. Mặc định 8.';
$string['settings:mastery_decay_enabled']        = 'Bật giảm thành thạo';
$string['settings:mastery_decay_enabled_desc']   = 'Khi bật, điểm thành thạo giảm theo thời gian so với dấu thời gian thử nghiệm gần nhất. Một mục tiêu đã thành thạo trước đó sẽ trở lại "đang học" sau thời gian đủ. Không xuống dưới "đang học". <strong>Mặc định tắt ở v4.0.</strong>';
$string['settings:mastery_decay_half_life_days'] = 'Chu kỳ bán rã giảm thành thạo (ngày)';
$string['settings:mastery_decay_half_life_days_desc'] = 'Chu kỳ bán rã tính bằng ngày. Điểm được nhân với 0,5 ^ (ngày kể từ lần thử cuối / chu kỳ bán rã). Mặc định 30. Chỉ được sử dụng khi giảm được bật.';
$string['settings:mastery_classifier_model'] = 'Mô hình phân loại';
$string['settings:mastery_classifier_model_desc'] = 'Mô hình dùng để phân loại lượt trò chuyện của trợ lý theo mục tiêu. Để trống để kế thừa mô hình nhà cung cấp AI mặc định; nếu không, hãy chỉ định một mô hình rẻ như gpt-4o-mini.';
$string['settings:mastery_classifier_weight'] = 'Trọng số phân loại';
$string['settings:mastery_classifier_weight_desc'] = 'Mức độ một lần thử qua trò chuyện được tính so với một lần thử quiz (1.0). Mặc định 0.3.';
$string['settings:mastery_classifier_threshold'] = 'Ngưỡng độ tin cậy phân loại';
$string['settings:mastery_classifier_threshold_desc'] = 'Độ tin cậy tối thiểu của bộ phân loại để ghi nhận một lần thử qua trò chuyện. Từ 0.0 đến 1.0. Mặc định 0.7.';
$string['chat:mode_progress'] = 'Tiến độ';
$string['objectives:toggle_dashboard'] = 'Hiển thị tab bảng Tiến độ cho học viên';
$string['objectives:toggle_dashboard_help'] = 'Tuỳ chọn. Thêm một tab Tiến độ bên cạnh Trò chuyện / Thoại / Lịch sử trong widget. Tab này cho học viên thấy mục tiêu nào đã thuần thục, mục tiêu nào đang tiến hành và mục tiêu nào chưa bắt đầu.';
$string['mastery:dashboard_title'] = 'Tiến độ học tập của bạn';
$string['mastery:dashboard_subtitle'] = 'Mức thuần thục được đo từ câu trả lời quiz và luyện tập trò chuyện của bạn. Hãy tiếp tục — chiều sâu hơn diện rộng.';
$string['mastery:dashboard_refresh'] = 'Làm mới';
$string['mastery:section_mastered'] = 'Đã thuần thục';
$string['mastery:section_learning'] = 'Đang tiến hành';
$string['mastery:section_not_started'] = 'Chưa bắt đầu';
$string['mastery:summary_label'] = 'Đã thuần thục {$a->mastered}/{$a->total} mục tiêu';
$string['mastery:ask_about'] = 'Hỏi về điều này';
$string['mastery:celebrate'] = 'Bạn đã thuần thục mọi mục tiêu của khoá học này. Tuyệt vời.';
$string['mastery:ask_template'] = 'Hãy giúp tôi luyện tập và đào sâu hiểu biết về mục tiêu này: {$a}.';
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
$string['settings:current_page_content_maxchars_desc'] = 'Số ký tự tối đa của văn bản trang hiện tại được chèn vào system prompt dưới dạng phần "Current Page Content", khi RAG tắt. Mặc định 8,000 nền tảng tốt cho các câu hỏi đặc thù của trang trong khi vẫn để lại ngân sách cho cấu trúc và hướng dẫn. (Khi bật RAG, trang được nền tảng bằng chính các đoạn liên quan nhất của nó, thiên về trang hiện tại, nên giới hạn này không áp dụng.) Một trang rất dài sẽ bị cắt từ đầu đến số ký tự này, nên phần cuối của một trang cực dài có thể không được trích dẫn; bật RAG sẽ tránh được điều đó. Các trang quan tâm đến chi phí có thể đặt thấp hơn (vd. 3,000-4,000). Giới hạn trong phạm vi 500-8,000. Độc lập với <code>prompt_budget_chars</code>: giá trị này chỉ giới hạn phần trang; ngân sách giới hạn toàn bộ prompt.';
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
$string['pedagogy:crossmastery'] = 'Mặc định bật tổng hợp mức độ thành thạo liên khóa học';
$string['pedagogy:crossmastery_desc'] = 'Khi bật, [[tutorshort]] nhận biết khi học viên đã thành thạo một mục tiêu trong khóa học khác (đối chiếu theo mã tham chiếu năng lực hoặc tiêu đề) và ghi nhận năng lực đã có đó thay vì luyện lại. Yêu cầu phải theo dõi mức độ thành thạo; các khóa học không có mục tiêu sẽ tự động chuyển về chế độ dự phòng một cách nhẹ nhàng. Chỉ mang tính tư vấn — tính năng này không bao giờ thay đổi điểm thành thạo đã lưu của học viên trong bất kỳ khóa học nào.';
$string['pedagogy:mastery_starter'] = 'Mặc định bật câu mở đầu nhận biết mức độ thành thạo';
$string['pedagogy:mastery_starter_desc'] = 'Khi bật, câu mở đầu cuộc trò chuyện "Tôi nên tập trung vào điều gì?" sẽ được cá nhân hóa để nêu rõ mục tiêu yếu nhất của học viên (và bất kỳ năng lực nào đã được thành thạo ở nơi khác). Yêu cầu phải theo dõi mức độ thành thạo; sẽ chuyển về câu mở đầu chung khi chưa có dữ liệu thành thạo.';
$string['task:rebuild_objective_links'] = 'Tạo lại liên kết mục tiêu liên khóa học cho tổng hợp mức độ thành thạo (v5.7.0)';
$string['mastery_starter:practice_label'] = 'Luyện tập: {$a}';
$string['objectives:rebuild_links_heading'] = 'Liên kết mức độ thành thạo liên khóa học';
$string['objectives:rebuild_links_help'] = '[[tutorshort]] liên kết các mục tiêu khớp với nhau giữa các khóa học (theo mã tham chiếu năng lực hoặc tiêu đề) để học viên đã thành thạo một chủ đề ở nơi khác không phải luyện lại. Các liên kết được tạo lại tự động vào mỗi đêm; dùng nút này để tạo lại ngay sau khi chỉnh sửa mục tiêu.';
$string['objectives:rebuild_links_button'] = 'Tạo lại liên kết ngay';
$string['objectives:rebuild_links_done'] = 'Đã tạo lại liên kết mức độ thành thạo liên khóa học: tổng cộng {$a->total} ({$a->ref} theo tham chiếu, {$a->exact} khớp tiêu đề chính xác, {$a->fuzzy} khớp tiêu đề gần đúng).';

// Forward learning-path awareness (v5.8.0).
$string['pedagogy:program_path'] = 'Bật nhận biết lộ trình học tập phía trước theo mặc định';
$string['pedagogy:program_path_desc'] = 'Khi được bật, [[tutorshort]] có thể cho người học biết khóa học hiện tại dẫn đến đâu tiếp theo trong chương trình của họ (bằng cấp hoặc chứng chỉ) và các khái niệm hôm nay kết nối thế nào với những khóa học sau. Nó đọc plugin Programs của Moodle (Degrees và Learn) và chỉ nêu tên một khóa học tiếp theo cụ thể khi chương trình xác định điều kiện tiên quyết hoặc thứ tự bắt buộc; nếu không, nó ghi nhận vị trí của người học trong lộ trình. Chỉ mang tính tư vấn — nó không bao giờ thay đổi việc ghi danh hay mức độ thành thạo, và chỉ luôn sử dụng phân bổ chương trình của chính người học hiện tại. Tự động không làm gì khi không có chương trình nào áp dụng.';

// Learning path map + next-course nudge (v5.9.0).
$string['pedagogy:learning_path'] = 'Bản đồ lộ trình học tập và gợi ý khóa học tiếp theo bật theo mặc định';
$string['pedagogy:learning_path_desc'] = 'Khi bật, [[tutorshort]] thêm một bảng lộ trình học tập trực quan (nút "lộ trình của tôi" trong tiêu đề widget) hiển thị chương trình của người học dưới dạng một chuỗi các khóa học, mỗi khóa có thể mở rộng để xem mục tiêu và mức độ thành thạo của người học. Khi người học đạt được tiêu chuẩn của khóa học hiện tại (hoàn thành hoặc thành thạo một tỷ lệ cao các mục tiêu), [[tutorshort]] cũng hiển thị một biểu ngữ nhẹ nhàng "sẵn sàng cho khóa học tiếp theo" và đề cập đến điều đó trong cuộc trò chuyện. Chỉ mang tính tư vấn; sử dụng phân bổ chương trình của chính người học; lặng lẽ không làm gì khi không có chương trình nào áp dụng.';
$string['settings:learning_path_mastery_threshold'] = 'Ngưỡng sẵn sàng của lộ trình học tập (%)';
$string['settings:learning_path_mastery_threshold_desc'] = 'Tỷ lệ phần trăm các mục tiêu được theo dõi của khóa học mà người học phải thành thạo trước khi gợi ý lộ trình học tập xem họ đã sẵn sàng cho khóa học tiếp theo. Việc hoàn thành khóa học trên Moodle là yếu tố kích hoạt khác; điều nào xảy ra trước sẽ kích hoạt gợi ý. Mặc định là 80.';
$string['pathpanel_title'] = 'Lộ trình học tập của tôi';
$string['pathpanel_open'] = 'Lộ trình học tập của tôi';
$string['pathpanel_empty'] = 'Chưa có lộ trình chương trình nào cho khóa học này.';
$string['path_position'] = 'Khóa học {$a->index} trên {$a->total}';
$string['path_status_done'] = 'Hoàn thành';
$string['path_status_current'] = 'Bạn đang ở đây';
$string['path_status_upcoming'] = 'Sắp tới';
$string['path_mastery_mastered'] = 'Đã thành thạo';
$string['path_mastery_in_progress'] = 'Đang tiến hành';
$string['path_mastery_not_started'] = 'Chưa bắt đầu';
$string['path_mastery_demonstrated_elsewhere'] = 'Đã thể hiện ở khóa học khác';
$string['nudge_ready_title'] = 'Sẵn sàng tiếp tục';
$string['nudge_ready_body'] = 'Làm tốt lắm — bạn đã sẵn sàng cho {$a}.';
$string['nudge_view_path'] = 'Xem lộ trình của tôi';
$string['nudge_dismiss'] = 'Bỏ qua';

// v5.10.x strings (token-aware budgeting, backend retry, self-test, deployment presets, escalation consent, privacy).
$string['settings:backend_context_tokens'] = 'Cửa sổ ngữ cảnh của backend (token)';
$string['settings:backend_context_tokens_desc'] = 'Độ dài ngữ cảnh tối đa (max_model_len) của backend AI của bạn, tính bằng token. Đặt thành 0 cho các mô hình được lưu trữ có cửa sổ lớn (không giới hạn). Khi đặt trên 0 (ví dụ 8192 trên backend vLLM tự lưu trữ), [[tutorshort]] thu nhỏ ngân sách ký tự của lời nhắc hệ thống ở trên để lời nhắc cùng với đầu ra dự trữ và lịch sử hội thoại vừa với cửa sổ, ngay cả trong các ngôn ngữ có mật độ token cao. Xem trang wiki Deployment Sizing để biết cách điều này ánh xạ tới số người dùng đồng thời.';
$string['settings:backend_retry_attempts'] = 'Số lần thử lại với backend';
$string['settings:backend_retry_attempts_desc'] = 'Số lần thử lại một lỗi backend tạm thời (HTTP 429 hoặc 503) trước khi hiển thị lỗi cho học viên. Việc thử lại chỉ diễn ra trước khi bất kỳ văn bản phản hồi nào được truyền, vì vậy đầu ra không bao giờ bị trùng lặp. Hướng đến các backend nhỏ tự lưu trữ từ chối yêu cầu khi quá tải. Đặt thành 0 để tắt. Mặc định là 2.';
$string['settings:backend_retry_max_wait'] = 'Thời gian chờ tối đa khi thử lại backend (giây)';
$string['settings:backend_retry_max_wait_desc'] = 'Giới hạn trên, tính bằng giây, về thời gian tuân theo tiêu đề Retry-After từ backend trước khi thử lại. Khi backend không gửi Retry-After, [[tutorshort]] sử dụng một khoảng lùi theo cấp số nhân ngắn thay thế. Mặc định là 5.';
$string['prompt:truncation_hint'] = 'LƯU Ý: Không thể tìm kiếm toàn bộ nội dung khóa học trong lượt này do giới hạn độ dài. Nếu học viên hỏi về điều gì đó mà bạn không thể tìm thấy trong nội dung được cung cấp, hãy nói rằng bạn không thể tìm kiếm toàn bộ khóa học và đề xuất họ mở trang hoặc hoạt động cụ thể nơi chủ đề được đề cập, thay vì khẳng định rằng nó không có trong khóa học.';
$string['selftest:title'] = 'Tự kiểm tra backend';
$string['selftest:intro'] = 'Chạy kiểm tra trực tiếp backend AI đã cấu hình của bạn: một vòng trao đổi trò chuyện nhỏ, tự động phát hiện cửa sổ ngữ cảnh (max_model_len) và so sánh với cài đặt cửa sổ ngữ cảnh backend của bạn, mức sàn ngân sách lời nhắc hệ thống, và (khi bật RAG) một vòng trao đổi embedding. Các cuộc gọi mạng chỉ chạy khi bạn nhấn Chạy.';
$string['selftest:run'] = 'Chạy tự kiểm tra backend';
$string['selftest:check'] = 'Kiểm tra';
$string['selftest:status'] = 'Trạng thái';
$string['selftest:detail'] = 'Chi tiết';
$string['selftest:link'] = 'Trang tự kiểm tra backend';
$string['selftest:link_desc'] = 'Mở trang <a href="{$a}">Tự kiểm tra backend</a> để xác minh backend AI của bạn hoạt động và được định cỡ chính xác. Hữu ích ngay sau khi cấu hình một backend tự lưu trữ.';
$string['profile:title'] = 'Cài đặt sẵn cho triển khai';
$string['profile:intro'] = 'Áp dụng một gói cài đặt được đề xuất cho loại triển khai của bạn. Các giá trị được ghi vào cài đặt plugin thông thường và vẫn có thể chỉnh sửa riêng lẻ sau đó. Việc áp dụng một cài đặt sẵn sẽ ghi đè lên các cài đặt được liệt kê.';
$string['profile:current'] = 'Cài đặt sẵn được áp dụng gần nhất: {$a}';
$string['profile:setting'] = 'Cài đặt';
$string['profile:value'] = 'Giá trị';
$string['profile:self_hosted_small'] = 'Ngữ cảnh nhỏ tự lưu trữ (một GPU, ví dụ A30 24GB / vLLM ở 8K)';
$string['profile:hosted_large'] = 'Ngữ cảnh lớn được lưu trữ (mặc định)';
$string['profile:apply_self_hosted_small'] = 'Áp dụng cài đặt sẵn ngữ cảnh nhỏ tự lưu trữ';
$string['profile:apply_hosted_large'] = 'Áp dụng giá trị mặc định ngữ cảnh lớn được lưu trữ';
$string['profile:applied'] = 'Đã áp dụng cài đặt sẵn {$a}. Các giá trị hiện đã có trong cài đặt plugin của bạn.';
$string['profile:unknown'] = 'Cài đặt sẵn triển khai không xác định.';
$string['profile:link'] = 'Trang cài đặt sẵn cho triển khai';
$string['profile:link_desc'] = 'Mở trang <a href="{$a}">Cài đặt sẵn cho triển khai</a> để áp dụng một gói cài đặt được đề xuất cho một backend được lưu trữ hoặc tự lưu trữ.';
$string['settings:zendesk_require_consent'] = 'Yêu cầu sự đồng ý trước khi chuyển lên hỗ trợ';
$string['settings:zendesk_require_consent_desc'] = 'Khi bật (được khuyến nghị), [[tutorshort]] chỉ chuyển một cuộc trò chuyện lên bộ phận hỗ trợ Zendesk sau khi học viên đã chấp nhận thông báo đồng ý lần đầu, trong đó nêu rõ rằng việc yêu cầu trợ giúp từ con người sẽ chia sẻ cuộc trò chuyện (bao gồm tên và email) với bộ phận hỗ trợ. Chỉ tắt tùy chọn này nếu bạn có được sự đồng ý đó theo cách khác; khi tắt, các yêu cầu chuyển lên được gửi ngay lập tức. Không có hiệu lực trừ khi đã bật chuyển lên Zendesk.';
$string['chat:escalation_needs_consent'] = 'Có vẻ như việc này cần một thành viên trong nhóm hỗ trợ của chúng tôi. Để chuyển nó cho họ, tôi sẽ phải chia sẻ cuộc trò chuyện này, bao gồm tên và email của bạn, với bộ phận hỗ trợ. Bạn chưa đồng ý điều đó, nên tôi chưa gửi bất cứ thứ gì. Nếu bạn muốn được con người trợ giúp, vui lòng chấp nhận thông báo chia sẻ dữ liệu cho trợ lý này và hỏi lại, hoặc liên hệ trực tiếp với bộ phận hỗ trợ.';
$string['privacy:metadata:email_optout'] = 'Tùy chọn từ chối email theo từng người nhận (người nhận đã hủy đăng ký những loại email nào).';
$string['privacy:metadata:email_optout:email'] = 'Địa chỉ email của người nhận mà tùy chọn từ chối áp dụng.';
$string['privacy:metadata:email_optout:optout_type'] = 'Loại email mà người nhận đã từ chối.';
$string['privacy:metadata:email_optout:userid'] = 'Người dùng Moodle mà tùy chọn từ chối thuộc về, khi biết.';
$string['chat:consent_scroll_hint'] = 'Vui lòng cuộn xuống cuối để đọc toàn bộ thông báo trước khi tiếp tục.';
$string['settings:rag_min_similarity'] = 'Mức liên quan tối thiểu (cosine)';
$string['settings:rag_min_similarity_desc'] = 'Loại bỏ các đoạn được truy xuất có độ tương đồng cosine với câu hỏi thấp hơn giá trị này, để một câu hỏi lạc đề hoặc thưa thớt sẽ chèn ít (hoặc không) đoạn nào thay vì luôn lấp đầy đến Top-K bằng các kết quả khớp yếu. Phạm vi từ 0 đến 1; 0 vô hiệu hóa cổng lọc này. Giá trị phù hợp tùy thuộc vào mô hình embedding: 0.25 phù hợp với text-embedding-3-small. Tăng lên để nghiêm ngặt hơn (ít ngữ cảnh hơn, đúng chủ đề hơn), giảm xuống để dễ dãi hơn.';
$string['settings:rag_currentpage_boost'] = 'Tăng cường trang hiện tại';
$string['settings:rag_currentpage_boost_desc'] = 'Một phần thưởng nhỏ được cộng vào điểm liên quan của các đoạn từ trang mà người học đang xem, để những câu hỏi như "giải thích điều này" ưu tiên trang đang hiển thị khi điểm số gần nhau. Chỉ ảnh hưởng đến thứ tự sắp xếp: nó không ép một đoạn trang không liên quan vượt qua cổng lọc mức liên quan tối thiểu. Đặt 0 để vô hiệu hóa.';
$string['settings:history_mode'] = 'Chế độ chọn lịch sử';
$string['settings:history_mode_desc'] = 'Cách chọn các lượt hội thoại trước đây trước khi gửi đến mô hình. <strong>Ngữ nghĩa</strong> chỉ giữ lại các lượt gần đây liên quan đến câu hỏi hiện tại (và luôn giữ lượt trao đổi mới nhất), để một lượt cũ, lạc đề trước đó không làm tăng chi phí hoặc làm câu trả lời đi lệch hướng; nó thực hiện thêm một lệnh gọi embedding cho mỗi tin nhắn. <strong>Theo thời gian gần đây</strong> giữ lại số cặp "Max Conversation History" cuối cùng bất kể mức liên quan (hành vi lâu nay, không có lệnh gọi thêm). Nếu embedding không khả dụng, chế độ ngữ nghĩa tự động chuyển về theo thời gian gần đây.';
$string['settings:history_mode_semantic'] = 'Ngữ nghĩa (các lượt gần đây liên quan)';
$string['settings:history_mode_recency'] = 'Theo thời gian gần đây (N cặp cuối)';
$string['settings:history_semantic_minscore'] = 'Ngưỡng liên quan của lịch sử (cosine)';
$string['settings:history_semantic_minscore_desc'] = 'Trong chế độ lịch sử ngữ nghĩa, một lượt trước đây chỉ được giữ lại nếu độ tương đồng của nó với câu hỏi hiện tại ít nhất bằng giá trị này (lượt trao đổi mới nhất luôn được giữ). Phạm vi từ 0 đến 1; tùy thuộc vào mô hình. Tăng lên để nghiêm ngặt hơn (ít lịch sử hơn), giảm xuống để giữ lại nhiều hơn.';
$string['settings:history_candidates'] = 'Cửa sổ ứng viên lịch sử';
$string['settings:history_candidates_desc'] = 'Trong chế độ lịch sử ngữ nghĩa, chỉ chừng này cặp gần nhất được chấm điểm liên quan (một giới hạn chi phí). Các cặp cũ hơn cửa sổ này sẽ không được gửi. Hãy giữ giá trị này bằng hoặc lớn hơn "Max Conversation History".';

// v5.11.0 - v6.2.0 strings (added 2026-06-10).
$string['settings:embed_provider_voyage'] = 'Voyage AI (voyage-3.5 — khuyến nghị; +4 MTEB so với OpenAI 3-small, ngữ cảnh 4x, đa ngôn ngữ)';
$string['settings:rerank_heading'] = 'RAG: Truy xuất hai giai đoạn (xếp hạng lại)';
$string['settings:rerank_heading_desc'] = 'Giai đoạn truy xuất thứ hai tùy chọn: độ tương đồng cosine chọn ra N đoạn ứng viên hàng đầu (mặc định 50), sau đó bộ xếp hạng lại cross-encoder chấm điểm từng cặp (truy vấn, đoạn) và top-K tốt nhất đi vào prompt. Tắt theo mặc định; trở về cosine một giai đoạn nếu bộ xếp hạng lại chưa được cấu hình hoặc thất bại.';
$string['settings:rerank_enabled'] = 'Truy xuất hai giai đoạn (Voyage rerank-2.5)';
$string['settings:rerank_enabled_desc'] = 'Khi bật, RAG truy xuất trở thành hai giai đoạn: độ tương đồng cosine trả về N ứng viên hàng đầu (mặc định 50), sau đó Voyage rerank-2.5 cross-encoder chấm điểm từng ứng viên và top-K đi vào prompt. Cải tiến đã công bố: +15 Recall@10 doanh nghiệp, +39% NDCG BEIR. Tính phí ~$0,05/MTok. Yêu cầu <code>rerank_apikey</code> bên dưới; trở về cosine một giai đoạn an toàn nếu xếp hạng lại thất bại hoặc chưa được cấu hình.';
$string['settings:rerank_apikey'] = 'Khóa API Rerank';
$string['settings:rerank_apikey_desc'] = 'Khóa API Voyage AI cho rerank-2.5. Để trống để tái sử dụng Khóa API nhúng ở trên (các triển khai Voyage thông thường dùng chung một khóa cho cả embed + rerank).';
$string['settings:rerank_model'] = 'Mô hình Rerank';
$string['settings:rerank_model_desc'] = 'Mặc định <code>rerank-2.5</code>. Có thể chỉ định các mô hình xếp hạng lại Voyage mới hơn tại đây.';
$string['settings:rerank_apibaseurl'] = 'URL cơ sở API Rerank';
$string['settings:rerank_apibaseurl_desc'] = 'Ghi đè URL cơ sở Voyage rerank. Để trống để dùng URL cơ sở API nhúng ở trên, hoặc mặc định của Voyage (<code>https://api.voyageai.com/v1</code>).';
$string['settings:rerank_candidates'] = 'Cửa sổ ứng viên Rerank';
$string['settings:rerank_candidates_desc'] = 'Số lượng ứng viên cosine top-N đưa vào giai đoạn rerank. Mặc định 50. Cửa sổ lớn hơn cung cấp cho bộ xếp hạng lại nhiều tài liệu hơn để làm việc với chi phí bổ sung nhỏ (~10k token mỗi lần rerank).';
$string['settings:stt_selfhosted_heading'] = 'Phiên âm tự lưu trữ (Whisper)';
$string['settings:stt_selfhosted_heading_desc'] = 'Chạy chuyển giọng nói thành văn bản trên phần cứng của bạn với chi phí bằng không mỗi phút. Trỏ [[tutorshort]] đến bất kỳ máy chủ phiên âm tương thích OpenAI nào: <code>whisper-server</code> Docker, <code>speaches</code> (faster-whisper) hoặc máy chủ <code>whisper.cpp</code>. Khi URL máy chủ được thiết lập tại đây, nó trở thành đường dẫn STT mặc định; chọn nhà cung cấp trả phí trong Nhà cung cấp STT đang hoạt động ở trên để ghi đè. Nếu máy chủ nằm trong mạng riêng hoặc dùng http thuần, hãy thêm máy chủ đó vào danh sách được phép của SSRF trusted endpoints trong phần Bảo mật.';
$string['settings:stt_selfhosted_url'] = 'URL máy chủ Selfhosted STT';
$string['settings:stt_selfhosted_url_desc'] = 'URL cơ sở của máy chủ phiên âm tương thích OpenAI, ví dụ <code>http://10.0.0.5:8000</code>. [[tutorshort]] tự động thêm <code>/v1/audio/transcriptions</code>; đường dẫn endpoint đầy đủ cũng được chấp nhận. Để trống để tắt selfhosted STT.';
$string['settings:stt_selfhosted_model'] = 'Mô hình Selfhosted STT';
$string['settings:stt_selfhosted_model_desc'] = 'Tên mô hình được gửi đến máy chủ, khớp với mô hình Whisper mà máy chủ đã tải — ví dụ <code>Systran/faster-whisper-small</code> cho speaches hoặc <code>large-v3</code>. Để trống để gửi <code>whisper-1</code>, mà hầu hết các máy chủ tự lưu trữ đều chấp nhận hoặc bỏ qua.';
$string['settings:stt_selfhosted_apikey'] = 'Khóa API Selfhosted STT';
$string['settings:stt_selfhosted_apikey_desc'] = 'Tùy chọn. Hầu hết các máy chủ tự lưu trữ không cần khóa khi ở sau mạng tin cậy; chỉ đặt khóa này nếu máy chủ của bạn yêu cầu bearer token.';
$string['emergency:title'] = 'Điều khiển khẩn cấp [[tutorshort]]';
$string['emergency:page_warning'] = 'Các công tắc này có hiệu lực ngay lập tức với mọi học viên trên trang. Mỗi hành động ghi một hàng kiểm toán. Các công tắc chi tiết cho phép phần còn lại của [[tutorshort]] tiếp tục chạy; công tắc tắt tổng thể xóa hoàn toàn widget.';
$string['emergency:back_to_settings'] = 'Cài đặt [[tutorshort]]';
$string['emergency:state_disabled'] = 'ĐÃ TẮT';
$string['emergency:state_active'] = 'Đang hoạt động';
$string['emergency:confirm_label'] = 'Tôi hiểu rằng điều này ảnh hưởng đến mọi học viên ngay lập tức';
$string['emergency:confirm_required'] = 'Vui lòng tích vào ô xác nhận trước khi tắt một hệ thống con.';
$string['emergency:reason_placeholder'] = 'Lý do (được ghi vào nhật ký kiểm toán)';
$string['emergency:disable_button'] = 'Tắt';
$string['emergency:restore_button'] = 'Khôi phục';
$string['emergency:disabled_notice'] = 'Hệ thống con "{$a->flag}" đã bị tắt. Cấu hình đã chạm: {$a->touched}';
$string['emergency:restored_notice'] = 'Hệ thống con "{$a->flag}" đã được khôi phục. Cấu hình đã chạm: {$a->touched}';
$string['emergency:cli_reference'] = 'Các điều khiển tương tự cũng có sẵn từ shell trực chiến:';
$string['emergency:flag_chat'] = 'Trò chuyện';
$string['emergency:flag_chat_desc'] = 'Chặn lưu lượng chat qua kill flag chuyên dụng (bản sửa lỗi v5.13). Widget vẫn hiển thị; học viên thấy thông báo thân thiện "[[tutorshort]] đã tạm dừng". Sử dụng khi nhà cung cấp LLM gặp sự cố hoặc đang xảy ra tăng đột biến chi phí.';
$string['emergency:flag_voice'] = 'Giọng nói';
$string['emergency:flag_voice_desc'] = 'Xóa nhà cung cấp giọng nói realtime đang hoạt động (được lưu để khôi phục chính xác). Chat văn bản tiếp tục hoạt động.';
$string['emergency:flag_rag'] = 'RAG';
$string['emergency:flag_rag_desc'] = 'Tắt truy xuất và lập chỉ mục. Chat tiếp tục mà không có cơ sở nội dung khóa học.';
$string['emergency:flag_outreach'] = 'Outreach';
$string['emergency:flag_outreach_desc'] = 'Dừng gửi email tóm tắt, mốc quan trọng và nhắc nhở. Chat không bị ảnh hưởng.';
$string['emergency:flag_all'] = 'TẮT TOÀN BỘ';
$string['emergency:flag_all_desc'] = 'Vô hiệu hóa toàn bộ plugin: widget biến mất khỏi mọi trang, các tác vụ được lên lịch dừng lại, giọng nói bị xóa, RAG tắt, outreach tắt. Công tắc mạnh nhất — sử dụng trong trường hợp sự cố bảo mật hoặc khi [[tutorshort]] cần đưa ngoại tuyến ngay lập tức.';
$string['emergency:settings_link'] = 'Điều khiển khẩn cấp';
$string['emergency:settings_link_desc'] = 'Công tắc tắt từng hệ thống con (chat / giọng nói / RAG / outreach / tổng thể) với nhật ký kiểm toán — tương đương web của <code>admin/cli/emergency_disable.php</code>. Mở <a href="{$a}">Điều khiển khẩn cấp [[tutorshort]]</a>.';
$string['email_unsubscribe:done_title'] = 'Đã hủy đăng ký';
$string['email_unsubscribe:done_body'] = 'Xong — {$a->email} sẽ không còn nhận loại email này từ {$a->product} nữa. Nếu bạn thay đổi ý định, hãy nhờ quản trị viên {$a->product} kích hoạt lại đăng ký, hoặc gửi xác nhận mới qua trang quản trị [[tutorshort]] Recipients.';
$string['email_unsubscribe:invalid_title'] = 'Liên kết hủy đăng ký không còn hợp lệ';
$string['email_unsubscribe:invalid_body'] = 'Liên kết hủy đăng ký này đã hết hạn hoặc không đúng định dạng. Hãy tìm email gần đây hơn từ chúng tôi, hoặc liên hệ quản trị viên trang để được xóa thủ công.';
$string['settings:prompt_proportions_heading'] = 'Tỷ lệ phần của prompt (v5.6.0)';
$string['settings:prompt_proportions_heading_desc'] = 'Phân bổ ngân sách system prompt cho bốn nhóm: an toàn + nhận dạng, cấu trúc khóa học, nội dung khóa học và trang hiện tại. Trọng số là các phần trăm có tổng bằng 100. Các giá trị mặc định được tinh chỉnh thực nghiệm (10 / 10 / 40 / 40) đến từ chuẩn điều chỉnh trọng số v5.6.0; để trống textarea sẽ sử dụng các giá trị mặc định đó. Tăng cường tự động điều chỉnh phân bổ mỗi lượt tùy thuộc vào việc có trang cụ thể nào trong phạm vi hay không.';
$string['settings:prompt_section_weights'] = 'Trọng số phần cơ sở (JSON)';
$string['settings:prompt_section_weights_desc'] = 'Đối tượng JSON tùy chọn ánh xạ từng nhóm đến một phần trăm. Để trống để sử dụng các giá trị mặc định đã chuẩn hóa (10 / 10 / 40 / 40). Ví dụ: <code>{"safety_identity": 10, "course_structure": 10, "course_content": 40, "current_page": 40}</code>. Trọng số phải có tổng bằng 100 (±5%). <code>safety_identity</code> có mức sàn là 10% để kháng jailbreak và các marker định dạng đầu ra luôn được đưa vào đầy đủ. <code>current_page + course_content</code> phải ít nhất 40% để mô hình có đủ tài liệu để dựa vào. Các giá trị ngoài phạm vi sẽ âm thầm trở về các giá trị mặc định đã chuẩn hóa; quản trị viên nên xác nhận bằng cách kiểm tra nhật ký debug prompt sau khi lưu.';
$string['settings:prompt_context_boost_mode'] = 'Chế độ tăng cường ngữ cảnh';
$string['settings:prompt_context_boost_mode_desc'] = 'Điều chỉnh tự động chuyển trọng số về phần trang hiện tại khi một trang cụ thể trong phạm vi, và về nội dung khóa học khi không có trang nào được chọn. <strong>page_focus</strong> (mặc định) chuyển khoảng 15 điểm trọng số. <strong>aggressive</strong> chuyển 25 điểm và phù hợp nhất khi học viên liên tục đặt câu hỏi về trang cụ thể. <strong>off</strong> tắt tăng cường; các trọng số cơ sở do quản trị viên đặt áp dụng cho mỗi lượt bất kể ngữ cảnh trang.';
$string['settings:prompt_context_boost_off'] = 'Tắt (sử dụng trọng số cơ sở mỗi lượt)';
$string['settings:prompt_context_boost_page_focus'] = 'Tập trung trang (mặc định, ~15 điểm chuyển)';
$string['settings:prompt_context_boost_aggressive'] = 'Tích cực (~25 điểm chuyển)';
$string['settings:prompt_section_weights_coach'] = 'Ghi đè chế độ coach (JSON, tùy chọn)';
$string['settings:prompt_section_weights_coach_desc'] = 'Đối tượng JSON tùy chọn ghi đè trọng số phần cơ sở riêng trong chế độ coach bài kiểm tra được chấm điểm (khi <code>quizmode=coach</code>). Hữu ích để buộc phân bổ <code>current_page</code> nặng hơn trong các bài kiểm tra mà không ảnh hưởng đến chat thông thường. Để trống để kế thừa trọng số cơ sở. Quy tắc xác thực giống như cài đặt cơ sở.';
$string['prompt_debug_view:title'] = 'Trình xem nhật ký debug prompt';
$string['prompt_debug_view:subtitle'] = 'System prompt được lắp ráp mỗi lượt + phân tích theo phần + lịch sử hội thoại + tin nhắn người dùng hiện tại + metadata tệp đính kèm, chính xác như mô hình đã nhận. Sử dụng để xác minh xem một phần như Nội dung Trang Hiện tại có thực sự được đưa vào prompt không và để debug các vấn đề chất lượng câu trả lời mà không cần SSH vào máy chủ.';
$string['prompt_debug_view:disabled'] = 'Ghi nhật ký debug prompt hiện đang TẮT. Không có mục mới nào được ghi cho đến khi bật.';
$string['prompt_debug_view:enable_link'] = 'Mở cài đặt plugin để bật "Ghi system prompt đã lắp ráp vào tệp".';
$string['prompt_debug_view:no_log_yet'] = 'Chưa có tệp nhật ký. Gửi ít nhất một lượt chat sau khi bật nhật ký debug; tệp được tạo khi ghi lần đầu.';
$string['prompt_debug_view:empty'] = 'Tệp nhật ký tồn tại nhưng trống. Gửi một lượt chat và làm mới.';
$string['prompt_debug_view:file_status'] = 'Kích thước tệp nhật ký';
$string['prompt_debug_view:showing'] = 'Hiển thị các mục gần đây nhất (mới nhất trước), giới hạn';
$string['prompt_debug_view:total'] = 'Tổng prompt';
$string['prompt_debug_view:budget'] = 'Ngân sách tại thời điểm ghi';
$string['prompt_debug_view:sections'] = 'Các phần (theo danh mục)';
$string['prompt_debug_view:assembled_prompt'] = 'System prompt đã lắp ráp';
$string['prompt_debug_view:history'] = 'Lịch sử hội thoại được gửi đến mô hình';
$string['prompt_debug_view:current_message'] = 'Tin nhắn người dùng hiện tại';
$string['prompt_debug_view:attachment'] = 'Metadata tệp đính kèm';
$string['prompt_debug_view:show_more'] = 'Hiển thị thêm mục';
$string['settings:mastery_classifier_provider'] = 'Nhà cung cấp classifier';
$string['settings:mastery_classifier_provider_desc'] = 'ID nhà cung cấp được dùng cho classifier thành thạo mỗi lượt. Để trống để kế thừa nhà cung cấp AI mặc định. Mặc định <code>openai</code> kết hợp với mô hình classifier <code>gpt-4o-mini</code> bên dưới — tùy chọn rẻ nhất ở TIER 1 cho phân loại đầu ra có cấu trúc (tiết kiệm ~$220/tháng ở 100k MAU so với chat tier). Khi được đặt, hàng trong Nhà cung cấp so sánh với ID nhà cung cấp này cung cấp khóa API, URL cơ sở và nhiệt độ.';
$string['settings:premium_escalation_heading'] = 'Cấp leo thang cao cấp (A.10)';
$string['settings:premium_escalation_heading_desc'] = 'Định tuyến tùy chọn mỗi lượt đến mô hình cao cấp (Claude Opus 4.8 theo mặc định) cho các prompt mà chat tier chính gặp khó khăn rõ ràng — thường là toán học nhiều bước, CS và lý luận khoa học. Được giải quyết bởi A.10 bake-off ngày 2026-06-09: Opus 4.8 thắng 14,97/15 so với 12,68/15 của gpt-4o trên các prompt khó. Hai đường kích hoạt: regex khớp với tin nhắn người dùng, HOẶC danh sách cho phép khóa học leo thang mỗi lượt. Tắt theo mặc định. Với ~5% leo thang, kỳ vọng ~$700/tháng ở 100k [[unishort]] MAU trên chi phí chat cơ sở.';
$string['settings:premium_escalation_enabled'] = 'Bật định tuyến leo thang cao cấp';
$string['settings:premium_escalation_enabled_desc'] = 'Khi bật, bộ định tuyến mỗi lượt kiểm tra danh sách regex kích hoạt và danh sách cho phép khóa học cho mỗi lần gọi chat; các lượt khớp được định tuyến đến nhà cung cấp cao cấp. Trở về nhà cung cấp chính nếu hàng cao cấp bị thiếu hoặc không thể khởi tạo. Các ghi đè admin-LLM-picker luôn thắng bất kể điều gì.';
$string['settings:premium_escalation_provider'] = 'Nhà cung cấp cao cấp';
$string['settings:premium_escalation_provider_desc'] = 'ID nhà cung cấp để định tuyến các lệnh gọi cao cấp. Phải khớp với một hàng trong Nhà cung cấp so sánh (để khóa API, URL cơ sở và nhiệt độ đến từ cùng nơi quản trị viên đã quản lý). Mặc định <code>claude</code>.';
$string['settings:premium_escalation_model'] = 'Mô hình cao cấp';
$string['settings:premium_escalation_model_desc'] = 'Tên mô hình được truyền đến nhà cung cấp cao cấp. Mặc định <code>claude-opus-4-8</code> theo phán quyết A.10 bake-off.';
$string['settings:premium_escalation_triggers'] = 'Các regex kích hoạt cao cấp';
$string['settings:premium_escalation_triggers_desc'] = 'Một PCRE regex mỗi dòng (không có dấu phân cách; khớp không phân biệt chữ hoa thường được áp dụng tự động). Các dòng bắt đầu bằng # là chú thích. Để trống để sử dụng tập mặc định được chọn lọc từ A.10 bake-off (các marker STEM nhiều bước: "derive", "prove that", "step by step", LaTeX math, fenced code block, big-O, tích phân, tối ưu hóa, v.v.).';
$string['settings:premium_escalation_course_tags'] = 'Danh sách cho phép khóa học cao cấp';
$string['settings:premium_escalation_course_tags_desc'] = 'Một tiền tố tên ngắn hoặc ID số của khóa học mỗi dòng. Mọi lượt trong khóa học khớp đều tự động leo thang bất kể regex tin nhắn (sử dụng cho các khóa học nặng STEM nơi leo thang nên là mặc định). Khớp là tiền tố không phân biệt chữ hoa thường — "MATH" khớp MATH121, MATH205, v.v.';
$string['settings:spend_cap_per_course_default'] = 'Giới hạn chi tiêu mặc định mỗi khóa học (USD)';
$string['settings:spend_cap_per_course_default_desc'] = 'Giới hạn phòng thủ áp dụng cho mọi khóa học không có giới hạn chi tiêu mỗi khóa học riêng được cấu hình. Đặt thành ví dụ <code>30</code> để giới hạn chi tiêu hàng tháng của bất kỳ khóa học nào ở $30 mà không cần điều chỉnh từng khóa. <code>0</code> = không có mặc định (chỉ áp dụng giới hạn toàn trang và ghi đè mỗi khóa học). Khi một khóa học vượt 80% / 95% / 100% giới hạn này, đường ống cảnh báo spend-guard hiện có gửi thông báo quản trị viên (danh sách người nhận: <code>spend_notify_emails</code>, mặc định về quản trị viên trang). Một khóa học cụ thể luôn có thể nâng trần của mình bằng cách đặt giá trị ghi đè mỗi khóa học cao hơn.';
$string['settings:cost_anomaly_heading'] = 'Bộ phát hiện bất thường chi phí (v6.0)';
$string['settings:cost_anomaly_heading_desc'] = 'Tác vụ được lên lịch hàng ngày (<code>cost_anomaly_check</code>) so sánh chi tiêu [[tutorshort]] toàn trang hôm nay với trung vị 7 ngày liên tiếp. Gửi email đến danh sách người nhận <code>spend_notify_emails</code> (mặc định về quản trị viên trang) khi hôm nay vượt quá số nhân được cấu hình × trung vị. Phát hiện ba chế độ lỗi mà các ngưỡng 80% / 95% / 100% hiện có bỏ lỡ: (1) khóa học mất kiểm soát nơi trần tuyệt đối không bị vượt nhưng một khóa đột nhiên tạo ra lưu lượng gấp 10 lần bình thường, (2) vô tình bật cấp cao cấp, (3) nhà cung cấp bị định tuyến sai. Tắt theo mặc định; tương đương trong [[tutorshort]] của truy vấn Redash tại <code>.drafts/sola-redash-cost-anomaly-2026-06-09.md</code>.';
$string['settings:cost_anomaly_enabled'] = 'Bật bộ phát hiện bất thường chi phí';
$string['settings:cost_anomaly_enabled_desc'] = 'Khi bật, tác vụ được lên lịch hàng ngày đánh giá chi tiêu hôm nay so với trung vị 7 ngày liên tiếp và gửi email cho quản trị viên khi phát hiện bất thường. 7 ngày đầu sau khi bật tạo ra trạng thái <code>insufficient_history</code> (chưa có dữ liệu lịch sử cơ sở) và không bao giờ phát cảnh báo. Bất biến mỗi ngày: một flag trong <code>config_plugins</code> ngăn các email lặp lại nếu cron chạy nhiều lần.';
$string['settings:cost_anomaly_multiplier'] = 'Số nhân bất thường';
$string['settings:cost_anomaly_multiplier_desc'] = 'Chi tiêu hôm nay phải vượt số nhân này × trung vị 7 ngày để kích hoạt cảnh báo. Mặc định <code>2.0</code>. Giảm xuống <code>1.5</code> để cảnh báo sớm hơn (nhiều dương tính giả hơn trong các đợt đăng ký tăng đột biến). Tăng lên <code>3.0</code> nếu việc sử dụng [[unishort]] đủ biến động đến mức tăng đột biến 2x là bình thường.';
$string['task:cost_anomaly_check'] = 'Kiểm tra bất thường chi phí [[tutorshort]] (hàng ngày)';

$string['settings:policy_bundle_heading'] = 'Gói chính sách đã ký (cập nhật hành vi từ xa)';
$string['settings:policy_bundle_heading_desc'] = 'Áp dụng cài đặt hành vi (lời nhắc, định tuyến, bộ kích hoạt leo thang, điều chỉnh RAG, chính sách chi tiêu) từ tệp JSON được ký bằng mã hóa mà không cần triển khai mã. Nhiệm vụ được lên lịch hàng ngày tải URL của gói, xác minh chữ ký Ed25519 của nó với khóa công khai bên dưới và chỉ áp dụng cài đặt nếu mọi khóa đều có trong danh sách cho phép tích hợp sẵn và phiên bản gói mới hơn phiên bản được áp dụng gần nhất. Khóa API, URL, webhook và cài đặt bảo mật không bao giờ có thể được đặt bởi gói. Tạo và ký gói với <code>admin/cli/policy_bundle_tool.php</code> (keygen, sign, verify, status, sync).';
$string['settings:policy_bundle_enabled'] = 'Bật đồng bộ gói chính sách';
$string['settings:policy_bundle_enabled_desc'] = 'Khi được bật, nhiệm vụ hàng ngày tải và áp dụng các gói đã ký. Tắt theo mặc định. Tắt tính năng này sẽ dừng ngay lập tức tất cả các đồng bộ; cài đặt đã áp dụng trước đó vẫn giữ nguyên giá trị.';
$string['settings:policy_bundle_url'] = 'URL gói chính sách';
$string['settings:policy_bundle_url_desc'] = 'URL HTTPS của JSON gói đã ký (ví dụ: đối tượng S3 hoặc GitHub raw URL). URL được xác thực SSRF giống như các điểm cuối nhà cung cấp AI; máy chủ mạng riêng tư hoặc plain-http cần có mục nhập trong danh sách cho phép các điểm cuối tin cậy SSRF.';
$string['settings:policy_bundle_pubkey'] = 'Khóa công khai gói chính sách';
$string['settings:policy_bundle_pubkey_desc'] = 'Khóa công khai Base64 Ed25519 dùng để xác minh chữ ký gói. Tạo cặp khóa bằng <code>policy_bundle_tool.php --keygen</code>; khóa riêng tư vẫn ở với tác giả gói và không bao giờ được tải lên bất kỳ đâu.';
$string['settings:policy_bundle_status'] = 'Lần đồng bộ cuối';
$string['settings:policy_bundle_applied_version'] = 'phiên bản đã áp dụng';
$string['task:policy_bundle_sync'] = '[[tutorshort]] đồng bộ gói chính sách đã ký';
$string['policy_bundle:invalid'] = 'Gói chính sách bị từ chối: {$a}';

// v6.4.0 signed policy bundle strings (added 2026-06-11).
$string['settings:policy_bundle_heading'] = 'Gói chính sách đã ký (cập nhật hành vi từ xa)';
$string['settings:policy_bundle_heading_desc'] = 'Áp dụng các cài đặt hành vi (lời nhắc, định tuyến, trình kích hoạt leo thang, tinh chỉnh RAG, chính sách chi tiêu) từ tệp JSON được ký mã hóa mà không cần triển khai mã. Tác vụ được lên lịch hàng ngày tải URL của gói, xác minh chữ ký Ed25519 với khóa công khai bên dưới và chỉ áp dụng các cài đặt khi mỗi khóa nằm trong danh sách cho phép tích hợp sẵn và phiên bản gói mới hơn phiên bản đã áp dụng lần cuối. Khóa API, URL, webhook và cài đặt bảo mật không bao giờ có thể được đặt bởi một gói. Tạo và ký gói bằng <code>admin/cli/policy_bundle_tool.php</code> (keygen, sign, verify, status, sync).';
$string['settings:policy_bundle_enabled'] = 'Bật đồng bộ hóa gói chính sách';
$string['settings:policy_bundle_enabled_desc'] = 'Khi được bật, tác vụ hàng ngày tải và áp dụng các gói đã ký. Tắt theo mặc định. Tắt sẽ dừng tất cả các lần đồng bộ ngay lập tức; các cài đặt đã áp dụng giữ nguyên giá trị của chúng.';
$string['settings:policy_bundle_url'] = 'URL gói chính sách';
$string['settings:policy_bundle_url_desc'] = 'URL HTTPS của JSON gói đã ký (ví dụ: đối tượng S3 hoặc GitHub raw URL). URL trải qua cùng một xác thực SSRF như các endpoint của nhà cung cấp AI; các máy chủ mạng riêng tư hoặc plain-http cần có mục trong danh sách cho phép endpoint SSRF đáng tin cậy.';
$string['settings:policy_bundle_pubkey'] = 'Khóa công khai gói chính sách';
$string['settings:policy_bundle_pubkey_desc'] = 'Khóa công khai Base64 Ed25519 dùng để xác minh chữ ký của gói. Tạo cặp khóa bằng <code>policy_bundle_tool.php --keygen</code>; khóa riêng tư ở lại với tác giả gói và không bao giờ được tải lên bất kỳ đâu.';
$string['settings:policy_bundle_status'] = 'Lần đồng bộ cuối';
$string['settings:policy_bundle_applied_version'] = 'phiên bản đã áp dụng';
$string['task:policy_bundle_sync'] = '[[tutorshort]] đồng bộ hóa gói chính sách đã ký';
$string['policy_bundle:invalid'] = 'Gói chính sách bị từ chối: {$a}';
$string['prompt_debug_view:retrieved_chunks'] = 'Các đoạn được truy xuất (lựa chọn RAG)';
$string['prompt_debug_view:retrieved_chunks_hint'] = 'Các đoạn văn mà bộ truy xuất đã chọn cho câu hỏi này, sắp xếp theo thứ hạng cùng với điểm liên quan và nguồn (cmid). Dùng thông tin này để xác minh rằng mô hình đã nhận được nội dung khóa học phù hợp nhất.';
$string['settings:avatar_animation_enabled'] = 'Hoạt ảnh avatar';
$string['settings:avatar_animation_enabled_desc'] = 'Hoạt ảnh hóa avatar SVG được tạo: nhấp nháy khi không hoạt động, cộng với chuyển động miệng được đồng bộ với âm thanh chuyển văn bản thành giọng nói khi trợ lý nói. Tôn trọng tùy chọn chuyển động giảm của thiết bị người học. Ghi đè theo khóa học để đo lường A/B: đặt giá trị cấu hình avatar_animation_course_COURSEID thành 0 hoặc 1.';
$string['analytics:exp_heading'] = 'So sánh thí nghiệm A/B';
$string['analytics:exp_desc'] = 'So sánh mức độ tương tác giữa hai khóa học trong khoảng thời gian đã chọn. Được xây dựng cho các thí nghiệm theo từng khóa học (ví dụ như thử nghiệm hoạt hình avatar): đặt ghi đè vào một khóa học, để khóa còn lại làm đối chứng và đọc sự khác biệt ở đây.';
$string['analytics:exp_course_a'] = 'Khóa học A';
$string['analytics:exp_course_b'] = 'Khóa học B';
$string['analytics:exp_compare'] = 'So sánh';
$string['analytics:exp_metric'] = 'Chỉ số';
$string['analytics:exp_delta'] = 'B vs A';
$string['analytics:exp_enrolled'] = 'Học viên đã đăng ký';
$string['analytics:exp_active_users'] = 'Người dùng [[tutorshort]] đang hoạt động';
$string['analytics:exp_usage_rate'] = 'Tỷ lệ sử dụng (%)';
$string['analytics:exp_sessions'] = 'Phiên';
$string['analytics:exp_messages'] = 'Tin nhắn';
$string['analytics:exp_avg_msgs_session'] = 'Tin nhắn trung bình mỗi phiên';
$string['analytics:exp_avg_session_minutes'] = 'Thời lượng phiên trung bình (phút)';
$string['analytics:exp_return_rate'] = 'Người dùng quay lại (%)';
$string['analytics:exp_tts_plays'] = 'Lượt phát TTS';
$string['analytics:exp_tts_per_active'] = 'Lượt phát TTS trên mỗi người dùng hoạt động';

$string['settings:redash_allowed_origin'] = 'Nguồn gốc được phép cho Redash (CORS)';
$string['settings:redash_allowed_origin_desc'] = 'Để trống (khuyến nghị): bản xuất được Redash lấy theo phương thức máy chủ tới máy chủ và không cần tiêu đề CORS của trình duyệt. Chỉ đặt một nguồn gốc chính xác duy nhất (ví dụ https://redash.example.org) nếu một bảng điều khiển dựa trên trình duyệt phải đọc trực tiếp bản xuất. Không bao giờ sử dụng ký tự đại diện.';

// Soapbox speech practice (v6.7.0).
$string['privacy:metadata:local_ai_course_assistant_practice_scores:session_meta'] = 'Siêu dữ liệu tùy chọn mà bạn cung cấp cho phiên, chẳng hạn như tên, chủ đề và độ dài mục tiêu của một bài nói Soapbox. Không bao giờ bao gồm âm thanh hoặc bản ghi.';
$string['pedagogy:soapbox'] = 'Bật phản hồi bài nói Soapbox theo mặc định';
$string['pedagogy:soapbox_desc'] = 'Khi bật, công cụ luyện nói Soapbox sẽ có sẵn trong mọi khóa học trừ khi khóa học có ghi đè riêng. Hãy để tắt và chỉ bật nó trong những khóa học cần đến (thường là các khóa học về nói và giao tiếp).';
$string['settings:soapbox_stt_mode'] = 'Chế độ phiên âm Soapbox';
$string['settings:soapbox_stt_mode_desc'] = 'Cách Soapbox chuyển một bài nói đã ghi thành văn bản. Máy chủ sử dụng nhà cung cấp Whisper đã cấu hình (tự lưu trữ là miễn phí; OpenAI lưu trữ khoảng USD 0.006 mỗi phút). Trình duyệt sử dụng tính năng nhận dạng giọng nói tích hợp sẵn của người học (miễn phí, không cần máy chủ, chỉ hoạt động trong Chrome và Safari). Khuyến nghị dùng Máy chủ để chất lượng phiên âm không phụ thuộc vào trình duyệt của người học.';
$string['settings:soapbox_stt_mode_server'] = 'Máy chủ (nhà cung cấp Whisper)';
$string['settings:soapbox_stt_mode_browser'] = 'Trình duyệt (miễn phí, không cần máy chủ)';
$string['soapbox:title'] = 'Soapbox';
$string['soapbox:link'] = 'Luyện nói Soapbox';
$string['soapbox:disabled'] = 'Soapbox chưa được bật cho khóa học này.';
$string['soapbox:intro'] = 'Trình bày một bài nói và nhận hướng dẫn. Bạn có thể tùy chọn đặt tên, chủ đề và độ dài mục tiêu, rồi ghi âm phần nói của mình. Soapbox phiên âm bài nói của bạn, chấm điểm theo một thang đánh giá kỹ năng nói, và đưa ra những lời khuyên cụ thể. Âm thanh và bản ghi của bạn không bao giờ được lưu trữ, chỉ điểm số và phản hồi của bạn được lưu.';
$string['soapbox:optional'] = 'tùy chọn';
$string['soapbox:name_label'] = 'Đặt tên cho bài nói này';
$string['soapbox:topic_label'] = 'Chủ đề';
$string['soapbox:time_label'] = 'Độ dài mục tiêu';
$string['soapbox:no_target'] = 'Không có mục tiêu';
$string['soapbox:record'] = 'Ghi âm bài nói';
$string['soapbox:stop'] = 'Dừng và nhận phản hồi';
$string['soapbox:recording'] = 'Đang ghi âm. Hãy nói tự nhiên; nhấp dừng khi bạn hoàn thành.';
$string['soapbox:transcribing'] = 'Đang phiên âm bài nói của bạn…';
$string['soapbox:scoring'] = 'Đang chấm điểm bài nói của bạn…';
$string['soapbox:too_short'] = 'Bản ghi đó quá ngắn để chấm điểm. Hãy cố gắng nói ít nhất một hai câu và thử lại.';
$string['soapbox:mic_denied'] = 'Cần quyền truy cập micrô để ghi âm. Hãy cho phép truy cập micrô và thử lại.';
$string['soapbox:no_browser_stt'] = 'Trình duyệt này không hỗ trợ nhận dạng giọng nói trong trình duyệt. Hãy thử Chrome hoặc Safari, hoặc đề nghị quản trị viên của bạn chuyển Soapbox sang phiên âm trên máy chủ.';
$string['soapbox:browser_note'] = 'Bài nói này được phiên âm trong trình duyệt của bạn. Không có gì được tải lên. Hoạt động tốt nhất trong Chrome và Safari.';
$string['soapbox:server_note'] = 'Bản ghi của bạn chỉ được tải lên để phiên âm và không được lưu trữ.';
$string['soapbox:error'] = 'Hiện không thể chấm điểm bài nói này. Hãy thử lại sau giây lát.';
$string['soapbox:audio_too_large'] = 'Bản ghi đó quá lớn. Hãy giữ các bài nói dưới khoảng 25 MB (khoảng 20 phút).';
$string['soapbox:no_stt'] = 'Chưa cấu hình nhà cung cấp phiên âm nào. Hãy đề nghị quản trị viên của bạn thiết lập Whisper hoặc bật phiên âm trên trình duyệt.';
$string['soapbox:result_heading'] = 'Điểm theo thang đánh giá';
$string['soapbox:overall_heading'] = 'Tổng thể';
$string['soapbox:tips_heading'] = 'Lời khuyên cho lần sau';
$string['soapbox:col_criterion'] = 'Tiêu chí';
$string['soapbox:col_score'] = 'Điểm';
$string['soapbox:col_feedback'] = 'Phản hồi';
$string['soapbox:history_heading'] = 'Các bài nói của tôi';
$string['soapbox:history_empty'] = 'Bạn chưa ghi âm bài nói nào. Hãy ghi âm một bài ở trên để bắt đầu xây dựng lịch sử của bạn.';
$string['soapbox:untitled'] = 'Bài nói chưa đặt tên';
$string['soapbox:overall_badge'] = 'Tổng thể {$a}';
$string['soapbox:toggle'] = 'Bật Soapbox cho khóa học này';
$string['soapbox:toggle_help'] = 'Người học có một trang riêng để ghi âm một bài nói và nhận phản hồi kỹ năng nói được chấm điểm theo thang đánh giá kèm lời khuyên. Âm thanh và bản ghi không bao giờ được lưu trữ. Tắt theo mặc định.';
// Soapbox course-type/level + sample loader (v6.7.0).
$string['soapbox:level_label'] = 'Loại khóa học / trình độ nói';
$string['soapbox:level_help'] = 'Điều chỉnh phần huấn luyện AI và khung tiêu chí mẫu mặc định cho phù hợp với loại khóa học. Các cấp độ ESL nhận phản hồi học ngôn ngữ; phần nói tổng quát tập trung vào kỹ năng thuyết trình. Bạn vẫn có thể chỉnh sửa khung tiêu chí bên dưới.';
$string['soapbox:level_general'] = 'Nói tổng quát / thuyết trình';
$string['soapbox:level_esl_beginner'] = 'ESL (sơ cấp)';
$string['soapbox:level_esl_advanced'] = 'ESL (nâng cao)';
$string['soapbox:edit_rubric'] = 'Chỉnh sửa khung tiêu chí bài nói';
$string['soapbox:sample_label'] = 'Tải khung tiêu chí mẫu';
$string['soapbox:sample_choose'] = 'Chọn một mẫu…';
$string['soapbox:sample_hint'] = 'Tải các tiêu chí mẫu vào trình chỉnh sửa bên dưới. Xem lại và Lưu để áp dụng chúng cho phạm vi này.';
$string['soapbox:level_esl_intermediate'] = 'ESL (trung cấp)';

// v6.8.32 i18n catch-up: strings added v6.4.0 - v6.8.31 (outcomes, Soapbox video/slides,
// slide vision, RAG scope, privacy metadata, analytics JS, vendor DPA). Auto-translated batch.
$string['cachedef_ratelimit'] = 'Giới hạn tốc độ yêu cầu theo từng người dùng';
$string['cachedef_uistate'] = 'Chuyển đổi giao diện theo từng phiên (xem với vai trò học viên, hiển thị tên thật)';
$string['benchmark:pagetitle'] = 'Đo hiệu năng nhà cung cấp';
$string['benchmark:intro'] = 'Gửi một tập cố định các câu nhắc điển hình đến mọi nhà cung cấp AI đã cấu hình, ghi lại mức sử dụng token, chi phí và độ trễ, rồi đề xuất một nhà cung cấp cho mỗi năng lực. Mỗi lần chạy thực hiện các lệnh gọi API thực, nên một lần chạy tốn khoảng 5 đến 20 cent tùy theo số lượng nhà cung cấp đã cấu hình.';
$string['cachedef_systemprompt'] = 'Câu nhắc hệ thống AI đã tổng hợp (theo từng khóa học)';
$string['cachedef_remoteconfig'] = 'Cấu hình từ xa được lấy từ kênh cập nhật';
$string['cachedef_spend'] = 'Tổng chi tiêu AI theo từng kỳ cho bộ kiểm soát chi tiêu';
$string['cachedef_failover_circuit'] = 'Trạng thái bộ ngắt mạch chuyển đổi dự phòng nhà cung cấp';
$string['settings:rag_scope'] = 'Phạm vi truy xuất';
$string['settings:rag_scope_desc'] = 'Khi học viên đang xem một tài liệu cụ thể (một trang, sách, hoặc PDF), có nên giới hạn việc truy xuất trong tài liệu đó hay không. <b>Ưu tiên tài liệu</b> đặt câu trả lời dựa trên tài liệu hiện tại khi nó có các đoạn liên quan, và quay lại toàn bộ khóa học nếu không có. <b>Chỉ tài liệu</b> không bao giờ quay lại: nếu tài liệu hiện tại không có đoạn liên quan thì không truy xuất gì cả, nên trợ giảng trả lời từ kiến thức chung thay vì trích dẫn các trang không liên quan. <b>Toàn khóa học</b> tìm kiếm trong tất cả nội dung khóa học (trang hiện tại vẫn được tăng thứ hạng như trên). Ưu tiên tài liệu được khuyến nghị, để câu trả lời về trang mà học viên đang đọc được đặt trên chính trang đó.';
$string['settings:rag_scope_document_first'] = 'Ưu tiên tài liệu (ưu tiên tài liệu hiện tại, quay lại khóa học)';
$string['settings:rag_scope_document_only'] = 'Chỉ tài liệu (giới hạn trong tài liệu hiện tại)';
$string['settings:rag_scope_course'] = 'Toàn khóa học (tìm kiếm tất cả nội dung khóa học)';
$string['ragadmin:no_content_documents'] = '{$a->count} tài liệu không tạo ra văn bản có thể lập chỉ mục và không được chia đoạn (thường là các trang chủ yếu là nội dung nhúng hoặc rất ngắn): {$a->titles}. Nếu bất kỳ tài liệu nào trong số này cần tìm kiếm được, hãy thêm văn bản trên trang hoặc bản chép lời, rồi lập chỉ mục lại.';
$string['privacy:metadata:sbx_rec'] = 'Bản ghi bài thuyết trình Soapbox. Đối tượng phương tiện bị xóa sau thời hạn lưu giữ; bản chép lời được giữ lại để phản hồi và cho các yêu cầu truy cập.';
$string['privacy:metadata:sbx_rec:userid'] = 'Học viên đã tạo bản ghi.';
$string['privacy:metadata:sbx_rec:transcript'] = 'Bản chép lời chuyển giọng nói thành văn bản của bản ghi.';
$string['privacy:metadata:sbx_rec:duration_seconds'] = 'Độ dài của bản ghi tính bằng giây.';
$string['privacy:metadata:sbx_rec:timecreated'] = 'Thời điểm tạo bản ghi.';
$string['privacy:metadata:ai_provider'] = 'Để tạo phản hồi trợ giảng, nội dung do học viên soạn được gửi đến nhà cung cấp AI mà quản trị viên trang hoặc khóa học đã cấu hình.';
$string['privacy:metadata:ai_provider:message'] = 'Nội dung tin nhắn và tệp đính kèm mà học viên gửi cho trợ giảng AI.';
$string['privacy:metadata:ai_provider:coursecontext'] = 'Bối cảnh khóa học và hoạt động (như tên khóa học và trang hiện tại) được đưa vào để làm cơ sở cho phản hồi.';
$string['privacy:metadata:voice_provider'] = 'Khi sử dụng đầu vào hoặc đầu ra bằng giọng nói, âm thanh và văn bản được gửi đến nhà cung cấp chuyển giọng nói thành văn bản / văn bản thành giọng nói đã cấu hình.';
$string['privacy:metadata:voice_provider:audio'] = 'Âm thanh ghi lại giọng nói của học viên, được gửi đi để chép lời.';
$string['privacy:metadata:voice_provider:text'] = 'Văn bản được gửi đến nhà cung cấp để tổng hợp thành giọng nói.';
$string['privacy:metadata:zendesk'] = 'Khi tính năng chuyển tiếp đến hỗ trợ con người được bật và học viên đồng ý, thông tin liên hệ và tin nhắn của họ được gửi đến Zendesk để mở phiếu hỗ trợ.';
$string['privacy:metadata:zendesk:name'] = 'Họ tên đầy đủ của học viên, dùng để xác định người yêu cầu hỗ trợ.';
$string['privacy:metadata:zendesk:email'] = 'Địa chỉ email của học viên, dùng để trả lời yêu cầu hỗ trợ.';
$string['privacy:metadata:zendesk:message'] = 'Nội dung tin nhắn hoặc cuộc trò chuyện mà học viên chọn để chuyển tiếp.';
$string['privacy:metadata:radar_webhook'] = 'Khi cấu hình gửi Learning Radar đến webhook của Slack hoặc Microsoft Teams, báo cáo được tạo sẽ được đăng lên điểm cuối bên ngoài đó.';
$string['privacy:metadata:radar_webhook:report'] = 'Nội dung báo cáo Learning Radar, có thể tham chiếu đến hoạt động của học viên trong khóa học.';
$string['instructor_dashboard:navlink'] = 'Bảng điều khiển Trợ giảng AI';
$string['analytics_js:total_students'] = 'Tổng số học viên';
$string['analytics_js:active_ai_users'] = 'Người dùng AI đang hoạt động';
$string['analytics_js:msgs_per_student'] = 'Tin nhắn / Học viên';
$string['analytics_js:avg_session'] = 'Phiên trung bình';
$string['analytics_js:return_rate'] = 'Tỷ lệ quay lại';
$string['analytics_js:total_sessions'] = 'Tổng số phiên';
$string['analytics_js:ai_users'] = 'Người dùng AI';
$string['analytics_js:non_users'] = 'Người không dùng';
$string['analytics_js:thumbs_up'] = 'Thích';
$string['analytics_js:thumbs_down'] = 'Không thích';
$string['analytics_js:hallucination_flags'] = 'Cờ đánh dấu ảo giác';
$string['analytics_js:avg_star_rating'] = 'Đánh giá sao trung bình';
$string['analytics_js:avg_msgs_resolution'] = 'Số tin nhắn trung bình đến khi giải quyết';
$string['analytics_js:survey_respondents'] = 'Người trả lời khảo sát';
$string['analytics_js:messages'] = 'Tin nhắn';
$string['analytics_js:students'] = 'Học viên';
$string['analytics_js:frequency'] = 'Tần suất';
$string['analytics_js:responses'] = 'Phản hồi';
$string['analytics_js:error_loading'] = 'Lỗi khi tải dữ liệu';
$string['analytics_js:loading'] = 'Đang tải phân tích...';
$string['analytics_js:no_course_data'] = 'Không có dữ liệu khóa học.';
$string['analytics_js:no_unit_data'] = 'Chưa có dữ liệu đơn vị. Việc theo dõi đơn vị tích lũy khi học viên sử dụng trợ giảng.';
$string['analytics_js:no_keyword_data'] = 'Không có dữ liệu từ khóa cho giai đoạn này.';
$string['task:soapbox_cleanup'] = 'Lưu giữ và dọn dẹp bản ghi Soapbox';
$string['admin:vendor_dpa:col_provider'] = 'Nhà cung cấp';
$string['admin:vendor_dpa:col_optout'] = 'Từ chối huấn luyện';
$string['admin:vendor_dpa:col_dpa'] = 'DPA';
$string['admin:vendor_dpa:col_retention'] = 'Lưu giữ';
$string['admin:vendor_dpa:col_tier'] = 'Trần bậc';
$string['admin:vendor_dpa:col_link'] = 'Liên kết';
$string['admin:vendor_dpa:vendor_terms'] = 'Điều khoản nhà cung cấp';
$string['admin:vendor_dpa:tier'] = 'Bậc {$a}';
$string['admin:vendor_dpa:too_contractual'] = 'Theo hợp đồng (đã từ chối)';
$string['admin:vendor_dpa:too_default_on'] = 'Bật theo mặc định';
$string['admin:vendor_dpa:too_none'] = 'Không thể từ chối';
$string['admin:vendor_dpa:too_local'] = 'Cục bộ (không có nhà cung cấp)';
$string['admin:vendor_dpa:too_unknown'] = 'Chưa được xem xét';
$string['admin:vendor_dpa:dpa_signed'] = 'Đã ký';
$string['admin:vendor_dpa:dpa_available'] = 'Có sẵn';
$string['admin:vendor_dpa:dpa_negotiating'] = 'Đang thương lượng';
$string['admin:vendor_dpa:dpa_not_offered'] = 'Không cung cấp';
$string['admin:vendor_dpa:dpa_not_applicable'] = 'N/A';
$string['admin:vendor_dpa:dpa_unknown'] = 'Không rõ';
$string['settings:soapbox_slide_vision'] = 'Phản hồi thiết kế hình ảnh cho trang chiếu Soapbox';
$string['settings:soapbox_slide_vision_desc'] = 'Cho phép một lượt phân tích hình ảnh trên các ảnh trang chiếu đã kết xuất để thêm một ghi chú ngắn về thiết kế hình ảnh vào bài thuyết trình đã chấm điểm. Tắt theo mặc định và tôn trọng quyền riêng tư: không có ảnh nào được lưu trữ, và mỗi bài tập cũng phải tự đồng ý bằng ô đánh dấu Phản hồi thiết kế hình ảnh trang chiếu riêng của nó. Chỉ các bài thuyết trình có trang chiếu với bộ trang chiếu đã tải lên mới bị ảnh hưởng.';
$string['settings:soapbox_vision_provider'] = 'Nhà cung cấp phân tích hình ảnh trang chiếu Soapbox';
$string['settings:soapbox_vision_provider_desc'] = 'Nhà cung cấp dùng cho lượt phân tích hình ảnh trang chiếu. Mặc định là openai. Phải là một nhà cung cấp có khả năng xử lý hình ảnh được cấu hình với khóa API (qua nhà cung cấp của khóa học hoặc danh sách nhà cung cấp so sánh).';
$string['settings:soapbox_vision_model'] = 'Mô hình phân tích hình ảnh trang chiếu Soapbox';
$string['settings:soapbox_vision_model_desc'] = 'Mô hình có khả năng xử lý hình ảnh cho lượt phân tích hình ảnh trang chiếu. Mặc định là gpt-4o-mini.';
$string['soapbox:slide_design_note'] = 'Thiết kế trang chiếu:';
$string['soapbox:assign_manage'] = 'Bài tập thuyết trình Soapbox';
$string['soapbox:copy_link'] = 'Liên kết cho học viên';
$string['soapbox:deck_label'] = 'Trang chiếu (PDF). Tải lên bộ trang chiếu của bạn, sau đó chuyển trang chiếu khi bạn ghi.';
$string['soapbox:slide_prev'] = 'Trang chiếu trước';
$string['soapbox:slide_next'] = 'Trang chiếu tiếp theo';
$string['soapbox:play_slides'] = 'Phát cùng trang chiếu';
$string['soapbox:audio_ready'] = 'Chỉ âm thanh. Micrô của bạn sẽ được ghi lại; camera không được sử dụng.';
$string['soapbox:audio_recording'] = 'Đang ghi âm...';
$string['soapbox:cap_reached'] = 'Bạn đã đạt số lượng bản ghi tối đa cho bài tập này.';
$string['soapbox:storage_unconfigured'] = 'Bộ lưu trữ bản ghi chưa được thiết lập. Vui lòng liên hệ quản trị viên của bạn.';
$string['soapbox:bad_key'] = 'Tệp tải lên đó không thuộc về bạn.';
$string['soapbox:slides_disabled'] = 'Trang chiếu không được bật cho bài tập này.';
$string['soapbox:upload_missing'] = 'Không tìm thấy tệp tải lên. Vui lòng thử ghi lại.';
$string['soapbox:mode_label'] = 'Loại bài thuyết trình';
$string['soapbox:mode_informative'] = 'Cung cấp thông tin (giải thích hoặc giảng dạy)';
$string['soapbox:mode_persuasive'] = 'Thuyết phục (thuyết phục người nghe)';
$string['soapbox:err_provider'] = 'Không thể kết nối đến dịch vụ chấm điểm AI. Vui lòng thử lại sau giây lát.';
$string['soapbox:err_parse'] = 'AI đã trả về một phản hồi không mong đợi. Vui lòng thử lại.';
$string['soapbox:err_disabled'] = 'Luyện tập nói không được bật cho khóa học này.';
$string['soapbox:err_transcribe'] = 'Không thể chép lời bản ghi của bạn. Vui lòng thử lại và kiểm tra xem micrô của bạn có hoạt động không.';
$string['settings:outcomes_benchmark_default'] = 'Chuẩn đầu ra (phần trăm)';
$string['settings:outcomes_benchmark_default_desc'] = 'Chuẩn do tổ chức đặt ra cho báo cáo kết quả đầu ra: một học viên đạt được kết quả đầu ra khi mức độ thành thạo của họ bằng hoặc cao hơn phần trăm này. Đây là chuẩn báo cáo (khác với ngưỡng thành thạo cho việc hướng dẫn). Mặc định 70.';
$string['outcomes:title'] = 'Báo cáo kết quả đầu ra';
$string['outcomes:intro'] = 'Với mỗi kết quả đầu ra của khóa học, hiển thị chuẩn và phần trăm học viên được đánh giá đã đạt hoặc vượt chuẩn đó. Chuẩn của trang là {$a}%.';
$string['outcomes:none'] = 'Chưa có kết quả đầu ra nào được xác định cho khóa học này.';
$string['outcomes:export'] = 'Xuất CSV';
$string['outcomes:col_outcome'] = 'Kết quả đầu ra';
$string['outcomes:col_benchmark'] = 'Chuẩn';
$string['outcomes:col_assessed'] = 'Học viên được đánh giá';
$string['outcomes:col_met'] = 'Đạt chuẩn';
$string['outcomes:col_pct'] = 'Phần trăm đạt';
$string['outcomes:footnote'] = 'Học viên được đánh giá là những người có ít nhất một lần thử với kết quả đầu ra đó. Thành tích được báo cáo theo tổng hợp; tiến độ cá nhân không bao giờ bị chặn bởi bất kỳ một kết quả đầu ra đơn lẻ nào.';
$string['outcomes:navlink'] = 'Báo cáo kết quả đầu ra';
