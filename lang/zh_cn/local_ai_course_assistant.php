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
 * Language strings for local_ai_course_assistant — Simplified Chinese (zh_cn).
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname'] = 'AI 课程助手';
$string['attachment:attach'] = '附加';
$string['attachment:attach_image_or_pdf'] = '附加图片或PDF';
$string['privacy:metadata:local_ai_course_assistant_convs'] = '存储每位用户和课程的 AI 辅导聊天会话。';
$string['privacy:metadata:local_ai_course_assistant_convs:userid'] = '拥有该会话的用户 ID。';
$string['privacy:metadata:local_ai_course_assistant_convs:courseid'] = '该会话所属课程的 ID。';
$string['privacy:metadata:local_ai_course_assistant_convs:title'] = '会话的标题。';
$string['privacy:metadata:local_ai_course_assistant_convs:timecreated'] = '会话的创建时间。';
$string['privacy:metadata:local_ai_course_assistant_convs:timemodified'] = '会话的最后修改时间。';
$string['privacy:metadata:local_ai_course_assistant_msgs'] = '存储 AI 辅导聊天会话中的各条消息。';
$string['privacy:metadata:local_ai_course_assistant_msgs:userid'] = '发送该消息的用户 ID。';
$string['privacy:metadata:local_ai_course_assistant_msgs:courseid'] = '该消息所属课程的 ID。';
$string['privacy:metadata:local_ai_course_assistant_msgs:role'] = '消息发送者的角色（用户或助手）。';
$string['privacy:metadata:local_ai_course_assistant_msgs:message'] = '消息的内容。';
$string['privacy:metadata:local_ai_course_assistant_msgs:tokens_used'] = '该消息使用的 token 数量。';
$string['privacy:metadata:local_ai_course_assistant_msgs:timecreated'] = '消息的创建时间。';

// Capabilities.
$string['ai_course_assistant:use'] = '使用 AI 辅导聊天';
$string['ai_course_assistant:viewanalytics'] = '查看 AI 辅导聊天分析';
$string['ai_course_assistant:manage'] = '管理 AI 辅导聊天设置（管理员角色）';

// Settings.
$string['settings:enabled'] = '启用 AI 课程助手';
$string['settings:enabled_desc'] = '在课程页面上启用或禁用 AI 课程助手小部件。';
$string['settings:default_course_mode'] = '新课程的默认设置';
$string['settings:default_course_mode_desc'] = '控制在未做出每个课程选择时SOLA是否显示在课程上。新安装默认设置为"默认禁用"，以便管理员可以从Analytics页面或Course AI Settings页面逐个课程启用。';
$string['settings:default_course_mode_per_course'] = '默认禁用（按课程启用）';
$string['settings:default_course_mode_all'] = '在所有课程上启用';
$string['settings:auto_open'] = '首次访问时自动打开';
$string['settings:auto_open_desc'] = '启用后,学生首次进入每门课程时,SOLA抽屉会自动打开。同一课程中后续的页面加载不会再次打开抽屉 — 状态通过localStorage按课程在学生的浏览器中跟踪。适用于桌面和移动端。可以从Course AI Settings页面按课程覆盖此设置。';
$string['settings:comparison_providers'] = '对比供应商 (LLM 选择器)';
$string['settings:comparison_providers_desc'] = '在小组件内的 LLM 选择器中添加额外的 AI 供应商，以便管理员可以跨供应商比较回复。使用下面的表格添加行。温度列是可选的（留空以使用全局温度）。存储格式: provider_id|api_key|model1,model2|temperature。上面配置的主要供应商始终自动包含在内。只有具有管理权限的管理员才能看到选择器；学生永远看不到。有效的 provider IDs: openai, claude, deepseek, gemini, ollama, minimax, mistral, openrouter, xai, coreai, custom.';
$string['settings:provider'] = '默认 AI 供应商';
$string['settings:provider_desc'] = '选择用于聊天补全的AI提供商。选择"Moodle AI (core_ai subsystem)"通过Moodle的内置AI配置（Site admin > AI）路由请求；在该模式下，下面的API密钥、模型和基础URL字段将被忽略。通过core_ai无法使用Streaming、工具使用和prompt caching——响应作为单个块交付。为获得最佳学生体验，请使用直接提供商。';
$string['settings:provider_claude'] = 'Claude（Anthropic）';
$string['settings:provider_openai'] = 'OpenAI';
$string['settings:provider_deepseek'] = 'DeepSeek';
$string['settings:provider_ollama'] = 'Ollama（本地）';
$string['settings:provider_minimax'] = 'MiniMax';
$string['settings:provider_custom'] = '自定义（OpenAI 兼容）';
$string['settings:apikey'] = 'API 密钥';
$string['settings:apikey_desc'] = '所选提供商的 API 密钥。Ollama 不需要此项。';
$string['settings:model'] = '模型名称';
$string['settings:model_desc'] = '要使用的模型。默认值取决于提供商（例如 claude-sonnet-4-5-20250929、gpt-4o、llama3、MiniMax-Text-01）。';
$string['settings:apibaseurl'] = 'API 基础 URL';
$string['settings:apibaseurl_desc'] = 'API 的基础 URL。会根据提供商自动填写，但可以覆盖。留空则使用提供商默认值。';
$string['settings:systemprompt'] = '系统提示模板';
$string['settings:systemprompt_desc'] = '发送给 AI 的系统提示。可使用占位符：{{coursename}}、{{userrole}}、{{coursetopics}}。';
$string['settings:systemprompt_default'] = '您是课程"{{coursename}}"的 AI 辅导助手。学生的角色为 {{userrole}}。

课程涵盖的主题：
{{coursetopics}}

请帮助学生理解课程内容。请保持鼓励、清晰且具有教学意义的态度。';
$string['settings:temperature'] = '温度';
$string['settings:temperature_desc'] = '控制随机性。值越低输出越集中，值越高输出越有创意。范围：0.0 至 2.0。';
$string['settings:maxhistory'] = '最大会话历史记录';
$string['settings:maxhistory_desc'] = 'API 请求中包含的最大消息对数量。较旧的消息将被裁减。';
$string['settings:avatar'] = '聊天头像';
$string['settings:avatar_desc'] = '选择聊天小部件按钮的头像图标。';
$string['settings:avatar_saylor'] = '{$a} 徽标（默认）';
$string['settings:position'] = '小部件位置';
$string['settings:position_desc'] = '聊天小部件在页面上的位置。';
$string['settings:position_br'] = '右下角';
$string['settings:position_bl'] = '左下角';
$string['settings:position_tr'] = '右上角';
$string['settings:position_tl'] = '左上角';
$string['chat:settings'] = '插件设置';
$string['analytics:viewdashboard'] = '查看分析仪表板';

// Course settings (per-course AI provider override).
$string['coursesettings:title'] = '课程 AI 设置';
$string['coursesettings:enabled'] = '启用课程覆盖';
$string['coursesettings:enabled_desc'] = '启用后，以下设置将仅针对本课程覆盖全局 AI 提供商配置。留空字段则继承全局值。';
$string['coursesettings:sola_enabled'] = '此课程上的SOLA';
$string['coursesettings:sola_enabled_toggle'] = '在此课程上显示SOLA小部件';
$string['coursesettings:sola_enabled_desc'] = '控制SOLA聊天小部件是否显示在此课程上。全站默认值在插件设置的General > Default for new courses下设置。';
$string['coursesettings:using_global'] = '使用全局设置';
$string['coursesettings:saved'] = '课程 AI 设置已保存。';
$string['coursesettings:global_settings_link'] = '全局 AI 设置';

// Language detection and preference.
$string['lang:switch'] = '是，切换';
$string['lang:dismiss'] = '不，谢谢';
$string['lang:change'] = '更改语言';
$string['lang:english'] = '英语';

// Chat widget.
$string['chat:title'] = 'AI 辅导';
$string['chat:placeholder'] = '请输入您的问题...';
$string['chat:send'] = '发送';
$string['chat:close'] = '关闭聊天';
$string['chat:open'] = '打开 AI 辅导聊天';
$string['chat:clear'] = '清除屏幕';
$string['chat:clear_confirm'] = '清除可见消息？您的完整聊天历史记录仍会保存，并可通过重新打开小部件重新加载。';
$string['chat:copy'] = '复制会话';
$string['chat:copied'] = '会话已复制到剪贴板';
$string['chat:copy_failed'] = '复制会话失败';
$string['chat:thinking'] = '正在思考...';
$string['chat:error'] = '很抱歉，出现了一些问题。请重试。';
$string['chat:error_auth'] = '身份验证错误。请联系您的管理员。';
$string['chat:error_ratelimit'] = '请求过多。请稍等片刻后重试。';
$string['chat:error_unavailable'] = 'AI 服务暂时不可用。请稍后再试。';
$string['chat:error_notconfigured'] = 'AI 辅导助手尚未配置。请联系您的管理员。';
$string['chat:mic'] = '语音提问';
$string['chat:mic_error'] = '麦克风错误。请检查您的浏览器权限。';
$string['chat:mic_unsupported'] = '此浏览器不支持语音输入。';
$string['chat:newline_hint'] = 'Shift+Enter 换行';
$string['chat:you'] = '您';
$string['chat:assistant'] = 'AI 辅导';
$string['chat:history_loaded'] = '已加载之前的会话。';
$string['chat:history_cleared'] = '聊天历史已清除。';
$string['chat:offtopic_warning'] = '您的问题似乎与本课程无关。请尽量保持话题相关，以便我更好地帮助您！';
$string['chat:offtopic_ended'] = '由于会话多次偏离主题，您的 AI 辅导访问权限已被暂停 {$a} 分钟。请利用这段时间复习课程材料，稍后再试。';
$string['chat:offtopic_locked'] = '您的 AI 辅导访问权限已被暂停。您可以在 {$a} 分钟后重试。回来后请专注于与课程相关的问题。';
$string['chat:escalated_to_support'] = '我无法完全回答您的问题，因此已为您创建了一个支持工单。支持团队成员将跟进处理。您的工单编号为：{$a}';
$string['chat:studyplan_intro'] = '我可以帮您为本课程制定学习计划！只需告诉我您每周可以投入多少小时用于学习，我将帮您制定一个有条理的计划。';

