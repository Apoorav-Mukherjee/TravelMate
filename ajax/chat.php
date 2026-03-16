<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Must be logged in
if (!is_logged_in()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action  = sanitize($_GET['action'] ?? $_POST['action'] ?? '');

switch ($action) {

    // ─────────────────────────────────────────────────────────────
    case 'get_messages':
        $partner_id  = (int)($_GET['partner_id'] ?? 0);
        $last_id     = (int)($_GET['last_id']    ?? 0);

        if (!$partner_id) {
            echo json_encode(['error' => 'Invalid partner']);
            exit();
        }

        // Fetch messages after last_id
        $stmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.message_text,
                   m.file_path, m.file_type, m.is_read, m.created_at,
                   u.full_name AS sender_name
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE ((m.sender_id = ? AND m.receiver_id = ?)
               OR  (m.sender_id = ? AND m.receiver_id = ?))
              AND m.id > ?
            ORDER BY m.created_at ASC
            LIMIT 50
        ");
        $stmt->bind_param('iiiii', $user_id, $partner_id, $partner_id, $user_id, $last_id);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Mark received messages as read
        $stmt = $conn->prepare("
            UPDATE messages SET is_read = 1
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $stmt->bind_param('ii', $partner_id, $user_id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['messages' => $messages]);
        break;

    // ─────────────────────────────────────────────────────────────
    case 'send_message':
        $receiver_id  = (int)($_POST['receiver_id']  ?? 0);
        $message_text = trim($_POST['message']        ?? '');
        $file_path    = null;
        $file_type    = null;

        if (!$receiver_id) {
            echo json_encode(['error' => 'Invalid receiver']);
            exit();
        }

        // Cannot message yourself
        if ($receiver_id === $user_id) {
            echo json_encode(['error' => 'Cannot message yourself']);
            exit();
        }

        // Verify receiver exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->bind_param('i', $receiver_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['error' => 'User not found']);
            exit();
        }
        $stmt->close();

        // Spam filter
        if (!empty($message_text)) {
            $stmt = $conn->prepare("SELECT word FROM spam_words");
            $stmt->execute();
            $spam_words = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'word');
            $stmt->close();

            $msg_lower = strtolower($message_text);
            foreach ($spam_words as $word) {
                if (strpos($msg_lower, strtolower($word)) !== false) {
                    echo json_encode(['error' => 'Message contains prohibited content.']);
                    exit();
                }
            }

            // Sanitize
            $message_text = htmlspecialchars(strip_tags(trim($message_text)), ENT_QUOTES, 'UTF-8');

            // Max length
            if (strlen($message_text) > 2000) {
                echo json_encode(['error' => 'Message too long (max 2000 characters)']);
                exit();
            }
        }

        // Handle file upload
        if (!empty($_FILES['file']['name'])) {
            $file    = $_FILES['file'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'];

            if (!in_array($ext, $allowed)) {
                echo json_encode(['error' => 'File type not allowed.']);
                exit();
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['error' => 'File must be under 5MB.']);
                exit();
            }

            // Check MIME type for images
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $mime = mime_content_type($file['tmp_name']);
                if (!str_starts_with($mime, 'image/')) {
                    echo json_encode(['error' => 'Invalid image file.']);
                    exit();
                }
                $file_type = 'image';
            } else {
                $file_type = 'document';
            }

            $fname = uniqid('chat_') . '_' . $user_id . '.' . $ext;
            $dest  = UPLOAD_PATH . 'chat/' . $fname;

            // Create dir if not exists
            if (!is_dir(UPLOAD_PATH . 'chat/')) {
                mkdir(UPLOAD_PATH . 'chat/', 0755, true);
            }

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                echo json_encode(['error' => 'File upload failed.']);
                exit();
            }
            $file_path = $fname;
        }

        // Must have message or file
        if (empty($message_text) && !$file_path) {
            echo json_encode(['error' => 'Empty message']);
            exit();
        }

        // Rate limiting: max 30 messages per minute
        if (!isset($_SESSION['msg_rate'])) {
            $_SESSION['msg_rate'] = ['count' => 0, 'time' => time()];
        }
        $rate = &$_SESSION['msg_rate'];
        if (time() - $rate['time'] > 60) {
            $rate = ['count' => 0, 'time' => time()];
        }
        $rate['count']++;
        if ($rate['count'] > 30) {
            echo json_encode(['error' => 'Too many messages. Please slow down.']);
            exit();
        }

        // Insert message
        $stmt = $conn->prepare("
            INSERT INTO messages
                (sender_id, receiver_id, message_text, file_path, file_type, is_read)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $stmt->bind_param('iisss', $user_id, $receiver_id, $message_text, $file_path, $file_type);
        $stmt->execute();
        $msg_id = $conn->insert_id;
        $stmt->close();

        echo json_encode(['success' => true, 'message_id' => $msg_id]);
        break;

    // ─────────────────────────────────────────────────────────────
    case 'get_unread_count':
        $stmt = $conn->prepare("
            SELECT COUNT(*) as cnt FROM messages
            WHERE receiver_id = ? AND is_read = 0
        ");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        echo json_encode(['unread' => $cnt]);
        break;

    // ─────────────────────────────────────────────────────────────
    case 'search_users':
        $q = sanitize($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode(['users' => []]);
            exit();
        }

        $like = "%$q%";
        $stmt = $conn->prepare("
            SELECT u.id, u.full_name, u.profile_picture, r.name AS role_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.full_name LIKE ?
              AND u.id != ?
              AND u.deleted_at IS NULL
              AND u.status = 'active'
            LIMIT 10
        ");
        $stmt->bind_param('si', $like, $user_id);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['users' => $users]);
        break;

    // ─────────────────────────────────────────────────────────────
    case 'mark_read':
        $partner_id = (int)($_POST['partner_id'] ?? 0);
        if ($partner_id) {
            $stmt = $conn->prepare("
                UPDATE messages SET is_read = 1
                WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
            ");
            $stmt->bind_param('ii', $partner_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['success' => true]);
        break;

    // ─────────────────────────────────────────────────────────────
    case 'flag_message':
        $msg_id = (int)($_POST['message_id'] ?? 0);
        if ($msg_id) {
            $stmt = $conn->prepare("
                UPDATE messages SET is_flagged = 1
                WHERE id = ? AND receiver_id = ?
            ");
            $stmt->bind_param('ii', $msg_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}
?>