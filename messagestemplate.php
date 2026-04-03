<?php
/**
 * Messages Page - FIXED ONLINE STATUS VERSION
 * File: chat/messages.php
 * FIXES: Online status detection + Proper last_seen updates
 */

session_start();
require_once "../../config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get selected user from URL first (priority)
$selected_user_id = $_GET['user_id'] ?? null;

// Validate selected user exists in database
if ($selected_user_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$selected_user_id]);
    if (!$stmt->fetch()) {
        $selected_user_id = null;
    }
    
    // CRITICAL: Mark messages as read IMMEDIATELY when opening chat
    if ($selected_user_id) {
        $stmt = $pdo->prepare("
            UPDATE messages 
            SET is_read = 1, is_delivered = 1 
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $stmt->execute([$selected_user_id, $user_id]);
        // Force session refresh of unread count
    $_SESSION['last_messages_visit'] = time();
    }
}

// Get list of users with whom current user has messages
$stmt = $pdo->prepare("
    SELECT DISTINCT
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END as other_user_id,
        u.display_name,
        u.profile_pics,
        u.gender,
        u.last_seen,
        u.is_online,
        (SELECT message FROM messages 
         WHERE (sender_id = ? AND receiver_id = other_user_id) 
            OR (sender_id = other_user_id AND receiver_id = ?)
         ORDER BY sent_at DESC LIMIT 1) as last_message,
        (SELECT sent_at FROM messages 
         WHERE (sender_id = ? AND receiver_id = other_user_id) 
            OR (sender_id = other_user_id AND receiver_id = ?)
         ORDER BY sent_at DESC LIMIT 1) as last_message_time,
        (SELECT COUNT(*) FROM messages 
         WHERE sender_id = other_user_id AND receiver_id = ? AND is_read = 0) as unread_count
    FROM messages m
    JOIN users u ON (
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END
    ) = u.id
    WHERE m.sender_id = ? OR m.receiver_id = ?
    ORDER BY last_message_time DESC
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no user selected from URL, use first conversation
if (!$selected_user_id && !empty($conversations)) {
    $selected_user_id = $conversations[0]['other_user_id'];
}

$pageTitle = 'Messages';
include '../../includes/header.php';
?>

<style>
    /* ========== CHAT INTERFACE STYLING ========== */
    .chat-container {
        display: grid;
        grid-template-columns: 350px 1fr;
        height: calc(100vh - 160px);
        gap: 1rem;
        max-width: 1400px;
        margin: 0 auto;
    }

    /* Conversation List */
    .conversations-list {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(15px);
        border-radius: 20px;
        overflow-y: auto;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
    }

    body.dark-mode .conversations-list {
        background: rgba(45, 45, 45, 0.95);
    }

    .conversation-item {
        padding: 1rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 1rem;
        position: relative;
    }

    body.dark-mode .conversation-item {
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .conversation-item:hover {
        background: rgba(255, 105, 180, 0.1);
    }

    .conversation-item.active {
        background: linear-gradient(135deg, rgba(255, 105, 180, 0.2), rgba(138, 43, 226, 0.2));
        border-left: 4px solid #ff69b4;
    }

    .conversation-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid rgba(255, 105, 180, 0.3);
        position: relative;
    }

    .online-indicator {
        width: 12px;
        height: 12px;
        background: #10b981;
        border: 2px solid white;
        border-radius: 50%;
        position: absolute;
        bottom: 0;
        right: 0;
        animation: pulse 2s infinite;
    }

    body.dark-mode .online-indicator {
        border-color: #2d2d2d;
    }

    @keyframes pulse {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.1); opacity: 0.8; }
    }

    .conversation-info {
        flex: 1;
        min-width: 0;
    }

    .conversation-name {
        font-weight: 600;
        font-size: 1rem;
        margin-bottom: 0.25rem;
    }

    .conversation-preview {
        font-size: 0.85rem;
        opacity: 0.7;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .conversation-time {
        font-size: 0.75rem;
        opacity: 0.6;
    }

    .unread-badge {
        background: #ef4444;
        color: white;
        border-radius: 50%;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        font-size: 0.75rem;
        font-weight: bold;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* Chat Window */
    .chat-window {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(15px);
        border-radius: 20px;
        display: flex;
        flex-direction: column;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
    }

    body.dark-mode .chat-window {
        background: rgba(45, 45, 45, 0.95);
    }

    .chat-header {
        padding: 1.5rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    body.dark-mode .chat-header {
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .chat-header-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid rgba(255, 105, 180, 0.3);
    }

    .chat-header-info {
        flex: 1;
    }

    .chat-header-name {
        font-weight: 700;
        font-size: 1.1rem;
    }

    .chat-header-status {
        font-size: 0.85rem;
        opacity: 0.7;
    }

    .streak-badge {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: linear-gradient(135deg, #ff6b6b, #ff8e53);
        border-radius: 20px;
        color: white;
        font-weight: 600;
        font-size: 0.9rem;
        box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
    }

    /* Messages Area */
    .messages-area {
        flex: 1;
        overflow-y: auto;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .message {
        display: flex;
        gap: 0.75rem;
        max-width: 70%;
    }

    .message.sent {
        margin-left: auto;
        flex-direction: row-reverse;
    }

    .message-avatar {
        width: 35px;
        height: 35px;
        border-radius: 50%;
        object-fit: cover;
        flex-shrink: 0;
    }

    .message-content {
        background: rgba(255, 105, 180, 0.15);
        padding: 0.75rem 1rem;
        border-radius: 18px;
        position: relative;
    }

    .message.sent .message-content {
        background: linear-gradient(135deg, #ff69b4, #ff1493);
        color: white;
    }

    body.dark-mode .message.received .message-content {
        background: rgba(138, 43, 226, 0.2);
    }

    .message-text {
        word-wrap: break-word;
        margin-bottom: 0.25rem;
    }

    .message-media {
        margin-top: 0.5rem;
    }

    .message-media img {
        max-width: 300px;
        border-radius: 12px;
    }

    .message-media audio {
        width: 100%;
        max-width: 300px;
    }

    .message-time {
        font-size: 0.7rem;
        opacity: 0.7;
        margin-top: 0.25rem;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }

    .message-ticks {
        font-size: 0.9rem;
        display: inline-block;
    }

    /* Input Area */
    .input-area {
        padding: 1rem 1.5rem;
        border-top: 1px solid rgba(0, 0, 0, 0.05);
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    body.dark-mode .input-area {
        border-top: 1px solid rgba(255, 255, 255, 0.05);
    }

    #messageInput {
        flex: 1;
        padding: 0.75rem 1rem;
        border-radius: 25px;
        border: 2px solid rgba(255, 105, 180, 0.3);
        background: rgba(255, 255, 255, 0.5);
        font-family: 'Cormorant Garamond', serif;
        font-size: 1rem;
    }

    body.dark-mode #messageInput {
        background: rgba(45, 45, 45, 0.8);
        border-color: rgba(138, 43, 226, 0.5);
        color: white;
    }

    #messageInput:focus {
        outline: none;
        border-color: #ff69b4;
    }

    .input-btn {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        transition: all 0.3s ease;
        background: rgba(255, 105, 180, 0.2);
    }

    .input-btn:hover {
        background: rgba(255, 105, 180, 0.4);
        transform: scale(1.1);
    }

    .input-btn.send-btn {
        background: linear-gradient(135deg, #ff69b4, #ff1493);
        color: white;
    }

    .input-btn.send-btn:hover {
        box-shadow: 0 4px 15px rgba(255, 105, 180, 0.5);
    }

    .media-upload-input {
        display: none;
    }

    .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        opacity: 0.6;
    }

    .empty-state i {
        font-size: 4rem;
        margin-bottom: 1rem;
        color: #ff69b4;
    }

/* ========== MOBILE RESPONSIVE ========== */
@media (max-width: 768px) {
    .container {
        padding: 0 !important;
    }
    
    .chat-container {
        grid-template-columns: 1fr;
        height: calc(100vh - 72px);
        position: relative;
        gap: 0;
        margin: 0;
        max-width: 100%;
    }

    .conversations-list {
        position: fixed;
        top: 72px;
        left: -100%;
        width: 85%;
        max-width: 320px;
        height: calc(100vh - 72px);
        z-index: 998;
        transition: left 0.3s ease;
        box-shadow: 4px 0 20px rgba(0, 0, 0, 0.2);
        border-radius: 0;
    }

    .conversations-list.mobile-show {
        left: 0;
    }

    .conversations-toggle {
        position: fixed;
        top: 85px;
        left: 10px;
        z-index: 999;
        background: linear-gradient(135deg, #8b5cf6, #a78bfa);
        color: white;
        border: none;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        box-shadow: 0 4px 15px rgba(139, 92, 246, 0.5);
        cursor: pointer;
    }

    .chat-window {
        width: 100%;
        height: calc(100vh - 72px);
        border-radius: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .chat-header {
        padding: 0.8rem;
        flex-shrink: 0;
    }

    .chat-header-avatar {
        width: 40px;
        height: 40px;
    }

    .messages-area {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 0.8rem 0.5rem;
        gap: 0.8rem;
        min-height: 0;
    }

    .message {
        max-width: 75%;
        gap: 0.5rem;
    }

    .input-area {
        padding: 0.7rem 0.8rem;
        gap: 0.5rem;
        flex-shrink: 0;
        flex-grow: 0;
        background: inherit;
        border-top: 1px solid rgba(0, 0, 0, 0.05);
        min-height: 60px;
    }

    .input-btn {
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    #messageInput {
        flex: 1;
        font-size: 16px;
        padding: 0.65rem 0.9rem;
        min-width: 0;
    }
}

.conversations-toggle {
    position: fixed;
    top: 85px;
    left: 10px;
    z-index: 999;
    background: linear-gradient(135deg, #8b5cf6, #a78bfa);
    color: white;
    border: none;
    width: 45px;
    height: 45px;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    box-shadow: 0 4px 15px rgba(139, 92, 246, 0.5);
    cursor: pointer;
}

@media (max-width: 768px) {
    .conversations-toggle {
        display: flex;
    }
}

/* ========== TYPING INDICATOR ========== */
.typing-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.5rem 0.8rem;
    background: rgba(255, 105, 180, 0.15);
    border-radius: 18px;
    margin-bottom: 1rem;
}

body.dark-mode .typing-indicator {
    background: rgba(138, 43, 226, 0.2);
}

.typing-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ff69b4;
    animation: typingDots 1.4s infinite;
}

body.dark-mode .typing-dot {
    background: #a78bfa;
}

.typing-dot:nth-child(1) { animation-delay: 0s; }
.typing-dot:nth-child(2) { animation-delay: 0.2s; }
.typing-dot:nth-child(3) { animation-delay: 0.4s; }

@keyframes typingDots {
    0%, 60%, 100% {
        transform: translateY(0);
        opacity: 0.7;
    }
    30% {
        transform: translateY(-10px);
        opacity: 1;
    }
}
</style>

<!-- Conversations Toggle (Mobile Only) -->
<button class="conversations-toggle" id="conversationsToggleBtn" onclick="toggleConversations()">
    💬
</button>

<div class="container mx-auto px-4 py-6">
    <div class="chat-container">
        <!-- Conversations List -->
        <div class="conversations-list">
            <div style="padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.05);">
                <h2 class="text-xl font-bold">Messages</h2>
            </div>
            
            <?php
            // If selected user is not in conversations (new chat), add them to the top
            $userInConversations = false;
            foreach ($conversations as $conv) {
                if ($conv['other_user_id'] == $selected_user_id) {
                    $userInConversations = true;
                    break;
                }
            }
            
            if ($selected_user_id && !$userInConversations) {
                $stmt = $pdo->prepare("SELECT display_name, profile_pics, gender, last_seen, is_online FROM users WHERE id = ?");
                $stmt->execute([$selected_user_id]);
                $newUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($newUser) {
                    array_unshift($conversations, [
                        'other_user_id' => $selected_user_id,
                        'display_name' => $newUser['display_name'],
                        'profile_pics' => $newUser['profile_pics'],
                        'gender' => $newUser['gender'],
                        'last_seen' => $newUser['last_seen'],
                        'is_online' => $newUser['is_online'],
                        'last_message' => 'Start chatting...',
                        'last_message_time' => null,
                        'unread_count' => 0
                    ]);
                }
            }
            ?>
            
            <?php if (empty($conversations)): ?>
                <div class="p-4 text-center opacity-60">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p>No conversations yet</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <?php
                    $photo = '../../assets/images/male-female.png';
                    if (!empty($conv['profile_pics'])) {
                        $photos = json_decode($conv['profile_pics'], true);
                        if (is_array($photos) && !empty($photos)) {
                            $firstPhoto = trim($photos[0]);
                            $uploadPath = "../../uploads/profile_pics/" . $firstPhoto;
                            if (file_exists($uploadPath)) {
                                $photo = $uploadPath;
                            }
                        }
                    }
                    if ($photo === '../../assets/images/male-female.png') {
                        if ($conv['gender'] === 'Male') $photo = "../../assets/images/male.png";
                        elseif ($conv['gender'] === 'Female') $photo = "../../assets/images/woman.png";
                    }

                    $active_class = ($selected_user_id == $conv['other_user_id']) ? 'active' : '';
                    
                    // CRITICAL: If this is the active conversation, unread should be 0
                    $display_unread = ($selected_user_id == $conv['other_user_id']) ? 0 : $conv['unread_count'];
                    ?>
                    
                    <div class="conversation-item <?= $active_class ?>" 
                         data-user-id="<?= $conv['other_user_id'] ?>"
                         onclick="location.href='?user_id=<?= $conv['other_user_id'] ?>'">
                        <div style="position: relative;">
                            <img src="<?= htmlspecialchars($photo) ?>" 
                                 alt="Avatar" 
                                 class="conversation-avatar">
                        </div>
                        
                        <div class="conversation-info">
                            <div class="conversation-name">
                                <?= htmlspecialchars($conv['display_name']) ?>
                            </div>
                            <div class="conversation-preview">
                                <?= htmlspecialchars(substr($conv['last_message'] ?? 'Start chatting...', 0, 30)) ?>
                            </div>
                            <div class="conversation-time">
                                <?php
                                if (!empty($conv['last_message_time'])) {
                                    $time_diff = time() - strtotime($conv['last_message_time']);
                                    if ($time_diff < 60) echo 'Just now';
                                    elseif ($time_diff < 3600) echo floor($time_diff / 60) . 'm ago';
                                    elseif ($time_diff < 86400) echo floor($time_diff / 3600) . 'h ago';
                                    else echo date('M j', strtotime($conv['last_message_time']));
                                }
                                ?>
                            </div>
                        </div>
                        
                        <?php if ($display_unread > 0): ?>
                            <div class="unread-badge" data-user-id="<?= $conv['other_user_id'] ?>"><?= $display_unread ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Chat Window -->
        <?php if ($selected_user_id): ?>
            <?php
            $stmt = $pdo->prepare("SELECT display_name, profile_pics, gender, last_seen, is_online FROM users WHERE id = ?");
            $stmt->execute([$selected_user_id]);
            $selected_user = $stmt->fetch(PDO::FETCH_ASSOC);

            $selected_photo = '../../assets/images/male-female.png';
            if (!empty($selected_user['profile_pics'])) {
                $photos = json_decode($selected_user['profile_pics'], true);
                if (is_array($photos) && !empty($photos)) {
                    $firstPhoto = trim($photos[0]);
                    $uploadPath = "../../uploads/profile_pics/" . $firstPhoto;
                    if (file_exists($uploadPath)) {
                        $selected_photo = $uploadPath;
                    }
                }
            }
            if ($selected_photo === '../../assets/images/male-female.png') {
                if ($selected_user['gender'] === 'Male') $selected_photo = "../../assets/images/male.png";
                elseif ($selected_user['gender'] === 'Female') $selected_photo = "../../assets/images/woman.png";
            }

            $streak_days = 0;
            try {
                $smaller_id = min($user_id, $selected_user_id);
                $larger_id = max($user_id, $selected_user_id);
                $stmt = $pdo->prepare("SELECT streak_days FROM chat_streaks WHERE user1_id = ? AND user2_id = ?");
                $stmt->execute([$smaller_id, $larger_id]);
                $streak = $stmt->fetch(PDO::FETCH_ASSOC);
                $streak_days = $streak['streak_days'] ?? 0;
            } catch (PDOException $e) {
                $streak_days = 0;
            }
            ?>

            <div class="chat-window">
                <div class="chat-header">
                    <img src="<?= htmlspecialchars($selected_photo) ?>" 
                         alt="Avatar" 
                         class="chat-header-avatar">
                    
                    <div class="chat-header-info">
                        <div class="chat-header-name">
                            <?= htmlspecialchars($selected_user['display_name']) ?>
                        </div>
                        <div class="chat-header-status" id="typingStatus">
                            <span id="userOnlineStatus">Checking...</span>
                        </div>
                    </div>

                    <?php if ($streak_days > 0): ?>
                        <div class="streak-badge">
                            🔥 <?= $streak_days ?> Day Streak
                            <?php
                            if ($streak_days >= 30) echo ' 🥇';
                            elseif ($streak_days >= 20) echo ' 🥈';
                            elseif ($streak_days >= 10) echo ' 🥉';
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="messages-area" id="messagesArea">
                    <!-- Messages loaded via JavaScript -->
                </div>

                <div class="input-area">
                    
                    <input type="file" id="voiceUpload" class="media-upload-input" accept="audio/*" onchange="handleMediaUpload(this, 'voice')">
                    
                    <button class="input-btn" id="videoCallBtn" onclick="startVideoCall()" title="Video Call">
    📹
</button>
                    
                    <button class="input-btn" id="voiceBtn" title="Hold to record" style="position: relative;">
                        <span id="voiceBtnIcon">🎤</span>
                        <span id="recordingDuration" style="display: none; position: absolute; top: -20px; font-size: 0.7rem;">0:00</span>
                    </button>
                    
                    <input type="text" 
                           id="messageInput" 
                           placeholder="Type a message..." 
                           onkeypress="if(event.key==='Enter') sendMessage()">
                    
                    <button class="input-btn send-btn" onclick="sendMessage()" title="Send">
                        ➤
                    </button>
                </div>
            </div>

            <script>
const currentUserId = <?= $user_id ?>;
const selectedUserId = <?= $selected_user_id ?>;
const selectedUserPhoto = '<?= htmlspecialchars($selected_photo) ?>';
const currentUserPhoto = '<?= htmlspecialchars($_SESSION['user_avatar'] ?? '../../assets/images/male-female.png') ?>';

// ========== LOAD MESSAGES WITH CORRECT TICK COLORS ==========
function loadMessages() {
    fetch(`fetch_messages.php?user_id=${selectedUserId}`)
        .then(res => res.json())
        .then(messages => {
            const messagesArea = document.getElementById('messagesArea');
            const isScrolledToBottom = messagesArea.scrollHeight - messagesArea.scrollTop <= messagesArea.clientHeight + 100;

            messagesArea.innerHTML = '';

            if (messages.length === 0) {
                messagesArea.innerHTML = '<div class="empty-state"><i class="fas fa-comments"></i><p>No messages yet. Start the conversation!</p></div>';
                return;
            }

            messages.forEach(msg => {
                const isSent = msg.sender_id == currentUserId;
                const messageDiv = document.createElement('div');
                messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;

                const avatar = document.createElement('img');
                avatar.src = isSent ? currentUserPhoto : selectedUserPhoto;
                avatar.className = 'message-avatar';

                const contentDiv = document.createElement('div');
                contentDiv.className = 'message-content';

                if (msg.message) {
                    const textDiv = document.createElement('div');
                    textDiv.className = 'message-text';
                    textDiv.textContent = msg.message;
                    contentDiv.appendChild(textDiv);
                }

                if (msg.media_url) {
                    const mediaDiv = document.createElement('div');
                    mediaDiv.className = 'message-media';

                    if (msg.media_type === 'image') {
                        const img = document.createElement('img');
                        img.src = msg.media_url;
                        img.alt = 'Image';
                        mediaDiv.appendChild(img);
                    } else if (msg.media_type === 'voice') {
                        const audio = document.createElement('audio');
                        audio.controls = true;
                        audio.src = msg.media_url;
                        mediaDiv.appendChild(audio);
                    }

                    contentDiv.appendChild(mediaDiv);
                }

                const timeDiv = document.createElement('div');
                timeDiv.className = 'message-time';
                const time = new Date(msg.sent_at || msg.created_at);
                timeDiv.textContent = time.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

                // TICK SYSTEM:
                // ✓ (grey) = Sent, receiver NOT logged in (is_delivered=0, is_read=0)
                // ✓✓ (blue) = Delivered, receiver IS logged in but hasn't read (is_delivered=1, is_read=0)
                // ✓✓ (gold) = Read, receiver opened the chat (is_delivered=1, is_read=1)
                if (isSent) {
                    const ticks = document.createElement('span');
                    ticks.className = 'message-ticks';
                    
                    const isRead = parseInt(msg.is_read) === 1;
                    const isDelivered = parseInt(msg.is_delivered) === 1;
                    
                    if (isRead) {
                        // READ = Double ticks GOLD/YELLOW
                        ticks.textContent = '✓✓';
                        ticks.style.color = '#fbbf24'; // Golden/Yellow
                    } else if (isDelivered) {
                        // DELIVERED = Double ticks BLUE
                        ticks.textContent = '✓✓';
                        ticks.style.color = '#3b82f6'; // Blue
                    } else {
                        // SENT ONLY = Single tick GREY (receiver not logged in)
                        ticks.textContent = '✓';
                        ticks.style.color = '#9ca3af'; // Grey
                    }
                    
                    timeDiv.appendChild(ticks);
                }

                contentDiv.appendChild(timeDiv);
                messageDiv.appendChild(avatar);
                messageDiv.appendChild(contentDiv);
                messagesArea.appendChild(messageDiv);
            });

            if (isScrolledToBottom) {
                messagesArea.scrollTop = messagesArea.scrollHeight;
            }
        })
        .catch(err => console.error('Error loading messages:', err));
}

// ========== SEND MESSAGE ==========
function sendMessage() {
    const input = document.getElementById('messageInput');
    const message = input.value.trim();

    if (!message) return;

    // Clear input immediately for better UX
    input.value = '';

    const typingFormData = new FormData();
    typingFormData.append('receiver_id', selectedUserId);
    typingFormData.append('is_typing', 0);
    
    fetch('update_typing.php', {
        method: 'POST',
        body: typingFormData
    }).catch(err => console.log('Typing update failed:', err));

    const formData = new FormData();
    formData.append('receiver_id', selectedUserId);
    formData.append('message', message);
    formData.append('media_type', 'text');

    fetch('send_message.php', {
        method: 'POST',
        body: formData
    })
    .then(res => {
        console.log('Response status:', res.status);
        return res.text(); // Get as text first
    })
    .then(text => {
        console.log('Response text:', text);
        try {
            const data = JSON.parse(text);
            if (data.success) {
    console.log('Message sent successfully:', data.message_id);
    loadMessages();
    
    // Send push notification via browser directly to OneSignal
    if (data.receiver_player_id) {
        sendPushViaJS(
            data.receiver_player_id,
            '💬 New Message',
            '<?= addslashes($_SESSION['user_name'] ?? 'Someone') ?> sent you a message!',
            'https://unispark.rf.gd/dashboard/chat/messages.php?user_id=<?= $user_id ?>'
        );
    }
} else {
                console.error('Send failed:', data.error);
                alert('Failed to send message: ' + (data.error || 'Unknown error'));
                input.value = message; // Restore message on error
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Raw response:', text);
            alert('Server error - check console');
            input.value = message; // Restore message on error
        }
    })
    .catch(err => {
        console.error('Network error:', err);
        alert('Network error: ' + err.message);
        input.value = message; // Restore message on error
    });
}

// ========== HANDLE MEDIA UPLOAD ==========
function handleMediaUpload(input, mediaType) {
    const file = input.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('receiver_id', selectedUserId);
    formData.append('media', file);
    formData.append('media_type', mediaType);
    formData.append('message', '');

    fetch('send_message.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadMessages();
            input.value = '';
        } else {
            alert('Failed to upload media');
        }
    })
    .catch(err => console.error('Error uploading media:', err));
}

// ========== TYPING INDICATOR ==========
let typingTimeout;

document.getElementById('messageInput').addEventListener('input', (e) => {
    const message = e.target.value.trim();
    
    if (message.length > 0) {
        const formData = new FormData();
        formData.append('receiver_id', selectedUserId);
        formData.append('is_typing', 1);
        
        fetch('update_typing.php', {
            method: 'POST',
            body: formData
        });
        
        clearTimeout(typingTimeout);
        
        typingTimeout = setTimeout(() => {
            const fd = new FormData();
            fd.append('receiver_id', selectedUserId);
            fd.append('is_typing', 0);
            
            fetch('update_typing.php', {
                method: 'POST',
                body: fd
            });
        }, 2000);
    } else {
        const formData = new FormData();
        formData.append('receiver_id', selectedUserId);
        formData.append('is_typing', 0);
        
        fetch('update_typing.php', {
            method: 'POST',
            body: formData
        });
        
        clearTimeout(typingTimeout);
    }
});

function checkTyping() {
    fetch(`check_typing.php?user_id=${selectedUserId}`)
        .then(res => res.json())
        .then(data => {
            const messagesArea = document.getElementById('messagesArea');
            let typingIndicator = document.getElementById('typingIndicator');
            
            if (data.is_typing) {
                if (!typingIndicator) {
                    typingIndicator = document.createElement('div');
                    typingIndicator.id = 'typingIndicator';
                    typingIndicator.className = 'typing-indicator';
                    typingIndicator.innerHTML = `
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                    `;
                    messagesArea.appendChild(typingIndicator);
                }
                
                messagesArea.scrollTop = messagesArea.scrollHeight;
                
                const statusDiv = document.getElementById('typingStatus');
                statusDiv.textContent = 'typing...';
                statusDiv.style.color = '#10b981';
            } else {
                if (typingIndicator) {
                    typingIndicator.remove();
                }
                
                const statusDiv = document.getElementById('typingStatus');
                statusDiv.textContent = '';
                statusDiv.style.color = '';
            }
        })
        .catch(err => console.error('Error checking typing:', err));
}

// ========== CONVERSATIONS TOGGLE (MOBILE) ==========
function toggleConversations() {
    const convList = document.querySelector('.conversations-list');
    const btn = document.getElementById('conversationsToggleBtn');
    
    if (convList.classList.contains('mobile-show')) {
        convList.classList.remove('mobile-show');
        btn.innerHTML = '💬';
    } else {
        convList.classList.add('mobile-show');
        btn.innerHTML = '✕';
    }
}

document.querySelectorAll('.conversation-item').forEach(item => {
    item.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            const convList = document.querySelector('.conversations-list');
            const btn = document.getElementById('conversationsToggleBtn');
            if (convList && btn) {
                convList.classList.remove('mobile-show');
                btn.innerHTML = '💬';
            }
        }
    });
});

// ========== REAL-TIME POLLING (SIMPLIFIED) ==========
// Check and deliver messages immediately on page load
fetch('check_deliveries.php', { method: 'POST' })
    .then(res => res.json())
    .then(data => {
        if (data.messages_delivered > 0) {
            console.log(`${data.messages_delivered} messages marked as delivered`);
        }
    })
    .catch(err => console.error('Delivery check failed:', err));

// Poll for new messages
setInterval(loadMessages, 5000);
setInterval(checkTyping, 1000);

// Check for deliveries every 10 seconds
setInterval(() => {
    fetch('check_deliveries.php', { method: 'POST' });
}, 10000);

// Initial load
loadMessages();

// Auto-focus input
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('direct') === '1') {
    setTimeout(() => {
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.focus();
            messageInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 500);
}
                function sendPushViaJS(playerId, title, message, url) {
    fetch('https://onesignal.com/api/v1/notifications', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'os_v2_app_f6tdlkuuujg3tldkycrfqxnqtd6233wmuyqefmmujyb6urzxkxsp76sktr2a5ykzzo2d5eagkbjpfyqbhfsklegqa4xzlrw7o7zl4za'
        },
        body: JSON.stringify({
            app_id: '2fa635aa-94a2-4db9-ac6a-c0a2585db098',
            include_player_ids: [playerId],
            headings: { en: title },
            contents: { en: message },
            url: url
        })
    })
    .then(res => res.json())
    .then(data => console.log('Push sent:', data))
    .catch(err => console.error('Push error:', err));
}

