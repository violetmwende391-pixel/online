<?php
require_once 'config.php';

if (!is_logged_in()) redirect('login.php');

$meter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Verify access
$stmt = $pdo->prepare("SELECT * FROM meters WHERE meter_id = ? AND (user_id = ? OR ? = 1)");
$stmt->execute([$meter_id, $_SESSION['user_id'], is_admin_logged_in()]);
$meter = $stmt->fetch();
if (!$meter) {
    $_SESSION['error'] = "Meter not found or not authorized.";
    redirect('meters.php');
}

// Date filters
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-7 days'));
$to_date   = $_GET['to_date'] ?? date('Y-m-d');

// Reusable fetcher
function fetchData($pdo, $sql, $params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildDataset($label, $data) {
    return [
        'label' => $label,
        'labels' => array_column($data, 'label'),
        'data' => array_column($data, 'total_volume')
    ];
}

// Queries
$params = [$meter_id, $from_date, $to_date];
$hourly_sql = "SELECT HOUR(recorded_at) AS label, SUM(volume) AS total_volume FROM flow_data WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? GROUP BY HOUR(recorded_at) ORDER BY label";
$daily_sql = "SELECT DATE(recorded_at) AS label, SUM(volume) AS total_volume FROM flow_data WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? GROUP BY DATE(recorded_at)";
$weekly_sql = "SELECT YEARWEEK(recorded_at, 1) AS label, SUM(volume) AS total_volume FROM flow_data WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? GROUP BY YEARWEEK(recorded_at, 1)";
$monthly_sql = "SELECT DATE_FORMAT(recorded_at, '%Y-%m') AS label, SUM(volume) AS total_volume FROM flow_data WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? GROUP BY DATE_FORMAT(recorded_at, '%Y-%m')";

$hourlyData = fetchData($pdo, $hourly_sql, $params);
$dailyData  = fetchData($pdo, $daily_sql, $params);
$weeklyData = fetchData($pdo, $weekly_sql, $params);
$monthlyData = fetchData($pdo, $monthly_sql, $params);

// Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=water_usage.csv");
    $out = fopen("php://output", "w");
    fputcsv($out, ["Category", "Time", "Volume"]);

    foreach ([
        'Hourly' => $hourlyData,
        'Daily' => $dailyData,
        'Weekly' => $weeklyData,
        'Monthly' => $monthlyData
    ] as $label => $dataset) {
        foreach ($dataset as $row) {
            fputcsv($out, [$label, $row['label'], $row['total_volume']]);
        }
    }
    fclose($out);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Usage Stats | <?= APP_NAME ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 20px;
            background-color: #f0f4f8;
            color: #333;
        }
        h2 {
            color: #007bff;
            margin-bottom: 20px;
        }
        form {
            margin-bottom: 30px;
            background: #fff;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        label {
            font-weight: 500;
        }
        button, a {
            background-color: #007bff;
            color: #fff !important;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
        }
        a:hover, button:hover {
            background-color: #0056b3;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin-bottom: 40px;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 12px;
            border: 1px solid #ddd;
        }
        th {
            background-color: #007bff;
            color: white;
        }
        .chart-section {
            background: #fff;
            padding: 20px;
            margin-bottom: 40px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.08);
        }
        .chart-container {
            margin-top: 20px;
        }
        canvas {
            max-width: 100%;
            height: auto;
        }
        #monthlyChart {
            max-width: 400px;
            margin: 0 auto;
            padding: 10px;
        }
    </style>
</head>
<body>

<h2>Water Usage Stats - <?= htmlspecialchars($meter['meter_name']) ?></h2>

<form method="get">
    <input type="hidden" name="id" value="<?= $meter_id ?>">
    <label>From: <input type="date" name="from_date" value="<?= $from_date ?>"></label>
    <label>To: <input type="date" name="to_date" value="<?= $to_date ?>"></label>
    <button type="submit">Filter</button>
    <a href="usage_stats.php?id=<?= $meter_id ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&export=excel">Export to Excel</a>
</form>

<?php
function printTable($title, $data) {
    echo "<h4>$title</h4>";
    if (!$data) {
        echo "<p>No data found.</p>";
        return;
    }
    echo "<table><tr><th>Time</th><th>Volume (L)</th></tr>";
    foreach ($data as $row) {
        echo "<tr><td>{$row['label']}</td><td>" . number_format($row['total_volume'], 2) . "</td></tr>";
    }
    echo "</table>";
}
printTable("Hourly Usage", $hourlyData);
printTable("Daily Usage", $dailyData);
printTable("Weekly Usage", $weeklyData);
printTable("Monthly Usage", $monthlyData);
?>

