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
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname'] = 'Saylor 在线学习助手 (SOLA)';
$string['error'] = '{$a}';
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
$string['settings:provider'] = 'AI 提供商';
$string['settings:provider_desc'] = '选择用于聊天补全的 AI 提供商。';
$string['settings:provider_claude'] = 'Claude（Anthropic）';
$string['settings:provider_openai'] = 'OpenAI';
$string['settings:provider_deepseek'] = 'DeepSeek';
$string['settings:provider_ollama'] = 'Ollama（本地）';
$string['settings:provider_minimax'] = 'MiniMax';
$string['settings:provider_gemini'] = 'Google Gemini';
$string['settings:provider_custom'] = '自定义（OpenAI 兼容）';
$string['settings:apikey'] = 'API 密钥';
$string['settings:apikey_desc'] = '所选提供商的 API 密钥。Ollama 不需要此项。';
$string['settings:model'] = '模型名称';
$string['settings:model_desc'] = '要使用的模型。默认值取决于提供商（例如 claude-sonnet-4-5-20250929、gpt-4o、llama3、MiniMax-Text-01）。';
$string['settings:apibaseurl'] = 'API 基础 URL';
$string['settings:apibaseurl_desc'] = 'API 的基础 URL。会根据提供商自动填写，但可以覆盖。留空则使用提供商默认值。';
$string['settings:systemprompt'] = '系统提示模板';
$string['settings:systemprompt_desc'] = '发送给 AI 的系统提示。可使用占位符：{{coursename}}、{{userrole}}、{{coursetopics}}。';
$string['settings:systemprompt_default'] = '你是 SOLA（Saylor 在线学习助手），为选修「{{coursename}}」的 Saylor Academy 学生提供学习的 AI 辅导。学生角色为 {{userrole}}。

## 角色
提供与课程一致的学业支持，鼓励学习、练习、动力和负责任的 AI 使用。你辅助教师设计的课程，但不替代教师。

## 核心规则
- 所有学业回答均基于认可的课程材料或机构信息。
- 不编造内容或超出课程范围。
- 当问题超出课程时，将学习者引回课程材料。在两次偏题后，将对话引导回学习。
- 生成练习题时，直接依据课程材料。

## 课程结构
{{coursetopics}}

## 课程内容
以下为课程页面与材料的实际文本，是你对本课程的主要知识来源。

{{coursecontent}}

## SOLA 可提供的帮助
- 解释概念、总结课程
- 举例与练习题
- 建议学习策略
- 鼓励坚持与进步

## SOLA 不会做的事
- 做学业或政策决定
- 提供医疗、法律或心理健康咨询
- 协助学业不诚信或绕过学习

## 语气与风格
以友好、关心、鼓励、风趣、激励的方式交流。简洁、支持、尊重。

## 安全
不参与辱骂、仇恨、歧视或不适当对话。设定坚定而友善的边界，并引导至有益话题。';
$string['remoteconfigurl'] = '远程配置 URL';
$string['remoteconfigurl_desc'] = '指向包含远程管理的 SOLA 配置（系统提示、指令块、模型默认值）的 JSON 文件的 URL。必须为 HTTPS。留空则使用默认 GitHub URL。本地管理员设置始终优先于远程配置。';
$string['settings:temperature'] = '温度';
$string['settings:temperature_desc'] = '控制随机性。值越低越集中，越高越有创意。范围：0.0 至 2.0。';
$string['settings:maxhistory'] = '最大会话历史';
$string['settings:maxhistory_desc'] = 'API 请求中包含的最大消息对数量。更早的消息会被截断。';
$string['settings:avatar'] = '聊天头像';
$string['settings:avatar_desc'] = '选择聊天小部件按钮的头像图标。';
$string['settings:avatar_saylor'] = 'Saylor Academy 徽标（默认）';
$string['settings:avatar_color'] = '头像边框颜色';
$string['settings:avatar_color_desc'] = '浮动头像按钮的边框颜色。使用十六进制值，如 #4a6cf7。';
$string['settings:avatar_fill'] = '头像背景颜色';
$string['settings:avatar_fill_desc'] = '浮动头像按钮内部填充颜色（在透明区域后方显示）。使用十六进制值，如 #ffffff。';
$string['settings:display_mode'] = '显示模式';
$string['settings:display_mode_desc'] = 'SOLA 在页面上的呈现方式。「小部件」为浮动头像按钮与弹出聊天面板；「侧边抽屉」为从屏幕右侧滑入的全高面板。';
$string['settings:display_mode_widget'] = '小部件（浮动按钮）';
$string['settings:display_mode_drawer'] = '侧边抽屉（右侧）';
$string['settings:position'] = '小部件位置';
$string['settings:position_desc'] = '聊天小部件在页面上的位置（仅在小部件模式下生效）。';
$string['settings:position_br'] = '右下角';
$string['settings:position_bl'] = '左下角';
$string['settings:position_tr'] = '右上角';
$string['settings:position_tl'] = '左上角';
$string['chat:settings'] = '插件设置';
$string['analytics:viewdashboard'] = '查看分析仪表板';