// FAQ & Support settings.
$string['settings:faq_heading'] = '常见问题与支持';
$string['settings:faq_heading_desc'] = '配置集中式常见问题解答和 Zendesk 支持工单集成。';
$string['settings:faq_content'] = '常见问题内容';
$string['settings:faq_content_desc'] = '输入常见问题条目（每行一条，格式为：Q: 问题 | A: 答案）。这些内容将提供给 AI 以回答常见支持问题。';
$string['settings:zendesk_enabled'] = '启用 Zendesk 升级';
$string['settings:zendesk_enabled_desc'] = '当 AI 无法解决支持问题时，自动创建包含会话摘要的 Zendesk 工单。';
$string['settings:zendesk_subdomain'] = 'Zendesk 子域名';
$string['settings:zendesk_subdomain_desc'] = '您的 Zendesk 子域名（例如 mycompany.zendesk.com 中的"mycompany"）。';
$string['settings:zendesk_email'] = 'Zendesk API 邮箱';
$string['settings:zendesk_email_desc'] = '用于 API 身份验证的 Zendesk 用户邮箱地址（需附加 /token 后缀）。';
$string['settings:zendesk_token'] = 'Zendesk API 令牌';
$string['settings:zendesk_token_desc'] = '用于 Zendesk 身份验证的 API 令牌。';

// Off-topic detection settings.
$string['settings:offtopic_heading'] = '偏题检测';
$string['settings:offtopic_heading_desc'] = '配置聊天处理偏题会话的方式。';
$string['settings:offtopic_enabled'] = '启用偏题检测';
$string['settings:offtopic_enabled_desc'] = '指示 AI 检测并重定向偏题会话。';
$string['settings:offtopic_max'] = '最大偏题消息数';
$string['settings:offtopic_max_desc'] = '采取行动前连续偏题消息的数量。';
$string['settings:offtopic_action'] = '偏题处理方式';
$string['settings:offtopic_action_desc'] = '达到偏题上限时的处理方式。';
$string['settings:offtopic_action_warn'] = '警告并重定向';
$string['settings:offtopic_action_end'] = '暂时锁定访问';
$string['settings:offtopic_lockout_duration'] = '锁定时长（分钟）';
$string['settings:offtopic_lockout_duration_desc'] = '学生超出偏题限制后失去 AI 辅导访问权限的时长（分钟）。默认：30 分钟。';

// Study planning & reminders settings.
$string['settings:studyplan_heading'] = '学习规划与提醒';
$string['settings:studyplan_heading_desc'] = '配置学习规划功能和提醒通知。';
$string['settings:studyplan_enabled'] = '启用学习规划';
$string['settings:studyplan_enabled_desc'] = '允许 AI 辅导根据学生的可用时间帮助其制定个性化学习计划。';
$string['settings:reminders_email_enabled'] = '启用电子邮件提醒';
$string['settings:reminders_email_enabled_desc'] = '允许学生通过电子邮件订阅学习提醒。';
$string['settings:reminders_whatsapp_enabled'] = '启用 WhatsApp 提醒';
$string['settings:reminders_whatsapp_enabled_desc'] = '允许学生通过 WhatsApp 订阅学习提醒（需要 WhatsApp API 配置）。';
$string['settings:whatsapp_api_url'] = 'WhatsApp API URL';
$string['settings:whatsapp_api_url_desc'] = '发送 WhatsApp 消息的 API 端点（例如 Twilio、MessageBird）。必须接受包含"to"、"from"和"body"字段的 JSON 正文 POST 请求。';
$string['settings:whatsapp_api_token'] = 'WhatsApp API 令牌';
$string['settings:whatsapp_api_token_desc'] = 'WhatsApp API 的身份验证令牌。';
$string['settings:whatsapp_from_number'] = 'WhatsApp 发件号码';
$string['settings:whatsapp_from_number_desc'] = '发送 WhatsApp 消息的电话号码（含国家代码，例如 +14155238886）。';
$string['settings:whatsapp_blocked_countries'] = 'WhatsApp 屏蔽国家';
$string['settings:whatsapp_blocked_countries_desc'] = '因当地法规不允许发送 WhatsApp 提醒的国家的 ISO 3166-1 alpha-2 国家代码（逗号分隔，例如"CN,IR,KP"）。';

// Reminder messages.
$string['reminder:email_subject'] = '学习提醒：{$a}';
$string['reminder:email_body'] = '您好 {$a->firstname}，

这是您关于"{$a->coursename}"的学习提醒。

{$a->message}

您的学习计划建议每周为本课程投入 {$a->hours_per_week} 小时。

继续保持，加油！

---
如需取消接收提醒，请点击此处：{$a->unsubscribe_url}';
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
$string['reminder:whatsapp_body'] = '{$a->coursename} 的学习提醒：{$a->message}（退订：{$a->unsubscribe_url}）';
$string['reminder:study_tip_prefix'] = '今日学习重点：';

// Unsubscribe page.
$string['unsubscribe:title'] = '退订学习提醒';
$string['unsubscribe:success'] = '您已成功退订本课程的学习提醒。';
$string['unsubscribe:already'] = '您已经退订了这些提醒。';
$string['unsubscribe:invalid'] = '退订链接无效或已过期。';
$string['unsubscribe:resubscribe'] = '改变主意了？您可以通过 AI 辅导聊天重新启用提醒。';

// Scheduled task.
$string['task:send_reminders'] = '发送 AI 辅导学习提醒';

// Privacy - additional tables.
$string['privacy:metadata:local_ai_course_assistant_plans'] = '存储学生的学习计划。';
$string['privacy:metadata:local_ai_course_assistant_plans:userid'] = '拥有该学习计划的用户 ID。';
$string['privacy:metadata:local_ai_course_assistant_plans:courseid'] = '该学习计划所属的课程。';
$string['privacy:metadata:local_ai_course_assistant_plans:hours_per_week'] = '学生计划每周学习的小时数。';
$string['privacy:metadata:local_ai_course_assistant_plans:plan_data'] = 'JSON 格式的学习计划详情。';
$string['privacy:metadata:local_ai_course_assistant_reminders'] = '存储学习提醒偏好和订阅信息。';
$string['privacy:metadata:local_ai_course_assistant_reminders:userid'] = '订阅提醒的用户 ID。';
$string['privacy:metadata:local_ai_course_assistant_reminders:channel'] = '提醒渠道（电子邮件或 WhatsApp）。';
$string['privacy:metadata:local_ai_course_assistant_reminders:destination'] = '接收提醒的电子邮件地址或电话号码。';
$string['privacy:metadata:local_ai_course_assistant_reminders:country_code'] = '用于法规合规的用户国家代码。';

// Analytics dashboard.
$string['analytics:title'] = 'AI 辅导分析';
$string['analytics:overview'] = '概览';
$string['analytics:total_conversations'] = '会话总数';
$string['analytics:total_messages'] = '消息总数';
$string['analytics:active_students'] = '活跃学生';
$string['analytics:avg_messages_per_student'] = '每位学生平均消息数';
$string['analytics:offtopic_rate'] = '偏题率';
$string['analytics:escalation_count'] = '已升级至支持';
$string['analytics:studyplan_adoption'] = '拥有学习计划的学生';
$string['analytics:usage_trends'] = '使用趋势';
$string['analytics:daily_messages'] = '每日消息量';
$string['analytics:hotspots'] = '课程热点';
$string['analytics:hotspots_desc'] = '学生问题中最常被引用的课程章节。较高的计数可能表示学生在这些区域需要更多支持。';
$string['analytics:section'] = '章节';
$string['analytics:mention_count'] = '提及次数';
$string['analytics:common_prompts'] = '常见提问模式';
$string['analytics:common_prompts_desc'] = '学生频繁出现的问题模式。审查这些内容以识别课程内容中的系统性差距。';
$string['analytics:prompt_pattern'] = '模式';
$string['analytics:frequency'] = '频率';
$string['analytics:recent_activity'] = '近期活动';
$string['analytics:no_data'] = '暂无分析数据。一旦学生开始使用 AI 辅导，数据将在此显示。';
$string['analytics:timerange'] = '时间范围';
$string['analytics:last_7_days'] = '最近 7 天';
$string['analytics:last_30_days'] = '最近 30 天';
$string['analytics:all_time'] = '全部时间';
$string['analytics:export'] = '导出数据';
$string['analytics:provider_comparison'] = 'AI 提供商对比';
$string['analytics:provider_comparison_desc'] = '比较本课程中使用的各 AI 提供商的表现。';
$string['analytics:provider'] = '提供商';
$string['analytics:response_count'] = '回复数';
$string['analytics:avg_response_length'] = '平均回复长度';
$string['analytics:total_tokens'] = '总 token 数';
$string['analytics:avg_tokens'] = '平均 token 数 / 回复';

// User settings.
$string['usersettings:title'] = 'AI 课程助手 - 您的数据';
$string['usersettings:intro'] = '管理您的 AI 辅导聊天数据和隐私设置';
$string['usersettings:privacy_info'] = '您与 AI 辅导的会话将被存储，以便在整个课程中为您提供持续支持。您对这些数据拥有完全控制权，可以随时删除。';
$string['usersettings:usage_stats'] = '您的使用统计';
$string['usersettings:total_messages'] = '消息总数';
$string['usersettings:total_conversations'] = '会话数';
$string['usersettings:messages'] = '消息';
$string['usersettings:last_activity'] = '最后活动时间';
$string['usersettings:delete_course_data'] = '删除课程数据';
$string['usersettings:no_data'] = '您尚未使用 AI 辅导。开始聊天后，您的使用数据将显示在此处。';
$string['usersettings:delete_all_title'] = '删除您的所有数据';
$string['usersettings:delete_all_warning'] = '这将永久删除您在所有课程中的 AI 辅导会话。此操作无法撤销。';
$string['usersettings:delete_all_button'] = '删除我的所有数据';
$string['usersettings:confirm_delete_course'] = '您确定要永久删除课程"{$a}"中的所有 AI 辅导数据吗？此操作无法撤销。';
$string['usersettings:confirm_delete_all'] = '您确定要永久删除您在所有课程中的所有 AI 辅导数据吗？此操作无法撤销。';
$string['usersettings:data_deleted'] = '您的数据已被删除。';

// === SOLA v1.0.12 — updated/new strings ===

$string['chat:greeting'] = '你好，{$a}！我是SOLA。今天我能帮你什么？';
$string['chat:title'] = 'SOLA';
$string['chat:assistant'] = 'SOLA';
$string['chat:open'] = '打开 SOLA';
$string['chat:change_avatar'] = '更换头像';