<div class="chart-section">
    <h3>Live Water Usage Charts (Refreshes every 1s)</h3>
    <div class="chart-container">
        <canvas id="hourlyChart"></canvas>
    </div>
    <div class="chart-container">
        <canvas id="dailyChart"></canvas>
    </div>
    <div class="chart-container">
        <canvas id="weeklyChart"></canvas>
    </div>
    <div class="chart-container">
        <canvas id="monthlyChart"></canvas>
    </div>
</div>

<script>
const meterId = <?= $meter_id ?>;
const fromDate = "<?= $from_date ?>";
const toDate = "<?= $to_date ?>";

let hourlyChart, dailyChart, weeklyChart, monthlyChart;

function renderChart(ctxId, chartType, title, labels, data, color = 'rgba(75, 192, 192, 0.6)') {
    const ctx = document.getElementById(ctxId).getContext('2d');
    return new Chart(ctx, {
        type: chartType,
        data: {
            labels: labels,
            datasets: [{
                label: title,
                data: data,
                backgroundColor: chartType === 'pie'
                    ? ['#007bff','#28a745','#ffc107','#dc3545'] // hourly, daily, weekly, monthly
                    : color,
                borderColor: chartType === 'pie' ? '#fff' : 'rgba(0, 123, 255, 1)',
                borderWidth: 2,
                fill: chartType !== 'pie'
            }]
        },
        options: {
            responsive: true,
            animation: {
                duration: 1000,
                easing: 'easeOutQuart'
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + parseFloat(context.raw).toFixed(2) + ' L';
                        }
                    }
                },
                legend: {
                    position: chartType === 'pie' ? 'bottom' : 'top',
                    labels: {
                        color: '#333'
                    }
                }
            },
            scales: chartType !== 'pie' ? {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Volume (Liters)' }
                },
                x: {
                    ticks: { color: '#555' }
                }
            } : {}
        }
    });
}

async function fetchDataAndDraw() {
    const res = await fetch(`usage_data_api.php?id=${meterId}&from_date=${fromDate}&to_date=${toDate}`);
    const data = await res.json();

    if (hourlyChart) hourlyChart.destroy();
    if (dailyChart) dailyChart.destroy();
    if (weeklyChart) weeklyChart.destroy();
    if (monthlyChart) monthlyChart.destroy();

    // Totals for pie chart
    const hourlyTotal = data.hourly.data.reduce((a, b) => a + b, 0);
    const dailyTotal = data.daily.data.reduce((a, b) => a + b, 0);
    const weeklyTotal = data.weekly.data.reduce((a, b) => a + b, 0);
    const monthlyTotal = data.monthly.data.reduce((a, b) => a + b, 0);

    hourlyChart = renderChart('hourlyChart', 'line', 'Hourly Usage (L)', data.hourly.labels, data.hourly.data);
    dailyChart  = renderChart('dailyChart', 'bar', 'Daily Usage (L)', data.daily.labels, data.daily.data);
    weeklyChart = renderChart('weeklyChart', 'line', 'Weekly Usage (L)', data.weekly.labels, data.weekly.data);

    // Render improved pie chart showing all levels
    monthlyChart = renderChart(
        'monthlyChart',
        'pie',
        'Usage Distribution (Hourly, Daily, Weekly, Monthly)',
        ['Hourly', 'Daily', 'Weekly', 'Monthly'],
        [hourlyTotal, dailyTotal, weeklyTotal, monthlyTotal]
    );
}

fetchDataAndDraw();
setInterval(fetchDataAndDraw, 10000); // Auto-refresh every 1 second
</script>


</body>
</html>














































































 
<?php
session_start();
 
include 'db.php'; // Ensure db.php uses PDO
 include 'check_block.php';

session_start();
if (isset($_SESSION['LoginTime']) && (time() - strtotime($_SESSION['LoginTime']) > 300)) {
    include 'logout.php'; // Logs out and marks session inactive
    exit();
} else {
    $_SESSION['LoginTime'] = date("Y-m-d H:i:s"); // Refresh time on activity
}



if (!isset($_SESSION['StudentID'])) {
    header("Location: login.php");
    exit();
}