// Course settings (per-course AI provider override).
$string['coursesettings:title'] = '课程 AI 设置';
$string['coursesettings:enabled'] = '启用课程覆盖';
$string['coursesettings:enabled_desc'] = '启用后，以下设置仅针对本课程覆盖全局 AI 提供商配置。留空则继承全局值。';
$string['coursesettings:using_global'] = '使用全局设置';
$string['coursesettings:saved'] = '课程 AI 设置已保存。';
$string['coursesettings:ell_pronunciation'] = '发音练习模式';
$string['coursesettings:ell_pronunciation_desc'] = '在本课程中为学生显示「发音练习」芯片。使用 OpenAI Realtime API 提供音素级发音反馈。需在全局插件设置中启用语音模式。';
$string['coursesettings:ell_pronunciation_enable'] = '为本课程启用发音练习芯片';
$string['coursesettings:rag'] = '语义搜索 (RAG)';
$string['coursesettings:rag_desc'] = '为本课程启用检索增强生成。启用后，SOLA 将嵌入并搜索课程内容以支撑回答。需在插件设置中全局启用 RAG。';
$string['coursesettings:rag_enable'] = '为本课程启用 RAG';
$string['coursesettings:speaking_practice'] = '口语练习';
$string['coursesettings:speaking_practice_desc'] = '在本课程中为学生显示「练习口语」芯片。使用 OpenAI TTS 进行语音回复。需在全局插件设置中配置 OpenAI API 密钥。';
$string['coursesettings:speaking_practice_enable'] = '为本课程启用口语练习芯片';
$string['coursesettings:global_settings_link'] = '全局 AI 设置';
$string['coursesettings:token_usage'] = 'Token 使用量与成本';
$string['coursesettings:token_usage_desc'] = '查看本课程的 token 使用、成本估算及按学生的明细。';

// Language detection and preference.
$string['lang:switch'] = '是，切换';
$string['lang:dismiss'] = '不，谢谢';
$string['lang:change'] = '更改语言';
$string['lang:english'] = '英语';