$string['chat:quiz'] = '参加练习测验';
$string['chat:quiz_setup_title'] = '练习测验';
$string['chat:quiz_questions'] = '题目数量';
$string['chat:quiz_topic'] = '主题';
$string['chat:quiz_topic_guided'] = 'AI 引导（基于你的学习进度）';
$string['chat:quiz_topic_adaptive']      = '自适应——专注于我的薄弱环节';
$string['chat:quiz_topic_default'] = '当前课程内容';
$string['chat:quiz_topic_custom'] = '自定义主题…';
$string['chat:quiz_custom_placeholder'] = '输入一个主题或问题...';
$string['chat:quiz_start'] = '开始测验';
$string['chat:quiz_cancel'] = '取消';
$string['chat:quiz_loading'] = '正在生成测验…';
$string['chat:quiz_error'] = '无法生成测验，请重试。';
$string['chat:quiz_correct'] = '正确！';
$string['chat:quiz_wrong'] = '错误。';
$string['chat:quiz_next'] = '下一题';
$string['chat:quiz_finish'] = '查看结果';
$string['chat:quiz_score'] = '测验完成！你答对了 {$a->score} / {$a->total} 题。';
$string['chat:quiz_summary'] = '我刚完成了关于"{$a->topic}"的 {$a->total} 题练习测验，得分 {$a->score}/{$a->total}。';
$string['chat:quiz_topic_objectives'] = '学习目标';
$string['chat:quiz_topic_modules'] = '课程主题';
$string['chat:quiz_subtopic_select'] = '选择具体项目…';
$string['chat:quiz_topic_sections'] = '课程章节';
$string['chat:quiz_score_great'] = '出色！你真的掌握了这些内容。';
$string['chat:quiz_score_good'] = '不错！继续复习以加深理解。';
$string['chat:quiz_score_practice'] = '继续练习——先复习相关课程材料，然后重新参加测验。';
$string['chat:quiz_review_heading'] = '复习';
$string['chat:quiz_retake'] = '重新测验';
$string['chat:quiz_exit'] = '退出测验';
$string['chat:quiz_your_answer'] = '你的答案';
$string['chat:quiz_correct_answer'] = '正确答案';

$string['chat:starters_label'] = '对话开始建议';
$string['chat:starter_quiz'] = '测验我这个';
$string['chat:starter_explain'] = '解释这个';
$string['chat:starter_key_concepts'] = '关键概念';
$string['chat:starter_study_plan'] = '学习计划';
$string['chat:starter_help_me'] = 'AI 帮助';
$string['chat:starter_ai_project_coach'] = 'AI项目教练';
$string['chat:starter_ell_practice'] = '对话练习';
$string['chat:starter_ell_pronunciation'] = 'ELL 发音练习';
$string['chat:starter_ai_coach'] = 'AI 辅导';
$string['chat:starter_speak'] = '朗读开始语';

$string['chat:reset'] = '重新开始';

$string['chat:topic_picker_title'] = '你想专注于什么？';
$string['chat:topic_picker_title_help'] = '你需要哪方面的帮助？';
$string['chat:topic_picker_title_explain'] = '你想让我解释什么？';
$string['chat:topic_picker_title_study'] = '你想专注于哪个领域？';
$string['chat:topic_start'] = '继续';

$string['chat:fullscreen'] = '全屏';
$string['chat:exitfullscreen'] = '退出全屏';

$string['chat:language'] = '更改语言';
$string['chat:settings_panel'] = '设置';
$string['chat:settings_language'] = '语言';
$string['chat:settings_avatar'] = '头像';
$string['chat:settings_voice'] = '语音';
$string['chat:settings_voice_admin'] = '语音设置在网站管理面板中管理。';

$string['chat:voice_mode'] = '语音模式';
$string['chat:voice_end'] = '结束语音会话';
$string['chat:voice_connecting'] = '正在连接...';
$string['chat:voice_listening'] = '正在聆听...';
$string['chat:voice_speaking'] = 'SOLA 正在说话...';
$string['chat:voice_idle'] = '就绪';
$string['chat:voice_error'] = '语音连接失败，请检查您的设置。';
$string['chat:quiz_locked'] = '测验期间 SOLA 已暂停，以维护学术诚信。祝你好运！';

// Bottom nav.
$string['chat:mode_nav'] = 'Mode navigation';
$string['chat:mode_chat'] = 'Chat';
$string['chat:mode_voice'] = 'Voice';
$string['chat:mode_history'] = '笔记';

// History panel.
$string['chat:history_title'] = '笔记与对话历史';
$string['task:send_inactivity_reminders'] = '发送每周不活跃提醒邮件';
$string['messageprovider:study_notes'] = '学习会话笔记';
$string['task:send_inactivity_reminders'] = '发送每周不活跃提醒邮件';
$string['task:run_meta_ai_query'] = '运行预定的 学习雷达 分析查询';
$string['messageprovider:study_notes'] = '学习课程笔记';

// CDN settings.
$string['settings:cdn_heading'] = 'CDN / 前端分发';
$string['settings:cdn_heading_desc'] = '从外部 CDN 提供 SOLA 前端资源（JS/CSS），而不是从 Moodle 文件系统提供。这允许在不升级插件的情况下更新前端。将 CDN URL 留空以使用本地插件文件。';
$string['settings:cdn_url'] = 'CDN 基础 URL';
$string['settings:cdn_url_desc'] = '托管 sola.min.js 和 sola.min.css 的基础 URL。示例：https://your-org.github.io/sola-cdn。留空以使用本地插件文件。';
$string['settings:cdn_version'] = 'CDN 资源版本';
$string['settings:cdn_version_desc'] = '附加到 CDN URL 的版本字符串，用于 cache busting。每次 CDN 部署后更新（例如 3.2.4 或 commit hash）。';

// Analytics dashboard.
$string['analytics:tab_overall'] = '总体使用情况';
$string['analytics:tab_bycourse'] = '按课程';
$string['analytics:tab_comparison'] = 'AI用户与非用户';
$string['analytics:tab_byunit'] = '按单元';
$string['analytics:tab_usagetypes'] = '使用类型';
$string['analytics:tab_themes'] = '主题';
$string['analytics:tab_feedback'] = 'AI反馈';
$string['analytics:total_students'] = '学生总数';
$string['analytics:active_users'] = '活跃AI用户';
$string['analytics:msgs_per_student'] = '每位学生的消息数';
$string['analytics:avg_session'] = '平均会话时长';
$string['analytics:return_rate'] = '回访率';
$string['analytics:total_sessions'] = '总会话数';
$string['analytics:thumbs_up'] = '点赞';
$string['analytics:thumbs_down'] = '踩';
$string['analytics:hallucination_flags'] = '不准确信息标记';
$string['analytics:msgs_to_resolution'] = '解决问题所需消息数';
$string['analytics:helpful'] = '有帮助';
$string['analytics:not_helpful'] = '没有帮助';
$string['analytics:flag_hallucination'] = '此回答包含不准确的信息';
$string['analytics:submit_rating'] = '提交';
$string['analytics:thanks_feedback'] = '感谢您的反馈';

// LLM provider names.
$string['settings:provider_mistral'] = 'Mistral AI';
$string['settings:provider_openrouter'] = 'OpenRouter';
$string['settings:provider_together'] = 'Together AI (Llama 3.1 8B/70B/405B Turbo, Mistral, Qwen)';
$string['settings:provider_xai'] = 'xAI (Grok)';

$string['settings:provider_coreai'] = 'Moodle AI (core_ai subsystem)';
// Strings added by update_langs.py.
$string['chat:starter_help_page'] = '解释此页面';
$string['chat:starter_ask_anything'] = '问任何问题';
$string['chat:starter_review_practice'] = '复习与练习';
$string['chat:history_saved_subtitle'] = '已保存的回复将在此课程期间保留在此设备上。';
$string['chat:history_saved_empty'] = '保存AI回复以在此处查看。';
$string['chat:history_views_label'] = '历史视图';
$string['chat:history_view_saved'] = '已保存';
$string['chat:history_view_recent'] = '历史';
$string['chat:debug_refresh'] = '刷新';
$string['chat:debug_copy_all'] = '全部复制';
$string['chat:debug_close'] = '关闭';
$string['chat:language_switch'] = '切换语言';
$string['chat:language_dismiss'] = '忽略语言建议';
$string['chat:llm_label'] = 'LLM';
$string['chat:llm_provider_select'] = '选择LLM提供商';
$string['chat:llm_model_label'] = '模型';
$string['chat:llm_model_select'] = '选择LLM模型';
$string['chat:footer_usertesting'] = '可用性测试';
$string['chat:footer_feedback'] = '反馈';
$string['chat:voice_panel_title']       = 'Talk with {$a}';

