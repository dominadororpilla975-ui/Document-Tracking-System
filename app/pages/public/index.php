<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/includes/auth.php';
$landingTitle = getSystemSetting('landing_title', 'Administrative Document Desk');
$landingSubtitle = getSystemSetting('landing_subtitle', 'A unified document management and tracking system designed to improve efficiency, transparency, and public service across all municipal departments.');
$municipalityName = getSystemSetting('municipality_name', 'Municipality of Carranglan');
$logoUrl = trim((string) getSystemSetting('brand_logo_url', '')) ?: 'image/carranglan-seal.png';
$backgroundUrl = getSystemSetting('landing_background_url', 'image/carranglan_hall.jpg');
$contactAddress = getSystemSetting('contact_address', 'Municipal Hall, Poblacion, Carranglan, Nueva Ecija');
$contactNumber = getSystemSetting('contact_number', '(044) 123-4567');
$contactEmail = getSystemSetting('contact_email', 'info@carranglan.gov.ph');
$contactWebsite = getSystemSetting('contact_website', 'www.carranglan.gov.ph');
$officeHoursDays = getSystemSetting('office_hours_days', 'Monday - Friday');
$officeHoursTime = getSystemSetting('office_hours_time', '8:00 AM - 5:00 PM');
$officeHoursNote = getSystemSetting('office_hours_note', 'Except Holidays');

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($landingTitle) ?> | <?= htmlspecialchars($municipalityName) ?></title>
    <link rel="icon" type="image/png" href="image/carranglan-seal.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/index.css">
