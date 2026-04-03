require('dotenv').config();
const WebSocket = require('ws');
const http = require('http');
const https = require('https');

const PORT = process.env.PORT || 8080;
const SITE_URL = 'https://unispark.rf.gd/dashboard/chat/update_status.php';
const connectedUsers = {};

function updateUserStatus(userId, isOnline) {
    const url = `${SITE_URL}?user_id=${userId}&is_online=${isOnline ? 1 : 0}`;
    https.get(url, (res) => {
        console.log(`Status update for user ${userId}: ${isOnline ? 'online' : 'offline'} → HTTP ${res.statusCode}`);
    }).on('error', (err) => {
        console.error(`Status update failed for user ${userId}:`, err.message);
    });
}

const server = http.createServer((req, res) => {
    res.writeHead(200);
    res.end('Unispark realtime server alive 🟢');
});

const wss = new WebSocket.Server({ server });

wss.on('connection', (ws) => {
    let currentUserId = null;

    ws.on('message', async (message) => {
        try {
            const data = JSON.parse(message);

            if (data.type === 'auth') {
                currentUserId = data.userId;
                connectedUsers[currentUserId] = ws;
                updateUserStatus(currentUserId, true);
                ws.send(JSON.stringify({ type: 'auth_success' }));
                console.log(`✅ User ${currentUserId} online`);
            }

if (data.type === 'offline') {
    if (currentUserId) {
        delete connectedUsers[currentUserId];
        updateUserStatus(currentUserId, false);
        console.log(`❌ User ${currentUserId} manually offline`);
    }
}

            if (data.type === 'heartbeat') {
                updateUserStatus(currentUserId, true);
                ws.send(JSON.stringify({ type: 'heartbeat_ack' }));
            }

            if (['call_offer','call_answer','ice_candidate',
                 'call_rejected','call_ended'].includes(data.type)) {
                const targetWs = connectedUsers[data.targetUserId];
                if (targetWs && targetWs.readyState === WebSocket.OPEN) {
                    targetWs.send(JSON.stringify({
                        ...data,
                        fromUserId: currentUserId
                    }));
                }
            }
        } catch (err) {
            console.error('Error:', err);
        }
    });

    ws.on('close', () => {
        if (currentUserId) {
            delete connectedUsers[currentUserId];
            updateUserStatus(currentUserId, false);
            console.log(`❌ User ${currentUserId} offline`);
        }
    });
});

server.listen(PORT, () => {
    console.log(`🚀 Unispark realtime server running on port ${PORT}`);
});