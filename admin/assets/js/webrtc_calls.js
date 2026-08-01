
// Global variables
let ws;
let wsConnected = false;
let peer;
let localStream;
let isCaller = false;
let currentCallType;
let currentCallPartnerId;
let currentCallPartnerName;
let currentCallId;
let isCallActive = false;
let currentCallStatus;
let callDurationInterval;
let callStartTime;

// Placeholder/Helper functions (to be properly implemented or checked against existing code)
// These are minimal implementations to make the provided code runnable.
function showToast(type, title, message = '') {
    console.log(`Toast (${type}): ${title} - ${message}`);
    // A real implementation would display a toast notification to the user
}

function playDialtone() {
    console.log('Playing dialtone...');
    // A real implementation would play an audio file
}

function playRingtone() {
    console.log('Playing ringtone...');
    // A real implementation would play an audio file
}

function stopAllCallAudio() {
    console.log('Stopping all call audio...');
    // A real implementation would stop playing dialtone/ringtone
}

function showIncomingCallUI(data) {
    console.log('Showing incoming call UI:', data);
    document.getElementById('incomingCallerName').innerText = data.callerName || 'مستخدم';
    document.getElementById('incomingCallType').innerText = data.callType === 'video' ? 'مكالمة فيديو' : 'مكالمة صوتية';
    document.getElementById('incomingCallOverlay').classList.remove('d-none');
    // A real implementation would display a more elaborate UI
}

function showCallUI(callType) {
    console.log('Showing call UI:', callType);
    document.getElementById('callPartnerName').innerText = currentCallPartnerName;
    document.getElementById('callStatus').innerText = 'يتصل...';
    document.getElementById('callOverlay').classList.remove('d-none');

    // Show/hide video based on callType
    const localVideo = document.getElementById('localVideo');
    const remoteVideo = document.getElementById('remoteVideo');
    if (callType === 'video') {
        localVideo.style.display = 'block';
        remoteVideo.style.display = 'block';
        localVideo.srcObject = localStream; // Assign local stream to local video element
    } else {
        localVideo.style.display = 'none';
        remoteVideo.style.display = 'none';
    }
}


function startCallDurationCounter() {
    console.log('Starting call duration counter...');
    callStartTime = new Date();
    // A real implementation would update a timer in the UI
}

function toggleAudio() {
    console.log('Toggling audio...');
    if (localStream) {
        localStream.getAudioTracks().forEach(track => track.enabled = !track.enabled);
    }
}

function toggleVideo() {
    console.log('Toggling video...');
    if (localStream) {
        localStream.getVideoTracks().forEach(track => track.enabled = !track.enabled);
    }
}

function endCall() {
    console.log('Ending call...');
    if (peer) {
        peer.destroy();
    }
    handleCallEnded();
    // Send signal to partner that call has ended
    if (wsConnected && ws && currentCallPartnerId && currentCallId) {
        ws.send(JSON.stringify({
            type: 'call_end',
            targetUserId: currentCallPartnerId,
            callId: currentCallId
        }));
    }
}

function acceptCall() {
    console.log('Accepting call...');
    document.getElementById('incomingCallOverlay').classList.add('d-none');
    stopAllCallAudio();
    isCallActive = true;
    showCallUI(currentCallType); // Show the main call UI

    // Logic to accept the call and establish WebRTC connection
    if (wsConnected && ws && currentCallPartnerId && currentCallId) {
        ws.send(JSON.stringify({
            type: 'call_accept',
            targetUserId: currentCallPartnerId,
            callId: currentCallId
        }));
    }
    // Start media capture for local stream
    navigator.mediaDevices.getUserMedia({
        video: currentCallType === 'video',
        audio: true
    }).then(stream => {
        localStream = stream;
        document.getElementById('localVideo').srcObject = localStream;
        // If peer is already initialized, add stream. Otherwise, it will be added when peer is created
        if (peer) {
            peer.addStream(localStream);
        } else {
             // Create peer for the callee
            peer = new SimplePeer({
                initiator: false, // Callee is not the initiator
                trickle: true,
                stream: localStream,
                config: getWebRtcConfig()
            });

            peer.on('signal', (signal) => {
                if (wsConnected && ws) {
                    ws.send(JSON.stringify({
                        type: 'signal',
                        targetUserId: currentCallPartnerId,
                        callId: currentCallId,
                        signal: signal
                    }));
                }
            });

            peer.on('stream', (remoteStream) => {
                const remoteVideo = document.getElementById('remoteVideo');
                if (remoteVideo) {
                    remoteVideo.srcObject = remoteStream;
                    remoteVideo.style.display = 'block';
                }
            });

            peer.on('connect', () => {
                showToast('success', 'تم الاتصال!');
                isCallActive = true;
                startCallDurationCounter();
            });

            peer.on('close', handleCallEnded);
            peer.on('error', (err) => {
                console.error('Peer error:', err);
                showToast('error', 'خطأ في المكالمة', err.message);
                handleCallEnded();
            });
        }
    }).catch(error => {
        console.error('Error getting user media on accept:', error);
        showToast('error', 'تعذر الوصول للكاميرا أو الميكروفون عند قبول المكالمة');
        handleCallEnded();
    });
}

