<?php
session_start();

require 'config.php';
 
// Handle message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    if (!empty($message)) {
        $stmt = $pdo->prepare("INSERT INTO kcse_chat (student_id, message, is_from_admin) VALUES (?, ?, 0)");
        $stmt->execute([$studentID, $message]);
        header("Location: student_chat.php");
        exit();
    }
}

// Handle student-side delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $deleteID = (int) $_GET['delete'];
    $stmt = $pdo->prepare("UPDATE kcse_chat SET is_deleted_by_student = 1 WHERE id = ? AND student_id = ?");
    $stmt->execute([$deleteID, $studentID]);
    header("Location: student_chat.php");
    exit();
}

// Fetch messages (filter out those deleted by student)
$stmt = $pdo->prepare("SELECT * FROM kcse_chat WHERE student_id = ? AND is_deleted_by_student = 0 ORDER BY timestamp ASC");
 

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Chat with Admin</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f9f7f1;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        .chat-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }
        .message {
            max-width: 70%;
            margin: 10px 0;
            padding: 12px;
            border-radius: 12px;
            line-height: 1.4;
        }
        .student {
            background: #dcf8c6;
            align-self: flex-end;
        }
        .admin {
            background: #fff;
            border: 1px solid #ddd;
            align-self: flex-start;
        }
        .timestamp {
            font-size: 0.75rem;
            color: #999;
            margin-top: 4px;
        }
        .chat-box {
            display: flex;
            border-top: 1px solid #ddd;
        }
        .chat-box textarea {
            flex: 1;
            padding: 10px;
            font-size: 1rem;
            border: none;
            resize: none;
        }
        .chat-box button {
            padding: 10px 20px;
            border: none;
            background: #007bff;
            color: #fff;
            cursor: pointer;
        }
        .chat-box button:hover {
            background: #0056b3;
        }
        .delete-link {
            color: red;
            font-size: 0.8rem;
            margin-top: 4px;
            display: block;
        }
    </style>
</head>
<body>

<div class="chat-container">
    <?php   ?>
        <div class="message <?= $msg['is_from_admin'] ? 'admin' : 'student' ?>">
            <?= nl2br(htmlspecialchars($msg['message'])) ?>
            <div class="timestamp">
                <?= date('d M Y, h:i A', strtotime($msg['timestamp'])) ?>
                <?php   ?>
                    <a class="delete-link" href="?delete=<?= $msg['id'] ?>" onclick="return confirm('Delete this message?')">Delete</a>
                <?php   ?>
            </div>
        </div>
    <?php   ?>
</div>

<form class="chat-box" method="post">
    <textarea name="message" rows="2" placeholder="Type your message..." required></textarea>
    <button type="submit">Send</button>
</form>

</body>
</html>




































































working student_cha.php 

<?php
session_start();
include 'db.php';
include 'check_block.php';

// Auto logout after 5 minutes
if (isset($_SESSION['LoginTime']) && (time() - strtotime($_SESSION['LoginTime']) > 300)) {
    include 'logout.php';
    exit();
} else {
    $_SESSION['LoginTime'] = date("Y-m-d H:i:s");
}

if (!isset($_SESSION['StudentID'])) {
    header("Location: login.php");
    exit();
}

$studentID = $_SESSION['StudentID'];

// Handle new message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    if (!empty($message)) {
        $stmt = $pdo->prepare("INSERT INTO kcse_chat (student_id, message, is_from_admin) VALUES (?, ?, 0)");
        $stmt->execute([$studentID, $message]);
    }
    header("Location: student_cha.php"); // Prevent form resubmission
    exit();
}

// Handle delete action
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    $stmt = $pdo->prepare("UPDATE kcse_chat SET is_deleted_by_student = 1 WHERE id = ? AND student_id = ?");
    $stmt->execute([$deleteId, $studentID]);
    header("Location: student_cha.php");
    exit();
}

