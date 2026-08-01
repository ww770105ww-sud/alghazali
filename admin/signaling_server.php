<?php

require __DIR__ . '/../vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

// Database connection (assuming $pdo is available or can be initialized)
// You might need to adjust this based on your actual database connection setup
function getDbConnection() {
    // Replace with your actual database credentials
    $host = 'localhost';
    $db   = 'alghazali'; // Replace with your actual database name
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        throw new PDOException($e->getMessage(), (int)$e->getCode());
    }
}

class SignalingServer implements MessageComponentInterface {
    protected $clients;
    protected $users;
    protected $pdo;

    public function __construct() {
        $this->clients = new \SplObjectStorage; // To store all connected clients
        $this->users = []; // To map user IDs to connections
        $this->pdo = getDbConnection(); // Initialize PDO connection
        echo "Signaling server started. Waiting for connections...\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) {
            return;
        }

        switch ($data['type']) {
            case 'login':
                $userId = $data['userId'];
                $this->users[$userId] = $from; // Associate user ID with connection
                $from->userId = $userId; // Store userId on connection object
                echo "User {$userId} logged in from {$from->resourceId}\n";
                break;
            case 'offer':
            case 'answer':
            case 'candidate':
            case 'call_request':
            case 'call_accept':
            case 'call_reject':
            case 'call_end':
                $targetUserId = $data['targetUserId'] ?? null;
                if ($targetUserId && isset($this->users[$targetUserId])) {
                    // Send message to specific target user
                    $targetConnection = $this->users[$targetUserId];
                    // Add sender's userId to the message
                    $data['senderUserId'] = $from->userId;
                    $targetConnection->send(json_encode($data));
                    echo "Message '{$data['type']}' from {$from->userId} to {$targetUserId}\n";

                    // Update call status in DB for call_request, call_accept, call_end
                    $this->updateCallStatusInDb($from->userId, $targetUserId, $data['type'], $data['callId'] ?? null, $data['callType'] ?? 'video');

                } else {
                    // Optionally, handle error if target user is not found/offline
                    $from->send(json_encode(['type' => 'error', 'message' => 'Target user is offline or not found.']));
                    echo "Target user {$targetUserId} for {$data['type']} not found or offline.\n";
                }
                break;
            // Add more message types as needed (e.g., chat messages)
            default:
                echo "Unknown message type: {$data['type']}\n";
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);
        // Remove user from the users map if they were logged in
        foreach ($this->users as $userId => $userConn) {
            if ($userConn === $conn) {
                unset($this->users[$userId]);
                echo "User {$userId} ({$conn->resourceId}) has disconnected\n";
                break;
            }
        }
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    private function updateCallStatusInDb(int $senderId, int $receiverId, string $messageType, ?int $callId = null, string $callType = 'video') {
        // This function will interact with your database to update call states
        // based on signaling messages. This is where you leverage the existing
        // chat_ functions or create new entries in `internal_calls`.

        // Example logic (you'll need to adapt this to your specific database schema and existing functions):
        // For 'call_request', create a new call entry in internal_calls table.
        // For 'call_accept', update status of existing call to 'accepted'.
        // For 'call_end', update status of existing call to 'ended'.
        // For 'offer', 'answer', 'candidate', you might store these in a temporary signaling table
        // or rely purely on the WebSocket for real-time exchange.

        // This is a placeholder. You'll integrate with your existing functions like
        // chat_find_user_active_call and chat_finish_user_active_calls here.
        // You might need to create a new function to create a call, etc.

        try {
            switch ($messageType) {
                case 'call_request':
                    // Check for existing active call for either user to prevent multiple calls
                    if ($this->chat_find_user_active_call($this->pdo, $senderId) || $this->chat_find_user_active_call($this->pdo, $receiverId)) {
                        // A user is already in a call, reject the new request
                        // You might want to send a message back to the sender
                        echo "User {$senderId} or {$receiverId} is already in a call. Cannot initiate new call.\n";
                        return;
                    }
                    // Create a new call entry in the database
                    $stmt = $this->pdo->prepare("
                        INSERT INTO internal_calls (caller_id, receiver_id, status, call_type, created_at, started_at)
                        VALUES (?, ?, 'ringing', ?, NOW(), NOW())
                    ");
                    $stmt->execute([$senderId, $receiverId, $callType]);
                    // Get the ID of the newly created call
                    $newCallId = $this->pdo->lastInsertId();
                    echo "New call request created: #{$newCallId} from {$senderId} to {$receiverId}\n";
                    break;
                case 'call_accept':
                    if ($callId) {
                        $stmt = $this->pdo->prepare("UPDATE internal_calls SET status = 'accepted', answered_at = NOW() WHERE id = ? AND receiver_id = ?");
                        $stmt->execute([$callId, $receiverId]);
                        echo "Call #{$callId} accepted by {$receiverId}\n";
                    }
                    break;
                case 'call_reject':
                    if ($callId) {
                        $stmt = $this->pdo->prepare("UPDATE internal_calls SET status = 'rejected', ended_at = NOW() WHERE id = ? AND receiver_id = ?");
                        $stmt->execute([$callId, $receiverId]);
                        echo "Call #{$callId} rejected by {$receiverId}\n";
                    }
                    break;
                case 'call_end':
                    // Use the existing chat_finish_user_active_calls function
                    // Note: This might need adjustment if it expects a single user ending their participation
                    // For simplicity, we'll assume either sender or receiver can end the call and it affects both.
                    if ($callId) {
                         $stmt = $this->pdo->prepare("UPDATE internal_calls SET status = 'ended', ended_at = NOW() WHERE id = ?");
                         $stmt->execute([$callId]);
                         echo "Call #{$callId} ended.\n";
                    }
                    break;
                // 'offer', 'answer', 'candidate' are purely for WebRTC negotiation and typically don't require DB updates
            }
        } catch (PDOException $e) {
            echo "Database error in updateCallStatusInDb: {$e->getMessage()}\n";
        }
    }

    // Include the existing chat functions here for use by the signaling server
    // You might need to adjust their signatures if they are not static or require PDO instance
    // For now, I'm just copying them in, assuming they can be adapted.
    // In a real application, these might be in a separate service class.

    // This function will need to be made available to the class, or passed in
    // For simplicity, I'm including it directly, but it's better practice
    // to inject dependencies or refactor.
    function chat_find_user_active_call(PDO $pdo, int $user_id): ?array
    {
        $stmt = $pdo->prepare("
            SELECT id, status, call_type, group_id
            FROM internal_calls
            WHERE status IN ('calling', 'ringing', 'accepted')
              AND (
                  caller_id = ?
                  OR receiver_id = ?
                  OR group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = ?)
              )
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$user_id, $user_id, $user_id]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);
        return $call ?: null;
    }

}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new SignalingServer()
        )
    ),
    8081 // Port for WebSocket server. Make sure this port is free.
);

$server->run();