function rejectCall() {
    console.log('Rejecting call...');
    document.getElementById('incomingCallOverlay').classList.add('d-none');
    stopAllCallAudio();
    // Send signal to partner that call has been rejected
    if (wsConnected && ws && currentCallPartnerId && currentCallId) {
        ws.send(JSON.stringify({
            type: 'call_reject',
            targetUserId: currentCallPartnerId,
            callId: currentCallId
        }));
    }
    handleCallEnded();
}

function handleCallEnded() {
    console.log('Handling call ended...');
    isCallActive = false;
    isCaller = false;
    stopAllCallAudio();
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
    }
    if (peer) {
        peer.destroy();
        peer = null;
    }
    localStream = null;
    currentCallId = null;
    currentCallPartnerId = null;
    currentCallType = null;
    clearInterval(callDurationInterval);
    document.getElementById('callOverlay').classList.add('d-none');
    document.getElementById('incomingCallOverlay').classList.add('d-none');
    const remoteVideo = document.getElementById('remoteVideo');
    if (remoteVideo) remoteVideo.srcObject = null;
    const localVideo = document.getElementById('localVideo');
    if (localVideo) localVideo.srcObject = null;
}

// استبدال دالة connectWebSocket بهذه النسخة البسيطة
function connectWebSocket() {
    if (wsConnected) return;

    // استخدام البيئة المكتشفة من PHP
    const wsHost = window.WS_CONFIG?.host || window.SERVER_ENV?.host || window.location.hostname;
    const wsPort = window.WS_CONFIG?.port || 8081;
    const wsUrl = `ws://${wsHost}:${wsPort}`;

    try {
        ws = new WebSocket(wsUrl);

        ws.onopen = function() {
            console.log('WebSocket متصل');
            wsConnected = true;
            // Assuming userId is defined globally or retrieved from a session/cookie
            // For now, let's use a placeholder if not found.
            const userId = '<?php echo $_SESSION['user_id'] ?? 'guest'; ?>';
            ws.send(JSON.stringify({ type: 'login', userId: userId }));
        };

        ws.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);
                handleWebSocketMessage(data);
            } catch (e) {
                console.error('خطأ في قراءة الرسالة:', e);
            }
        };

        ws.onclose = function() {
            console.log('فقد الاتصال بـ WebSocket، محاولة إعادة الاتصال...');
            wsConnected = false;
            setTimeout(connectWebSocket, 3000);
        };

        ws.onerror = function(error) {
            console.error('خطأ في WebSocket:', error);
            ws.close();
        };
    } catch (error) {
        console.error('تعذر الاتصال بـ WebSocket:', error);
        // محاولة إعادة الاتصال بعد 5 ثواني
        setTimeout(connectWebSocket, 5000);
    }
}

// دالة بسيطة لإعدادات WebRTC:
// استبدال دالة getWebRtcConfig بهذه النسخة البسيطة
function getWebRtcConfig() {
    // استخدام STUN servers العامة دائماً (تعمل في كل مكان)
    return {
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'stun:stun2.l.google.com:19302' }
        ],
        iceTransportPolicy: 'all',
        bundlePolicy: 'max-bundle',
        rtcpMuxPolicy: 'require'
    };
}

// دالة مبسطة لبدء المكالمة:
// استبدال دالة startCall بهذه النسخة البسيطة
async function startCall(callType) {
    if (!currentChatId || currentChatType !== 'user') {
        showToast('error', 'اختر مستخدمًا أولاً');
        return;
    }

    if (isCallActive) {
        showToast('info', 'لديك مكالمة نشطة');
        return;
    }

    isCaller = true;
    currentCallType = callType;
    currentCallPartnerId = currentChatId;
    currentCallPartnerName = document.getElementById('chatTitle').innerText;

    try {
        // 1. الحصول على الصوت والفيديو
        const stream = await navigator.mediaDevices.getUserMedia({
            video: callType === 'video',
            audio: true
        });

        localStream = stream;

        // 2. إنشاء سجل المكالمة في قاعدة البيانات
        const formData = new FormData();
        formData.append('call_type', callType);
        formData.append('target_type', 'user');
        formData.append('target_id', currentCallPartnerId);

        const response = await fetch('internal_messages.php?action=start_call', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.status !== 'success') {
            throw new Error(data.message || 'فشل بدء المكالمة');
        }

        currentCallId = data.call_id;

        // 3. إنشاء اتصال Peer
        peer = new SimplePeer({
            initiator: true,
            trickle: true,
            stream: stream,
            config: getWebRtcConfig()
        });

        // 4. إعدادات Peer
        peer.on('signal', (signal) => {
            // إرسال الإشارة عبر WebSocket
            if (wsConnected && ws) {
                ws.send(JSON.stringify({
                    type: 'signal',
                    targetUserId: currentCallPartnerId,
                    callId: currentCallId,
                    signal: signal
                }));
            }
        });

        peer.on('stream', (remoteStream) => {
            // عرض الفيديو البعيد
            const remoteVideo = document.getElementById('remoteVideo');
            if (remoteVideo) {
                remoteVideo.srcObject = remoteStream;
                remoteVideo.style.display = 'block';
            }
            document.getElementById('callStatusOverlay')?.classList.add('d-none'); // Using optional chaining for safety
            isCallActive = true;
            startCallDurationCounter();
        });

        peer.on('connect', () => {
            showToast('success', 'تم الاتصال!');
            isCallActive = true;
            startCallDurationCounter();
        });

        peer.on('close', () => {
            handleCallEnded();
        });

        peer.on('error', (err) => {
            console.error('Peer error:', err);
            showToast('error', 'خطأ في المكالمة', err.message);
            handleCallEnded();
        });

        // 5. إظهار واجهة المكالمة
        showCallUI(callType);
        playDialtone();

        // 6. إرسال طلب المكالمة
        if (wsConnected && ws) {
            ws.send(JSON.stringify({
                type: 'call_request',
                targetUserId: currentCallPartnerId,
                callId: currentCallId,
                callType: callType,
                callerName: '<?php echo $_SESSION['username'] ?? 'مستخدم'; ?>'
            }));
        }

        // 7. مهلة الرد
        setTimeout(() => {
            if (!isCallActive && currentCallId) {
                showToast('error', 'لم يتم الرد على المكالمة');
                handleCallEnded();
            }
        }, 45000);

    } catch (error) {
        console.error('Error starting call:', error);
        showToast('error', 'تعذر بدء المكالمة', error.message);
        handleCallEnded();
    }
}