// Additional translated strings.
$string['chat:debug_context'] = '上下文调试';
$string['chat:debug_context_browser'] = '浏览器快照';
$string['chat:debug_context_copy'] = '复制';
$string['chat:debug_context_prompt'] = '服务器响应';
$string['chat:debug_context_request'] = '最近的SSE请求';
$string['chat:debug_context_toggle'] = '切换调试检查器';
$string['chat:history_empty'] = '暂无对话。';
$string['chat:history_refresh'] = '刷新';
$string['chat:history_subtitle'] = '您的最近消息。';
$string['chat:starter_explain_prompt'] = '解释最重要的概念?';
$string['chat:starter_help_lesson'] = '解释这个';
$string['chat:starter_help_lesson_prompt'] = '帮我理解课程内容。总结关键概念。';
$string['chat:starter_prompt_coach'] = 'AI提示词教练';
$string['chat:starter_quick_study'] = '快速学习';
$string['chat:starter_study_plan_prompt'] = '我想规划学习。请问：(1) 今天目标，(2) 有多少时间。更新现有计划。';
$string['chat:voice_copy'] = '与学习助手进行语音对话。';
$string['chat:voice_ready'] = '准备就绪';
$string['chat:voice_start'] = '开始';
$string['chat:voice_title'] = '与SOLA对话';
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
$string['mobile_chip_concepts'] = '关键概念';
$string['mobile_chip_quiz'] = '测验';
$string['mobile_chip_studyplan'] = '学习计划';
$string['mobile_clear'] = '清除历史';
$string['mobile_disabled'] = 'SOLA不适用于此课程。';
$string['mobile_placeholder'] = '提问...';
$string['mobile_welcome'] = '你好，{$a}！';
$string['mobile_welcome_sub'] = '我是SOLA，您的学习助手。今天能帮您什么？';
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
$string['rubric:done'] = '完成';
$string['rubric:encourage_high'] = '太棒了！继续保持！';
$string['rubric:encourage_low'] = '好的开始！坚持练习会有帮助。';
$string['rubric:encourage_mid'] = '不错的努力！继续练习提高。';
$string['rubric:overall'] = '总体';
$string['rubric:practice_again'] = '再次练习';
$string['rubric:score_title_conversation'] = '会话练习得分';
$string['rubric:score_title_pronunciation'] = '发音练习得分';
$string['rubric:scoring'] = '正在评估练习...';
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
$string['demo:title'] = '测试环境';
$string['demo:heading'] = '测试环境';
$string['demo:intro'] = '此页面创建一个<strong>对学生隐藏</strong>（visible=0）的测试课程，并使用假学生、AI 对话、评分和反馈填充它。对于预览 Analytics Dashboard 或在不影响任何真实注册学生的情况下验证插件更改非常有用。';
$string['demo:step1'] = 'Step 1：测试课程';
$string['demo:step2'] = 'Step 2：填充假学生和 AI 聊天';
$string['demo:course_exists'] = '测试课程已存在：<strong>{$a->fullname}</strong>（简称 <code>{$a->shortname}</code>，id {$a->id}）';
$string['demo:badge_hidden'] = '隐藏';
$string['demo:badge_visible'] = '对学生可见';
$string['demo:no_course'] = '未找到测试课程。点击下面创建一个。';
$string['demo:create_btn'] = '创建隐藏的测试课程';
$string['demo:open_course'] = '打开课程 &rarr;';
$string['demo:seed_intro'] = '创建 demo_student_001、demo_student_002、... 并将他们注册到测试课程中，然后插入合成的对话、消息、评分和反馈。再次运行以添加更多数据，或勾选「先清除」以重新开始。';
$string['demo:users_label'] = '用户';
$string['demo:weeks_label'] = '周';
$string['demo:clear_label'] = '先清除现有的 demo_* 用户';
$string['demo:seed_btn'] = '填充学生和聊天';
$string['demo:view_analytics'] = '查看此课程的 Analytics &rarr;';
$string['demo:footer'] = '此处创建的数据位于标准 Moodle 用户 / 注册表以及插件自己的对话表中。所有假用户的用户名都以 <code>demo_student_</code> 开头，因此很容易过滤或删除。要删除它们，请再次运行 seed 步骤并勾选「先清除现有的 demo_* 用户」。';
$string['demo:course_fullname'] = 'SOLA 测试课程（隐藏）';
$string['demo:notify_created'] = '测试课程已就绪：{$a->fullname}（id {$a->id}）。';
$string['demo:notify_create_fail'] = '创建课程失败：{$a}';
$string['demo:notify_seeded'] = '已填充：{$a->users} 个用户、{$a->conversations} 个对话、{$a->messages} 条消息、{$a->ratings} 个评分、{$a->feedback} 条反馈。';
$string['demo:notify_seed_fail'] = '填充数据失败：{$a}';
$string['toc:analytics'] = 'Analytics Dashboard &rarr;';
$string['toc:tokenanalytics'] = 'Token 成本和 Analytics &rarr;';
$string['toc:testing'] = '测试环境 &rarr;';
$string['toc:back_to_course'] = '&larr; 返回 {$a}';

// RAG extractor status strings (v3.9.6+).
$string['rag:pdftotext_missing'] = '未找到 pdftotext 二进制文件；已禁用 PDF 提取。';
$string['rag:pdftotext_available'] = '已在 {$a} 检测到 pdftotext。';
$string['rag:docx_unavailable'] = 'PHP ZipArchive 扩展不可用；已禁用 DOCX 提取。';
$string['rag:h5p_unavailable'] = '无法读取 H5P 内容；跳过。';
$string['rag:scorm_too_large'] = 'SCORM 包超出配置的大小限制（{$a} MB）；跳过。';
$string['rag:scorm_unzip_failed'] = '无法解压 SCORM 包；跳过。';
$string['rag:transcript_fetch_failed'] = '无法从 {$a} 获取文字记录。';
$string['rag:transcript_cf_challenge'] = '文字记录 URL 被 Cloudflare 挑战阻止：{$a}。';
$string['rag:embed_detected'] = '检测到嵌入媒体：{$a}';
$string['rag:embed_transcript_attached'] = '已为 {$a} 附加文字记录';

