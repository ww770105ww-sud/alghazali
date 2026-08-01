    <footer class="footer mt-0">
        <!-- قسم المحتوى الرئيسي -->
        <div class="footer-main py-5">
            <div class="container">
                <div class="row g-5">
                    <!-- عن الوكالة -->
                    <div class="col-lg-4 col-md-6">
                        <div class="footer-brand mb-3">
                            <?php if ($settings['site_logo']): ?>
                                <img src="assets/uploads/<?php echo $settings['site_logo']; ?>" alt="<?php echo $settings['site_name']; ?>" height="40" class="mb-3 footer-logo">
                            <?php else: ?>
                                <h4 class="footer-title mb-3"><?php echo $settings['site_name']; ?></h4>
                            <?php endif; ?>
                        </div>
                        <p class="footer-desc">نحن نقدم أفضل خدمات السفر والعمرة بأعلى معايير الجودة والاحترافية، نسعى دائماً لخدمتكم وتسهيل إجراءاتكم.</p>
                        <!-- روابط التواصل الاجتماعي -->
                        <?php if (!empty($social_links)): ?>
                            <div class="footer-social mt-4">
                                <?php foreach ($social_links as $link): ?>
                                    <a href="<?php echo $link['link_url']; ?>" target="_blank" class="footer-social-icon" title="<?php echo $link['platform_name']; ?>">
                                        <i class="<?php echo $link['platform_icon']; ?>"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- روابط سريعة -->
                    <div class="col-lg-2 col-md-6 col-6">
                        <h6 class="footer-heading">روابط سريعة</h6>
                        <ul class="footer-links">
                            <li><a href="index.php"><i class="fas fa-angle-left"></i> الرئيسية</a></li>
                            <li><a href="about.php"><i class="fas fa-angle-left"></i> من نحن</a></li>
                            <li><a href="services.php"><i class="fas fa-angle-left"></i> خدماتنا</a></li>
                            <li><a href="contact.php"><i class="fas fa-angle-left"></i> اتصل بنا</a></li>
                        </ul>
                    </div>

                    <!-- معلومات التواصل -->
                    <div class="col-lg-3 col-md-6">
                        <h6 class="footer-heading">تواصل معنا</h6>
                        <ul class="footer-contact-list">
                            <li>
                                <span class="footer-contact-icon"><i class="fas fa-map-marker-alt"></i></span>
                                <span><?php echo $settings['address']; ?></span>
                            </li>
                            <li>
                                <span class="footer-contact-icon"><i class="fas fa-phone-alt"></i></span>
                                <span><?php echo $settings['phone']; ?></span>
                            </li>
                            <li>
                                <span class="footer-contact-icon"><i class="fas fa-envelope"></i></span>
                                <span><?php echo $settings['email']; ?></span>
                            </li>
                        </ul>
                    </div>

                    <!-- ساعات العمل -->
                    <div class="col-lg-3 col-md-6">
                        <h6 class="footer-heading">ساعات العمل</h6>
                        <ul class="footer-hours">
                            <li><span>السبت – الخميس</span><span class="footer-time">8ص 10 م</span></li>
                            <li><span>الجمعة</span><span class="footer-time">مغلق</span></li>
                        </ul>
                        <div class="footer-badge mt-3">
                            <i class="fas fa-headset me-2"></i>
                            دعم على مدار الساعة
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- شريط الحقوق -->
        <div class="footer-bottom">
            <div class="container">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
                    <p class="mb-0 footer-copy">
                        <?php
                        if (!empty($settings['copyright_text'])) {
                            echo $settings['copyright_text'];
                        } else {
                            echo "&copy; " . date('Y') . " جميع الحقوق محفوظة لـ <span class='fw-bold text-white'>" . $settings['site_name'] . "</span>";
                        }
                        ?>
                    </p>
                    <p class="mb-0 footer-copy">
                        برمجة وتطوير:
                        <?php if (!empty($settings['developer_name'])): ?>
                            <?php if (!empty($settings['developer_link'])): ?>
                                <a href="<?php echo $settings['developer_link']; ?>" target="_blank" class="footer-dev-link"><?php echo $settings['developer_name']; ?></a>
                            <?php else: ?>
                                <span class="text-white fw-bold"><?php echo $settings['developer_name']; ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-white fw-bold">المطور</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <style>
        /* ===== FOOTER ===== */
        .footer {
            font-family: 'Cairo', 'Segoe UI', sans-serif;
            background-color: var(--footer-bg) !important;
            color: rgba(255, 255, 255, 0.8);
            position: relative;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #82c91e, #a3e635, #4ade80, #82c91e);
            background-size: 300% auto;
            animation: shimmer-footer 4s linear infinite;
        }

        @keyframes shimmer-footer {
            0% {
                background-position: 0% center;
            }

            100% {
                background-position: 300% center;
            }
        }

        /* Logo في الفوتر */
        .footer-logo {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        .footer-title {
            color: #fff;
            font-weight: 900;
            font-size: 1.3rem;
        }

        .footer-desc {
            color: rgba(255, 255, 255, 0.55);
            font-size: 0.9rem;
            line-height: 1.8;
        }

        /* العناوين في الفوتر */
        .footer-heading {
            color: #fff;
            font-family: 'Cairo', sans-serif;
            font-weight: 800;
            font-size: 1rem;
            margin-bottom: 20px;
            position: relative;
            padding-bottom: 12px;
        }

        .footer-heading::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 30px;
            height: 2.5px;
            background: linear-gradient(90deg, #82c91e, #a3e635);
            border-radius: 2px;
        }

        /* الروابط السريعة */
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: rgba(255, 255, 255, 0.55) !important;
            text-decoration: none !important;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
        }

        .footer-links a i {
            color: #82c91e;
            font-size: 0.75rem;
            transition: transform 0.25s ease;
        }

        .footer-links a:hover {
            color: #fff !important;
            padding-right: 5px;
        }

        .footer-links a:hover i {
            transform: translateX(-4px);
        }

        /* قائمة التواصل */
        .footer-contact-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-contact-list li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
            color: rgba(255, 255, 255, 0.55);
            font-size: 0.88rem;
            line-height: 1.6;
        }

        .footer-contact-icon {
            width: 32px;
            height: 32px;
            background: rgba(130, 201, 30, 0.12);
            border: 1px solid rgba(130, 201, 30, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #82c91e;
            font-size: 0.82rem;
            flex-shrink: 0;
        }

        /* ساعات العمل */
        .footer-hours {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-hours li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            color: rgba(255, 255, 255, 0.55);
            font-size: 0.88rem;
        }

        .footer-hours li:last-child {
            border-bottom: none;
        }

        .footer-time {
            background: rgba(130, 201, 30, 0.12);
            border: 1px solid rgba(130, 201, 30, 0.2);
            color: #a3e635;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        /* شارة الدعم */
        .footer-badge {
            display: inline-flex;
            align-items: center;
            background: rgba(130, 201, 30, 0.1);
            border: 1px solid rgba(130, 201, 30, 0.25);
            color: #a3e635;
            padding: 7px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
        }

        /* أيقونات التواصل الاجتماعي في الفوتر */
        .footer-social {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .footer-social-icon {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.6) !important;
            font-size: 1rem;
            text-decoration: none !important;
            transition: all 0.25s ease;
        }

        .footer-social-icon:hover {
            background: #82c91e;
            border-color: #82c91e;
            color: #fff !important;
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(130, 201, 30, 0.35);
        }

        /* شريط الحقوق */
        .footer-bottom {
            background: rgba(0, 0, 0, 0.2);
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            padding: 16px 0;
        }

        .footer-copy {
            color: rgba(255, 255, 255, 0.45);
            font-size: 0.85rem;
        }

        .footer-dev-link {
            color: #82c91e !important;
            text-decoration: none !important;
            font-weight: 700;
        }

        .footer-dev-link:hover {
            color: #a3e635 !important;
        }

        /* Dark mode overrides */
        body.dark-mode .footer {
            background-color: #060d1a !important;
        }

        body.dark-mode .footer-bottom {
            background: rgba(0, 0, 0, 0.3);
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        AOS.init({
            duration: 800,
            once: true,
            offset: 100
        });

        // كود تبديل الوضع الليلي/النهاري
        function getCookie(name) {
            const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? decodeURIComponent(match[2]) : null;
        }

        function setThemeCookie(theme) {
            document.cookie = 'theme=' + encodeURIComponent(theme) + '; path=/; max-age=31536000';
        }

        function saveThemePreference(theme) {
            const formData = new FormData();
            formData.append('theme', theme);
            formData.append('csrf_token', <?php echo function_exists('generate_csrf_token') ? json_encode(generate_csrf_token()) : '""'; ?>);

            fetch('admin/ajax_save_theme.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }).catch(() => {
                // تجاهل إخفاق الحفظ إذا لم يكن المستخدم مسجلاً
            });
        }

        function resolveTheme(theme) {
            if (theme === 'system') {
                return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            return theme;
        }

        function applyTheme(theme) {
            const body = document.body;
            const themeIcon = document.getElementById('themeIcon');
            const isDark = theme === 'dark';

            body.classList.toggle('dark-mode', isDark);
            document.documentElement.setAttribute('data-bs-theme', isDark ? 'dark' : 'light');

            if (themeIcon) {
                themeIcon.classList.toggle('fa-sun', isDark);
                themeIcon.classList.toggle('fa-moon', !isDark);
            }

            localStorage.setItem('theme', theme);
            localStorage.setItem('admin_theme', theme);
            setThemeCookie(theme);
            saveThemePreference(theme);
        }

        function toggleTheme() {
            const currentTheme = document.body.classList.contains('dark-mode') ? 'dark' : 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            applyTheme(newTheme);
        }

        // تحميل الوضع المحفوظ عند فتح الصفحة
        window.addEventListener('DOMContentLoaded', (event) => {
            const defaultTheme = '<?php echo $settings['default_theme'] ?? 'light'; ?>';
            const savedTheme = localStorage.getItem('theme') || getCookie('theme') || defaultTheme;
            const theme = resolveTheme(savedTheme);
            const themeIcon = document.getElementById('themeIcon');

            if (theme === 'dark') {
                document.body.classList.add('dark-mode');
                document.documentElement.setAttribute('data-bs-theme', 'dark');
                if (themeIcon) {
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                }
            } else if (theme === 'light') {
                document.body.classList.remove('dark-mode');
                document.documentElement.setAttribute('data-bs-theme', 'light');
                if (themeIcon) {
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                }
            }
        });
    </script>
    </body>

    </html>