// Chat widget.
$string['chat:title'] = 'SOLA';
$string['chat:placeholder'] = '请输入您的问题...';
$string['chat:send'] = '发送';
$string['chat:close'] = '关闭聊天';
$string['chat:open'] = '打开 SOLA';
$string['chat:change_avatar'] = '更换头像';
$string['chat:clear'] = '清除历史记录';
$string['chat:clear_confirm'] = '您确定要清除本课程的聊天历史记录吗？';
$string['chat:copy'] = '复制会话';
$string['chat:copied'] = '会话已复制到剪贴板';
$string['chat:copy_failed'] = '复制会话失败';
$string['chat:greeting'] = '你好，{$a}！我是 SOLA，你的 Saylor 在线学习助手。';
$string['chat:thinking'] = '正在思考...';
$string['chat:error'] = '很抱歉，出现了一些问题。请重试。';
$string['chat:error_auth'] = '身份验证错误。请联系您的管理员。';
$string['chat:error_ratelimit'] = '请求过多。请稍等片刻后重试。';
$string['chat:error_unavailable'] = 'AI 服务暂时不可用。请稍后再试。';
$string['chat:error_notconfigured'] = 'SOLA 尚未配置。请联系您的管理员。';
$string['chat:expand'] = '展开聊天';
$string['chat:collapse'] = '收起聊天';
$string['chat:mic'] = '语音提问';
$string['chat:mic_error'] = '麦克风错误。请检查您的浏览器权限。';
$string['chat:mic_unsupported'] = '此浏览器不支持语音输入。';
$string['chat:newline_hint'] = 'Shift+Enter 换行';
$string['chat:you'] = '你';
$string['chat:assistant'] = 'SOLA';
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
$string['settings:zendesk_subdomain_desc'] = '您的 Zendesk 子域名（例如 mycompany.zendesk.com 中的「mycompany」）。';
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
$string['settings:whatsapp_api_url_desc'] = '发送 WhatsApp 消息的 API 端点（例如 Twilio、MessageBird）。必须接受包含「to」、「from」和「body」字段的 JSON 正文 POST 请求。';
$string['settings:whatsapp_api_token'] = 'WhatsApp API 令牌';
$string['settings:whatsapp_api_token_desc'] = 'WhatsApp API 的身份验证令牌。';
$string['settings:whatsapp_from_number'] = 'WhatsApp 发件号码';
$string['settings:whatsapp_from_number_desc'] = '发送 WhatsApp 消息的电话号码（含国家代码，例如 +14155238886）。';
$string['settings:whatsapp_blocked_countries'] = 'WhatsApp 屏蔽国家';
$string['settings:whatsapp_blocked_countries_desc'] = '因当地法规不允许发送 WhatsApp 提醒的国家的 ISO 3166-1 alpha-2 国家代码（逗号分隔，例如「CN,IR,KP」）。';

// RAG / Semantic Search settings.
$string['settings:rag_heading'] = 'RAG / 语义搜索';
$string['settings:rag_heading_desc'] = '检索增强生成：将课程内容索引为嵌入向量，在查询时仅检索最相关的片段。减少 token 使用并支持所有内容类型。需要嵌入 API。';
$string['settings:rag_enabled'] = '启用 RAG（语义搜索）';
$string['settings:rag_enabled_desc'] = '启用后，AI 辅导使用语义搜索为每次查询检索相关课程内容，而非将所有内容填入系统提示。';
$string['settings:embed_provider'] = '嵌入提供商';
$string['settings:embed_provider_desc'] = '用于 RAG 索引与检索的文本嵌入 API 提供商。';
$string['settings:embed_provider_openai'] = 'OpenAI (text-embedding-3-small)';
$string['settings:embed_provider_ollama'] = 'Ollama（本地，如 nomic-embed-text）';
$string['settings:embed_apikey'] = '嵌入 API 密钥';
$string['settings:embed_apikey_desc'] = '嵌入提供商的 API 密钥。可与聊天 API 密钥不同。Ollama 不需要。';
$string['settings:embed_model'] = '嵌入模型';
$string['settings:embed_model_desc'] = '用于生成嵌入的模型。OpenAI 默认：text-embedding-3-small。Ollama 示例：nomic-embed-text。';
$string['settings:embed_apibaseurl'] = '嵌入 API 基础 URL';
$string['settings:embed_apibaseurl_desc'] = '嵌入 API 的基础 URL。留空使用 OpenAI 默认。Ollama：http://localhost:11434/api';
$string['settings:embed_dimensions'] = '嵌入维度';
$string['settings:embed_dimensions_desc'] = '嵌入向量的维度数。须与模型输出一致。OpenAI text-embedding-3-small：1536。nomic-embed-text：768。';
$string['settings:rag_topk'] = 'Top-K 片段数';
$string['settings:rag_topk_desc'] = '每次用户查询检索并注入系统提示的最相关片段数量。';
$string['settings:rag_chunksize'] = '片段大小（词数）';
$string['settings:rag_chunksize_desc'] = '索引课程材料时每段内容的目标词数。较小片段更精确；较大片段提供更多上下文。';