// v3.9.10–v3.9.14 new strings.
$string['usersettings:download'] = '下载我的 {$a} 数据';
$string['usersettings:download_help'] = '下载与您账户关联的所有 {$a} 记录的完整 JSON 副本：对话、消息、评分、学习计划、提醒、练习成绩、问卷回答、个人资料以及审计条目。';
$string['usersettings:privacy_notice_link'] = '阅读 {$a} 隐私声明';
$string['privacy:title'] = '{$a} 隐私声明';
$string['admin:user_data:title'] = '{$a} — 学习者数据导出与清除';
$string['admin:user_data:intro'] = '处理 GDPR 第 15 条（访问）或第 17 条（删除）请求的操作流程。通过 Moodle 用户 id 查找学习者，查看本插件为其保存的记录，并导出或清除。';
$string['admin:user_data:search_label'] = 'Moodle 用户 id';
$string['admin:user_data:lookup'] = '查找';
$string['admin:user_data:not_found'] = '未找到具有该 id 的用户。';
$string['admin:user_data:download'] = '以 JSON 格式下载全部学习者数据';
$string['admin:user_data:purge'] = '清除该用户的全部学习者数据';
$string['admin:user_data:confirm_purge'] = '永久删除 {$a} 的所有记录？此操作将级联删除对话、消息、评分、学习计划、提醒、个人资料、练习成绩、问卷、审计条目和反馈。无法撤销。';
$string['admin:user_data:purged'] = '所选用户的全部数据已被清除。';
$string['chat:consent_heading'] = '在与 {$a->product} 对话之前';
$string['chat:consent_body'] = '{$a->product} 是一款由 AI 驱动的学习助手。您的消息和 {$a->product} 的回复存储在 {$a->institution} 的 Moodle 数据库中，最近的十轮对话会发送给经批准的 AI 模型提供商以回答您的问题。您的名字会被共享以进行个性化处理；不会向 AI 提供商发送任何其他身份识别信息。如果您请求人工帮助且您的问题被升级，本次对话（包括您的姓名和电子邮件）可能会与我们的支持团队共享。您可以随时下载、删除或停止使用 {$a->product}。';
$string['chat:consent_accept'] = '我已了解，开始使用 {$a}';
$string['chat:consent_privacy_link'] = '阅读完整的隐私声明';
$string['task:audit_cleanup'] = 'AI Course Assistant 审计表清理';
$string['task:conversation_retention'] = 'AI Course Assistant 对话保留扫描';
$string['settings:audit_retention_days'] = '审计日志保留（天）';
$string['settings:audit_retention_days_desc'] = '每日定时任务清除超过此期限的审计行。0 表示禁用。默认 365。';
$string['settings:conversation_retention_days'] = '对话保留（天）';
$string['settings:conversation_retention_days_desc'] = '每日定时任务清除最后修改时间早于此期限的对话行。0 表示禁用。默认 730。';
$string['settings:ssrf_trusted_endpoints'] = 'SSRF 受信任端点';
$string['settings:ssrf_trusted_endpoints_desc'] = '每行一个 URL。列出的主机将绕过 SOLA SSRF 验证器中的 loopback / 私有-IP / 仅-https 检查。仅用于您控制的网络上的自托管 LLM — 例如本地 Ollama 使用 <code>http://localhost:11434</code>，同一 VPC 上的 vLLM pod 使用 <code>http://10.0.0.5:8000</code>。比较匹配 scheme + host + port；任何路径都将被忽略。默认为空（阻止所有内部）。以 <code>#</code> 开头的行是注释。';
$string['task:learner_weekly_digest']    = 'AI 课程助手 - 学习者周报';
$string['learner_digest:subject']        = '您与 {$a->course} 的本周 - {$a->product}';
$string['learner_digest:optin_offer']    = '想要每周收到一封简短邮件，告知接下来该重点关注什么吗？';
$string['next_best_action:get_started']           = '从 {$a->title} 开始。打开我并询问"帮我了解 {$a->title}"。';
$string['next_best_action:get_started_with_module'] = '从 {$a->title} 开始。模块"{$a->module}"涵盖了这个内容。';
$string['next_best_action:review']                = '复习 {$a->title} 的基础知识——打开我并询问"像新手一样向我解释 {$a->title}"。';
$string['next_best_action:review_with_module']    = '在"{$a->module}"中复习 {$a->title} 的基础知识，然后带着问题打开我。';
$string['next_best_action:practice']              = '在 {$a->title} 已有的基础上继续构建。打开我并询问"给我一个 {$a->title} 的解题示例"。';
$string['next_best_action:practice_with_module']  = '与"{$a->module}"一起练习 {$a->title}。打开我查看解题示例。';
$string['next_best_action:quiz']                  = '通过快速测验巩固 {$a->title}。打开我并选择"测验我——自适应"。';
$string['next_best_action:quiz_with_module']      = '通过快速测验巩固 {$a->title}。模块"{$a->module}"就是它所在的地方。';
$string['next_best_action:empty_state']           = '现在你在每个目标上都表现出色——没有什么需要提醒的。继续保持。';
$string['next_best_action:header']                = '以下是接下来要重点关注的 {$a} 件事：';
$string['learner_digest:unsubscribe_done_title']  = '已退订';
$string['learner_digest:unsubscribe_done_body']   = '完成——您将不再收到来自 {$a->product} 的本课程每周邮件。您可以随时从课程的聊天窗口重新订阅。';
$string['learner_digest:unsubscribe_invalid_title'] = '退订链接已失效';
$string['learner_digest:unsubscribe_invalid_body']  = '此退订链接已过期或已损坏。您可以从课程设置中管理邮件偏好。';
$string['active_learners:line']                   = '其他 {$a} 人正在学习此课程。';
$string['active_learners:line_global']             = '其他 {$a} 人正在学习。';
$string['settings:active_learners_scope']          = '活跃学习者指示器范围';
$string['settings:active_learners_scope_desc']     = '聊天启动器上方的"其他人正在学习"指示器是仅计算同一课程的学习者，还是计算整个站点的学习者。默认<strong>全局</strong>。';
$string['settings:active_learners_scope_global']   = '全局（任何课程）';
$string['settings:active_learners_scope_course']   = '仅按课程';
$string['learner_digest:optin_yes']      = '好的，给我发送每周邮件';
$string['learner_digest:optin_no']       = '不用，谢谢';
$string['learner_digest:optin_thanks']   = '好的。您将在每周一收到周报。';
$string['learner_digest:optin_declined'] = '好的。没有邮件 - 想要检查时随时打开我即可。';
$string['settings:xai_proxy_url'] = 'xAI Realtime 代理 URL';
$string['settings:xai_proxy_url_desc'] = 'SOLA xAI Realtime 代理服务的公开 wss URL（例如 wss://voice.example.com/xai-rt/rt）。当此项与 JWT 密钥同时设置时，xAI 语音将通过代理转发，主 xAI API 密钥永远不会到达浏览器。留空则回退到直接连接（不建议用于生产环境）。';
$string['settings:xai_proxy_jwt_secret'] = 'xAI Realtime 代理 JWT 密钥';
$string['settings:xai_proxy_jwt_secret_desc'] = '用于为 xAI Realtime 代理签发短期会话令牌的 HS256 共享密钥。必须与 Cloudflare Worker 上的 MOODLE_JWT_SECRET 密钥一致。请定期轮换。';
$string['admin:vendor_dpa:title'] = '{$a} — 供应商 DPA 状态';
$string['admin:vendor_dpa:intro'] = '每个 AI 提供方驱动的训练退出、DPA 与数据保留情况。用此判断在站点上启用哪些驱动。Tier 2 及以上路由需具备已签署的 DPA 和合同层面的训练退出条款。';
$string['admin:vendor_dpa:maintenance_note'] = '此表维护于 classes/vendor_registry.php。在供应商 ToS 变更时请更新。';
$string['settings:contact_email'] = 'Contact email';
$string['settings:contact_email_desc'] = 'General contact email shown on the learner facing privacy notice under "Contact". Defaults to contact@saylor.org. Leave empty to hide the line.';
$string['settings:dpo_email'] = '数据保护官邮箱';
$string['settings:dpo_email_desc'] = '在面向学习者的隐私声明的"联系"部分显示的联系邮箱。留空可隐藏此行。重新品牌化的部署应将此项指向各自的 DPO。';
$string['settings:privacy_external_url'] = '机构隐私页面 URL';
$string['settings:privacy_external_url_desc'] = '指向机构级隐私页面的链接，显示在面向学习者的隐私声明的"联系"部分。留空可隐藏此行。';
$string['settings:privacy_notice_override'] = '隐私声明覆盖（HTML）';
$string['settings:privacy_notice_override_desc'] = '如设置，则此 HTML 将替换 /local/ai_course_assistant/privacy.php 渲染的默认品牌隐私声明。可借此为您的机构插入经法务审阅的文本，无需修改代码。留空则使用默认声明，其文本由七个品牌配置项派生。';
$string['objectives:title'] = '学习目标与掌握度';
$string['objectives:toggles_heading'] = '掌握度跟踪';
$string['objectives:toggle_master'] = '为本课程启用掌握度跟踪';
$string['objectives:toggle_chip'] = '向学生展示"学习掌握度"小标签';
$string['objectives:toggle_chip_help'] = '可选。关闭时，掌握度仍会在后台引导助手，但学习者看不到任何指示。';
$string['objectives:toggled'] = '设置已更新。';
$string['objectives:detected_heading'] = '已从 {$a->source} 检测到 {$a->count} 个学习目标。';
$string['objectives:source_competency'] = 'Moodle 能力';
$string['objectives:source_summary'] = '课程简介';
$string['objectives:source_section'] = '章节或首页内容';
$string['objectives:source_page'] = '课程页面';
$string['objectives:source_llm'] = 'AI 提取';
$string['objectives:source_manual'] = '手动录入';
$string['objectives:source_none'] = '无自动来源';
$string['objectives:import_detected'] = '导入这些已检测到的目标';
$string['objectives:import_llm'] = '使用 AI 提取目标';
$string['objectives:llm_empty'] = 'AI 提取未返回任何目标。请稍后再试或手动录入。';
$string['objectives:imported'] = '已导入 {$a} 个目标。';
$string['objectives:none_detected'] = '未自动检测到学习目标。请在下方手动录入，或使用 AI 提取。';
$string['objectives:list_heading'] = '当前目标';
$string['objectives:col_code'] = '编码';
$string['objectives:col_title'] = '标题';
$string['objectives:col_source'] = '来源';
$string['objectives:col_actions'] = '操作';
$string['objectives:add_heading'] = '添加目标';
$string['objectives:add_submit'] = '添加目标';
$string['objectives:saved'] = '目标已保存。';
$string['objectives:deleted'] = '目标已删除。';
$string['objectives:delete_confirm'] = '删除该目标及其所有尝试历史？';
$string['objectives:delete_all'] = '删除本课程的所有目标';
$string['objectives:delete_all_confirm'] = '删除本课程的每个目标及所有尝试历史？无法撤销。';
$string['objectives:deleted_all'] = '本课程的所有目标已删除。';
$string['mastery:chip_aria'] = '学习掌握度状态';
$string['mastery:popover_aria'] = '学习掌握度详情';
$string['mastery:chip_label'] = '已掌握 {$a->mastered} / {$a->total}';
$string['mastery:status_mastered'] = '已掌握';
$string['mastery:status_learning'] = '进行中';
$string['mastery:status_not_started'] = '未开始';
$string['mastery:popover_empty'] = '本课程未配置任何学习目标。';
$string['settings:mastery_heading'] = '掌握度跟踪';
$string['settings:mastery_heading_desc'] = '按课程启用的可选功能，将测验答题与助手对话轮次按课程学习目标打标签，并把简洁的掌握度快照回填到 system prompt 以引导提问。默认低调：除非启用了按课程的小标签开关，学习者看不到任何提示。';
$string['settings:mastery_threshold'] = '掌握阈值';
$string['settings:mastery_threshold_desc'] = '滚动准确率达到或高于此值时，目标被视为已掌握。0.0 至 1.0。默认 0.85。';
$string['settings:mastery_window'] = '尝试窗口';
$string['settings:mastery_window_desc'] = '每个目标用于计算滚动准确率的最近尝试次数。默认 8。';
$string['settings:mastery_decay_enabled']        = '启用掌握度衰减';
$string['settings:mastery_decay_enabled_desc']   = '开启时，掌握度分数会随时间相对于最近一次尝试的时间戳衰减。之前掌握的目标在足够时间后会回到"学习中"。不会低于"学习中"。<strong>v4.0 中默认关闭。</strong>';
$string['settings:mastery_decay_half_life_days'] = '掌握度衰减半衰期（天）';
$string['settings:mastery_decay_half_life_days_desc'] = '以天为单位的半衰期。分数乘以 0.5 ^（自上次尝试以来的天数 / 半衰期）。默认 30。仅在启用衰减时使用。';
$string['settings:mastery_classifier_model'] = '分类器模型';
$string['settings:mastery_classifier_model_desc'] = '用于将助手对话轮次按目标分类的模型。留空将沿用默认 AI 提供方模型；否则可指定如 gpt-4o-mini 之类的低成本模型。';
$string['settings:mastery_classifier_weight'] = '分类器权重';
$string['settings:mastery_classifier_weight_desc'] = '一次对话尝试相对于一次测验尝试（1.0）的计入权重。默认 0.3。';
$string['settings:mastery_classifier_threshold'] = '分类器置信度阈值';
$string['settings:mastery_classifier_threshold_desc'] = '记录对话尝试所需的最低分类器置信度。0.0 至 1.0。默认 0.7。';
$string['chat:mode_progress'] = '进度';
$string['objectives:toggle_dashboard'] = '向学生展示"进度"仪表盘标签';
$string['objectives:toggle_dashboard_help'] = '可选。在挂件内的对话 / 语音 / 历史旁新增"进度"标签。该标签向学习者展示哪些目标已掌握、哪些进行中、哪些尚未开始。';
$string['mastery:dashboard_title'] = '你的学习进度';
$string['mastery:dashboard_subtitle'] = '掌握度根据你的测验答题和对话练习计算。继续加油——深度胜过广度。';
$string['mastery:dashboard_refresh'] = '刷新';
$string['mastery:section_mastered'] = '已掌握';
$string['mastery:section_learning'] = '进行中';
$string['mastery:section_not_started'] = '尚未开始';
$string['mastery:summary_label'] = '已掌握 {$a->mastered} / {$a->total} 个目标';
$string['mastery:ask_about'] = '就此提问';
$string['mastery:celebrate'] = '你已掌握本课程的每个目标。出色的工作。';
$string['mastery:ask_template'] = '请帮助我练习并加深对这一目标的理解：{$a}。';
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
$string['settings:current_page_content_maxchars_desc'] = '当 RAG 关闭时，作为 "Current Page Content" 部分注入到系统提示中的当前页面文本的最大字符数。默认 8,000 能很好地为针对页面的问题提供依据，同时为结构和指令留出预算。（启用 RAG 后，页面改为由其自身最相关的片段提供依据，并偏向当前页面，因此此上限不适用。）非常长的页面会从头部截断至此字符数，因此极长页面的末尾可能不会被引用；启用 RAG 可避免这种情况。注重成本的站点可以调得更低（例如 3,000-4,000）。限定在 500-8,000 范围内。与 <code>prompt_budget_chars</code> 相互独立：此项仅限制页面部分；而预算限制整个提示。';
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
$string['pedagogy:crossmastery'] = '默认开启跨课程掌握度汇总';
$string['pedagogy:crossmastery_desc'] = '开启后，SOLA 会识别学习者是否已在其他课程中掌握某个目标（通过能力点引用或标题匹配），并认可其先前已具备的能力，而不再重复训练该目标。需启用掌握度追踪；未设置目标的课程将自动平稳回退。仅作为参考提示，不会更改学习者在任何课程中已存储的掌握度分数。';
$string['pedagogy:mastery_starter'] = '默认开启掌握度感知对话开场';
$string['pedagogy:mastery_starter_desc'] = '开启后，"我应该重点学习什么？"这一对话开场会进行个性化处理，指出学习者最薄弱的目标（以及任何已在别处掌握的能力点）。需启用掌握度追踪；当尚无掌握度数据时，将回退到通用对话开场。';
$string['task:rebuild_objective_links'] = '为掌握度汇总重建跨课程目标关联 (v5.7.0)';
$string['mastery_starter:practice_label'] = '练习：{$a}';
$string['objectives:rebuild_links_heading'] = '跨课程掌握度关联';
$string['objectives:rebuild_links_help'] = 'SOLA 会关联跨课程相互匹配的目标（通过能力点引用或标题），使已在别处掌握某主题的学习者无需重复训练。关联每晚自动重建；编辑目标后，可使用此按钮立即重建。';
$string['objectives:rebuild_links_button'] = '立即重建关联';
$string['objectives:rebuild_links_done'] = '已重建跨课程掌握度关联：共 {$a->total} 个（按引用 {$a->ref} 个，标题完全匹配 {$a->exact} 个，标题模糊匹配 {$a->fuzzy} 个）。';

