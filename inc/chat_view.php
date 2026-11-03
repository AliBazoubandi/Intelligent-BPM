<?php
/**
 * ماژول گفتگوی هوشمند با فرآیندهای سازمان (Process AI Chat / RAG)
 * رابط کاربری مشترک برای پنل کاربر و پنل تیم
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/hermes.php';

function handle_and_render_process_chat(string $area, array $id, string $processLinkPrefix): void
{
    $db = app_db();
    $sessionType = $area; // 'user' یا 'team'
    $sessionId = (int)$id['id'];
    $chatError = '';
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_GET['ajax']) && $_GET['ajax'] === '1')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

    // 1. پردازش ارسال پیام یا پاکسازی گفتگو
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? 'send');

        if (!csrf_check($_POST['csrf'] ?? null)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => 'اعتبار صفحه منقضی شده است. صفحه را دوباره بارگذاری کنید.']);
                exit;
            }
            $chatError = 'اعتبار صفحه منقضی شده است. لطفاً صفحه را تازه‌سازی فرمایید.';
        } elseif ($action === 'clear') {
            $st = $db->prepare('DELETE FROM AgentChats WHERE SessionType = ? AND SessionID = ?');
            $st->bind_param('si', $sessionType, $sessionId);
            $st->execute();
            $st->close();
            header('Location: ask.php');
            exit;
        } elseif ($action === 'send') {
            $query = trim((string)($_POST['query'] ?? ''));
            if ($query === '') {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['success' => false, 'error' => 'متن پرسش نمی‌تواند خالی باشد.']);
                    exit;
                }
            } else {
                // دریافت سابقه چند گفتگوی اخیر برای کانتکست مکالمه
                $st = $db->prepare('SELECT UserQuery, AiResponse FROM AgentChats WHERE SessionType = ? AND SessionID = ? ORDER BY CreatedAt DESC, ID DESC LIMIT 3');
                $st->bind_param('si', $sessionType, $sessionId);
                $st->execute();
                $histRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                $st->close();

                $chatHistory = [];
                foreach (array_reverse($histRows) as $hr) {
                    $chatHistory[] = ['role' => 'user', 'content' => $hr['UserQuery']];
                    $chatHistory[] = ['role' => 'assistant', 'content' => $hr['AiResponse']];
                }

                // اجرای موتور RAG هرمس
                @set_time_limit(120);
                $ragResult = hermes_process_rag_query($db, $query, $chatHistory);

                if ($ragResult['success']) {
                    $jsonSources = json_encode($ragResult['related_processes'], JSON_UNESCAPED_UNICODE);
                    $st = $db->prepare('INSERT INTO AgentChats (SessionType, SessionID, UserQuery, AiResponse, RelatedProcesses, CreatedAt) VALUES (?,?,?,?,?,NOW())');
                    $st->bind_param('sisss', $sessionType, $sessionId, $query, $ragResult['answer'], $jsonSources);
                    $st->execute();
                    $st->close();
                }

                if ($isAjax) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'success' => $ragResult['success'],
                        'answer' => $ragResult['answer'],
                        'related_processes' => $ragResult['related_processes'],
                        'created_at' => fmt_date(date('Y-m-d H:i:s')),
                        'error' => $ragResult['error']
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                header('Location: ask.php');
                exit;
            }
        }
    }

    // 2. بارگذاری پیام‌های ذخیره شده
    $st = $db->prepare('SELECT * FROM AgentChats WHERE SessionType = ? AND SessionID = ? ORDER BY CreatedAt ASC, ID ASC LIMIT 60');
    $st->bind_param('si', $sessionType, $sessionId);
    $st->execute();
    $chats = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $csrf = csrf_token();

    // 3. چاپ هدر متناسب با ناحیه
    if ($area === 'team') {
        team_header($id, 'ask', 'پرسش از فرآیندها');
    } else {
        user_header($id, 'ask', 'پرسش از فرآیندها');
    }
    ?>

    <?php if ($chatError): ?><div class="alert alert-error" style="margin-bottom:14px"><?= e($chatError) ?></div><?php endif; ?>

    <div class="ai-chat-wrap">
        <!-- بنر راهنمای دستیار هوشمند -->
        <div class="ai-chat-hero">
            <div class="ai-hero-header">
                <div class="ai-hero-icon">
                    <?= app_icon('bot', '', 28) ?>
                </div>
                <div class="ai-hero-title">
                    <h2>پرسش هوشمند از فرآیندهای سازمان (Process AI Chat)</h2>
                    <p>هر سوالی درباره مراحل اجرایی، مدارک، تاییدات و مسئولین فرآیندها دارید مطرح کنید؛ هرمس بر اساس تمام مستندات سازمان پاسخ می‌دهد.</p>
                </div>
            </div>

            <!-- پرسش‌های پیشنهادی آماده -->
            <div class="ai-prompt-chips">
                <button type="button" class="ai-chip-btn" data-query="چطور درخواست مرخصی ثبت کنم و مراحل تأیید آن چیست؟">
                    <?= app_icon('improvements', '', 14) ?>
                    <span>مراحل درخواست و تأیید مرخصی</span>
                </button>
                <button type="button" class="ai-chip-btn" data-query="مراحل صدور دسترسی شبکه و اینترنت برای کارمند جدید چیست؟">
                    <?= app_icon('processes', '', 14) ?>
                    <span>صدور دسترسی شبکه</span>
                </button>
                <button type="button" class="ai-chip-btn" data-query="پیش‌نیازها و نحوه صدور پیش‌فاکتور برای مشتری چیست؟">
                    <?= app_icon('files', '', 14) ?>
                    <span>نحوه صدور پیش‌فاکتور</span>
                </button>
                <button type="button" class="ai-chip-btn" data-query="تسویه هزینه‌های جاری و مأموریت چگونه انجام می‌شود؟">
                    <?= app_icon('changes', '', 14) ?>
                    <span>تسویه هزینه‌ها و مأموریت</span>
                </button>
                <button type="button" class="ai-chip-btn" data-query="فرآیند ثبت درخواست تعمیرات تجهیزات و ساختمان چگونه است؟">
                    <?= app_icon('basics', '', 14) ?>
                    <span>ثبت درخواست تعمیرات</span>
                </button>
            </div>
        </div>

        <!-- جریان پیام‌های گفتگو -->
        <div class="ai-chat-stream" id="chatStream">
            <?php if (empty($chats)): ?>
                <div class="card" id="emptyStateBox" style="text-align:center;padding:34px 20px">
                    <div class="empty">
                        <span class="ico"><?= app_icon('bot', '', 40) ?></span>
                        <p style="font-weight:600;font-size:15px;margin-bottom:6px">هنوز سوالی مطرح نکرده‌اید.</p>
                        <p style="color:var(--muted);font-size:13px;max-width:500px;margin:0 auto">
                            می‌توانید یکی از پرسش‌های پیشنهادی بالا را انتخاب کنید یا پرسش دلخواه خود را در کادر زیر بنویسید.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($chats as $chat): ?>
                    <!-- پیام کاربر -->
                    <div class="chat-row chat-row-user">
                        <div class="chat-avatar chat-avatar-user">
                            <?= e(mb_substr($id['name'], 0, 1)) ?>
                        </div>
                        <div class="chat-bubble chat-bubble-user">
                            <div style="font-weight:600"><?= nl2br(e($chat['UserQuery'])) ?></div>
                            <div class="chat-meta">
                                <span><?= e(fmt_date($chat['CreatedAt'])) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- پیام هوش مصنوعی هرمس -->
                    <div class="chat-row chat-row-bot">
                        <div class="chat-avatar chat-avatar-bot">
                            <?= app_icon('bot', '', 20) ?>
                        </div>
                        <div class="chat-bubble chat-bubble-bot">
                            <div><?= nl2br(e($chat['AiResponse'])) ?></div>

                            <?php
                            $related = json_decode((string)$chat['RelatedProcesses'], true);
                            if (!empty($related) && is_array($related)): ?>
                                <div class="chat-sources">
                                    <span style="color:var(--muted);font-size:11.5px">فرآیندهای مرتبط:</span>
                                    <?php foreach ($related as $rel): ?>
                                        <a class="chat-source-tag" href="<?= e($processLinkPrefix . (int)$rel['id']) ?>" title="مشاهده فرآیند <?= e($rel['name']) ?>">
                                            <?= app_icon('processes', '', 13) ?>
                                            <span><?= e($rel['name']) ?></span>
                                            <span style="opacity:.7;font-size:10.5px">(<?= e($rel['cat']) ?>)</span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="chat-meta">
                                <span>🤖 هرمس (پاسخ مستند بر اساس فرآیندها)</span>
                                <span><?= e(fmt_date($chat['CreatedAt'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- نوار ورودی و ارسال پیام -->
        <div class="chat-input-panel">
            <form id="chatForm" method="post" action="ask.php">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="send">
                <div class="chat-input-bar">
                    <textarea id="chatInput" name="query" class="chat-textarea" rows="2" placeholder="پرسش خود را درباره هر یک از فرآیندهای سازمان بنویسید (مثلاً: شرایط ثبت مرخصی ساعتی چیست؟)…" required></textarea>
                    <button type="submit" id="chatSendBtn" class="chat-send-btn">
                        <?= app_icon('reply', '', 15) ?>
                        <span>ارسال پرسش</span>
                    </button>
                </div>
            </form>

            <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 4px 0;font-size:11.5px;color:var(--muted)">
                <span>💡 کلید Enter برای ارسال، و Shift + Enter برای خط جدید است.</span>
                <?php if (!empty($chats)): ?>
                    <form method="post" action="ask.php" onsubmit="return confirm('آیا مایل به پاک کردن تاریخچه این گفتگو هستید؟');" style="margin:0">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-ghost btn-xs" style="color:var(--danger);border-color:transparent;display:inline-flex;align-items:center;gap:4px">
                            <?= app_icon('x', '', 12) ?>
                            <span>پاکسازی گفتگو</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- اسکریپت تعاملی چت -->
    <script>
    (function () {
        const stream = document.getElementById('chatStream');
        const form = document.getElementById('chatForm');
        const input = document.getElementById('chatInput');
        const sendBtn = document.getElementById('chatSendBtn');
        const emptyState = document.getElementById('emptyStateBox');
        const processLinkPrefix = <?= json_encode($processLinkPrefix) ?>;
        const userNameInitial = <?= json_encode(mb_substr($id['name'], 0, 1)) ?>;

        function scrollToBottom() {
            window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        }

        // کلیک روی پرامپت‌های پیشنهادی
        document.querySelectorAll('.ai-chip-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const q = this.getAttribute('data-query');
                if (q) {
                    input.value = q;
                    form.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            });
        });

        // پشتیبانی از اینتر برای ارسال
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });

        // ارسال از طریق AJAX و مدیریت بلادرنگ پیام‌ها
        form.addEventListener('submit', function (e) {
            const query = input.value.trim();
            if (!query) return;

            e.preventDefault();
            if (emptyState) emptyState.remove();

            // ۱. درج حباب پیام کاربر
            const userRow = document.createElement('div');
            userRow.className = 'chat-row chat-row-user';
            userRow.innerHTML = `
                <div class="chat-avatar chat-avatar-user">${userNameInitial}</div>
                <div class="chat-bubble chat-bubble-user">
                    <div style="font-weight:600">${escapeHtml(query).replace(/\n/g, '<br>')}</div>
                    <div class="chat-meta"><span>هم‌اکنون</span></div>
                </div>
            `;
            stream.appendChild(userRow);

            // ۲. درج نشانگر در حال تایپ
            const typingRow = document.createElement('div');
            typingRow.className = 'chat-row chat-row-bot';
            typingRow.id = 'typingIndicator';
            typingRow.innerHTML = `
                <div class="chat-avatar chat-avatar-bot">🤖</div>
                <div class="chat-typing-indicator">
                    <span>هرمس در حال جستجو در مستندات فرآیندها و پاسخگویی است</span>
                    <div class="typing-dots"><span></span><span></span><span></span></div>
                </div>
            `;
            stream.appendChild(typingRow);
            scrollToBottom();

            // غیرفعال‌سازی موقت ورودی
            input.value = '';
            input.disabled = true;
            sendBtn.disabled = true;
            sendBtn.style.opacity = '0.6';

            const formData = new FormData(form);
            formData.set('query', query);

            fetch('ask.php?ajax=1', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                typingRow.remove();
                input.disabled = false;
                sendBtn.disabled = false;
                sendBtn.style.opacity = '1';
                input.focus();

                if (!data.success && data.error && !data.answer) {
                    alert(data.error);
                    return;
                }

                // ۳. ایجاد حباب پاسخ هرمس
                let sourcesHtml = '';
                if (data.related_processes && data.related_processes.length > 0) {
                    sourcesHtml = '<div class="chat-sources"><span style="color:var(--muted);font-size:11.5px">فرآیندهای مرتبط:</span>';
                    data.related_processes.forEach(p => {
                        sourcesHtml += `
                            <a class="chat-source-tag" href="${processLinkPrefix + p.id}" title="مشاهده فرآیند ${escapeHtml(p.name)}">
                                ⚙ <span>${escapeHtml(p.name)}</span>
                                <span style="opacity:.7;font-size:10.5px">(${escapeHtml(p.cat)})</span>
                            </a>
                        `;
                    });
                    sourcesHtml += '</div>';
                }

                const botRow = document.createElement('div');
                botRow.className = 'chat-row chat-row-bot';
                botRow.innerHTML = `
                    <div class="chat-avatar chat-avatar-bot">🤖</div>
                    <div class="chat-bubble chat-bubble-bot">
                        <div>${escapeHtml(data.answer).replace(/\n/g, '<br>')}</div>
                        ${sourcesHtml}
                        <div class="chat-meta">
                            <span>🤖 هرمس (پاسخ مستند بر اساس فرآیندها)</span>
                            <span>${data.created_at || 'هم‌اکنون'}</span>
                        </div>
                    </div>
                `;
                stream.appendChild(botRow);
                scrollToBottom();
            })
            .catch(err => {
                typingRow.remove();
                input.disabled = false;
                sendBtn.disabled = false;
                sendBtn.style.opacity = '1';

                const errRow = document.createElement('div');
                errRow.className = 'chat-row chat-row-bot';
                errRow.innerHTML = `
                    <div class="chat-avatar chat-avatar-bot">⚠️</div>
                    <div class="chat-bubble chat-bubble-bot" style="border-color:#f87171;background:#fef2f2">
                        <div style="color:#b91c1c">خطا در برقراری ارتباط با سرویس هرمس. لطفاً چند لحظه بعد دوباره تلاش فرمایید.</div>
                    </div>
                `;
                stream.appendChild(errRow);
                scrollToBottom();
            });
        });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        scrollToBottom();
    })();
    </script>

    <?php
    app_footer();
}