// Reminder messages.
$string['reminder:email_subject'] = '学习提醒：{$a}';
$string['reminder:email_body'] = '您好 {$a->firstname}，

这是您关于「{$a->coursename}」的学习提醒。

{$a->message}

您的学习计划建议每周为本课程投入 {$a->hours_per_week} 小时。

继续保持，加油！

---
如需取消接收提醒，请点击此处：{$a->unsubscribe_url}';
$string['reminder:whatsapp_body'] = '{$a->coursename} 的学习提醒：{$a->message}（退订：{$a->unsubscribe_url}）';
$string['reminder:study_tip_prefix'] = '今日学习重点：';

// Unsubscribe page.
$string['unsubscribe:title'] = '退订学习提醒';
$string['unsubscribe:success'] = '您已成功退订本课程的学习提醒。';
$string['unsubscribe:already'] = '您已经退订了这些提醒。';
$string['unsubscribe:invalid'] = '退订链接无效或已过期。';
$string['unsubscribe:resubscribe'] = '改变主意了？您可以通过 AI 辅导聊天重新启用提醒。';

// Scheduled tasks.
$string['task:send_reminders'] = '发送 AI 辅导学习提醒';
$string['task:index_course_content'] = '为 RAG 语义搜索索引课程内容';
$string['task:send_inactivity_reminders'] = '发送每周不活跃提醒邮件';
$string['task:run_integrity_checks'] = '运行每日 SOLA 插件完整性检查';
$string['messageprovider:study_notes'] = '学习会话笔记';
$string['messageprovider:integrity_report'] = 'SOLA 完整性检查失败报告';

// Plugin Updates.
$string['update:title'] = '插件更新';
$string['update:desc'] = '从 GitHub 发布直接检查并安装 SOLA 插件更新。';
$string['update:check'] = '检查更新';
$string['update:install'] = '安装更新';
$string['update:current_version'] = '已安装版本';
$string['update:latest_version'] = '最新可用版本';
$string['update:up_to_date'] = '已是最新';
$string['update:available'] = '有可用更新';
$string['update:confirm'] = '安装此更新？将自动创建当前版本的备份。';
$string['update:changelog'] = '发布说明';
$string['update:back_to_settings'] = '返回设置';
$string['update:github_error'] = '无法连接 GitHub。请检查网络或在设置中添加 GitHub 令牌。';
$string['update:download_failed'] = '下载更新失败。请重试或手动安装。';
$string['update:github_token'] = 'GitHub 令牌（可选）';
$string['update:github_token_desc'] = '用于访问私有 GitHub 仓库的个人访问令牌。公开仓库留空即可。';

// Analytics Export (Redash).
$string['redash_heading'] = '分析导出';
$string['redash_heading_desc'] = '为 Redash 等外部分析平台配置 API 密钥访问。导出端点提供对使用数据、反馈和成本分析的只读 JSON 访问。';
$string['redash_api_key'] = 'Redash API 密钥';
$string['redash_api_key_desc'] = '供 Redash 等外部分析平台使用的 API 密钥。提供对使用数据、反馈和成本分析的只读访问。留空则禁用导出端点。';

// Integrity Checks.
$string['integrity:title'] = '完整性检查';
$string['integrity:desc'] = '每日自动健康检查，验证 PHP 语法、JS 构建、语言文件、数据库表等。仅在发现问题时发送邮件通知。';
$string['integrity:enabled'] = '启用每日完整性检查';
$string['integrity:enabled_desc'] = '每天凌晨 3 点（服务器时间）运行自动插件健康检查。';
$string['integrity:email'] = '报告邮箱地址';
$string['integrity:email_desc'] = '失败报告的接收邮箱。留空则通知主站管理员。';
$string['integrity:view_results'] = '查看完整性结果';
$string['integrity:run_now'] = '立即运行检查';