// Forward learning-path awareness (v5.8.0).
$string['pedagogy:program_path'] = '默认开启前向学习路径感知';
$string['pedagogy:program_path_desc'] = '开启后，SOLA 可以告诉学习者当前课程在其项目（学位或证书）中接下来通向何处，以及今天的概念如何衔接到后续课程。该功能读取 Moodle Programs 插件（Degrees 和 Learn），仅在项目定义了先修要求或必修顺序时才指明具体的下一门课程；否则只说明学习者在路径中的位置。仅供参考——它绝不会更改注册或掌握度，且始终只使用当前学习者本人的项目分配。在不适用任何项目的情况下，它会静默地不执行任何操作。';

// Learning path map + next-course nudge (v5.9.0).
$string['pedagogy:learning_path'] = '默认开启学习路径图和下一门课程提示';
$string['pedagogy:learning_path_desc'] = '开启后，SOLA 会添加一个可视化的学习路径面板（小组件标题栏中的"我的路径"按钮），以课程序列的形式展示学员的学习项目，每门课程都可展开查看其目标以及学员的掌握情况。当学员达到当前课程的标准时（完成课程或掌握了较高比例的目标），SOLA 还会温和地显示"已准备好学习下一门课程"的横幅，并在对话中提及。仅供建议参考；使用学员自己的学习项目分配；在不适用任何学习项目时静默不做任何操作。';
$string['settings:learning_path_mastery_threshold'] = '学习路径就绪阈值 (%)';
$string['settings:learning_path_mastery_threshold_desc'] = '学习路径提示将学员视为已准备好学习下一门课程之前，学员必须掌握的课程已跟踪目标的百分比。Moodle 课程完成是另一个触发条件；以先发生者触发提示。默认为 80。';
$string['pathpanel_title'] = '我的学习路径';
$string['pathpanel_open'] = '我的学习路径';
$string['pathpanel_empty'] = '此课程暂无可用的学习项目路径。';
$string['path_position'] = '第 {$a->index} 门课程，共 {$a->total} 门';
$string['path_status_done'] = '已完成';
$string['path_status_current'] = '您在这里';
$string['path_status_upcoming'] = '即将开始';
$string['path_mastery_mastered'] = '已掌握';
$string['path_mastery_in_progress'] = '进行中';
$string['path_mastery_not_started'] = '未开始';
$string['path_mastery_demonstrated_elsewhere'] = '已在其他课程中展示';
$string['nudge_ready_title'] = '准备好继续了';
$string['nudge_ready_body'] = '做得好——您已准备好学习 {$a}。';
$string['nudge_view_path'] = '查看我的路径';
$string['nudge_dismiss'] = '关闭';

// v5.10.x strings (token-aware budgeting, backend retry, self-test, deployment presets, escalation consent, privacy).
$string['settings:backend_context_tokens'] = '后端上下文窗口（令牌）';
$string['settings:backend_context_tokens_desc'] = '您的 AI 后端的最大上下文长度（max_model_len），以令牌为单位。对于具有大窗口的托管模型，设置为 0（不进行裁剪）。当设置为大于 0 时（例如在自托管 vLLM 后端上设置为 8192），SOLA 会缩减上方的系统提示字符预算，以便提示加上预留输出和对话历史能够适应该窗口，即使在令牌密集的语言中也是如此。请参阅 Deployment Sizing 维基页面，了解这如何对应到并发用户数。';
$string['settings:backend_retry_attempts'] = '后端重试次数';
$string['settings:backend_retry_attempts_desc'] = '在向学生显示错误之前，对暂时性后端错误（HTTP 429 或 503）重试的次数。重试仅在任何响应文本开始流式传输之前发生，因此输出永远不会重复。专为在负载下拒绝请求的小型自托管后端而设计。设置为 0 可禁用。默认值为 2。';
$string['settings:backend_retry_max_wait'] = '后端重试最长等待时间（秒）';
$string['settings:backend_retry_max_wait_desc'] = '在重试之前，遵循来自后端的 Retry-After 标头的最长时间上限（以秒为单位）。当后端未发送 Retry-After 时，SOLA 会改用短暂的指数退避。默认值为 5。';
$string['prompt:truncation_hint'] = '注意：由于长度限制，本轮无法搜索完整的课程内容。如果学生询问您在所提供内容中找不到的内容，请说明您无法搜索整个课程，并建议他们打开涵盖该主题的特定页面或活动，而不是声称该内容在课程中不存在。';
$string['selftest:title'] = '后端自检';
$string['selftest:intro'] = '对您配置的 AI 后端运行实时检查：一次微小的聊天往返、自动检测上下文窗口（max_model_len）并与您的后端上下文窗口设置进行比较、系统提示预算下限，以及（在启用 RAG 时）一次嵌入往返。仅在您按下“运行”时才会执行网络调用。';
$string['selftest:run'] = '运行后端自检';
$string['selftest:check'] = '检查';
$string['selftest:status'] = '状态';
$string['selftest:detail'] = '详情';
$string['selftest:link'] = '后端自检页面';
$string['selftest:link_desc'] = '打开<a href="{$a}">后端自检</a>页面，以验证您的 AI 后端是否正常工作且大小配置正确。在配置自托管后端后立即使用十分有用。';
$string['profile:title'] = '部署预设';
$string['profile:intro'] = '为您的部署类型应用一组推荐的设置。这些值会写入常规插件设置中，之后仍可单独编辑。应用预设会覆盖所列的设置。';
$string['profile:current'] = '上次应用的预设：{$a}';
$string['profile:setting'] = '设置';
$string['profile:value'] = '值';
$string['profile:self_hosted_small'] = '自托管小上下文（单 GPU，例如 A30 24GB / 8K 下的 vLLM）';
$string['profile:hosted_large'] = '托管大上下文（默认）';
$string['profile:apply_self_hosted_small'] = '应用自托管小上下文预设';
$string['profile:apply_hosted_large'] = '应用托管大上下文默认值';
$string['profile:applied'] = '已应用 {$a} 预设。这些值现已存入您的插件设置中。';
$string['profile:unknown'] = '未知的部署预设。';
$string['profile:link'] = '部署预设页面';
$string['profile:link_desc'] = '打开<a href="{$a}">部署预设</a>页面，为托管或自托管后端应用一组推荐的设置。';
$string['settings:zendesk_require_consent'] = '在支持升级前要求同意';
$string['settings:zendesk_require_consent_desc'] = '启用时（推荐），只有在学习者接受首次运行同意通知后，SOLA 才会将对话升级到 Zendesk 支持台；该通知会披露请求人工帮助会将对话（包括姓名和电子邮件）共享给支持团队。仅当您以其他方式获得该同意时才关闭此选项；关闭后，升级会立即发送。除非启用了 Zendesk 升级，否则此选项无效。';
$string['chat:escalation_needs_consent'] = '看起来这需要我们支持团队的成员介入。为了将其转交给他们，我必须将本次对话（包括您的姓名和电子邮件）共享给支持台。您尚未同意这样做，因此我尚未发送任何内容。如果您希望获得人工帮助，请接受本助手的数据共享通知并再次提问，或直接联系支持团队。';
$string['privacy:metadata:email_optout'] = '每位收件人的电子邮件退订偏好（收件人已取消订阅哪些电子邮件类型）。';
$string['privacy:metadata:email_optout:email'] = '退订所适用的收件人电子邮件地址。';
$string['privacy:metadata:email_optout:optout_type'] = '收件人已退订的电子邮件类型。';
$string['privacy:metadata:email_optout:userid'] = '退订所属的 Moodle 用户（在已知的情况下）。';
$string['chat:consent_scroll_hint'] = '请滚动到底部阅读完整通知后再继续。';
$string['settings:rag_min_similarity'] = '最低相关度（余弦）';
$string['settings:rag_min_similarity_desc'] = '丢弃与问题的余弦相似度低于此值的检索片段，这样偏离主题或信息稀疏的问题就会注入较少（或不注入）段落，而不是总用弱匹配项填满到 Top-K。范围为 0 到 1；设为 0 会禁用该筛选。合适的取值取决于嵌入模型：0.25 适合 text-embedding-3-small。调高可更严格（上下文更少、更切题），调低则更宽松。';
$string['settings:rag_currentpage_boost'] = '当前页面加成';
$string['settings:rag_currentpage_boost_desc'] = '为学习者当前正在查看的页面所产生的片段，在其相关度得分上添加的一个小幅加分，这样当得分相近时，诸如"解释这个"之类的问题会更倾向于可见页面。仅影响排序：它不会强行让一个不相关的页面片段越过最低相关度筛选。设为 0 可禁用。';
$string['settings:history_mode'] = '历史记录选取模式';
$string['settings:history_mode_desc'] = '在发送给模型之前，如何选取过往的对话轮次。<strong>语义</strong>模式只保留与当前问题相关的近期轮次（并始终保留最近一次往来），这样陈旧、偏离主题的较早轮次就不会增加成本或使回答偏离方向；它每条消息会额外执行一次嵌入调用。<strong>时间近度</strong>模式无论相关与否都保留最后 "Max Conversation History" 对（长期沿用的行为，无额外调用）。如果嵌入不可用，语义模式会自动回退为时间近度模式。';
$string['settings:history_mode_semantic'] = '语义（相关的近期轮次）';
$string['settings:history_mode_recency'] = '时间近度（最后 N 对）';
$string['settings:history_semantic_minscore'] = '历史相关度下限（余弦）';
$string['settings:history_semantic_minscore_desc'] = '在语义历史模式下，只有当某一过往轮次与当前问题的相似度至少达到此值时，才会被保留（最近一次往来始终保留）。范围为 0 到 1；取决于模型。调高可更严格（历史更少），调低则保留更多。';
$string['settings:history_candidates'] = '历史候选窗口';
$string['settings:history_candidates_desc'] = '在语义历史模式下，只有最近的这么多对会被评分以判断相关度（一项成本上限）。早于此窗口的对不会被发送。请将此值保持在等于或大于 "Max Conversation History"。';