</head>
<body>

    <!-- TOP NAVBAR -->
    <header class="top-navbar">
        <div class="brand-logo-group">
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($municipalityName) ?> Logo" class="brand-logo-img">
            <div class="brand-text-container">
                <span class="brand-title-text"><?= htmlspecialchars(strtoupper($municipalityName)) ?></span>
                <span class="brand-subtitle-text">NUEVA ECIJA</span>
            </div>
        </div>

        <ul class="nav-menu-links">
            <li><a href="index.php" class="active">HOME</a></li>
            <li><a href="#guidelines">GUIDELINES</a></li>
            <li><a href="#contact">CONTACT</a></li>
        </ul>

        <a href="login.php" class="btn-login-header">
             LOGIN
        </a>
    </header>

    <main>
        <!-- HERO BACKGROUND BANNER -->
        <section class="hero-banner-section" style="background-image: linear-gradient(rgba(8, 20, 39, 0.35), rgba(8, 20, 39, 0.45)), url('<?= htmlspecialchars($backgroundUrl, ENT_QUOTES) ?>');" aria-hidden="true"></section>

        <!-- STATS BAR STRIP -->
        <section class="stats-strip-bar">
            <div class="stats-strip-grid">
                <div class="stat-strip-item">
                    <span class="num">22</span>
                    <span class="lbl">Municipal Offices Connected</span>
                </div>
                <div class="stat-strip-item">
                    <span class="num">100%</span>
                    <span class="lbl">Digital Workflow Visibility</span>
                </div>
                <div class="stat-strip-item">
                    <span class="num">24/7</span>
                    <span class="lbl">Real-Time Document Tracking</span>
                </div>
                <div class="stat-strip-item">
                    <span class="num">&lt; 1s</span>
                    <span class="lbl">Instant Query Search</span>
                </div>
            </div>
        </section>

        <!-- SYSTEM FEATURES SECTION -->
        <section class="section-container" id="guidelines" style="background: #ffffff; max-width: 100%; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">
            <div style="max-width: 1240px; margin: 0 auto;">
                <div class="section-title-divider">
                    <span class="section-heading-text">HOW TO USE THE SYSTEM</span>
                </div>
                <p class="section-subtitle-text">A simple guide for submitting and tracking a municipal document.</p>

                <div class="how-to-use-grid" style="margin-bottom: 3rem;">
                    <div class="how-to-use-step">
                        <span class="how-to-use-number">1</span>
                        <h3>Sign in</h3>
                        <p>Open the Client Portal and sign in with your registered Google account.</p>
                    </div>
                    <div class="how-to-use-step">
                        <span class="how-to-use-number">2</span>
                        <h3>Choose a service</h3>
                        <p>Select a Department, then choose one of its available services or activities.</p>
                    </div>
                    <div class="how-to-use-step">
                        <span class="how-to-use-number">3</span>
                        <h3>Submit your document</h3>
                        <p>Enter the title, purpose, description, priority, and optional attachment, then submit.</p>
                    </div>
                    <div class="how-to-use-step">
                        <span class="how-to-use-number">4</span>
                        <h3>Track progress</h3>
                        <p>Save your tracking number and follow each department step from My Documents.</p>
                    </div>
                </div>

                <div class="section-title-divider">
                    <span class="section-heading-text">SYSTEM FEATURES</span>
                </div>
                <p class="section-subtitle-text">Empowering citizens and public servants through digital innovation</p>

                <div class="features-row-container">
                    
                    <div class="feature-item-col">
                        <div class="feature-circle-icon icon-submission">📄</div>
                        <h4 class="feature-item-title">Document Submission</h4>
                        <p class="feature-item-text">Submit and upload documents online with ease.</p>
                    </div>

                    <div class="feature-item-col">
                        <div class="feature-circle-icon icon-tracking">🔍</div>
                        <h4 class="feature-item-title">Document Tracking</h4>
                        <p class="feature-item-text">Track the status of your documents in real-time.</p>
                    </div>

                    <div class="feature-item-col">
                        <div class="feature-circle-icon icon-notifications">🔔</div>
                        <h4 class="feature-item-title">Notifications</h4>
                        <p class="feature-item-text">Receive updates and notifications about your documents.</p>
                    </div>

                    <div class="feature-item-col">
                        <div class="feature-circle-icon icon-reports">📊</div>
                        <h4 class="feature-item-title">Documents & Records</h4>
                        <p class="feature-item-text">Process documents and maintain organized records.</p>
                    </div>

                    <div class="feature-item-col">
                        <div class="feature-circle-icon icon-secure">🛡️</div>
                        <h4 class="feature-item-title">Secure & Reliable</h4>
                        <p class="feature-item-text">Your data is protected with secure and reliable system access.</p>
                    </div>

                </div>
            </div>
        </section>

    <!-- FOOTER -->
    <footer class="footer-navy-main" id="contact">
        <div style="max-width: 1240px; margin: 0 auto;">
            <div class="footer-grid-layout">
                
                <!-- COL 1: BRAND -->
                <div>
                    <div class="brand-logo-group" style="margin-bottom: 1rem;">
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Carranglan Logo" class="brand-logo-img">
                        <div class="brand-text-container">
                            <span class="footer-brand-title">MUNICIPALITY OF CARRANGLAN</span>
                            <span class="brand-subtitle-text" style="color: #64748b;">NUEVA ECIJA</span>
                        </div>
                    </div>
                    <p style="font-size: 0.85rem; color: #cbd5e1;">Document Tracking System</p>
                    <p class="footer-subtext">© 2026 All rights reserved.</p>
                </div>

                <!-- COL 2: QUICK LINKS -->
                <div>
                    <h4 class="footer-col-header">QUICK LINKS</h4>
                    <ul class="footer-links-list">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="#services">Services</a></li>
                        <li><a href="#guidelines">Guidelines</a></li>
                        <li><a href="#faq">FAQ</a></li>
                        <li><a href="#contact">Contact Us</a></li>
                    </ul>
                </div>

                <!-- COL 3: CONTACT US -->
                <div>
                    <h4 class="footer-col-header">CONTACT US</h4>
                    <div class="footer-info-item">
                        <span class="footer-info-icon">📍</span>
                        <span><?= htmlspecialchars($contactAddress) ?></span>
                    </div>
                    <div class="footer-info-item">
                        <span class="footer-info-icon">📞</span>
                        <span><?= htmlspecialchars($contactNumber) ?></span>
                    </div>
                    <div class="footer-info-item">
                        <span class="footer-info-icon">✉️</span>
                        <span><?= htmlspecialchars($contactEmail) ?></span>
                    </div>
                    <div class="footer-info-item">
                        <span class="footer-info-icon">🌐</span>
                        <span><?= htmlspecialchars($contactWebsite) ?></span>
                    </div>
                </div>

                <!-- COL 4: OFFICE HOURS -->
                <div>
                    <h4 class="footer-col-header">OFFICE HOURS</h4>
                    <div class="footer-info-item">
                        <span class="footer-info-icon">🕒</span>
                        <div>
                            <strong style="color: #ffffff; display: block; font-size: 0.85rem;"><?= htmlspecialchars($officeHoursDays) ?></strong>
                            <span style="font-size: 0.82rem; color: #94a3b8;"><?= htmlspecialchars($officeHoursTime) ?></span>
                            <span style="display: block; font-size: 0.78rem; color: #64748b; margin-top: 0.25rem;">(<?= htmlspecialchars($officeHoursNote) ?>)</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Decorative background SVG watermark -->
        <svg class="footer-watermark-bg" width="320" height="320" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="0.5">
            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
        </svg>
    </footer>

    <script>
    function toggleFaq(header) {
        const item = header.parentElement;
        item.classList.toggle('active');
    }
    </script>
</body>
</html>
