require('dotenv').config();
const WebSocket = require('ws');
const http = require('http');
const https = require('https');

const PORT = process.env.PORT || 8080;
const SITE_URL = 'https://unispark.rf.gd/dashboard/chat/update_status.php';
const connectedUsers = {};

const lastHeartbeat = {};

// Check for dead connections every 20 seconds
setInterval(() => {
    const now = Date.now();
    Object.keys(lastHeartbeat).forEach(userId => {
        if (now - lastHeartbeat[userId] > 40000) {
            console.log(`⏰ User ${userId} timed out`);
            delete connectedUsers[userId];
            delete lastHeartbeat[userId];
            updateUserStatus(userId, false);
        }
    });
}, 20000);

function updateUserStatus(userId, isOnline) {
    const url = `${SITE_URL}?user_id=${userId}&is_online=${isOnline ? 1 : 0}`;
    https.get(url, (res) => {
        console.log(`Status update for user ${userId}: ${isOnline ? 'online' : 'offline'} → HTTP ${res.statusCode}`);
    }).on('error', (err) => {
        console.error(`Status update failed for user ${userId}:`, err.message);
    });
}

const server = http.createServer((req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    if (req.url === '/turn-credentials') {
        const auth = Buffer.from('Dammy:7d84be6e-2fae-11f1-aff3-0242ac130003').toString('base64');
        const body = JSON.stringify({ format: 'urls' });
        const options = {
            hostname: 'global.xirsys.net',
            path: '/_turn/unispark-realtime',
            method: 'PUT',
            headers: {
                'Authorization': 'Basic ' + auth,
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(body)
            }
        };
        const request = https.request(options, (response) => {
            let data = '';
            response.on('data', chunk => data += chunk);
            response.on('end', () => {
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(data);
            });
        });
        request.on('error', () => {
            res.writeHead(500);
            res.end('{}');
        });
        request.write(body);
        request.end();
    } else {
        res.writeHead(200);
        res.end('Unispark realtime server alive 🟢');
    }
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
    lastHeartbeat[currentUserId] = Date.now();
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
    lastHeartbeat[currentUserId] = Date.now();
    updateUserStatus(currentUserId, true);
    ws.send(JSON.stringify({ type: 'heartbeat_ack' }));
}

           if (['call_offer','call_answer','ice_candidate',
     'call_rejected','call_ended','call_ready'].includes(data.type)) {
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
