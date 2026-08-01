        </div> <!-- end content-body -->
    </div> <!-- end main-wrapper -->


    <style>
        /* Fix SweetAlert visibility - ULTRA HIGH PRIORITY */
        .swal2-popup,
        .app-swal-popup {
            color: #0f172a !important;
        }
        
        .theme-dark .swal2-popup,
        .dark-mode .swal2-popup,
        body.dark-mode .swal2-popup,
        body.theme-dark .swal2-popup,
        .theme-dark .app-swal-popup,
        .dark-mode .app-swal-popup,
        body.dark-mode .app-swal-popup,
        body.theme-dark .app-swal-popup {
            color: #e2e8f0 !important;
            background-color: #1e293b !important;
        }
        
        .swal2-title,
        .app-swal-title {
            color: #0f172a !important;
        }
        
        .swal2-html-container,
        .swal2-content,
        .swal2-text,
        .app-swal-html {
            color: #0f172a !important;
        }
        
        .theme-dark .swal2-title,
        .dark-mode .swal2-title,
        body.dark-mode .swal2-title,
        body.theme-dark .swal2-title,
        .theme-dark .app-swal-title,
        .dark-mode .app-swal-title,
        body.dark-mode .app-swal-title,
        body.theme-dark .app-swal-title {
            color: #e2e8f0 !important;
        }
        
        .theme-dark .swal2-html-container,
        .dark-mode .swal2-html-container,
        .theme-dark .swal2-content,
        .dark-mode .swal2-content,
        .theme-dark .swal2-text,
        .dark-mode .swal2-text,
        body.dark-mode .swal2-html-container,
        body.theme-dark .swal2-html-container,
        body.dark-mode .swal2-content,
        body.theme-dark .swal2-content,
        body.dark-mode .swal2-text,
        body.theme-dark .swal2-text,
        .theme-dark .app-swal-html,
        .dark-mode .app-swal-html,
        body.dark-mode .app-swal-html,
        body.theme-dark .app-swal-html {
            color: #e2e8f0 !important;
        }
        
        /* Force text color for ABSOLUTELY EVERYTHING inside SweetAlert */
        .swal2-popup *,
        .app-swal-popup *,
        .swal2-popup *::before,
        .swal2-popup *::after,
        .app-swal-popup *::before,
        .app-swal-popup *::after {
            color: inherit !important;
        }
    </style>

    <script>
        // التنبيهات يتم إدارتها الآن عبر js/global_notifications.js بشكل أكثر شمولية
    </script>
<script src="js/global_notifications.js?v=<?php echo filemtime(__DIR__ . '/js/global_notifications.js'); ?>"></script>
<script>
    function isDarkUiTheme() {
        return document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode');
    }

    function getAppSwalOptions(options = {}) {
        const dark = isDarkUiTheme();
        const defaults = {
            confirmButtonText: 'حسناً',
            cancelButtonText: 'إلغاء',
            reverseButtons: true,
            buttonsStyling: true,
            background: dark ? '#0f172a' : '#ffffff',
            color: dark ? '#e2e8f0' : '#0f172a',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: dark ? '#334155' : '#e2e8f0',
            customClass: {
                popup: 'app-swal-popup',
                title: 'app-swal-title',
                htmlContainer: 'app-swal-html',
                actions: 'app-swal-actions',
                confirmButton: 'app-swal-confirm',
                cancelButton: 'app-swal-cancel',
                denyButton: 'app-swal-deny',
                icon: 'app-swal-icon'
            },
            // Force text color with inline styles as backup
            didOpen: (popup) => {
                const title = popup.querySelector('.swal2-title');
                const html = popup.querySelector('.swal2-html-container');
                if (title) {
                    title.style.color = dark ? '#e2e8f0' : '#0f172a';
                }
                if (html) {
                    html.style.color = dark ? '#e2e8f0' : '#0f172a';
                }
            }
        };

        const merged = {
            ...defaults,
            ...options,
            customClass: {
                ...defaults.customClass,
                ...(options.customClass || {})
            }
        };

        if (merged.toast) {
            merged.position = merged.position || 'top-start';
            merged.showConfirmButton = merged.showConfirmButton ?? false;
            merged.timer = merged.timer ?? 3500;
            merged.timerProgressBar = merged.timerProgressBar ?? true;
        }

        return merged;
    }

    (function patchSweetAlertGlobally() {
        if (typeof window.Swal === 'undefined' || window.__appSwalPatched) {
            return;
        }

        const originalFire = window.Swal.fire.bind(window.Swal);
        const originalMixin = window.Swal.mixin.bind(window.Swal);

        function normalizeFireArgs(args) {
            if (!args.length) {
                return {};
            }
            if (typeof args[0] === 'string') {
                return {
                    title: args[0],
                    text: args[1],
                    icon: args[2]
                };
            }
            return args[0] || {};
        }

        window.Swal.fire = function(...args) {
            return originalFire(getAppSwalOptions(normalizeFireArgs(args)));
        };

        window.Swal.mixin = function(options = {}) {
            return originalMixin(getAppSwalOptions(options));
        };

        window.__appSwalPatched = true;
    })();

    function showToast(message, type = 'success') {
        const toastEl = document.getElementById('liveToast');
        const toastMessage = document.getElementById('toastMessage');
        const toastIcon = document.getElementById('toastIcon');
        if (!toastEl || !toastMessage || !toastIcon) {
            return;
        }

        let iconClass = 'fa-check-circle';
        switch (type) {
            case 'danger':
            case 'error':
                type = 'danger';
                iconClass = 'fa-circle-xmark';
                break;
            case 'warning':
                iconClass = 'fa-triangle-exclamation';
                break;
            case 'info':
                iconClass = 'fa-circle-info';
                break;
            default:
                type = 'success';
                iconClass = 'fa-circle-check';
                break;
        }

        toastEl.setAttribute('data-toast-type', type);
        toastEl.className = 'app-toast toast align-items-center border-0 shadow-lg';
        toastMessage.innerText = message;
        toastIcon.className = 'fas ' + iconClass + ' me-2 fs-5';

        const toast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4200 });
        toast.show();
    }

    window.showToast = showToast;

    // التحقق من وجود رسائل في PHP لتحويلها إلى Toast
    <?php if (isset($success)): ?>
        showToast("<?php echo addslashes($success); ?>", "success");
    <?php endif; ?>
    <?php if (isset($error)): ?>
        showToast("<?php echo addslashes($error); ?>", "danger");
    <?php endif; ?>
</script>
</body>
</html>