$studentID = $_SESSION['StudentID'];

try {
    // Check if student has selected exams
    $checkStmt = $pdo->prepare("SELECT 1 FROM StudentSelectedExams WHERE StudentID = ? LIMIT 1");
    $checkStmt->execute([$studentID]);
    if (!$checkStmt->fetchColumn()) {
        header("Location: select_subjects.php");
        exit();
    }

    // Fetch student details
    $studentStmt = $pdo->prepare("SELECT FirstName, PhoneNumber FROM Students WHERE StudentID = ?");
    $studentStmt->execute([$studentID]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

    $firstName = $student['FirstName'];
    $phoneNumber = $student['PhoneNumber'];

    // Fetch selected exams
    $examsStmt = $pdo->prepare("
        SELECT e.ExamID, e.PaperCode, e.PaperName, e.Price, e.ExamDate, e.ExamTime, 
               p.Status, p.TransactionID, e.PaperPath 
        FROM Exams e 
        INNER JOIN StudentSelectedExams se ON e.ExamID = se.ExamID 
        LEFT JOIN Payments p ON e.ExamID = p.ExamID AND se.StudentID = p.StudentID 
        WHERE se.StudentID = ?
    ");
    $examsStmt->execute([$studentID]);
    $examsData = $examsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Sort exams by date & time
    usort($examsData, function ($a, $b) {
        return strtotime($a['ExamDate'] . ' ' . $a['ExamTime']) - strtotime($b['ExamDate'] . ' ' . $b['ExamTime']);
    });

} catch (PDOException $e) {
    echo "Query Error: " . $e->getMessage();
    exit;
}
// latest add remove

function logStudentAction($pdo, $studentID, $action) {
    $stmt = $pdo->prepare("INSERT INTO StudentLogs (StudentID, Action, Timestamp) VALUES (?, ?, NOW())");
    $stmt->execute([$studentID, $action]);
}

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

    <title>Student Dashboard -  Education Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.0/main.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.0/main.min.js"></script>
</head>

<body>
 

    <!-- Header Section -->
  <header class="header">
    <div class="header-container">
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>
        <h1>Student Dashboard</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($firstName); ?></span>
            <i class="fas fa-user-circle"></i>
        </div>
    </div>
</header>
<!-- Sidebar Structure -->
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fas fa-graduation-cap"></i>
            <span>Education Portal</span>
        </div>
        <button class="sidebar-close" id="sidebarClose">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <nav class="sidebar-nav">
        <a href="edit_profile.php" class="sidebar-link">
            <i class="fas fa-user-edit"></i> Edit Profile
        </a>
        <a href="select_subjects.php" class="sidebar-link">
            <i class="fas fa-book"></i> Subjects
        </a>
        <a href="payment_history.php" class="sidebar-link">
            <i class="fas fa-history"></i> Payment History
        </a>
        <a href="normal_overal_payment.php" class="sidebar-link">
            <i class="fas fa-lock-open"></i> Unlock All Papers
        </a>
        <a href="chat.php" class="sidebar-link">
            <i class="fas fa-comments"></i> Chat with Admin
        </a>
        <a href="discount_request.php" class="sidebar-link">
            <i class="fas fa-percentage"></i> Request Discount
        </a>
        <a href="logout.php" class="sidebar-link logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Main Content -->
    <main class="container">
        <div class="welcome-card">
            <h1 class="mb-2">Welcome, <?php echo htmlspecialchars($firstName); ?>!</h1>
            <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($phoneNumber); ?></p>
        </div>

        <!-- Action Buttons -->
       <div class="action-buttons">
    <a href="chat.php" class="action-btn">
        <i class="fas fa-comments"></i> Chat with Admin
    </a>
    <a href="modify_subjects.php" class="action-btn">
        <i class="fas fa-edit"></i> Modify Subjects
    </a>
    <a href="payment_history.php" class="action-btn">
        <i class="fas fa-file-invoice-dollar"></i> Payment History
    </a>
    <a href="discount_request.php" class="action-btn">
        <i class="fas fa-percentage"></i> Request Discount
    </a>
    <a href="normal_overal_payment.php" class="action-btn">
        <i class="fas fa-lock-open"></i> Unlock all papers
    </a>
    <a href="logout.php" class="action-btn logout">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>

        <!-- Exam Section -->
         
         
         <div id="exams-section">
            <h3>Selected Exams:</h3>

            <div class="scroll-container">
            <table>
                <thead>
                    <tr>
                        <th>Paper Code</th>
                        <th>Paper Name</th>
                        <th>Price</th>
                        <th>Exam Date</th>
                        <th>Exam Time</th>
                        <th>Time Remaining</th>
                        <th>Payment Status</th>
                        <th>Transaction ID</th>
                        <th>Pay to unlock Exam Papers</th>
                        <th>Payment</th>
                         
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $nearestExam = true;
                    foreach ($examsData as $row) {
                        $examTime = strtotime($row["ExamDate"] . " " . $row["ExamTime"]);
                        echo "<tr>";
                        echo "<td class='paper-code'>" . htmlspecialchars($row["PaperCode"]) . "</td>";
                        echo "<td class='paper-name'>" . htmlspecialchars($row["PaperName"]) . "</td>";
                        echo "<td class='price'>Ksh " . htmlspecialchars($row["Price"]) . "</td>";
                        echo "<td>" . htmlspecialchars($row["ExamDate"]) . "</td>";
                        echo "<td>" . htmlspecialchars($row["ExamTime"]) . "</td>";
                        echo "<td><span id='countdown-" . htmlspecialchars($row["ExamID"]) . "'></span></td>";
                        $statusClass = strtolower(str_replace(" ", "-", $row["Status"] ?: "not-paid"));
                        echo "<td class='payment-status $statusClass'>" . htmlspecialchars($row["Status"] ?: "Not Paid") . "</td>";
 

                        

                        echo "<td>" . htmlspecialchars($row["TransactionID"] ?: "N/A") . "</td>";
                        echo "<td>";
                        if ($row["Status"] === "Approved" && $row["PaperPath"]) {
                            echo "<a href='" . htmlspecialchars($row["PaperPath"]) . "' target='_blank'>View Paper</a>";
                        } else {
                            echo $row["Status"] === "Approved" ? "Paper Not Uploaded" : "Payment Pending";
                        }
                        echo "</td>";
                        echo "<td><a href='payment.php?examID=" . htmlspecialchars($row["ExamID"]) . "'>Make Payment</a></td>";
                        echo "</tr>";

                        // Countdown script
                    echo "<script>
    function updateCountdown{$row['ExamID']}() {
        var examTimestamp = ($examTime - (7 * 60 * 60)) * 1000; // Add 3 hours in seconds, then convert to ms
        var now = new Date().getTime();
        var timeLeft = examTimestamp - now;
        var countdownEl = document.getElementById('countdown-{$row['ExamID']}');

        if (timeLeft > 0) {
            var days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
            var hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
            var seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);

            countdownEl.innerHTML = days + 'd ' + hours + 'h ' + minutes + 'm ' + seconds + 's ';
            countdownEl.style.color = " . ($nearestExam ? "'red'; countdownEl.classList.add('blink');" : "'green'") . ";
        } else {
            countdownEl.innerHTML = 'Exam started or finished';
        }
    }
    updateCountdown{$row['ExamID']}();
    setInterval(updateCountdown{$row['ExamID']}, 1000);
</script>";


                        $nearestExam = false; // Only the first (nearest) exam blinks red
                    }
                    ?>
                </tbody>
            </table>
            </div> <!-- End of scroll-container -->
        </div>

        <!-- Calendar Section -->
        <div id="calendar-section">
            <h3>Exam Calendar:</h3>
            <div id="calendar"></div>
        </div>
    </div>
       <!-- Footer Section -->
       <footer class="footer" role="contentinfo">
        <div class="container">
            <div class="footer-links">
                <a href="privacy.php" class="footer-link">Privacy Policy</a>
                <a href="terms.php" class="footer-link">Terms of Service</a>
                <a href="contact.php" class="footer-link">Contact Us</a>
                <a href="help.php" class="footer-link">Help Center</a>
            </div>
            <div class="text-center mt-3">
                <p>&copy; <?php echo date('Y'); ?> Education Portal. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                events: [
                    <?php foreach ($examsData as $row) { ?>
                    {
                        
                        title: '<?php echo htmlspecialchars($row['PaperName']); ?>',
                        start: '<?php echo $row['ExamDate'] . 'T' . $row['ExamTime']; ?>',
                        color: '<?php echo $row['Status'] === "Approved" ? "green" : "blue"; ?>'
                    },
                    <?php } ?>
                ]
            });
            calendar.render();
        });
    </script>