// دالة مبسطة لمعالجة الإشارات الواردة:
// دالة مبسطة لمعالجة رسائل WebSocket
function handleWebSocketMessage(data) {
    console.log('رسالة WebSocket:', data);

    if (data.senderUserId === userId) { // Assuming userId is globally available from PHP echo
        return; // تجاهل الرسائل المرسلة من نفسي
    }

    switch(data.type) {
        case 'call_request':
            // عرض مكالمة واردة
            if (!isCallActive) {
                currentCallId = data.callId;
                currentCallType = data.callType;
                currentCallPartnerId = data.senderUserId;
                currentCallPartnerName = data.callerName || 'مستخدم';

                // عرض نافذة المكالمة الواردة
                showIncomingCallUI(data);
                playRingtone();
            }
            break;

        case 'signal':
            // معالجة إشارة WebRTC
            if (peer) {
                try {
                    peer.signal(data.signal);
                } catch (error) {
                    console.error('خطأ في معالجة الإشارة:', error);
                }
            } else {
                // If peer is not yet created for callee, create it now
                peer = new SimplePeer({
                    initiator: false, // Callee is not the initiator
                    trickle: true,
                    config: getWebRtcConfig()
                });
                peer.on('signal', (signal) => {
                    if (wsConnected && ws) {
                        ws.send(JSON.stringify({
                            type: 'signal',
                            targetUserId: currentCallPartnerId,
                            callId: currentCallId,
                            signal: signal
                        }));
                    }
                });
                peer.on('stream', (remoteStream) => {
                    const remoteVideo = document.getElementById('remoteVideo');
                    if (remoteVideo) {
                        remoteVideo.srcObject = remoteStream;
                        remoteVideo.style.display = 'block';
                    }
                });
                peer.on('connect', () => {
                    showToast('success', 'تم الاتصال!');
                    isCallActive = true;
                    startCallDurationCounter();
                });
                peer.on('close', handleCallEnded);
                peer.on('error', (err) => {
                    console.error('Peer error:', err);
                    showToast('error', 'خطأ في المكالمة', err.message);
                    handleCallEnded();
                });
                // After creating peer, signal it
                try {
                    peer.signal(data.signal);
                } catch (error) {
                    console.error('خطأ في معالجة الإشارة بعد إنشاء peer:', error);
                }
            }
            break;

        case 'call_accept':
            // تم قبول المكالمة
            currentCallStatus = 'accepted';
            stopAllCallAudio();
            isCallActive = true;
            showToast('success', 'تم قبول المكالمة');
            break;

        case 'call_reject':
        case 'call_end':
            // تم رفض أو إنهاء المكالمة
            handleCallEnded();
            showToast('info', data.type === 'call_reject' ? 'تم رفض المكالمة' : 'انتهت المكالمة');
            break;
    }
}


// أضف هذه الدوال للمساعدة في التصحيح
function testWebRTC() {
    try {
        // اختبار دعم WebRTC
        if (!window.RTCPeerConnection) {
            showToast('error', 'المتصفح لا يدعم WebRTC');
            return false;
        }

        // اختبار الكاميرا والميكروفون
        navigator.mediaDevices.getUserMedia({ audio: true, video: true })
            .then(stream => {
                showToast('success', 'الكاميرا والميكروفون يعملان');
                stream.getTracks().forEach(track => track.stop());
            })
            .catch(error => {
                showToast('error', 'تعذر الوصول للكاميرا أو الميكروفون');
            });

        return true;
    } catch (error) {
        showToast('error', 'WebRTC غير مدعوم');
        return false;
    }
}