// RAG admin page.
$string['ragadmin:title'] = 'RAG 索引状态与重新索引';
$string['ragadmin:back_to_settings'] = '返回插件设置';
$string['ragadmin:view_status'] = '查看 RAG 索引状态 / 重新索引';
$string['ragadmin:rag_disabled_notice'] = 'RAG 当前已禁用。请在插件设置中启用以激活语义搜索。您仍可在下方预索引课程，以便启用 RAG 时索引就绪。';
$string['ragadmin:reindexall'] = '重新索引所有活跃课程';
$string['ragadmin:reindexall_desc'] = '对所有有在册学员的课程运行增量索引。仅重新嵌入新增或变更的内容。';
$string['ragadmin:reindexall_confirm'] = '将针对所有活跃课程的新增/变更内容调用嵌入 API。是否继续？';
$string['ragadmin:reindexall_done'] = '重新索引完成：已处理 {$a->courses} 门课程 — 已索引 {$a->indexed} 个片段，跳过 {$a->skipped}，{$a->errors} 个错误。';
$string['ragadmin:reindexcourse_done'] = '课程已重新索引：已索引 {$a->indexed} 个片段，跳过 {$a->skipped}，{$a->errors} 个错误。';
$string['ragadmin:deleteindex'] = '清除索引';
$string['ragadmin:deleteindex_confirm'] = '删除本课程的所有已索引片段？在课程重新索引前，AI 辅导将回退为完整内容填充。';
$string['ragadmin:deleteindex_done'] = '课程索引已清除。';
$string['ragadmin:index_status'] = '各课程索引状态';
$string['ragadmin:no_courses'] = '未找到已索引课程和活跃课程。';
$string['ragadmin:never'] = '从未';
$string['ragadmin:reindex'] = '重新索引';
$string['ragadmin:stat_courses_indexed'] = '已索引课程数';
$string['ragadmin:stat_total_chunks'] = '总片段数';
$string['ragadmin:stat_embedded_chunks'] = '已嵌入片段数';
$string['ragadmin:stat_active_courses'] = '活跃课程数';
$string['ragadmin:col_course'] = '课程';
$string['ragadmin:col_chunks'] = '片段数';
$string['ragadmin:col_embedded'] = '已嵌入';
$string['ragadmin:col_lastindexed'] = '上次索引时间';
$string['ragadmin:col_actions'] = '操作';

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
$string['usersettings:confirm_delete_course'] = '您确定要永久删除课程「{$a}」中的所有 AI 辅导数据吗？此操作无法撤销。';
$string['usersettings:confirm_delete_all'] = '您确定要永久删除您在所有课程中的所有 AI 辅导数据吗？此操作无法撤销。';
$string['usersettings:data_deleted'] = '您的数据已被删除。';

// Quiz.
$string['chat:quiz'] = '参加练习测验';
$string['chat:quiz_setup_title'] = '练习测验';
$string['chat:quiz_questions'] = '题目数量';
$string['chat:quiz_topic'] = '主题';
$string['chat:quiz_topic_guided'] = 'AI 引导（基于你的学习进度）';
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
$string['chat:quiz_summary'] = '我刚完成了关于「{$a->topic}」的 {$a->total} 题练习测验，得分 {$a->score}/{$a->total}。';

// Quiz topic categories.
$string['chat:quiz_topic_objectives'] = '学习目标';
$string['chat:quiz_topic_modules'] = '课程主题';
$string['chat:quiz_subtopic_select'] = '选择具体项目…';
$string['chat:quiz_topic_sections'] = '课程章节';

// Quiz end screen.
$string['chat:quiz_score_great'] = '出色！你真的掌握了这些内容。';
$string['chat:quiz_score_good'] = '不错！继续复习以加深理解。';
$string['chat:quiz_score_practice'] = '继续练习——先复习相关课程材料，然后重新参加测验。';
$string['chat:quiz_review_heading'] = '复习';
$string['chat:quiz_retake'] = '重新测验';
$string['chat:quiz_exit'] = '退出测验';
$string['chat:quiz_your_answer'] = '你的答案';
$string['chat:quiz_correct_answer'] = '正确答案';

