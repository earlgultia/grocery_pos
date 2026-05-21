<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';

// Create logs table if not exists
createLogsTable();

// Get statistics for landing page
$stats = [];
try {
    $db = getDB();
    
    // Get total stores
    $stmt = $db->query("SELECT COUNT(*) as count FROM stores WHERE is_active = true");
    $stats['stores'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get total products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products");
    $stats['products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get total transactions
    $stmt = $db->query("SELECT COUNT(*) as count FROM transactions");
    $stats['transactions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
} catch(PDOException $e) {
    $stats = ['stores' => 0, 'products' => 0, 'transactions' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> — Modern Grocery POS & Store Management</title>
    <meta name="description" content="Complete POS system for grocery stores. Manage inventory, track sales, and handle transactions efficiently.">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
</head>
<body class="landing-page">
    <header class="landing-topbar">
        <div class="container">
            <div class="landing-brand-row">
                <div class="landing-brand-mark">🍞</div>
                <div class="landing-brand-text">
                    <h1>GroceryPOS</h1>
                    <p>Modern grocery management</p>
                </div>
            </div>

            <div class="landing-nav-wrap">
                <button type="button" class="landing-nav-toggle" aria-expanded="false" aria-controls="landingNavMenu" aria-label="Toggle navigation">
                    <span class="landing-nav-toggle-lines" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                    <span class="landing-nav-toggle-text">Menu</span>
                </button>

                <nav id="landingNavMenu" class="landing-nav" aria-label="Primary">
                    <a href="#features"><span class="label">Features</span><span class="tag">Explore</span></a>
                    <a href="#how-it-works"><span class="label">How It Works</span><span class="tag">3 steps</span></a>
                    <a href="#pricing"><span class="label">Pricing</span><span class="tag">Plans</span></a>
                    <a href="#testimonials"><span class="label">Testimonials</span><span class="tag">Reviews</span></a>
                    <a href="login.php" class="primary-link"><span class="label">Login</span><span class="tag">Quick access</span></a>
                </nav>
            </div>
        </div>
    </header>

    <main class="landing-content">
    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content landing-hero-copy">
                <div class="landing-hero-badge">Faster checkout · Smarter inventory</div>
                <h1 class="animate-fade-in">
                    Point-of-Sale built for
                    <span class="highlight">local grocery stores</span>
                </h1>
                <p class="animate-fade-in-delay">
                    Powerful, easy-to-use POS that helps you manage stock, speed up checkout,
                    and get instant sales insights — all from one dashboard.
                </p>
                <div class="hero-buttons animate-fade-in-delay-2">
                    <a href="login.php" class="btn btn-large btn-primary">Sign in to your account</a>
                    <a href="#features" class="btn btn-large btn-outline">See features</a>
                </div>
                <div class="hero-stats animate-fade-in-delay-3">
                    <div class="stat">
                        <span class="stat-number" data-count="<?php echo $stats['stores']; ?>">0</span>
                        <span class="stat-label">Stores using GroceryPOS</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number" data-count="<?php echo $stats['products']; ?>">0</span>
                        <span class="stat-label">Products tracked</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number" data-count="<?php echo $stats['transactions']; ?>">0</span>
                        <span class="stat-label">Transactions recorded</span>
                    </div>
                </div>
                <div class="landing-hero-panel animate-fade-in-delay-3">
                    <div class="landing-mini-card">
                        <span class="mini-label">Quick setup</span>
                        <span class="mini-value">Ready in minutes</span>
                    </div>
                    <div class="landing-mini-card">
                        <span class="mini-label">Optimized checkout</span>
                        <span class="mini-value">Faster transactions</span>
                    </div>
                    <div class="landing-mini-card">
                        <span class="mini-label">Insightful reports</span>
                        <span class="mini-value">Sales & stock analytics</span>
                    </div>
                </div>
            </div>
            <div class="hero-image animate-slide-in">
                <img alt="POS Dashboard Preview" src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 520'%3E%3Cdefs%3E%3ClinearGradient id='bg' x1='0' x2='1' y1='0' y2='1'%3E%3Cstop offset='0%25' stop-color='%230f172a'/%3E%3Cstop offset='100%25' stop-color='%231e293b'/%3E%3C/linearGradient%3E%3ClinearGradient id='accent' x1='0' x2='1' y1='0' y2='1'%3E%3Cstop offset='0%25' stop-color='%234f46e5'/%3E%3Cstop offset='100%25' stop-color='%2322c55e'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='800' height='520' rx='32' fill='url(%23bg)'/%3E%3Crect x='28' y='28' width='744' height='464' rx='26' fill='%23ffffff' fill-opacity='.06' stroke='%23ffffff' stroke-opacity='.12'/%3E%3Crect x='54' y='54' width='162' height='412' rx='22' fill='%230b1120' fill-opacity='.9'/%3E%3Crect x='240' y='54' width='478' height='72' rx='20' fill='%23ffffff' fill-opacity='.10'/%3E%3Crect x='240' y='146' width='226' height='150' rx='20' fill='url(%23accent)' fill-opacity='.92'/%3E%3Crect x='490' y='146' width='228' height='150' rx='20' fill='%23ffffff' fill-opacity='.10'/%3E%3Crect x='240' y='320' width='478' height='146' rx='20' fill='%23ffffff' fill-opacity='.08'/%3E%3Crect x='78' y='84' width='118' height='18' rx='9' fill='%23e2e8f0' fill-opacity='.9'/%3E%3Crect x='78' y='126' width='96' height='12' rx='6' fill='%2394a3b8'/%3E%3Crect x='78' y='154' width='96' height='12' rx='6' fill='%2394a3b8'/%3E%3Crect x='78' y='182' width='96' height='12' rx='6' fill='%2394a3b8'/%3E%3Crect x='78' y='210' width='96' height='12' rx='6' fill='%2394a3b8'/%3E%3Crect x='78' y='258' width='96' height='12' rx='6' fill='%2394a3b8'/%3E%3Crect x='78' y='286' width='76' height='12' rx='6' fill='%2394a3b8'/%3E%3Crect x='78' y='326' width='118' height='16' rx='8' fill='%2322c55e'/%3E%3Crect x='78' y='356' width='88' height='12' rx='6' fill='%23cbd5e1'/%3E%3Crect x='78' y='384' width='104' height='12' rx='6' fill='%23cbd5e1'/%3E%3Crect x='264' y='166' width='72' height='18' rx='9' fill='%23ffffff' fill-opacity='.9'/%3E%3Crect x='264' y='198' width='148' height='12' rx='6' fill='%23e2e8f0' fill-opacity='.75'/%3E%3Crect x='264' y='234' width='182' height='40' rx='18' fill='%23ffffff' fill-opacity='.14'/%3E%3Crect x='514' y='166' width='70' height='18' rx='9' fill='%23cbd5e1'/%3E%3Cpath d='M512 254 L545 214 L575 238 L608 192 L652 220 L698 176' fill='none' stroke='%2322c55e' stroke-width='8' stroke-linecap='round' stroke-linejoin='round'/%3E%3Cpath d='M512 254 L545 214 L575 238 L608 192 L652 220 L698 176' fill='none' stroke='%23ffffff' stroke-opacity='.35' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3Crect x='264' y='346' width='118' height='18' rx='9' fill='%23e2e8f0' fill-opacity='.9'/%3E%3Crect x='264' y='382' width='180' height='14' rx='7' fill='%2394a3b8' fill-opacity='.85'/%3E%3Crect x='264' y='412' width='220' height='14' rx='7' fill='%2394a3b8' fill-opacity='.6'/%3E%3Crect x='510' y='346' width='128' height='18' rx='9' fill='%23e2e8f0' fill-opacity='.9'/%3E%3Crect x='510' y='382' width='160' height='14' rx='7' fill='%2394a3b8' fill-opacity='.85'/%3E%3Crect x='510' y='412' width='194' height='14' rx='7' fill='%2394a3b8' fill-opacity='.6'/%3E%3C/svg%3E">
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features">
        <div class="container">
            <div class="section-header">
                <h2>Powerful Features for Your Grocery Store</h2>
                <p>Everything you need to manage your grocery business efficiently</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">📦</div>
                    <h3>Inventory Management</h3>
                    <p>Track stock levels, set low stock alerts, and manage product expiration dates automatically.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">💳</div>
                    <h3>Fast Checkout</h3>
                    <p>Quick and easy checkout process with support for multiple payment methods.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">📊</div>
                    <h3>Real-time Reports</h3>
                    <p>Get instant insights into sales, profits, and inventory with detailed analytics.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🏪</div>
                    <h3>Multi-store Support</h3>
                    <p>Manage multiple store locations from a single dashboard.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">📱</div>
                    <h3>Mobile Friendly</h3>
                    <p>Access your POS system from any device - desktop, tablet, or mobile.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🔒</div>
                    <h3>Secure & Reliable</h3>
                    <p>Bank-level security with encrypted data and secure authentication.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section id="how-it-works" class="how-it-works">
        <div class="container">
            <div class="section-header">
                <h2>How It Works</h2>
                <p>Get started in 3 simple steps</p>
            </div>
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <h3>Sign In</h3>
                    <p>Sign in and explore the system immediately</p>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <h3>Add Products</h3>
                    <p>Review products, stock levels, and store data from the dashboard</p>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <h3>Start Selling</h3>
                    <p>Open the POS screen, process transactions, and print receipts</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="pricing">
        <div class="container">
            <div class="section-header">
                <h2>Simple, Transparent Pricing</h2>
                <p>Choose the plan that fits your business</p>
            </div>
            <div class="pricing-grid">
                <div class="pricing-card">
                    <h3>Basic</h3>
                    <div class="price">$29<span>/month</span></div>
                    <ul>
                        <li>✓ Up to 500 products</li>
                        <li>✓ Single store</li>
                        <li>✓ Basic reports</li>
                        <li>✓ Email support</li>
                    </ul>
                    <a href="login.php" class="btn btn-outline">Get Started</a>
                </div>
                <div class="pricing-card popular">
                    <div class="popular-badge">Most Popular</div>
                    <h3>Professional</h3>
                    <div class="price">$79<span>/month</span></div>
                    <ul>
                        <li>✓ Unlimited products</li>
                        <li>✓ Up to 5 stores</li>
                        <li>✓ Advanced analytics</li>
                        <li>✓ Priority support</li>
                        <li>✓ API access</li>
                    </ul>
                    <a href="login.php" class="btn btn-primary">Get Started</a>
                </div>
                <div class="pricing-card">
                    <h3>Enterprise</h3>
                    <div class="price">Custom</div>
                    <ul>
                        <li>✓ Unlimited everything</li>
                        <li>✓ Custom features</li>
                        <li>✓ Dedicated support</li>
                        <li>✓ On-premise option</li>
                    </ul>
                    <a href="#contact" class="btn btn-outline">Contact Us</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section id="testimonials" class="testimonials">
        <div class="container">
            <div class="section-header">
                <h2>What Our Customers Say</h2>
                <p>Trusted by grocery store owners worldwide</p>
            </div>
            <div class="testimonials-slider">
                <div class="testimonial">
                    <div class="testimonial-content">
                        "This POS system transformed our grocery store. Inventory management is now effortless, and checkout is 3x faster!"
                    </div>
                    <div class="testimonial-author">
                        <strong>Sarah Johnson</strong>
                        <span>FreshMart Grocery</span>
                    </div>
                </div>
                <div class="testimonial">
                    <div class="testimonial-content">
                        "The expiration tracking feature saved us thousands of dollars in waste. Highly recommended!"
                    </div>
                    <div class="testimonial-author">
                        <strong>Michael Chen</strong>
                        <span>City Supermarket</span>
                    </div>
                </div>
                <div class="testimonial">
                    <div class="testimonial-content">
                        "Great support team and regular updates. Best investment we made for our business."
                    </div>
                    <div class="testimonial-author">
                        <strong>Emily Rodriguez</strong>
                        <span>Corner Grocery</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta">
        <div class="container">
            <h2>Ready to Streamline Your Grocery Business?</h2>
            <p>Join thousands of satisfied store owners using GroceryPOS</p>
            <a href="login.php" class="btn btn-large btn-primary">Get Started</a>
            <p class="cta-note">No credit card required. Cancel anytime.</p>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4><?php echo SITE_NAME; ?></h4>
                    <p>Modern POS solution for grocery stores</p>
                </div>
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <a href="#features">Features</a>
                    <a href="#pricing">Pricing</a>
                    <a href="#testimonials">Testimonials</a>
                    <a href="login.php">Login</a>
                </div>
                <div class="footer-section">
                    <h4>Support</h4>
                    <a href="#">Help Center</a>
                    <a href="#">Contact Us</a>
                    <a href="#">Privacy Policy</a>
                    <a href="#">Terms of Service</a>
                </div>
                <div class="footer-section">
                    <h4>Connect With Us</h4>
                    <div class="social-links">
                        <a href="#">📘 Facebook</a>
                        <a href="#">🐦 Twitter</a>
                        <a href="#">📸 Instagram</a>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2026 Grocery POS System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    </main>

    <script src="js/main.js?v=<?php echo filemtime(__DIR__ . '/js/main.js'); ?>"></script>
</body>
</html>