// ========== ONLINE STATUS ==========
function updateOnlineStatus() {
    const ws = window._uniWs;
    if (!ws || ws.readyState !== WebSocket.OPEN) return;

    fetch(`get_user_status.php?user_id=${selectedUserId}`)
        .then(res => res.json())
        .then(data => {
            const statusEl = document.getElementById('userOnlineStatus');
            if (!statusEl) return;

            if (data.is_online) {
                statusEl.textContent = '🟢 Online';
                statusEl.style.color = '#10b981';
                document.getElementById('videoCallBtn').style.opacity = '1';
            } else {
                const lastSeen = data.last_seen ? formatLastSeen(data.last_seen) : 'a while ago';
                statusEl.textContent = '⚫ Last seen ' + lastSeen;
                statusEl.style.color = '';
                document.getElementById('videoCallBtn').style.opacity = '0.5';
            }
        })
        .catch(err => console.error('Status error:', err));
}

function formatLastSeen(datetime) {
    const diff = Math.floor((Date.now() - new Date(datetime).getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) return 'today at ' + new Date(datetime).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    return 'yesterday';
}

// ========== VIDEO CALL ==========
function startVideoCall() {
    const ws = window._uniWs;
    if (!ws || ws.readyState !== WebSocket.OPEN) {
        alert('Connection lost. Please refresh the page.');
        return;
    }

    ws.send(JSON.stringify({
        type: 'call_offer',
        targetUserId: selectedUserId,
        callerName: '<?= addslashes($_SESSION['user_name'] ?? 'Someone') ?>',
        callerPhoto: '<?= addslashes($_SESSION['user_avatar'] ?? '') ?>'
    }));

    // Log outgoing call in messages area
    appendCallLog('📹 Video call started...', 'sent');
    openCallWindow('caller');
}

// Listen for incoming calls from WebSocket
if (window._uniWs) {
    const originalOnMessage = window._uniWs.onmessage;
    window._uniWs.onmessage = function(event) {
        if (originalOnMessage) originalOnMessage(event);
        handleWsMessage(JSON.parse(event.data));
    };
}

function handleWsMessage(data) {
    if (data.type === 'call_offer') {
        showIncomingCall(data.fromUserId, data.callerName, data.callerPhoto);
    }
    if (data.type === 'call_answer') {
        openCallWindow('caller_connected');
    }
    if (data.type === 'call_rejected') {
        appendCallLog('📹 No answer', 'sent');
        closeCallWindow();
    }
    if (data.type === 'call_ended') {
        appendCallLog('📹 Call ended', 'received');
        closeCallWindow();
    }
}

function showIncomingCall(fromUserId, callerName, callerPhoto) {
    const existing = document.getElementById('incomingCallModal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'incomingCallModal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.85); z-index: 99999;
        display: flex; align-items: center; justify-content: center;
        flex-direction: column; gap: 20px; color: white;
    `;
    modal.innerHTML = `
        <img src="${callerPhoto || '../../assets/images/male-female.png'}" 
             style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid #ff69b4;">
        <h2 style="font-size:1.5rem;font-weight:700;">${callerName || 'Someone'}</h2>
        <p style="opacity:0.8;">Incoming video call...</p>
        <div style="display:flex;gap:30px;margin-top:20px;">
            <button onclick="acceptCall(${fromUserId})" 
                style="background:#10b981;color:white;border:none;width:70px;height:70px;
                border-radius:50%;font-size:1.8rem;cursor:pointer;">📹</button>
            <button onclick="rejectCall(${fromUserId})"
                style="background:#ef4444;color:white;border:none;width:70px;height:70px;
                border-radius:50%;font-size:1.8rem;cursor:pointer;">📵</button>
        </div>
    `;
    document.body.appendChild(modal);
}

function acceptCall(fromUserId) {
    const modal = document.getElementById('incomingCallModal');
    if (modal) modal.remove();

    window._uniWs.send(JSON.stringify({
        type: 'call_answer',
        targetUserId: fromUserId
    }));

    appendCallLog('📹 Video call', 'received');
    openCallWindow('receiver');
}

function rejectCall(fromUserId) {
    const modal = document.getElementById('incomingCallModal');
    if (modal) modal.remove();

    window._uniWs.send(JSON.stringify({
        type: 'call_rejected',
        targetUserId: fromUserId
    }));

    appendCallLog('📹 Missed video call', 'received');
}

function openCallWindow(role) {
    const existing = document.getElementById('callModal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'callModal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: #000; z-index: 99999;
        display: flex; flex-direction: column;
    `;
    modal.innerHTML = `
        <video id="remoteVideo" autoplay playsinline 
               style="flex:1;width:100%;object-fit:cover;background:#111;"></video>
        <video id="localVideo" autoplay playsinline muted 
               style="position:absolute;top:20px;right:20px;width:120px;height:160px;
               object-fit:cover;border-radius:12px;border:2px solid #ff69b4;"></video>
        <div style="position:absolute;bottom:40px;left:50%;transform:translateX(-50%);
                    display:flex;gap:20px;">
            <button onclick="endCall()" 
                style="background:#ef4444;color:white;border:none;width:65px;height:65px;
                border-radius:50%;font-size:1.6rem;cursor:pointer;">📵</button>
        </div>
        <div id="callTimer" style="position:absolute;top:20px;left:50%;transform:translateX(-50%);
             color:white;font-size:1rem;background:rgba(0,0,0,0.5);padding:5px 15px;border-radius:20px;">
             00:00
        </div>
    `;
    document.body.appendChild(modal);
    startCallTimer();
    startLocalVideo();
}

let callTimerInterval = null;
let callSeconds = 0;

function startCallTimer() {
    callSeconds = 0;
    callTimerInterval = setInterval(() => {
        callSeconds++;
        const m = String(Math.floor(callSeconds / 60)).padStart(2, '0');
        const s = String(callSeconds % 60).padStart(2, '0');
        const timerEl = document.getElementById('callTimer');
        if (timerEl) timerEl.textContent = m + ':' + s;
    }, 1000);
}

function startLocalVideo() {
    navigator.mediaDevices.getUserMedia({ video: true, audio: true })
        .then(stream => {
            const localVideo = document.getElementById('localVideo');
            if (localVideo) localVideo.srcObject = stream;
            window._localStream = stream;
        })
        .catch(err => console.error('Camera error:', err));
}

function endCall() {
    window._uniWs.send(JSON.stringify({
        type: 'call_ended',
        targetUserId: selectedUserId
    }));

    const duration = callSeconds;
    const m = String(Math.floor(duration / 60)).padStart(2, '0');
    const s = String(duration % 60).padStart(2, '0');
    appendCallLog(`📹 Video call • ${m}:${s}`, 'sent');
    closeCallWindow();
}

function closeCallWindow() {
    clearInterval(callTimerInterval);
    callSeconds = 0;

    if (window._localStream) {
        window._localStream.getTracks().forEach(t => t.stop());
        window._localStream = null;
    }

    const modal = document.getElementById('callModal');
    if (modal) modal.remove();
}

function appendCallLog(text, type) {
    const messagesArea = document.getElementById('messagesArea');
    const div = document.createElement('div');
    div.style.cssText = `
        text-align: ${type === 'sent' ? 'right' : 'left'};
        font-size: 0.85rem; opacity: 0.7; padding: 4px 0;
    `;
    div.textContent = text;
    messagesArea.appendChild(div);
    messagesArea.scrollTop = messagesArea.scrollHeight;
}

// Update status every 10 seconds
setInterval(updateOnlineStatus, 10000);
updateOnlineStatus();

</script>
        <?php else: ?>
            <div class="chat-window">
                <div class="empty-state">
                    <i class="fas fa-comment-dots"></i>
                    <h3>Select a conversation</h3>
                    <p>Choose a conversation from the left to start chatting</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>