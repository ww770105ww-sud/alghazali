// نظام التنبيهات الشامل - مثل واتساب
(function() {
    let lastUnreadCount = 0;
    let lastMessageId = 0;
    let lastNotifId = 0;
    let notificationPermissionRequested = false;
    let audioEnabled = localStorage.getItem('audioNotificationsEnabled') === 'true'; // Audio notifications are off by default unless explicitly enabled

    // تفعيل/تعطيل الصوت
    window.toggleAudioNotifications = function() {
        audioEnabled = !audioEnabled;
        localStorage.setItem('audioNotificationsEnabled', audioEnabled ? 'true' : 'false');
        
        const btn = document.getElementById('audioNotificationBtn');
        if (btn) {
            if (audioEnabled) {
                btn.classList.add('active');
                btn.title = 'تعطيل الصوت';
                playNotificationSound();
            } else {
                btn.classList.remove('active');
                btn.title = 'تفعيل الصوت';
            }
        }
    };

    // طلب إذن التنبيهات
    function requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default' && !notificationPermissionRequested) {
            Notification.requestPermission();
            notificationPermissionRequested = true;
        }
    }

    // تشغيل صوت التنبيه
    function playNotificationSound() {
        if (!audioEnabled) return;
        
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            
            if (audioContext.state === 'suspended') {
                audioContext.resume();
            }
            
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            const now = audioContext.currentTime;
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            // نغمة تنبيه مميزة (مثل واتساب)
            oscillator.frequency.setValueAtTime(900, now);
            oscillator.frequency.setValueAtTime(700, now + 0.1);
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.3, now);
            gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.6);
            
            oscillator.start(now);
            oscillator.stop(now + 0.6);
        } catch(e) {
            console.log('Audio notification error:', e);
        }
    }

    // إظهار تنبيه المتصفح للإشعارات العامة
    function showGeneralBrowserNotification(title, message, link) {
        if ('Notification' in window && Notification.permission === 'granted') {
            const notification = new Notification(title, {
                body: message.substring(0, 150) + (message.length > 150 ? '...' : ''),
                icon: 'assets/img/notif_icon.png', // Relative path
                badge: 'assets/img/notif_icon.png',
                tag: 'general-notif',
                requireInteraction: true, // Keep it visible until user acts
                vibrate: [200, 100, 200]
            });
            
            notification.onclick = function() {
                window.focus();
                window.location.href = link;
                notification.close();
            };
        }
    }

    // مراقبة الإشعارات العامة الجديدة
    function checkForGeneralNotifications() {
        fetch('ajax_work_visa.php?action=get_new_notifications&last_id=' + lastNotifId, {
            headers: { 'Accept': 'application/json' }
        })
            .then(r => {
                const contentType = r.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    return r.json();
                } else {
                    return r.text().then(text => {
                        console.group("نظام التنبيهات: استجابة غير صالحة من السيرفر");
                        console.error("المسار:", r.url);
                        console.error("الحالة:", r.status);
                        console.error("نوع المحتوى:", contentType);
                        console.error("بداية الاستجابة:", text.substring(0, 500));
                        console.groupEnd();
                        throw new Error("Invalid response from server (not JSON)");
                    });
                }
            })
            .then(data => {
                if(data.status === 'success' && data.notifications && data.notifications.length > 0) {
                    data.notifications.forEach(notif => {
                        if (notif.id > lastNotifId) {
                            playNotificationSound();
                            showGeneralBrowserNotification(notif.title, notif.message, notif.link);
                            
                            // Update the main badge count
                            const mainBadge = document.getElementById('mainNotifBadge');
                            if (mainBadge) {
                                let currentCount = parseInt(mainBadge.textContent) || 0;
                                mainBadge.textContent = currentCount + 1;
                                mainBadge.style.display = 'inline-block';
                                mainBadge.style.animation = 'pulse 0.6s';
                            }
                            
                            // Update the last ID
                            if (notif.id > lastNotifId) lastNotifId = notif.id;
                        }
                    });
                }
            })
            .catch(e => console.error('Error checking notifications:', e));
    }

    // إظهار تنبيه المتصفح مع تفاصيل الرسالة
    function showBrowserNotification(senderName, messageText) {
        if ('Notification' in window && Notification.permission === 'granted') {
            const notification = new Notification('رسالة جديدة من ' + senderName, {
                body: messageText.substring(0, 100) + (messageText.length > 100 ? '...' : ''),
                icon: 'assets/img/logo.png',
                badge: 'assets/img/logo.png',
                tag: 'new-message',
                requireInteraction: false,
                vibrate: [200, 100, 200]
            });
            
            // إغلاق التنبيه تلقائياً بعد 6 ثوانٍ
            setTimeout(() => notification.close(), 6000);
            
            // الانتقال للمحادثة عند الضغط على التنبيه
            notification.onclick = function() {
                window.focus();
                window.location.href = 'internal_messages.php';
                notification.close();
            };
        }
    }

    // تحديث العداد فوق الأيقونة
    function updateMessageBadge(count) {
        const badge = document.getElementById('topMessagesBadge');
        const messageLink = Array.from(document.querySelectorAll('a[href]')).find(a => {
            const href = a.getAttribute('href') || '';
            return href === 'internal_messages.php' || href.endsWith('/internal_messages.php');
        });
        
        if(count > 0) {
            if(badge) {
                badge.textContent = count;
                // إضافة تأثير بصري (pulse)
                badge.style.animation = 'pulse 0.6s';
            } else if(messageLink) {
                const newBadge = document.createElement('span');
                newBadge.className = 'icon-badge';
                newBadge.id = 'topMessagesBadge';
                newBadge.textContent = count;
                newBadge.style.cssText = `
                    position: absolute;
                    top: -8px;
                    right: -8px;
                    background: #dc3545;
                    color: white;
                    border-radius: 50%;
                    width: 24px;
                    height: 24px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 12px;
                    font-weight: bold;
                    z-index: 1000;
                    animation: pulse 0.6s;
                `;
                messageLink.style.position = 'relative';
                messageLink.appendChild(newBadge);
            }
        } else if(badge) {
            badge.remove();
        }
    }

    // مراقبة الرسائل الجديدة
    function checkForNewMessages() {
        // جلب عدد الرسائل غير المقروءة
        fetch('internal_messages.php?action=get_unread_count', {
            headers: { 'Accept': 'application/json' }
        })
            .then(r => {
                const contentType = r.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    return r.json();
                } else {
                    return r.text().then(text => {
                        console.group("نظام التنبيهات: استجابة غير صالحة من السيرفر");
                        console.error("المسار:", r.url);
                        console.error("الحالة:", r.status);
                        console.error("نوع المحتوى:", contentType);
                        console.error("بداية الاستجابة:", text.substring(0, 500));
                        console.groupEnd();
                        throw new Error("Invalid response from server (not JSON)");
                    });
                }
            })
            .then(data => {
                if(data.status === 'success') {
                    const unreadCount = data.unread_count || 0;
                    
                    // إذا كانت هناك رسائل جديدة
                    if(unreadCount > lastUnreadCount) {
                        // جلب تفاصيل آخر رسالة
                        fetch('internal_messages.php?action=get_latest_message', {
                            headers: { 'Accept': 'application/json' }
                        })
                            .then(r => {
                                const contentType = r.headers.get("content-type");
                                if (contentType && contentType.indexOf("application/json") !== -1) {
                                    return r.json();
                                } else {
                                    return r.text().then(text => {
                                        console.group("نظام التنبيهات: استجابة غير صالحة من السيرفر");
                                        console.error("المسار:", r.url);
                                        console.error("الحالة:", r.status);
                                        console.error("نوع المحتوى:", contentType);
                                        console.error("بداية الاستجابة:", text.substring(0, 500));
                                        console.groupEnd();
                                        throw new Error("Invalid response from server (not JSON)");
                                    });
                                }
                            })
                            .then(latestData => {
                                if(latestData.status === 'success' && latestData.message) {
                                    const msg = latestData.message;
                                    
                                    // التحقق من أن هذه رسالة جديدة (لم نعرضها من قبل)
                                    if(msg.id !== lastMessageId) {
                                        // تشغيل الصوت
                                        playNotificationSound();
                                        
                                        // إظهار تنبيه المتصفح مع التفاصيل
                                        showBrowserNotification(msg.full_name, msg.message);
                                        
                                        // تحديث آخر ID
                                        lastMessageId = msg.id;
                                    }
                                }
                            })
                            .catch(e => console.error('Error fetching latest message:', e));
                    }
                    
                    // تحديث العداد بغض النظر عن التغيير
                    updateMessageBadge(unreadCount);
                    lastUnreadCount = unreadCount;
                }
            })
            .catch(e => console.error('Error checking messages:', e));
    }

    // إضافة زر تفعيل الصوت إلى الشريط العلوي
    function addAudioToggleButton() {
        setTimeout(() => {
            const topNavbar = document.querySelector('.top-navbar');
            if (topNavbar && !document.getElementById('audioNotificationBtn')) {
                const btn = document.createElement('button');
                btn.id = 'audioNotificationBtn';
                btn.className = 'icon-btn' + (audioEnabled ? ' active' : '');
                btn.title = audioEnabled ? 'تعطيل الصوت' : 'تفعيل الصوت';
                btn.innerHTML = '<i class="fas ' + (audioEnabled ? 'fa-volume-up' : 'fa-volume-mute') + '"></i>';
                btn.style.cssText = `
                    background: none;
                    border: none;
                    color: ${audioEnabled ? '#28a745' : '#666'};
                    cursor: pointer;
                    font-size: 18px;
                    padding: 8px 12px;
                    transition: color 0.3s;
                    margin: 0 5px;
                `;
                btn.addEventListener('click', toggleAudioNotifications);
                btn.addEventListener('mouseenter', function() {
                    this.style.color = audioEnabled ? '#28a745' : '#dc3545';
                });
                btn.addEventListener('mouseleave', function() {
                    this.style.color = audioEnabled ? '#28a745' : '#666';
                });
                
                // إضافة الزر قبل أيقونة الرسائل
                const messageLink = Array.from(document.querySelectorAll('a[href]')).find(a => {
                    const href = a.getAttribute('href') || '';
                    return href === 'internal_messages.php' || href.endsWith('/internal_messages.php');
                });
                if (messageLink && messageLink.parentNode) {
                    messageLink.parentNode.insertBefore(btn, messageLink);
                }
            }
        }, 500);
    }

    // إضافة أنماط CSS للتأثيرات
    function addStyles() {
        if (!document.getElementById('notificationStyles')) {
            const style = document.createElement('style');
            style.id = 'notificationStyles';
            style.textContent = `
                @keyframes pulse {
                    0% { transform: scale(1); }
                    50% { transform: scale(1.1); }
                    100% { transform: scale(1); }
                }
                
                #topMessagesBadge {
                    animation: pulse 0.6s !important;
                }
                
                .icon-btn.active {
                    color: #28a745 !important;
                }
            `;
            document.head.appendChild(style);
        }
    }

    // بدء المراقبة عند تحميل الصفحة
    document.addEventListener('DOMContentLoaded', function() {
        addStyles();
        requestNotificationPermission();
        checkForNewMessages();
        checkForGeneralNotifications(); // Start general notif check
        addAudioToggleButton();
        
        // مراقبة الرسائل كل 3 ثوانٍ
        setInterval(checkForNewMessages, 3000);
        // مراقبة الإشعارات العامة كل 5 ثوانٍ
        setInterval(checkForGeneralNotifications, 5000);
    });

    // مراقبة الرسائل عند العودة للصفحة من تبويب آخر
    document.addEventListener('visibilitychange', function() {
        if(!document.hidden) {
            checkForNewMessages();
        }
    });

    // مراقبة الرسائل عند الضغط على أيقونة الرسائل
    document.addEventListener('click', function(e) {
        const messageLink = e.target.closest('a[href$="/internal_messages.php"], a[href="internal_messages.php"]');
        if(messageLink) {
            // إعادة تعيين العداد عند الدخول للمحادثات
            setTimeout(() => {
                lastUnreadCount = 0;
                lastMessageId = 0;
                updateMessageBadge(0);
            }, 500);
        }
    });
})();
