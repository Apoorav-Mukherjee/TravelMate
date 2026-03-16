<?php
$page_title = 'Chat Monitor';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

$filter  = sanitize($_GET['filter'] ?? 'all');
$page    = max(1, (int)($_GET['page'] ?? 1));
$per_page = 30;
$offset  = ($page - 1) * $per_page;

// Handle flag/unflag/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $msg_id = (int)($_POST['msg_id'] ?? 0);
    $action = sanitize($_POST['action'] ?? '');

    if ($msg_id) {
        switch ($action) {
            case 'flag':
                $conn->query("UPDATE messages SET is_flagged = 1 WHERE id = $msg_id");
                break;
            case 'unflag':
                $conn->query("UPDATE messages SET is_flagged = 0 WHERE id = $msg_id");
                break;
            case 'delete':
                $conn->query("DELETE FROM messages WHERE id = $msg_id");
                break;
        }
        log_admin_action($conn, $_SESSION['user_id'], "Chat message $action", 'message', $msg_id);
        set_flash('success', "Message $action successful.");
    }
    redirect('admin/chat_monitor.php?filter=' . $filter);
}

$where = '1=1';
if ($filter === 'flagged') $where = 'm.is_flagged = 1';

// Count
$total = $conn->query("SELECT COUNT(*) FROM messages m WHERE $where")->fetch_row()[0];
$total_pages = ceil($total / $per_page);

// Fetch messages
$stmt = $conn->prepare("
    SELECT m.*,
           s.full_name AS sender_name,
           r.full_name AS receiver_name
    FROM messages m
    JOIN users s ON m.sender_id   = s.id
    JOIN users r ON m.receiver_id = r.id
    WHERE $where
    ORDER BY m.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param('ii', $per_page, $offset);
$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Chat Monitor</h5>
            <div class="d-flex gap-2">
                <a href="?filter=all"
                   class="btn btn-sm <?= $filter==='all' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    All Messages
                </a>
                <a href="?filter=flagged"
                   class="btn btn-sm <?= $filter==='flagged' ? 'btn-danger' : 'btn-outline-danger' ?>">
                    Flagged Only
                </a>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Message</th>
                            <th>File</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $msg): ?>
                        <tr class="<?= $msg['is_flagged'] ? 'table-warning' : '' ?>">
                            <td>
                                <span class="fw-semibold">
                                    <?= htmlspecialchars($msg['sender_name']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($msg['receiver_name']) ?></td>
                            <td style="max-width:300px">
                                <div class="text-truncate">
                                    <?= htmlspecialchars($msg['message_text'] ?? '') ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($msg['file_path']): ?>
                                <a href="<?= BASE_URL ?>assets/uploads/chat/<?= htmlspecialchars($msg['file_path']) ?>"
                                   target="_blank" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-paperclip"></i> View
                                </a>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($msg['is_flagged']): ?>
                                <span class="badge bg-danger">Flagged</span>
                                <?php else: ?>
                                <span class="badge bg-success">
                                    <?= $msg['is_read'] ? 'Read' : 'Unread' ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= date('d M H:i', strtotime($msg['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <?php if (!$msg['is_flagged']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="msg_id"    value="<?= $msg['id'] ?>">
                                        <input type="hidden" name="action"    value="flag">
                                        <button class="btn btn-sm btn-warning" title="Flag">
                                            <i class="bi bi-flag"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="msg_id"    value="<?= $msg['id'] ?>">
                                        <input type="hidden" name="action"    value="unflag">
                                        <button class="btn btn-sm btn-outline-secondary" title="Unflag">
                                            <i class="bi bi-flag"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Delete this message?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="msg_id"    value="<?= $msg['id'] ?>">
                                        <input type="hidden" name="action"    value="delete">
                                        <button class="btn btn-sm btn-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($messages)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                No messages found.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link"
                       href="?filter=<?= $filter ?>&page=<?= $i ?>">
                        <?= $i ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>