// v5.11.0 - v6.2.0 strings (added 2026-06-10).
$string['settings:embed_provider_voyage'] = 'Voyage AI（voyage-3.5 — 推荐；MTEB 比 OpenAI 3-small 高 4 分，上下文窗口大 4 倍，支持多语言）';
$string['settings:rerank_heading'] = 'RAG：两阶段检索（重排序）';
$string['settings:rerank_heading_desc'] = '可选的第二检索阶段：cosine 相似度选出排名前 N 的候选块（默认 50），然后 cross-encoder 重排序器对每个（查询，块）对打分，得分最高的前 K 个进入提示词。默认关闭；重排序器未配置或失败时自动回退到单阶段 cosine 检索。';
$string['settings:rerank_enabled'] = '两阶段检索（Voyage rerank-2.5）';
$string['settings:rerank_enabled_desc'] = '启用后，RAG 检索变为两阶段：cosine 相似度返回前 N 个候选（默认 50），再由 Voyage rerank-2.5 cross-encoder 逐一打分，前 K 个进入提示词。已发布的提升：企业 Recall@10 +15，BEIR NDCG +39%。计费约 $0.05/MTok。需要下方的 <code>rerank_apikey</code>；重排序失败或未配置时会优雅地回退到单阶段 cosine。';
$string['settings:rerank_apikey'] = '重排序 API 密钥';
$string['settings:rerank_apikey_desc'] = '用于 rerank-2.5 的 Voyage AI API 密钥。留空则复用上方的嵌入 API 密钥（典型 Voyage 部署中 embed 与 rerank 共用一个密钥）。';
$string['settings:rerank_model'] = '重排序模型';
$string['settings:rerank_model_desc'] = '默认 <code>rerank-2.5</code>。可在此指定更新的 Voyage 重排序模型。';
$string['settings:rerank_apibaseurl'] = '重排序 API 基础 URL';
$string['settings:rerank_apibaseurl_desc'] = '覆盖 Voyage 重排序基础 URL。留空则使用上方的嵌入 API 基础 URL 或 Voyage 默认值（<code>https://api.voyageai.com/v1</code>）。';
$string['settings:rerank_candidates'] = '重排序候选窗口';
$string['settings:rerank_candidates_desc'] = '送入重排序阶段的 cosine 前 N 个候选数量。默认 50。窗口越大，重排序器可用素材越多，额外成本很小（每次重排序操作约 10k token）。';
$string['settings:stt_selfhosted_heading'] = '自托管转录（Whisper）';
$string['settings:stt_selfhosted_heading_desc'] = '在自己的硬件上以零分钟成本运行语音转文字。将 SOLA 指向任何兼容 OpenAI 的转录服务器：<code>whisper-server</code> Docker、<code>speaches</code>（faster-whisper）或 <code>whisper.cpp</code> 服务器。在此设置服务器 URL 后，它将成为默认 STT 路径；如需覆盖，请在上方"当前 STT 提供商"中选择付费提供商。如果服务器位于私有网络或使用 plain http，还需在安全部分的 SSRF 可信端点白名单中添加其主机。';
$string['settings:stt_selfhosted_url'] = '自托管 STT 服务器 URL';
$string['settings:stt_selfhosted_url_desc'] = '兼容 OpenAI 的转录服务器基础 URL，例如 <code>http://10.0.0.5:8000</code>。SOLA 会自动追加 <code>/v1/audio/transcriptions</code>；也接受完整端点路径。留空则禁用自托管 STT。';
$string['settings:stt_selfhosted_model'] = '自托管 STT 模型';
$string['settings:stt_selfhosted_model_desc'] = '发送给服务器的模型名称，需与服务器已加载的 Whisper 模型匹配——例如 speaches 用 <code>Systran/faster-whisper-small</code> 或 <code>large-v3</code>。留空则发送 <code>whisper-1</code>，大多数自托管服务器均接受或忽略该值。';
$string['settings:stt_selfhosted_apikey'] = '自托管 STT API 密钥';
$string['settings:stt_selfhosted_apikey_desc'] = '可选。大多数自托管服务器在受信任网络后面不需要密钥；仅当您的服务器需要 bearer token 时才设置此项。';
$string['emergency:title'] = 'SOLA 紧急控制';
$string['emergency:page_warning'] = '这些开关立即对站点上的所有学习者生效。每次操作都会写入一条审计记录。细粒度开关保持 SOLA 其余部分正常运行；主 kill 开关会从所有页面完全移除挂件。';
$string['emergency:back_to_settings'] = 'SOLA 设置';
$string['emergency:state_disabled'] = '已禁用';
$string['emergency:state_active'] = '运行中';
$string['emergency:confirm_label'] = '我了解此操作会立即影响所有学习者';
$string['emergency:confirm_required'] = '禁用子系统前，请先勾选确认复选框。';
$string['emergency:reason_placeholder'] = '原因（将记录到审计日志）';
$string['emergency:disable_button'] = '禁用';
$string['emergency:restore_button'] = '恢复';
$string['emergency:disabled_notice'] = '子系统"{$a->flag}"已禁用。触及的配置项：{$a->touched}';
$string['emergency:restored_notice'] = '子系统"{$a->flag}"已恢复。触及的配置项：{$a->touched}';
$string['emergency:cli_reference'] = '相同的控制也可通过值班 shell 执行：';
$string['emergency:flag_chat'] = '聊天';
$string['emergency:flag_chat_desc'] = '通过专用 kill 标志（v5.13 修复）阻断聊天流量。挂件继续渲染；学习者将看到友好的"SOLA 已暂停"提示。在 LLM 提供商行为异常或成本激增时使用。';
$string['emergency:flag_voice'] = '语音';
$string['emergency:flag_voice_desc'] = '清除当前活跃的实时语音提供商（已存储以便精确恢复）。文字聊天继续正常工作。';
$string['emergency:flag_rag'] = 'RAG';
$string['emergency:flag_rag_desc'] = '禁用检索和索引。聊天将在没有课程内容依据的情况下继续。';
$string['emergency:flag_outreach'] = '外联';
$string['emergency:flag_outreach_desc'] = '停止摘要、里程碑和提醒邮件。聊天不受影响。';
$string['emergency:flag_all'] = '主 KILL';
$string['emergency:flag_all_desc'] = '禁用整个插件：挂件从所有页面消失、定时任务停止、语音清除、RAG 关闭、外联关闭。最强力的开关——用于安全事件或需要立即将 SOLA 下线的情况。';
$string['emergency:settings_link'] = '紧急控制';
$string['emergency:settings_link_desc'] = '带审计日志的各子系统 kill 开关（聊天 / 语音 / RAG / 外联 / 主开关）——<code>admin/cli/emergency_disable.php</code> 的网页等价物。打开 <a href="{$a}">SOLA 紧急控制</a>。';
$string['email_unsubscribe:done_title'] = '已退订';
$string['email_unsubscribe:done_body'] = '完成——{$a->email} 将不再收到来自 {$a->product} 的此类邮件。如果您改变主意，请联系 {$a->product} 管理员重新启用订阅，或通过 SOLA Recipients 管理页面发送新的订阅申请。';
$string['email_unsubscribe:invalid_title'] = '退订链接已失效';
$string['email_unsubscribe:invalid_body'] = '此退订链接已过期或格式有误。请查找我们最近发送的邮件，或联系站点管理员手动移除。';
$string['settings:prompt_proportions_heading'] = '提示词各部分比例（v5.6.0）';
$string['settings:prompt_proportions_heading_desc'] = '将系统提示词预算分配到四个桶：安全与身份、课程结构、课程内容和当前页面。权重为百分比，总和为 100。经验调优的默认值（10 / 10 / 40 / 40）来自 v5.6.0 权重调优基准；将文本框留空则使用这些默认值。自动增益会根据特定页面是否在范围内，在每次交互时调整分配。';
$string['settings:prompt_section_weights'] = '基础部分权重（JSON）';
$string['settings:prompt_section_weights_desc'] = '可选 JSON 对象，将每个桶映射到百分比。留空则使用基准默认值（10 / 10 / 40 / 40）。示例：<code>{"safety_identity": 10, "course_structure": 10, "course_content": 40, "current_page": 40}</code>。权重之和必须为 100（±5%）。<code>safety_identity</code> 下限为 10%。<code>current_page + course_content</code> 至少为 40%。超出范围的值将静默回退到基准默认值；管理员应在保存后检查提示词调试日志进行验证。';
$string['settings:prompt_context_boost_mode'] = '上下文增益模式';
$string['settings:prompt_context_boost_mode_desc'] = '自动调整：当特定页面在范围内时将权重向当前页面部分偏移，无页面时向课程内容偏移。<strong>page_focus</strong>（默认）偏移约 15 个权重点。<strong>aggressive</strong> 偏移 25 点，适合学习者经常提出页面特定问题时。<strong>off</strong> 禁用增益；无论页面上下文如何，管理员设置的基础权重每轮均生效。';
$string['settings:prompt_context_boost_off'] = '关闭（每轮使用基础权重）';
$string['settings:prompt_context_boost_page_focus'] = '页面聚焦（默认，约偏移 15 点）';
$string['settings:prompt_context_boost_aggressive'] = '激进（约偏移 25 点）';
$string['settings:prompt_section_weights_coach'] = '辅导模式覆盖（JSON，可选）';
$string['settings:prompt_section_weights_coach_desc'] = '可选 JSON 对象，专门在评分测验辅导模式（<code>quizmode=coach</code>）期间覆盖基础部分权重。适合在不影响普通聊天的情况下，在测验期间强制增大 <code>current_page</code> 分配。留空则继承基础权重。验证规则与基础设置相同。';
$string['prompt_debug_view:title'] = '提示词调试日志查看器';
$string['prompt_debug_view:subtitle'] = '每轮组装的系统提示词 + 各部分分解 + 对话历史 + 当前用户消息 + 附件元数据，与模型收到的完全一致。用于验证当前页面内容等部分是否真正出现在提示词中，以及在不 SSH 登录服务器的情况下调试回答质量问题。';
$string['prompt_debug_view:disabled'] = '提示词调试日志当前处于关闭状态。启用之前不会写入新条目。';
$string['prompt_debug_view:enable_link'] = '打开插件设置以启用"将组装的系统提示词记录到文件"。';
$string['prompt_debug_view:no_log_yet'] = '尚无日志文件。请在启用调试日志后至少发送一条聊天消息；文件将在首次写入时创建。';
$string['prompt_debug_view:empty'] = '日志文件存在但为空。请发送一条聊天消息后刷新。';
$string['prompt_debug_view:file_status'] = '日志文件大小';
$string['prompt_debug_view:showing'] = '显示最新条目（最新在前），限制';
$string['prompt_debug_view:total'] = '提示词总计';
$string['prompt_debug_view:budget'] = '捕获时的预算';
$string['prompt_debug_view:sections'] = '各部分（按类别）';
$string['prompt_debug_view:assembled_prompt'] = '已组装的系统提示词';
$string['prompt_debug_view:history'] = '发送给模型的对话历史';
$string['prompt_debug_view:current_message'] = '当前用户消息';
$string['prompt_debug_view:attachment'] = '附件元数据';
$string['prompt_debug_view:show_more'] = '显示更多条目';
$string['settings:mastery_classifier_provider'] = '分类器提供商';
$string['settings:mastery_classifier_provider_desc'] = '用于每轮精通度分类器的提供商 ID。留空则继承默认 AI 提供商。默认 <code>openai</code> 与下方 <code>gpt-4o-mini</code> 分类器模型配对——结构化输出分类最便宜的 TIER 1 选项（在 100k MAU 下比聊天层每月节省约 $220）。设置后，对比提供商中具有此提供商 ID 的行将提供 API 密钥、基础 URL 和温度参数。';
$string['settings:premium_escalation_heading'] = '高级升级层（A.10）';
$string['settings:premium_escalation_heading_desc'] = '可选的每轮路由，将提示词路由到高级模型（默认 Claude Opus 4.8），适用于主力聊天层明显力不从心的场景——通常是多步数学、CS 和科学推理。由 2026-06-09 A.10 评测确定：Opus 4.8 在难题上以 14.97/15 胜出，gpt-4o 为 12.68/15。两条触发路径：在用户消息上匹配正则表达式，或通过课程白名单对每轮升级。默认关闭。在约 5% 的升级率下，预计在 100k Saylor MAU 基础聊天支出之上每月增加约 $700。';
$string['settings:premium_escalation_enabled'] = '启用高级升级路由';
$string['settings:premium_escalation_enabled_desc'] = '开启后，每轮路由器会对每次聊天调用检查触发正则表达式列表和课程白名单；匹配的轮次路由到高级提供商。若高级行缺失或实例化失败，则回退到主力提供商。管理员 LLM 选择器覆盖始终优先。';
$string['settings:premium_escalation_provider'] = '高级提供商';
$string['settings:premium_escalation_provider_desc'] = '用于路由高级调用的提供商 ID。必须与对比提供商中的某行匹配（以便从管理员统一管理的地方获取 API 密钥、基础 URL 和温度）。默认 <code>claude</code>。';
$string['settings:premium_escalation_model'] = '高级模型';
$string['settings:premium_escalation_model_desc'] = '传递给高级提供商的模型名称。根据 A.10 评测结论，默认 <code>claude-opus-4-8</code>。';
$string['settings:premium_escalation_triggers'] = '高级触发正则表达式';
$string['settings:premium_escalation_triggers_desc'] = '每行一个 PCRE 正则表达式（不含分隔符；自动应用不区分大小写匹配）。以 # 开头的行为注释。留空则使用来自 A.10 评测的精选默认集（多步 STEM 标记："derive"、"prove that"、"step by step"、LaTeX 数学、fenced 代码块、大 O 表示法、积分、优化等）。';
$string['settings:premium_escalation_course_tags'] = '高级课程白名单';
$string['settings:premium_escalation_course_tags_desc'] = '每行一个课程简称或 ID 号前缀。匹配课程中的每轮交互均会自动升级，不受消息正则表达式限制（适用于 STEM 密集型课程，应将升级设为默认行为）。匹配为不区分大小写的前缀——"MATH" 匹配 MATH121、MATH205 等。';
$string['settings:spend_cap_per_course_default'] = '默认每课程支出上限（美元）';
$string['settings:spend_cap_per_course_default_desc'] = '应用于所有未配置独立每课程支出上限的课程的保护性上限。例如设为 <code>30</code>，可在不单独调整各课程的情况下，将任意单门课程的月支出限制在 $30。<code>0</code> = 无默认值（仅适用站点级和每课程覆盖上限）。当课程超过此上限的 80% / 95% / 100% 时，现有的 spend-guard 提醒流程将发送管理员通知（收件人列表：<code>spend_notify_emails</code>，回退到站点管理员）。具体课程始终可通过设置更高的独立覆盖上限来提升自己的上限。';
$string['settings:cost_anomaly_heading'] = '成本异常检测器（v6.0）';
$string['settings:cost_anomaly_heading_desc'] = '每日定时任务（<code>cost_anomaly_check</code>），将当日站点级 SOLA 支出与滚动 7 日中位数进行比较。当今日支出超过配置的倍数 × 中位数时，向 <code>spend_notify_emails</code> 收件人列表（回退到站点管理员）发送邮件。可捕获现有 80% / 95% / 100% 支出上限阈值遗漏的三种故障模式：(1) 单课程突发流量远超其常规量、(2) 意外启用高级层、(3) 提供商路由错误。默认关闭。';
$string['settings:cost_anomaly_enabled'] = '启用成本异常检测器';
$string['settings:cost_anomaly_enabled_desc'] = '开启后，每日定时任务会将当日支出与滚动 7 日中位数对比，并在检测到异常时向管理员发送邮件。启用后的头 7 天会显示 <code>insufficient_history</code> 状态（尚无历史基线）且不发送告警。每日幂等：<code>config_plugins</code> 中的标志会在 cron 多次运行时阻止重复发送邮件。';
$string['settings:cost_anomaly_multiplier'] = '异常倍数';
$string['settings:cost_anomaly_multiplier_desc'] = '当日支出必须超过此倍数 × 7 日中位数才会触发告警。默认 <code>2.0</code>。降低到 <code>1.5</code> 可提前预警（在招生高峰期可能产生更多误报）。若 Saylor 使用量的 2 倍波动属于常态，可调高到 <code>3.0</code>。';
$string['task:cost_anomaly_check'] = 'SOLA 成本异常检查（每日）';

