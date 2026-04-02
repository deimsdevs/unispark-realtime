require('dotenv').config();
const WebSocket = require('ws');
const mysql = require('mysql2/promise');
const http = require('http');

const PORT = process.env.PORT || 8080;

const db = mysql.createPool({
  host: process.env.DB_HOST,
  user: process.env.DB_USER,
  password: process.env.DB_PASS,
  database: process.env.DB_NAME,
  port: process.env.DB_PORT || 3306,
  waitForConnections: true,
  connectionLimit: 10
});

const connectedUsers = {};

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
        await db.execute(
          'UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?',
          [currentUserId]
        );
        ws.send(JSON.stringify({ type: 'auth_success' }));
        console.log(`✅ User ${currentUserId} online`);
      }

      if (data.type === 'heartbeat') {
        await db.execute(
          'UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?',
          [currentUserId]
        );
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

  ws.on('close', async () => {
    if (currentUserId) {
      delete connectedUsers[currentUserId];
      try {
        await db.execute(
          'UPDATE users SET is_online = 0, last_seen = NOW() WHERE id = ?',
          [currentUserId]
        );
        console.log(`❌ User ${currentUserId} offline`);
      } catch (err) {
        console.error('DB error:', err);
      }
    }
  });
});

server.listen(PORT, () => {
  console.log(`🚀 Unispark realtime server running on port ${PORT}`);
});