<script>
setInterval(() => {
    fetch('update_activity.php');
}, 30000); // every 30 seconds
</script>
<script>





document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.querySelector('.sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebarClose = document.getElementById('sidebarClose');
    const mainContent = document.querySelector('.main-content');

    // Toggle sidebar
    function toggleSidebar() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
    }

    // Mobile menu toggle
    mobileMenuToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        toggleSidebar();
    });

    // Close sidebar when clicking overlay
    sidebarOverlay.addEventListener('click', function() {
        toggleSidebar();
    });

    // Close sidebar when clicking close button
    sidebarClose.addEventListener('click', function() {
        toggleSidebar();
    });

    // Close sidebar when clicking outside (for larger screens)
    document.addEventListener('click', function(e) {
  // Works on all screen sizes
document.addEventListener('click', function(e) {
    if (!sidebar.contains(e.target) && 
        e.target !== mobileMenuToggle && 
        !e.target.closest('.sidebar-link')) {
        sidebar.classList.remove('active');
        sidebarOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }
});
    });

    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 992) {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});
</script>
</body>
</html>
 
<style>
 /* ===== Header and Sidebar Z-Index Fix ===== */
.header {
    background: linear-gradient(135deg, #3498db, #2980b9);
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.header h1 {
    text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
}

.sidebar {
    position: fixed;
    top: 0;
    left: -280px;
    width: 280px;
    height: 100vh;
    z-index: 1000;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    background: linear-gradient(135deg, #1a252f, #2c3e50);
    box-shadow: 2px 0 15px rgba(0,0,0,0.1);
}

.sidebar-header {
    background: linear-gradient(135deg, #1a252f, #2c3e50);
    position: sticky;
    top: 0;
    z-index: 1001;
    padding: 1.2rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

/* ===== Compact Action Buttons ===== */
.action-buttons {
    display: flex;
    gap: 8px;
    margin: 0 0 1rem 0;
    padding: 8px 0;
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}

.action-buttons::-webkit-scrollbar {
    display: none;
}

.action-btn {
    flex: 0 0 auto;
    padding: 8px 12px;
    font-size: 0.85rem;
    min-width: 120px;
    height: 60px;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    background-color: var(--color-primary);
    color: white;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}

.action-btn i {
    font-size: 1rem;
    margin-bottom: 4px;
}

/* Adjusted pulse animations */
@keyframes pulseBlue {
    0%, 100% { box-shadow: 0 0 0 0 rgba(52, 152, 219, 0.7); }
    50% { box-shadow: 0 0 0 8px rgba(52, 152, 219, 0); }
}

@keyframes pulseGreen {
    0%, 100% { box-shadow: 0 0 0 0 rgba(46, 204, 113, 0.7); }
    50% { box-shadow: 0 0 0 8px rgba(46, 204, 113, 0); }
}

@keyframes pulseOrange {
    0%, 100% { box-shadow: 0 0 0 0 rgba(230, 126, 34, 0.7); }
    50% { box-shadow: 0 0 0 8px rgba(230, 126, 34, 0); }
}

@keyframes pulsePurple {
    0%, 100% { box-shadow: 0 0 0 0 rgba(155, 89, 182, 0.7); }
    50% { box-shadow: 0 0 0 8px rgba(155, 89, 182, 0); }
}

@keyframes pulseRed {
    0%, 100% { box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.7); }
    50% { box-shadow: 0 0 0 8px rgba(231, 76, 60, 0); }
}

/* Rainbow button animation */
.action-btn[href="discount_request.php"] {
    animation: pulseRainbow 4s infinite;
}

@keyframes pulseRainbow {
    0% { background-color: #ff0000; }
    14% { background-color: #ff7f00; }
    28% { background-color: #ffff00; }
    42% { background-color: #00ff00; }
    56% { background-color: #0000ff; }
    70% { background-color: #4b0082; }
    84% { background-color: #9400d3; }
    100% { background-color: #ff0000; }
}

/* ===== Mobile Sidebar Navigation ===== */
.mobile-menu-toggle {
    display: none;
    background: none;
    border: none;
    color: var(--color-white);
    font-size: 1.5rem;
    cursor: pointer;
    padding: var(--space-sm);
}

@media (max-width: 768px) {
    /* Mobile layout */
    body {
        grid-template-columns: 1fr;
        grid-template-areas: 
            "header"
            "main"
            "footer";
    }
 /* ===== Enhanced Sidebar ===== */
.sidebar {
     z-index: 1000;
    position: fixed;
    top: 0;
    left: -300px;
    width: 110px;
    height: 100vh;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    background: linear-gradient(135deg, #1a252f, #2c3e50);
    box-shadow: 2px 0 15px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
}

.sidebar.active {
    left: 0;
}

.sidebar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.2rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.sidebar-close {
    background: none;
    border: none;
    color: white;
    font-size: 1.3rem;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.sidebar-close:hover {
    opacity: 1;
}

.sidebar-nav {
    padding: 1rem 0;
    flex-grow: 1;
}

.sidebar-link {
    display: flex;
    align-items: center;
    padding: 0.7rem 1rem;
    margin: 0.2rem 0.5rem;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.sidebar-link:hover {
    background: rgba(255,255,255,0.1);
    transform: translateX(5px);
}

.sidebar-link i {
    margin-right: 0.5rem;
    width: 24px;
    text-align: center;
    font-size: 1rem;
}

.sidebar-link.logout {
    color: #ff6b6b;
    margin-top: auto;
}

.sidebar-header {
    background: linear-gradient(135deg, #1a252f, #2c3e50);
    position: sticky;
    top: 0;
    z-index: 1001; /* Higher than sidebar */
}
.sidebar-overlay {
    position: relative;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.sidebar-overlay.active {
    opacity: 1;
    visibility: visible;
}

/* Main content adjustment when sidebar is open */
.main-content {
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.sidebar.active ~ .main-content {
    transform: translateX(150px);
}

/* Mobile menu toggle button */
.mobile-menu-toggle {
    display: none;
    background: none;
    border: none;
    color: var(--color-white);
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0.5rem;
    margin-right: 1rem;
}

@media (max-width: 992px) {
    .mobile-menu-toggle {
        display: block;
    }
    
    .sidebar.active ~ .main-content {
        transform: translateX(150px);
    }
}

@media (max-width: 768px) {
    .sidebar {
        width: 260px;
    }
    
    .sidebar.active ~ .main-content {
        transform: translateX(100px);
    }
}
/* Main content adjustment when sidebar is open */
.main-content {
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.sidebar.active ~ .main-content {
    transform: translateX(150px);
}

/* Mobile menu toggle button */
.mobile-menu-toggle {
    display: none;
    background: none;
    border: none;
    color: var(--color-white);
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0.5rem;
    margin-right: 1rem;
}

@media (max-width: 992px) {
    .mobile-menu-toggle {
        display: block;
    }
    
    .sidebar.active ~ .main-content {
        transform: translateX(150px);
    }
}

@media (max-width: 768px) {
    .sidebar {
        width: 260px;
    }
    
    .sidebar.active ~ .main-content {
        transform: translateX(100px);
    }
}
      /* Improved Welcome Card */
.welcome-card {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: var(--color-white);
        padding: 0.8rem;
   border-radius: 8px;
    margin-bottom: 1rem;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    text-align: center;
    width: 100%;
    box-sizing: border-box;
}

.welcome-card h1 {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.welcome-card p {
    font-size: 0.95rem;
}

/* ===== Improved Action Buttons ===== */
.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
    overflow-x: auto;
    padding-bottom: var(--space-sm);
    scrollbar-width: none; /* Firefox */
}

.action-buttons::-webkit-scrollbar {
    display: none; /* Chrome/Safari */
}

.action-btn {
    flex: 0 0 auto;
    white-space: nowrap;
    min-width: 100px;
    padding: var(--space-sm) var(--space-md);
    display: inline-flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    min-height: auto;
}

.action-btn i {
    margin-bottom: 0;
    font-size: 1rem;
}

@media (max-width: 600px) {
    .action-buttons {
        flex-wrap: nowrap;
        padding-bottom: var(--space-xs);
    }
    
    .action-btn {
        min-width: 50px;
        padding: var(--space-xs) var(--space-sm);
        font-size: 0.8rem;
    }
    
    .action-btn i {
        font-size: 0.9rem;
    }
}


/* Different colors for different buttons */
.action-btn[href="chat.php"] {
    animation: pulseBlue 2s infinite;
}
.action-btn[href="modify_subjects.php"] {
    animation: pulseGreen 2s infinite 0.5s;
}
.action-btn[href="payment_history.php"] {
    animation: pulseOrange 2s infinite 1s;
}
.action-btn[href="discount_request.php"] {
    animation: pulseRainbow 4s infinite;
}
.action-btn[href="normal_overal_payment.php"] {
    animation: pulsePurple 2s infinite 1.5s;
}
.action-btn.logout {
    animation: pulseRed 1.5s infinite;
}

@keyframes pulseBlue {
    0%, 100% { box-shadow: 0 0 0 0 rgba(52, 152, 219, 0.7); }
    50% { box-shadow: 0 0 0 50px rgba(52, 152, 219, 0); }
}

@keyframes pulseGreen {
    0%, 100% { box-shadow: 0 0 0 0 rgba(46, 204, 113, 0.7); }
    50% { box-shadow: 0 0 0 50px rgba(46, 204, 113, 0); }
}

@keyframes pulseOrange {
    0%, 100% { box-shadow: 0 0 0 0 rgba(230, 126, 34, 0.7); }
    50% { box-shadow: 0 0 0 50px rgba(230, 126, 34, 0); }
}

@keyframes pulsePurple {
    0%, 100% { box-shadow: 0 0 0 0 rgba(155, 89, 182, 0.7); }
    50% { box-shadow: 0 0 0 50px rgba(155, 89, 182, 0); }
}

@keyframes pulseRed {
    0%, 100% { box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.7); }
    50% { box-shadow: 0 0 0 50px rgba(231, 76, 60, 0); }
}

@keyframes pulseRainbow {
    0% { background-color: #ff0000; }
    14% { background-color: #ff7f00; }
    28% { background-color: #ffff00; }
    42% { background-color: #00ff00; }
    56% { background-color: #0000ff; }
    70% { background-color: #4b0082; }
    84% { background-color: #9400d3; }
    100% { background-color: #ff0000; }
}
/* Responsive Adjustments */
@media (max-width: 100px) {
    .action-buttons {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .welcome-card {
        padding: var(--space-md) var(--space-sm);
    }
    
    .action-btn {
        min-height: 70px;
        font-size: 0.85rem;
        padding: var(--space-xs);
    }
    
    .action-btn i {
        font-size: 1.2rem;
    }
}

@media (max-width: 400px) {
    .action-buttons {
        grid-template-columns: 1fr;
    }
}

        /* ===== CSS Variables ===== */
        :root {
            /* Primary Colors */
            --color-primary: #2c3e50;
            --color-primary-light: #3d566e;
            --color-primary-dark: #1a252f;
            
            /* Secondary Colors */
            --color-secondary: #3498db;
            --color-accent: #2980b9;
            
            /* Status Colors */
            --color-success: #27ae60;
            --color-warning: #f39c12;
            --color-danger: #e74c3c;
            --color-info: #3498db;
            
            /* Neutral Colors */
            --color-white: #ffffff;
            --color-light: #ecf0f1;
            --color-gray: #95a5a6;
            --color-dark: #333333;
            
            /* Spacing */
            --space-xs: 0.25rem;
            --space-sm: 0.5rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            
            /* Typography */
            --font-primary: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            --font-size-base: 1rem;
            --line-height-base: 1.6;
            
            /* Borders */
            --border-radius: 0.25rem;
            --border-width: 1px;
            --border-color: #ddd;
            
            /* Shadows */
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            
            /* Transitions */
            --transition-fast: 0.15s ease;
            --transition-normal: 0.3s ease;
            --transition-slow: 0.5s ease;
        }
        
  /* Add this to your existing CSS */
.action-btn[href="discount_request.php"] {
    animation: rainbowBlink 2s linear infinite;
    font-weight: bold;
    position: relative;
    overflow: hidden;
    border: 2px solid transparent;
}

@keyframes rainbowBlink {
    0% { background-color: #ff0000; border-color: #ff0000; }
    14% { background-color: #ff7f00; border-color: #ff7f00; }
    28% { background-color: #ffff00; border-color: #ffff00; }
    42% { background-color: #00ff00; border-color: #00ff00; }
    56% { background-color: #0000ff; border-color: #0000ff; }
    70% { background-color: #4b0082; border-color: #4b0082; }
    84% { background-color: #9400d3; border-color: #9400d3; }
    100% { background-color: #ff0000; border-color: #ff0000; }
}

.action-btn[href="discount_request.php"]:hover {
    animation: rainbowBlinkFast 0.5s linear infinite;
    transform: scale(1.05);
}

@keyframes rainbowBlinkFast {
    0% { background-color: #ff0000; border-color: #ff0000; }
    14% { background-color: #ff7f00; border-color: #ff7f00; }
    28% { background-color: #ffff00; border-color: #ffff00; }
    42% { background-color: #00ff00; border-color: #00ff00; }
    56% { background-color: #0000ff; border-color: #0000ff; }
    70% { background-color: #4b0082; border-color: #4b0082; }
    84% { background-color: #9400d3; border-color: #9400d3; }
    100% { background-color: #ff0000; border-color: #ff0000; }
}

.action-btn[href="discount_request.php"]::after {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: rgba(255, 255, 255, 0.2);
    transform: rotate(45deg);
    animation: shine 3s infinite;
}

@keyframes shine {
    0% { left: -50%; }
    20% { left: 100%; }
    100% { left: 100%; }
}


        /* Navigation */
    
   

 
    
/* ===== Table Scroll Improvements ===== */
.scroll-container {
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: #3498db #f1f1f1;
    position: relative;
}

.scroll-container::-webkit-scrollbar {
    height: 6px;
}

.scroll-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.scroll-container::-webkit-scrollbar-thumb {
    background: #3498db;
    border-radius: 3px;
}

.scroll-container::after {
    content: '';
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 30px;
    background: linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,1));
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.3s;
}

.scroll-container.scroll-start::after {
    opacity: 1;
}

.scroll-container.scroll-middle::before,
.scroll-container.scroll-middle::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    width: 30px;
    background: linear-gradient(90deg, rgba(255,255,255,1), rgba(255,255,255,0));
    pointer-events: none;
    opacity: 1;
}

.scroll-container.scroll-middle::after {
    right: 0;
    background: linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,1));
}

.scroll-container.scroll-end::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 30px;
    background: linear-gradient(90deg, rgba(255,255,255,1), rgba(255,255,255,0));
    pointer-events: none;
    opacity: 1;
}

/* selected subject table 
/* Responsive Design */
body {
    font-family: Arial, sans-serif;
    margin: 0;
    padding: 0;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}

/* Container Styling */
.container {
    width: 95%;
    max-width: 1200px;
    margin: auto;
    padding: 10px;
}

/* Table Styling */
#exams-section table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 14px; /* Minimize table text */
}

#exams-section th, #exams-section td {
    border: 1px solid #ddd; /* Light border */
    padding: 6px; /* Reduce padding */
    text-align: center;
}

/* Header Row */
#exams-section th {
    background-color: #f4f4f4;
    font-weight: bold;
    padding: 8px;
}

/* Unique Colors */
.paper-code {
    color: purple; /* Unique color for Paper Code */
    font-weight: bold;
}

.paper-name {
    color: darkslategray;
    font-weight: bold;
}

.price {
    color: darkblue;
    font-weight: bold;
}

.transaction-id {
    color: darkgray;
}

/* Payment Status Colors */
.payment-status {
    font-weight: bold;
}

.payment-status.approved {
    color: green;
}

.payment-status.pending {
    color: orange;
}

.payment-status.not-paid {
    color: red;
}

/* Countdown Timer */
.countdown {
    font-weight: bold;
    color: green; /* Default Safaricom-style */
}

/* Blinking for Nearest Exam */
.blink {
    color: red !important;
    animation: blink-animation 1s infinite;
}

@keyframes blink-animation {
    50% { opacity: 0; }
}

 
        /* ===== Dashboard Specific Styles ===== */
   
    
  
 /* ======= FullCalendar Styling ======= */
#calendar {
    max-width: 100%;
    margin: 20px auto;
    border: 2px solid #007bff;
    border-radius: 8px;
    padding: 15px;
    background: #ffffff;
    box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1);
}

/* FullCalendar Header */
.fc-toolbar {
    background: #007bff;
    color: white;
    padding: 10px;
    border-radius: 6px;
    text-align: center;

}
/* ===== Calendar Improvements ===== */
#calendar-section {
    margin-top: var(--space-lg);
}

#calendar {
    max-width: 100%;
}


        /* Footer */
        .footer {
            background-color: var(--color-primary);
            color: var(--color-white);
            padding: var(--space-lg) 0;
            margin-top: auto;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: var(--space-lg);
            margin-bottom: var(--space-md);
            flex-wrap: wrap;
        }

        .footer-link {
            color: var(--color-white);
        }

        
    </style>