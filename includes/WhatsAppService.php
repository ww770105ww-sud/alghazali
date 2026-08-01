
<?php

class WhatsAppService {
    private $pdo;
    private $settings = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadSettings();
    }
    
    private function loadSettings() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM crm_settings");
            $this->settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            $this->settings = [];
        }
    }
    
    private function getSetting($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
    
    private function getApiUrl($endpoint) {
        $version = $this->getSetting('graph_api_version', 'v19.0');
        $phoneNumberId = $this->getSetting('phone_number_id');
        return "https://graph.facebook.com/{$version}/{$phoneNumberId}/{$endpoint}";
    }
    
    private function apiRequest($method, $url, $data = null) {
        $accessToken = $this->getSetting('access_token');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($data && ($method === 'POST' || $method === 'PUT')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log API call
        $this->logApiRequest($method, $url, $data, $response, $httpCode);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'response' => json_decode($response, true),
            'raw_response' => $response
        ];
    }
    
    private function logApiRequest($method, $url, $request, $response, $statusCode) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO crm_api_logs (endpoint, method, request, response, status_code, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                parse_url($url, PHP_URL_PATH),
                $method,
                is_array($request) ? json_encode($request) : $request,
                $response,
                $statusCode,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (PDOException $e) {
            // Ignore log errors
        }
    }
    
    public function sendTextMessage($to, $text) {
        $url = $this->getApiUrl('messages');
        
        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'text' => ['body' => $text]
        ];
        
        return $this->apiRequest('POST', $url, $data);
    }
    
    public function sendMediaMessage($to, $mediaType, $mediaUrl, $caption = null) {
        $url = $this->getApiUrl('messages');
        
        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            $mediaType => [
                'link' => $mediaUrl
            ]
        ];
        
        if ($caption && $mediaType !== 'audio') {
            $data[$mediaType]['caption'] = $caption;
        }
        
        return $this->apiRequest('POST', $url, $data);
    }
    
    public function processWebhook($payload) {
        // Log webhook
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO crm_webhook_logs (event, payload, ip_address, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([
                'whatsapp_message',
                json_encode($payload),
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (PDOException $e) {
            // Ignore
        }
        
        // Process incoming messages
        if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
            $message = $payload['entry'][0]['changes'][0]['value']['messages'][0];
            $contacts = $payload['entry'][0]['changes'][0]['value']['contacts'] ?? [];
            $contact = $contacts[0] ?? null;
            
            $this->processIncomingMessage($message, $contact);
        }
        
        return true;
    }
    
    private function processIncomingMessage($message, $contact = null) {
        $from = $message['from'];
        $messageId = $message['id'];
        $messageType = $message['type'];
        
        // Find or create contact
        $contactId = $this->findOrCreateContact($from, $contact);
        
        // Find or create conversation
        $conversationId = $this->findOrCreateConversation($contactId);
        
        // Save message
        $content = null;
        $mediaUrl = null;
        $mediaType = null;
        $mediaName = null;
        
        if ($messageType === 'text') {
            $content = $message['text']['body'];
        } elseif (isset($message[$messageType])) {
            $mediaType = $messageType;
            // For simplicity, we'll just save the type - in a real app, you'd download media
        }
        
        $this->saveMessage($conversationId, $contactId, 'customer', $messageType, $content, $mediaUrl, $mediaType, $mediaName, $messageId);
        
        // Check if AI auto-reply is enabled
        if ($this->getSetting('auto_reply') == '1' && $messageType === 'text' && !empty($content)) {
            $this->handleAutoReply($conversationId, $contactId, $content, $from);
        }
    }
    
    private function findOrCreateContact($phone, $contactData = null) {
        // Normalize phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strpos($phone, '00') === 0) {
            $phone = substr($phone, 2);
        }
        
        // Try to find existing contact
        $stmt = $this->pdo->prepare("SELECT id FROM crm_contacts WHERE whatsapp_number = ? OR phone = ? LIMIT 1");
        $stmt->execute([$phone, $phone]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            return $existing['id'];
        }
        
        // Create new contact
        $name = $contactData['profile']['name'] ?? 'Unknown';
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';
        
        $stmt = $this->pdo->prepare("
            INSERT INTO crm_contacts (first_name, last_name, whatsapp_number, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$firstName, $lastName, $phone]);
        
        return $this->pdo->lastInsertId();
    }
    
    private function findOrCreateConversation($contactId) {
        // Try to find open conversation
        $stmt = $this->pdo->prepare("SELECT id FROM crm_conversations WHERE contact_id = ? AND status = 'open' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$contactId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            return $existing['id'];
        }
        
        // Create new
        $stmt = $this->pdo->prepare("
            INSERT INTO crm_conversations (contact_id, channel, status, created_at)
            VALUES (?, 'whatsapp', 'open', NOW())
        ");
        $stmt->execute([$contactId]);
        
        return $this->pdo->lastInsertId();
    }
    
    private function saveMessage($conversationId, $contactId, $senderType, $messageType, $content, $mediaUrl, $mediaType, $mediaName, $wamId = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO crm_messages (conversation_id, contact_id, sender_type, message_type, content, media_url, media_type, media_name, whatsapp_message_id, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW())
        ");
        $stmt->execute([
            $conversationId,
            $contactId,
            $senderType,
            $messageType,
            $content,
            $mediaUrl,
            $mediaType,
            $mediaName,
            $wamId
        ]);
        
        $messageId = $this->pdo->lastInsertId();
        
        // Update conversation
        $this->pdo->prepare("
            UPDATE crm_conversations 
            SET last_message_at = NOW(), unread_count = unread_count + 1, updated_at = NOW() 
            WHERE id = ?
        ")->execute([$conversationId]);
        
        return $messageId;
    }
    
    private function handleAutoReply($conversationId, $contactId, $userMessage, $toPhone) {
        $aiProvider = $this->getSetting('ai_provider');
        
        if ($aiProvider === 'openai') {
            $aiResponse = $this->getOpenAIResponse($userMessage);
            if ($aiResponse) {
                // Send via WhatsApp
                $result = $this->sendTextMessage($toPhone, $aiResponse);
                if ($result['success']) {
                    // Save assistant message
                    $wamId = $result['response']['messages'][0]['id'] ?? null;
                    $this->saveMessage($conversationId, $contactId, 'bot', 'text', $aiResponse, null, null, null, $wamId);
                }
            }
        }
    }
    
    private function getOpenAIResponse($message) {
        $apiKey = $this->getSetting('openai_api_key');
        $model = $this->getSetting('ai_model', 'gpt-3.5-turbo');
        $maxTokens = intval($this->getSetting('max_tokens', 1000));
        $temperature = floatval($this->getSetting('temperature', 0.7));
        
        if (empty($apiKey)) return null;
        
        $systemPrompt = "أنت مساعد خدمة عملاء محترم. أجب بإيجابية ومفصلة بلغة عربية خاطفة.";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        
        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $message]
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature
        ];
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $result = json_decode($response, true);
            return $result['choices'][0]['message']['content'] ?? null;
        }
        
        return null;
    }
}

