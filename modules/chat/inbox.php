<?php
$page_title = 'Messages';
require_once __DIR__ . '/../../includes/header.php';
require_login();

$user_id       = $_SESSION['user_id'];
$role          = get_role();
$open_user_id  = (int)($_GET['user'] ?? 0);

// Fetch all conversation partners
// Get latest message per conversation
$stmt = $conn->prepare("
    SELECT
        CASE
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END AS partner_id,
        MAX(m.created_at) AS last_msg_time,
        SUM(CASE WHEN m.receiver_id = ? AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count
    FROM messages m
    WHERE m.sender_id = ? OR m.receiver_id = ?
    GROUP BY partner_id
    ORDER BY last_msg_time DESC
");
$stmt->bind_param('iiii', $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$partners_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch partner user details + last message
$conversations = [];
foreach ($partners_raw as $p) {
    $pid = $p['partner_id'];
    $stmt = $conn->prepare("SELECT id, full_name, profile_picture, role_id FROM users WHERE id = ?");
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $partner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$partner) continue;

    // Get last message
    $stmt = $conn->prepare("
        SELECT message_text, created_at, sender_id, file_path
        FROM messages
        WHERE (sender_id = ? AND receiver_id = ?)
           OR (sender_id = ? AND receiver_id = ?)
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->bind_param('iiii', $user_id, $pid, $pid, $user_id);
    $stmt->execute();
    $last_msg = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $conversations[] = [
        'partner'     => $partner,
        'last_msg'    => $last_msg,
        'unread'      => $p['unread_count'],
        'last_time'   => $p['last_msg_time'],
    ];
}

// If opening a specific user, validate they can chat
$chat_partner = null;
if ($open_user_id && $open_user_id !== $user_id) {
    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.profile_picture, r.slug as role_slug
        FROM users u JOIN roles r ON u.role_id = r.id
        WHERE u.id = ? AND u.deleted_at IS NULL
    ");
    $stmt->bind_param('i', $open_user_id);
    $stmt->execute();
    $chat_partner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Mark messages as read
    if ($chat_partner) {
        $stmt = $conn->prepare("
            UPDATE messages SET is_read = 1
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $stmt->bind_param('ii', $open_user_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Determine sidebar based on role
$sidebar_map = [
    'traveler'           => 'dashboards/traveler/sidebar.php',
    'guide'              => 'dashboards/guide/sidebar.php',
    'hotel_staff'        => 'dashboards/hotel_staff/sidebar.php',
    'transport_provider' => 'dashboards/transport_provider/sidebar.php',
    'admin'              => 'dashboards/admin/sidebar.php',
];
$sidebar = BASE_URL . '' . ($sidebar_map[$role] ?? '');
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../' . ($sidebar_map[$role] ?? 'dashboards/traveler/sidebar.php'); ?>

    <div class="main-content w-100 p-0">
        <div class="d-flex" style="height:calc(100vh - 0px)">

            <!-- Conversation List -->
            <div class="border-end bg-white"
                 style="width:320px;min-width:320px;overflow-y:auto;flex-shrink:0">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">Messages</h6>
                    <button class="btn btn-sm btn-primary"
                            data-bs-toggle="modal" data-bs-target="#newChatModal">
                        <i class="bi bi-pencil-square"></i> New
                    </button>
                </div>

                <!-- Search -->
                <div class="p-2 border-bottom">
                    <input type="text" id="conversationSearch" class="form-control form-control-sm"
                           placeholder="Search conversations...">
                </div>

                <!-- List -->
                <div id="conversationList">
                    <?php if (empty($conversations)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-chat-dots display-4"></i>
                        <p class="mt-2">No conversations yet</p>
                    </div>
                    <?php endif; ?>

                    <?php foreach ($conversations as $conv): ?>
                    <?php $p = $conv['partner']; ?>
                    <a href="<?= BASE_URL ?>modules/chat/inbox.php?user=<?= $p['id'] ?>"
                       class="d-flex align-items-center gap-3 p-3 text-decoration-none
                              conversation-item border-bottom
                              <?= ($open_user_id === $p['id']) ? 'bg-primary bg-opacity-10' : '' ?>"
                       data-name="<?= strtolower(htmlspecialchars($p['full_name'])) ?>">
                        <div class="position-relative flex-shrink-0">
                            <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($p['profile_picture'] ?? 'default.png') ?>"
                                 class="rounded-circle"
                                 style="width:46px;height:46px;object-fit:cover">
                            <?php if ($conv['unread'] > 0): ?>
                            <span class="badge bg-danger rounded-circle position-absolute"
                                  style="top:-4px;right:-4px;min-width:18px;height:18px;
                                         font-size:10px;display:flex;align-items:center;
                                         justify-content:center">
                                <?= $conv['unread'] ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold text-dark small">
                                    <?= htmlspecialchars($p['full_name']) ?>
                                </span>
                                <span class="text-muted" style="font-size:10px">
                                    <?php if ($conv['last_time']): ?>
                                    <?= date('H:i', strtotime($conv['last_time'])) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="text-muted text-truncate" style="font-size:12px">
                                <?php if ($conv['last_msg']): ?>
                                    <?php if ($conv['last_msg']['file_path']): ?>
                                    <i class="bi bi-paperclip"></i> Attachment
                                    <?php else: ?>
                                    <?= htmlspecialchars(
                                        substr($conv['last_msg']['message_text'] ?? '', 0, 40)
                                    ) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Chat Window -->
            <div class="flex-grow-1 d-flex flex-column" style="overflow:hidden">
                <?php if ($chat_partner): ?>

                <!-- Chat Header -->
                <div class="p-3 border-bottom bg-white d-flex align-items-center gap-3">
                    <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($chat_partner['profile_picture'] ?? 'default.png') ?>"
                         class="rounded-circle"
                         style="width:42px;height:42px;object-fit:cover">
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($chat_partner['full_name']) ?></div>
                        <small class="text-muted"><?= ucfirst(str_replace('_',' ',$chat_partner['role_slug'])) ?></small>
                    </div>
                    <div class="ms-auto">
                        <span class="badge bg-success" id="onlineStatus">Active</span>
                    </div>
                </div>

                <!-- Messages Area -->
                <div id="messagesArea"
                     style="flex:1;overflow-y:auto;padding:20px;background:#f8f9fa">
                    <div id="messagesList">
                        <!-- Messages loaded via AJAX -->
                    </div>
                    <div id="typingIndicator" class="text-muted small" style="display:none">
                        <?= htmlspecialchars($chat_partner['full_name']) ?> is typing...
                    </div>
                </div>

                <!-- Message Input -->
                <div class="p-3 bg-white border-top">
                    <!-- File Preview -->
                    <div id="filePreview" class="mb-2" style="display:none">
                        <div class="d-flex align-items-center gap-2 bg-light p-2 rounded">
                            <i class="bi bi-paperclip text-primary"></i>
                            <span id="fileName" class="small text-truncate flex-grow-1"></span>
                            <button type="button" class="btn-close btn-sm"
                                    onclick="clearFile()"></button>
                        </div>
                    </div>

                    <div class="d-flex gap-2 align-items-end">
                        <!-- File attach -->
                        <label class="btn btn-outline-secondary btn-sm mb-0" title="Attach file">
                            <i class="bi bi-paperclip"></i>
                            <input type="file" id="attachFile" style="display:none"
                                   accept="image/*,.pdf,.doc,.docx" onchange="previewFile(this)">
                        </label>

                        <!-- Text input -->
                        <textarea id="messageInput"
                                  class="form-control"
                                  placeholder="Type a message..."
                                  rows="1"
                                  style="resize:none;max-height:120px"
                                  onkeydown="handleKeyDown(event)"
                                  oninput="autoResize(this)"></textarea>

                        <!-- Send button -->
                        <button id="sendBtn"
                                class="btn btn-primary"
                                onclick="sendMessage()"
                                style="min-width:48px">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </div>
                    <div class="text-muted mt-1" style="font-size:11px">
                        Press Enter to send, Shift+Enter for new line
                    </div>
                </div>

                <?php else: ?>

                <!-- Empty state -->
                <div class="flex-grow-1 d-flex flex-column align-items-center
                            justify-content-center text-muted">
                    <i class="bi bi-chat-dots display-1"></i>
                    <h5 class="mt-3">Select a conversation</h5>
                    <p>Choose a conversation from the list or start a new one</p>
                    <button class="btn btn-primary"
                            data-bs-toggle="modal" data-bs-target="#newChatModal">
                        <i class="bi bi-pencil-square"></i> Start New Chat
                    </button>
                </div>

                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- New Chat Modal -->
<div class="modal fade" id="newChatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Search user to message</label>
                <input type="text" id="userSearch" class="form-control mb-3"
                       placeholder="Type name..." oninput="searchUsers(this.value)">
                <div id="userResults"></div>
            </div>
        </div>
    </div>
</div>

<?php if ($chat_partner): ?>
<script>
const CURRENT_USER_ID  = <?= $user_id ?>;
const PARTNER_USER_ID  = <?= $chat_partner['id'] ?>;
const PARTNER_NAME     = <?= json_encode($chat_partner['full_name']) ?>;
const BASE_URL         = <?= json_encode(BASE_URL) ?>;
let   lastMessageId    = 0;
let   pollInterval;
let   isPolling        = false;

// ─── Load initial messages ───────────────────────────────────────
async function loadMessages() {
    try {
        const res  = await fetch(`${BASE_URL}ajax/chat.php?action=get_messages&partner_id=${PARTNER_USER_ID}&last_id=0`);
        const data = await res.json();
        if (data.messages && data.messages.length > 0) {
            renderMessages(data.messages, true);
            lastMessageId = data.messages[data.messages.length - 1].id;
        }
    } catch(e) {
        console.error('Load messages error:', e);
    }
}

// ─── Poll for new messages ────────────────────────────────────────
async function pollMessages() {
    if (isPolling) return;
    isPolling = true;
    try {
        const res  = await fetch(`${BASE_URL}ajax/chat.php?action=get_messages&partner_id=${PARTNER_USER_ID}&last_id=${lastMessageId}`);
        const data = await res.json();
        if (data.messages && data.messages.length > 0) {
            renderMessages(data.messages, false);
            lastMessageId = data.messages[data.messages.length - 1].id;
        }
    } catch(e) {
        console.error('Poll error:', e);
    }
    isPolling = false;
}

// ─── Render messages ──────────────────────────────────────────────
function renderMessages(messages, replace = false) {
    const list   = document.getElementById('messagesList');
    const area   = document.getElementById('messagesArea');
    const atBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 60;

    let html = replace ? '' : list.innerHTML;

    messages.forEach(msg => {
        const isMine   = parseInt(msg.sender_id) === CURRENT_USER_ID;
        const timeStr  = new Date(msg.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        const align    = isMine ? 'flex-row-reverse' : 'flex-row';
        const bubbleCls = isMine ? 'bg-primary text-white' : 'bg-white border';
        const nameHtml = !isMine ? `<div class="small text-muted mb-1">${escapeHtml(msg.sender_name)}</div>` : '';

        let contentHtml = '';
        if (msg.file_path) {
            const ext = msg.file_path.split('.').pop().toLowerCase();
            if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
                contentHtml = `
                    <a href="${BASE_URL}assets/uploads/chat/${escapeHtml(msg.file_path)}" target="_blank">
                        <img src="${BASE_URL}assets/uploads/chat/${escapeHtml(msg.file_path)}"
                             style="max-width:220px;max-height:180px;border-radius:8px;cursor:pointer"
                             class="d-block mb-1">
                    </a>`;
            } else {
                contentHtml = `
                    <a href="${BASE_URL}assets/uploads/chat/${escapeHtml(msg.file_path)}"
                       target="_blank" class="${isMine ? 'text-white' : 'text-primary'}">
                        <i class="bi bi-file-earmark"></i>
                        ${escapeHtml(msg.file_path)}
                    </a>`;
            }
        }
        if (msg.message_text) {
            contentHtml += `<div>${escapeHtml(msg.message_text)}</div>`;
        }

        const readIcon = isMine
            ? `<span class="ms-1" style="font-size:10px">${msg.is_read ? '✓✓' : '✓'}</span>`
            : '';

        html += `
            <div class="d-flex ${align} align-items-end gap-2 mb-3">
                <div style="max-width:65%">
                    ${nameHtml}
                    <div class="p-3 rounded-3 ${bubbleCls}" style="word-break:break-word">
                        ${contentHtml}
                        <div class="d-flex justify-content-end align-items-center gap-1 mt-1">
                            <span class="${isMine ? 'text-white opacity-75' : 'text-muted'}"
                                  style="font-size:10px">${timeStr}</span>
                            ${readIcon}
                        </div>
                    </div>
                </div>
            </div>`;
    });

    list.innerHTML = html;
    if (replace || atBottom) {
        area.scrollTop = area.scrollHeight;
    }
}

// ─── Send message ─────────────────────────────────────────────────
async function sendMessage() {
    const input    = document.getElementById('messageInput');
    const fileEl   = document.getElementById('attachFile');
    const text     = input.value.trim();
    const file     = fileEl.files[0];

    if (!text && !file) return;

    document.getElementById('sendBtn').disabled = true;

    const formData = new FormData();
    formData.append('action',     'send_message');
    formData.append('receiver_id', PARTNER_USER_ID);
    formData.append('message',    text);
    if (file) formData.append('file', file);

    try {
        const res  = await fetch(`${BASE_URL}ajax/chat.php`, {
            method: 'POST',
            body:   formData
        });
        const data = await res.json();

        if (data.success) {
            input.value = '';
            input.style.height = 'auto';
            clearFile();
            await pollMessages();
        } else {
            alert(data.error || 'Failed to send message.');
        }
    } catch(e) {
        alert('Network error. Please try again.');
    }

    document.getElementById('sendBtn').disabled = false;
    input.focus();
}

// ─── Helpers ──────────────────────────────────────────────────────
function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function previewFile(input) {
    if (input.files[0]) {
        document.getElementById('filePreview').style.display = 'block';
        document.getElementById('fileName').textContent = input.files[0].name;
    }
}

function clearFile() {
    document.getElementById('attachFile').value = '';
    document.getElementById('filePreview').style.display = 'none';
    document.getElementById('fileName').textContent = '';
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ─── Init ─────────────────────────────────────────────────────────
loadMessages();
pollInterval = setInterval(pollMessages, 3000); // poll every 3 seconds

// Stop polling when tab is hidden
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        clearInterval(pollInterval);
    } else {
        pollInterval = setInterval(pollMessages, 3000);
        pollMessages();
    }
});
</script>
<?php endif; ?>

<!-- Conversation search -->
<script>
document.getElementById('conversationSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.conversation-item').forEach(el => {
        el.style.display = el.dataset.name.includes(q) ? '' : 'none';
    });
});

// New chat user search
async function searchUsers(query) {
    if (query.length < 2) {
        document.getElementById('userResults').innerHTML = '';
        return;
    }
    const res  = await fetch(`<?= BASE_URL ?>ajax/chat.php?action=search_users&q=${encodeURIComponent(query)}`);
    const data = await res.json();
    let html = '';
    if (data.users && data.users.length) {
        data.users.forEach(u => {
            html += `
                <a href="<?= BASE_URL ?>modules/chat/inbox.php?user=${u.id}"
                   class="d-flex align-items-center gap-3 p-2 rounded text-decoration-none
                          text-dark hover-bg mb-1 border">
                    <img src="<?= BASE_URL ?>assets/uploads/${u.profile_picture || 'default.png'}"
                         class="rounded-circle" width="38" height="38" style="object-fit:cover">
                    <div>
                        <div class="fw-semibold">${u.full_name}</div>
                        <small class="text-muted">${u.role_name}</small>
                    </div>
                </a>`;
        });
    } else {
        html = '<p class="text-muted small">No users found.</p>';
    }
    document.getElementById('userResults').innerHTML = html;
}
</script>

<style>
.conversation-item:hover { background: #f8f9fa; }
.hover-bg:hover { background: #f0f4ff; }
#messagesArea::-webkit-scrollbar { width: 4px; }
#messagesArea::-webkit-scrollbar-thumb { background: #dee2e6; border-radius: 2px; }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>