// Conversation starters.
$string['chat:starters_label'] = '对话开始建议';
$string['chat:starter_help_page'] = '帮助理解本页';
$string['chat:starter_quiz'] = '测验我';
$string['chat:starter_study_plan'] = '学习计划';
$string['chat:starter_ask_anything'] = '随便问';
$string['chat:starter_review_practice'] = '复习与练习';
$string['chat:starter_ell_practice'] = '口语练习';
$string['chat:starter_ell_pronunciation'] = '发音练习';
$string['chat:starter_speak'] = '朗读开始语';
$string['chat:starter_explain'] = '解释这个';
$string['chat:starter_key_concepts'] = '关键概念';
$string['chat:starter_help_me'] = 'AI 帮助';
$string['chat:starter_ai_coach'] = 'AI 辅导';
$string['chat:starter_quick_study'] = '快速学习';
$string['chat:starter_help_lesson'] = '解释这个';
$string['chat:starter_prompt_coach'] = 'AI 提示辅导';
$string['chat:starter_help_lesson_prompt'] = '能帮我理解当前课程吗？请总结一下关键概念。';
$string['chat:starter_study_plan_prompt'] = '我想规划今天的学习。请问我：（1）今天想完成什么，（2）有多少时间。如果之前讨论过学习计划，请在此基础上补充或根据我的回答更新。';
$string['chat:starter_explain_prompt'] = '能解释一下目前课程中最重要的概念吗？';

// Reset / home button.
$string['chat:reset'] = '重新开始';

// Starter admin settings.
$string['starters:admin_title'] = '对话开始建议设置';
$string['starters:admin_desc'] = '配置学生打开 SOLA 聊天时显示的对话开始芯片。可拖拽排序、开关启用/禁用，或添加带自定义 AI 提示的新建议。';
$string['starters:add_new'] = '添加新建议';
$string['starters:save'] = '保存更改';
$string['starters:saved'] = '建议配置已保存。';
$string['starters:reset_defaults'] = '恢复默认';
$string['starters:reset_confirm'] = '将所有建议恢复为内置默认？自定义建议将被删除。';
$string['starters:reset_done'] = '已恢复为默认建议。';
$string['starters:back_settings'] = '返回设置';
$string['starters:course_section'] = '对话开始建议';
$string['starters:course_desc'] = '为本课程启用或禁用各条建议。';

// Topic picker (used by conversation starters).
$string['chat:topic_picker_title'] = '你想专注于什么？';
$string['chat:topic_picker_title_help'] = '你需要哪方面的帮助？';
$string['chat:topic_picker_title_explain'] = '你想让我解释什么？';
$string['chat:topic_picker_title_study'] = '你想专注于哪个领域？';
$string['chat:topic_start'] = '继续';

// Expand states.
$string['chat:fullscreen'] = '全屏';
$string['chat:exitfullscreen'] = '退出全屏';

// Globe language picker in header.
$string['chat:language'] = '更改语言';
$string['chat:settings_panel'] = '设置';
$string['chat:settings_language'] = '语言';
$string['chat:settings_avatar'] = '头像';
$string['chat:settings_voice'] = '语音';
$string['chat:settings_voice_admin'] = '语音设置在网站管理面板中管理。';

// Voice mode (OpenAI Realtime).
$string['chat:voice_mode'] = '语音模式';
$string['chat:voice_title'] = '与 SOLA 对话';
$string['chat:voice_copy'] = '与你的学习助手进行自然语音对话。';
$string['chat:voice_ready'] = '准备就绪';
$string['chat:voice_start'] = '开始对话';
$string['chat:voice_end'] = '结束语音会话';
$string['chat:voice_connecting'] = '正在连接...';
$string['chat:voice_listening'] = '正在聆听...';
$string['chat:voice_speaking'] = 'SOLA 正在说话...';
$string['chat:voice_idle'] = '就绪';
$string['chat:voice_error'] = '语音连接失败，请检查您的设置。';
$string['chat:quiz_locked'] = '测验期间 SOLA 已暂停，以维护学术诚信。祝你好运！';