$string['settings:policy_bundle_heading'] = '已签名的策略包（远程行为更新）';
$string['settings:policy_bundle_heading_desc'] = '从经过加密签名的 JSON 文件应用行为设置（提示词、路由、升级触发器、RAG 调优、支出策略），无需代码部署。每日计划任务将获取包的 URL，根据下方公钥验证其 Ed25519 签名，仅在每个键均在内置允许列表中且包版本比上次应用版本更新时才应用设置。API 密钥、URL、webhook 及安全设置永远无法通过包进行设置。使用 <code>admin/cli/policy_bundle_tool.php</code>（keygen, sign, verify, status, sync）创建并签名包。';
$string['settings:policy_bundle_enabled'] = '启用策略包同步';
$string['settings:policy_bundle_enabled_desc'] = '启用后，每日任务将获取并应用已签名的包。默认关闭。禁用后立即停止所有同步；已应用的设置保留其值。';
$string['settings:policy_bundle_url'] = '策略包 URL';
$string['settings:policy_bundle_url_desc'] = '已签名包 JSON 的 HTTPS URL（例如 S3 对象或 GitHub 原始 URL）。该 URL 与 AI 提供商端点经过相同的 SSRF 验证；私有网络或 plain-http 主机需要在 SSRF 可信端点允许列表中添加条目。';
$string['settings:policy_bundle_pubkey'] = '策略包公钥';
$string['settings:policy_bundle_pubkey_desc'] = '用于验证包签名的 Base64 Ed25519 公钥。使用 <code>policy_bundle_tool.php --keygen</code> 生成密钥对；私钥由包作者保管，绝不能上传至任何地方。';
$string['settings:policy_bundle_status'] = '上次同步';
$string['settings:policy_bundle_applied_version'] = '已应用版本';
$string['task:policy_bundle_sync'] = 'SOLA 已签名策略包同步';
$string['policy_bundle:invalid'] = '策略包已拒绝：{$a}';

// v6.4.0 signed policy bundle strings (added 2026-06-11).
$string['settings:policy_bundle_heading'] = '签名策略包（远程行为更新）';
$string['settings:policy_bundle_heading_desc'] = '从经过加密签名的 JSON 文件中应用行为设置（提示词、路由、升级触发器、RAG 调整、支出策略），无需代码部署。每日定时任务获取包的 URL，根据下方的公钥验证其 Ed25519 签名，仅在每个键均在内置允许列表中且包版本比上次应用版本更新时才应用设置。API 密钥、URL、webhook 和安全设置永远无法通过包来设置。使用 <code>admin/cli/policy_bundle_tool.php</code> 创建和签名包（keygen、sign、verify、status、sync）。';
$string['settings:policy_bundle_enabled'] = '启用策略包同步';
$string['settings:policy_bundle_enabled_desc'] = '启用后，每日任务将获取并应用已签名的包。默认关闭。禁用将立即停止所有同步；已应用的设置保留其值。';
$string['settings:policy_bundle_url'] = '策略包 URL';
$string['settings:policy_bundle_url_desc'] = '已签名包 JSON 的 HTTPS URL（例如 S3 对象或 GitHub raw URL）。该 URL 经过与 AI 提供商端点相同的 SSRF 验证；私有网络或 plain-http 主机需要在 SSRF 可信端点允许列表中添加条目。';
$string['settings:policy_bundle_pubkey'] = '策略包公钥';
$string['settings:policy_bundle_pubkey_desc'] = '用于验证包签名的 Base64 Ed25519 公钥。使用 <code>policy_bundle_tool.php --keygen</code> 生成密钥对；私钥由包的作者保管，绝不能上传到任何地方。';
$string['settings:policy_bundle_status'] = '最近同步';
$string['settings:policy_bundle_applied_version'] = '已应用版本';
$string['task:policy_bundle_sync'] = 'SOLA 签名策略包同步';
$string['policy_bundle:invalid'] = '策略包已拒绝：{$a}';
$string['prompt_debug_view:retrieved_chunks'] = '检索到的文本块（RAG 选取）';
$string['prompt_debug_view:retrieved_chunks_hint'] = '检索器为此问题选取的段落，按排名顺序列出，并附有相关性评分和来源（cmid）。可用于验证模型是否获得了最匹配的课程内容。';
$string['settings:avatar_animation_enabled'] = '头像动画';
$string['settings:avatar_animation_enabled_desc'] = '为生成的SVG头像添加动画效果：空闲时眨眼，以及在助手说话时与文字转语音音频同步的嘴部动作。遵循学习者设备的减少动画偏好。每门课程的A/B测量覆盖：将配置值avatar_animation_course_COURSEID设为0或1。';
