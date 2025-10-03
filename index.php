<?php
// Start the session
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
} elseif (isset($_SESSION['admin_id'])) {
    header("Location: admin_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Water Metering System</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #00aaff;
            --accent-color: #00cc99;
            --dark-color: #003366;
            --light-color: #f0f8ff;
            --text-color: #333;
            --white: #ffffff;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            line-height: 1.6;
            color: var(--text-color);
            background: linear-gradient(135deg, var(--light-color), var(--white));
            min-height: 100vh;
        }
        
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header Styles */
        header {
            background: linear-gradient(to right, var(--dark-color), var(--primary-color));
            color: var(--white);
            padding: 1rem 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            font-size: 1.8rem;
            font-weight: 700;
            font-family: 'Montserrat', sans-serif;
        }
        
        .logo i {
            margin-right: 10px;
            color: var(--accent-color);
        }
        
        nav ul {
            display: flex;
            list-style: none;
        }
        
        nav ul li {
            margin-left: 2rem;
        }
        
        nav ul li a {
            color: var(--white);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
            padding: 0.5rem 1rem;
            border-radius: 4px;
        }
        
        nav ul li a:hover {
            color: var(--accent-color);
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .mobile-menu {
            display: none;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        /* Hero Section */
        .hero {
            background: linear-gradient(rgba(0, 51, 102, 0.8), rgba(0, 102, 204, 0.8)), url('images/water-bg.jpg');
            background-size: cover;
            background-position: center;
            color: var(--white);
            padding: 5rem 0;
            text-align: center;
        }
        
        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            font-family: 'Montserrat', sans-serif;
        }
        
        .hero p {
            font-size: 1.2rem;
            max-width: 800px;
            margin: 0 auto 2rem;
        }
        
        .cta-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .btn {
            display: inline-block;
            padding: 0.8rem 1.8rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .btn-primary {
            background-color: var(--accent-color);
            color: var(--white);
            border: 2px solid var(--accent-color);
        }
        
        .btn-primary:hover {
            background-color: transparent;
            color: var(--accent-color);
        }
        
        .btn-secondary {
            background-color: transparent;
            color: var(--white);
            border: 2px solid var(--white);
        }
        
        .btn-secondary:hover {
            background-color: var(--white);
            color: var(--primary-color);
        }
        
        /* Features Section */
        .section {
            padding: 4rem 0;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 3rem;
            font-family: 'Montserrat', sans-serif;
            color: var(--dark-color);
        }
        
        .section-title h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .section-title p {
            font-size: 1.1rem;
            color: var(--text-color);
            max-width: 700px;
            margin: 0 auto;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .feature-card {
            background-color: var(--white);
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            text-align: center;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        
        .feature-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }
        
        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--dark-color);
        }
        
        /* Challenges Section */
        .challenges {
            background-color: var(--light-color);
        }
        
        .challenge-item {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            background-color: var(--white);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .challenge-text {
            padding: 2rem;
            flex: 1;
        }
        
        .challenge-text h3 {
            color: var(--dark-color);
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .solution {
            background-color: var(--accent-color);
            color: var(--white);
            padding: 0.5rem 1rem;
            border-radius: 4px;
            display: inline-block;
            margin-top: 1rem;
            font-weight: 600;
        }
        
        .challenge-image {
            flex: 1;
            min-height: 250px;
            background-size: cover;
            background-position: center;
        }
        
        /* IOT Section */
        .iot-section {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: var(--white);
        }
        
        .iot-content {
            display: flex;
            align-items: center;
            gap: 3rem;
        }
        
        .iot-text {
            flex: 1;
        }
        
        .iot-text h2 {
            font-size: 2.5rem;
            margin-bottom: 1.5rem;
            font-family: 'Montserrat', sans-serif;
        }
        
        .iot-image {
            flex: 1;
            min-height: 350px;
            background: url('images/iot-water.jpg') center/cover;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        /* Footer */
        footer {
            background-color: var(--dark-color);
            color: var(--white);
            padding: 3rem 0 1rem;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .footer-column h3 {
            font-size: 1.3rem;
            margin-bottom: 1.5rem;
            font-family: 'Montserrat', sans-serif;
            color: var(--accent-color);
        }
        
        .footer-column ul {
            list-style: none;
        }
        
        .footer-column ul li {
            margin-bottom: 0.8rem;
        }
        
        .footer-column ul li a {
            color: var(--white);
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .footer-column ul li a:hover {
            color: var(--accent-color);
        }
        
        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .social-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            color: var(--white);
            transition: all 0.3s;
        }
        
        .social-links a:hover {
            background-color: var(--accent-color);
            transform: translateY(-3px);
        }
        
        .copyright {
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.9rem;
        }
        
        /* Responsive Styles */
        @media (max-width: 992px) {
            .hero h1 {
                font-size: 2.5rem;
            }
            
            .iot-content {
                flex-direction: column;
            }
            
            .iot-image {
                width: 100%;
                min-height: 250px;
            }
        }
        
        @media (max-width: 768px) {
            nav ul {
                display: none;
            }
            
            .mobile-menu {
                display: block;
            }
            
            .hero h1 {
                font-size: 2rem;
            }
            
            .hero p {
                font-size: 1rem;
            }
            
            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 250px;
            }
            
            .challenge-item {
                flex-direction: column;
            }
            
            .challenge-image {
                width: 100%;
                min-height: 200px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-container">
            <div class="logo">
                <i class="fas fa-tint"></i>
                <span>AquaMeter</span>
            </div>
            <nav>
                <ul>
                    <li><a href="#home">Home</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#challenges">Challenges</a></li>
                    <li><a href="#iot">IoT Solution</a></li>
                    <li><a href="login.php">User Login</a></li>
                    <li><a href="admin_login.php">Admin Login</a></li>
                </ul>
            </nav>
            <div class="mobile-menu">
                <i class="fas fa-bars"></i>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="container">
            <h1>Revolutionizing Water Management with Smart Technology</h1>
            <p>Our IoT-based smart water metering system provides real-time monitoring, leak detection, and consumption analytics to help conserve water and reduce costs.</p>
            <div class="cta-buttons">
                <a href="login.php" class="btn btn-primary">User Login</a>
                <a href="admin_login.php" class="btn btn-secondary">Admin Login</a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="section" id="features">
        <div class="container">
            <div class="section-title">
                <h2>Key Features</h2>
                <p>Discover how our smart water metering system can transform your water management approach</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3>Real-time Monitoring</h3>
                    <p>Track water consumption in real-time with our advanced metering infrastructure and IoT sensors.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h3>Leak Detection</h3>
                    <p>Receive instant alerts for unusual water flow patterns indicating potential leaks or wastage.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3>Consumption Analytics</h3>
                    <p>Detailed reports and visualizations to understand usage patterns and identify saving opportunities.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <h3>Mobile Access</h3>
                    <p>Monitor and control your water usage from anywhere through our responsive web and mobile interfaces.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <h3>Cost Savings</h3>
                    <p>Reduce water bills by identifying and eliminating wasteful consumption patterns.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-cloud"></i>
                    </div>
                    <h3>Cloud Storage</h3>
                    <p>Secure cloud-based data storage with historical records for trend analysis and reporting.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Challenges Section -->
    <section class="section challenges" id="challenges">
        <div class="container">
            <div class="section-title">
                <h2>Water Management Challenges & Solutions</h2>
                <p>Addressing modern water management problems with innovative technology</p>
            </div>
            
            <div class="challenge-item">
                <div class="challenge-text">
                    <h3>Manual Meter Reading Inefficiencies</h3>
                    <p>Traditional water meters require physical reading, leading to human errors, delayed billing, and inability to detect real-time consumption patterns.</p>
                    <span class="solution">Our Solution</span>
                    <p>Automated remote meter reading eliminates manual processes, provides accurate real-time data, and enables prompt billing and analysis.</p>
                </div>
                <div class="challenge-image" style="background-image: url('images/manual-meter.jpg');"></div>
            </div>
            
            <div class="challenge-item">
                <div class="challenge-image" style="background-image: url('images/water-leak.jpg');"></div>
                <div class="challenge-text">
                    <h3>Undetected Water Leaks</h3>
                    <p>Hidden leaks can waste thousands of gallons before being discovered, leading to high costs and potential property damage.</p>
                    <span class="solution">Our Solution</span>
                    <p>Advanced flow monitoring algorithms detect abnormal usage patterns instantly, sending alerts to prevent water loss and damage.</p>
                </div>
            </div>
            
            <div class="challenge-item">
                <div class="challenge-text">
                    <h3>Lack of Consumption Visibility</h3>
                    <p>Consumers and utilities often lack detailed insights into when and how water is being used, making conservation efforts difficult.</p>
                    <span class="solution">Our Solution</span>
                    <p>Comprehensive dashboards and reports provide hour-by-hour usage data, helping users understand and optimize their consumption.</p>
                </div>
                <div class="challenge-image" style="background-image: url('images/consumption-data.jpg');"></div>
            </div>
            
            <div class="challenge-item">
                <div class="challenge-image" style="background-image: url('images/water-scarcity.jpg');"></div>
                <div class="challenge-text">
                    <h3>Water Scarcity Concerns</h3>
                    <p>With growing populations and climate change, efficient water management is critical for sustainable communities.</p>
                    <span class="solution">Our Solution</span>
                    <p>By promoting awareness and providing tools for efficient usage, our system helps communities reduce consumption without sacrificing needs.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- IoT Section -->
    <section class="section iot-section" id="iot">
        <div class="container">
            <div class="iot-content">
                <div class="iot-text">
                    <h2>IoT-Powered Water Monitoring</h2>
                    <p>Our system leverages cutting-edge Internet of Things technology to revolutionize water management:</p>
                    <ul style="margin-top: 1rem; margin-left: 1.5rem;">
                        <li>Wireless sensors collect and transmit water usage data in real-time</li>
                        <li>Cloud-based processing analyzes consumption patterns and detects anomalies</li>
                        <li>Secure communication protocols ensure data privacy and integrity</li>
                        <li>Scalable architecture supports thousands of endpoints for utilities and communities</li>
                        <li>Integration with smart home systems for automated water management</li>
                    </ul>
                    <p style="margin-top: 1.5rem;">By combining IoT with advanced analytics, we're creating smarter water networks that benefit consumers, utilities, and the environment.</p>
                </div>
                <div class="iot-image"></div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <h3>AquaMeter</h3>
                    <p>Innovative smart water metering solutions for homes, businesses, and municipalities.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
                <div class="footer-column">
                    <h3>Quick Links</h3>
                    <ul>
                        <li><a href="#home">Home</a></li>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#challenges">Challenges</a></li>
                        <li><a href="#iot">IoT Solution</a></li>
                        <li><a href="login.php">User Login</a></li>
                        <li><a href="admin_login.php">Admin Login</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>Contact Us</h3>
                    <ul>
                        <li><i class="fas fa-map-marker-alt"></i> 123 Water Way, Tech City</li>
                        <li><i class="fas fa-phone"></i> +1 (555) 123-4567</li>
                        <li><i class="fas fa-envelope"></i> info@aquameter.com</li>
                    </ul>
                </div>
            </div>
            <div class="copyright">
                <p>&copy; <?php echo date("Y"); ?> AquaMeter Smart Water Metering System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        // Mobile menu toggle
        document.querySelector('.mobile-menu').addEventListener('click', function() {
            const nav = document.querySelector('nav ul');
            nav.style.display = nav.style.display === 'flex' ? 'none' : 'flex';
        });
        
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    window.scrollTo({
                        top: target.offsetTop - 80,
                        behavior: 'smooth'
                    });
                    
                    // Close mobile menu if open
                    if (window.innerWidth <= 768) {
                        document.querySelector('nav ul').style.display = 'none';
                    }
                }
            });
        });
        
        // Responsive adjustments
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.querySelector('nav ul').style.display = 'flex';
            } else {
                document.querySelector('nav ul').style.display = 'none';
            }
        });
    </script>
</body>
</html>