// Bottom navigation modes.
$string['chat:mode_nav'] = '模式切换';
$string['chat:mode_chat'] = '聊天';
$string['chat:mode_voice'] = '语音';
$string['chat:mode_history'] = '历史';

// History panel.
$string['chat:history_title'] = '对话历史';
$string['chat:history_subtitle'] = '你在本课程的最近消息。';
$string['chat:history_empty'] = '暂无对话。';
$string['chat:history_refresh'] = '刷新';

// Debug panel.
$string['chat:debug_context'] = '上下文调试';
$string['chat:debug_context_toggle'] = '切换上下文调试检查器';
$string['chat:debug_context_copy'] = '复制';
$string['chat:debug_context_browser'] = '浏览器快照';
$string['chat:debug_context_request'] = '最近 SSE 请求';
$string['chat:debug_context_prompt'] = '服务器响应';

// Quiz hide settings.
$string['settings:quiz_hide_heading'] = '测验页可见性';
$string['settings:quiz_hide_heading_desc'] = '控制 SOLA 小部件是否在 Moodle 测验页显示。比仅禁用计分测验期间聊天的内置设置更严格，此处可在所有测验页完全隐藏小部件。';
$string['settings:hide_on_quiz_for_students'] = '对学生隐藏测验页上的 SOLA';
$string['settings:hide_on_quiz_for_students_desc'] = '在所有测验页（查看、作答、回顾）完全对学生隐藏 SOLA 小部件。';
$string['settings:hide_on_quiz_for_staff'] = '对教职工隐藏测验页上的 SOLA';
$string['settings:hide_on_quiz_for_staff_desc'] = '对所有测验页上的教师和管理员完全隐藏 SOLA 小部件。';

// Wellbeing & Safety settings.
$string['settings:wellbeing_heading'] = '身心健康与安全';
$string['settings:wellbeing_heading_desc'] = '启用后，SOLA 会检测痛苦或危机表达，并以同理心和全球适用支持资源（findahelpline.com、Crisis Text Line、Befrienders Worldwide）回应。SOLA 不是咨询师——会承认感受、引导学生寻求人工支持，且不做诊断或治疗。';
$string['settings:wellbeing_enabled'] = '启用身心健康支持';
$string['settings:wellbeing_enabled_desc'] = '启用后，SOLA 将检测情绪困扰迹象并给出带全球危机资源链接的共情回复。若机构自有危机响应且不希望 SOLA 展示外部资源，请禁用。';

// Voice mode settings.
$string['settings:realtime_heading'] = '语音模式（OpenAI Realtime）';
$string['settings:realtime_enabled'] = '启用语音模式';
$string['settings:realtime_enabled_desc'] = '允许学生与 SOLA 进行实时语音对话。需要 OpenAI API 密钥。';
$string['settings:realtime_apikey'] = 'OpenAI API 密钥（语音与 TTS）';
$string['settings:realtime_apikey_desc'] = '用于语音模式及消息上的 TTS 朗读按钮。留空则在提供商为 OpenAI 时使用主 API 密钥。';
$string['settings:realtime_voice'] = 'SOLA 语音';
$string['settings:realtime_voice_desc'] = '语音模式与 TTS 朗读按钮使用的语音（OpenAI 语音：Shimmer、Alloy、Echo、Fable、Onyx、Nova）。';

// Mobile app.
$string['mobile_welcome'] = '你好，{$a}！';
$string['mobile_welcome_sub'] = '我是 SOLA，你的学习助手。今天需要什么帮助？';
$string['mobile_placeholder'] = '请输入您的问题...';
$string['mobile_clear'] = '清除历史记录';
$string['mobile_disabled'] = '本课程暂不提供 SOLA。';
$string['mobile_chip_concepts'] = '关键概念';
$string['mobile_chip_studyplan'] = '学习计划';
$string['mobile_chip_quiz'] = '测验我';