// Fetch all messages for this student that are NOT deleted by student
$stmt = $pdo->prepare("SELECT id, message, is_from_admin, timestamp FROM kcse_chat WHERE student_id = ? AND is_deleted_by_student = 0 ORDER BY timestamp ASC");
$stmt->execute([$studentID]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Cha</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #075e54;
            --secondary-color: #128c7e;
            --message-sent: #dcf8c6;
            --message-received: #ffffff;
            --cha-background: #e5ddd5;
            --header-background: #ededed;
            --input-background: #f0f0f0;
            --time-color: #888888;
            --text-color: #333333;
            --delete-color: #ff3b30;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
        }

        body {
            background-color: var(--cha-background);
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .cha-container {
            max-width: 800px;
            margin: 0 auto;
            width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: #f8f9fa;
            position: relative;
            box-shadow: 0 1px 1px rgba(0,0,0,0.1);
        }

        .cha-header {
            background-color: var(--primary-color);
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-info {
            display: flex;
            align-items: center;
        }

        .header-info i {
            margin-right: 15px;
            font-size: 20px;
        }

        .header-title {
            font-size: 18px;
            font-weight: 500;
        }

        .header-actions i {
            margin-left: 20px;
            font-size: 18px;
            cursor: pointer;
        }

        .cha-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px 15px;
            background-image: url('https://web.whatsapp.com/img/bg-cha-tile-light_a4be512e7195b6b733d9110b408f075d.png');
            background-repeat: repeat;
            display: flex;
            flex-direction: column;
        }

        .message-container {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
            max-width: 80%;
        }

        .message {
            padding: 8px 12px;
            border-radius: 7.5px;
            position: relative;
            word-wrap: break-word;
            box-shadow: 0 1px 0.5px rgba(0,0,0,0.13);
            font-size: 14.2px;
            line-height: 19px;
        }

        .student {
            background-color: var(--message-sent);
            align-self: flex-end;
            border-top-right-radius: 0;
            margin-left: auto;
        }

        .admin {
            background-color: var(--message-received);
            align-self: flex-start;
            border-top-left-radius: 0;
            margin-right: auto;
        }

        .message-info {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-top: 4px;
        }

        .timestamp {
            font-size: 11px;
            color: var(--time-color);
            margin-left: 8px;
        }

        .delete-btn {
            color: var(--delete-color);
            cursor: pointer;
            font-size: 12px;
            margin-left: 8px;
        }

        .cha-input-container {
            background-color: var(--input-background);
            padding: 10px 15px;
            display: flex;
            align-items: center;
            position: sticky;
            bottom: 0;
        }

        .input-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            background-color: white;
            border-radius: 20px;
            padding: 5px 15px;
            margin-right: 10px;
        }

        .cha-input {
            flex: 1;
            border: none;
            outline: none;
            padding: 10px 0;
            font-size: 15px;
            resize: none;
            max-height: 100px;
            overflow-y: auto;
        }

        .input-actions {
            display: flex;
            align-items: center;
            margin-left: 10px;
        }

        .input-actions i {
            color: #54656f;
            font-size: 22px;
            margin-left: 15px;
            cursor: pointer;
        }

        .send-button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .send-button i {
            font-size: 18px;
            margin: 0;
        }

        .status-indicator {
            font-size: 11px;
            color: var(--time-color);
            margin-top: 2px;
            text-align: right;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #cccccc;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #b3b3b3;
        }

        /* Typing indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            align-self: flex-start;
            background-color: white;
            padding: 8px 12px;
            border-radius: 7.5px;
            box-shadow: 0 1px 0.5px rgba(0,0,0,0.13);
            font-size: 12px;
            color: #666;
        }

        .typing-dots {
            display: flex;
            margin-left: 5px;
        }

        .typing-dot {
            width: 5px;
            height: 5px;
            background-color: #999;
            border-radius: 50%;
            margin: 0 2px;
            animation: typingAnimation 1.4s infinite ease-in-out;
        }

        .typing-dot:nth-child(1) {
            animation-delay: 0s;
        }

        .typing-dot:nth-child(2) {
            animation-delay: 0.2s;
        }

        .typing-dot:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes typingAnimation {
            0%, 60%, 100% {
                transform: translateY(0);
            }
            30% {
                transform: translateY(-5px);
            }
        }

        /* Message status icons */
        .message-status {
            margin-left: 4px;
            font-size: 12px;
        }

        .message-status.sent {
            color: #888;
        }

        .message-status.delivered {
            color: #888;
        }

        .message-status.read {
            color: #4fc3f7;
        }

        /* Responsive adjustments */
        @media (max-width: 600px) {
            .cha-container {
                max-width: 100%;
            }
            
            .message-container {
                max-width: 90%;
            }
            
            .header-title {
                font-size: 16px;
            }
            
            .header-actions i {
                margin-left: 15px;
            }
        }
    </style>
</head>
<body>

<div class="cha-container">
    <div class="cha-header">
        <div class="header-info">
            <i class="fas fa-arrow-left"></i>
            <div class="header-title">Admin Support</div>
        </div>
        <div class="header-actions">
            <i class="fas fa-search"></i>
            <i class="fas fa-ellipsis-v"></i>
        </div>
    </div>

    <div class="cha-messages">
        <?php 
        $prevDate = null;
        foreach ($messages as $msg): 
            $currentDate = date('Y-m-d', strtotime($msg['timestamp']));
            if ($currentDate != $prevDate): 
                $prevDate = $currentDate;
        ?>
            <div class="status-indicator" style="text-align: center; margin: 15px 0;">
                <?= date('F j, Y', strtotime($msg['timestamp'])) ?>
            </div>
        <?php endif; ?>
        
            <div class="message-container">
                <div class="message <?= $msg['is_from_admin'] ? 'admin' : 'student' ?>">
                    <?= htmlspecialchars($msg['message']) ?>
                    <div class="message-info">
                        <span class="timestamp">
                            <?= date('h:i A', strtotime($msg['timestamp'])) ?>
                        </span>
                        <?php if (!$msg['is_from_admin']): ?>
                            <span class="message-status delivered">
                                <i class="fas fa-check-double"></i>
                            </span>
                            <a class="delete-btn" href="?delete_id=<?= $msg['id'] ?>" onclick="return confirm('Delete this message?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Typing indicator (can be toggled with JavaScript) -->
        <!-- <div class="typing-indicator">
            Admin is typing
            <div class="typing-dots">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        </div> -->
    </div>

    <form method="POST" class="cha-input-container">
        <div class="input-wrapper">
            <i class="far fa-smile"></i>
            <textarea name="message" class="cha-input" rows="1" placeholder="Type a message..." required></textarea>
        </div>
        <div class="input-actions">
            <i class="fas fa-paperclip"></i>
            <button type="submit" class="send-button">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </form>
</div>

<script>
    // Auto-resize textarea
    const textarea = document.querySelector('.cha-input');
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    // Scroll to bottom of cha
    const chaMessages = document.querySelector('.cha-messages');
    chaMessages.scrollTop = chaMessages.scrollHeight;

    // Add WhatsApp-like message status indicators
    document.querySelectorAll('.message-status').forEach(status => {
        // Randomly set status for demo purposes
        const randomStatus = Math.random();
        if (randomStatus > 0.7) {
            status.classList.add('read');
            status.title = 'Read';
        } else if (randomStatus > 0.3) {
            status.classList.add('delivered');
            status.title = 'Delivered';
        } else {
            status.classList.add('sent');
            status.title = 'Sent';
        }
    });
</script>

</body>
</html>