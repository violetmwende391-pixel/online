<?php
require_once 'config.php';

if (!is_logged_in()) redirect('login.php');

$meter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Verify access
$is_admin = is_admin_logged_in() ? 1 : 0;

// FIXED: PostgreSQL-safe check
$stmt = $pdo->prepare("
    SELECT * 
    FROM meters 
    WHERE meter_id = ? 
      AND (user_id = ? OR ? = 1)
");
$stmt->execute([$meter_id, $_SESSION['user_id'], $is_admin]);

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

// PostgreSQL-safe queries
$params = [$meter_id, $from_date, $to_date];

$hourly_sql = "SELECT EXTRACT(HOUR FROM recorded_at) AS label, 
                      SUM(volume) AS total_volume 
               FROM flow_data 
               WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? 
               GROUP BY EXTRACT(HOUR FROM recorded_at) 
               ORDER BY label";

$daily_sql = "SELECT DATE(recorded_at) AS label, 
                     SUM(volume) AS total_volume 
              FROM flow_data 
              WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? 
              GROUP BY DATE(recorded_at) 
              ORDER BY label";

$weekly_sql = "SELECT TO_CHAR(recorded_at, 'IYYY-IW') AS label, 
                      SUM(volume) AS total_volume 
               FROM flow_data 
               WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? 
               GROUP BY TO_CHAR(recorded_at, 'IYYY-IW') 
               ORDER BY label";

$monthly_sql = "SELECT TO_CHAR(recorded_at, 'YYYY-MM') AS label, 
                       SUM(volume) AS total_volume 
                FROM flow_data 
                WHERE meter_id = ? AND DATE(recorded_at) BETWEEN ? AND ? 
                GROUP BY TO_CHAR(recorded_at, 'YYYY-MM') 
                ORDER BY label";

$hourlyData = fetchData($pdo, $hourly_sql, $params);
$dailyData  = fetchData($pdo, $daily_sql, $params);
$weeklyData = fetchData($pdo, $weekly_sql, $params);
$monthlyData = fetchData($pdo, $monthly_sql, $params);

// Export CSV
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Usage Stats | <?= APP_NAME ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background-color: #f0f4f8; color: #333; }
        h2 { color: #007bff; margin-bottom: 20px; }
        form { margin-bottom: 30px; background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        label { font-weight: 500; }
        button, a { background-color: #007bff; color: #fff !important; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; }
        a:hover, button:hover { background-color: #0056b3; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 40px; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
        th, td { padding: 12px; border: 1px solid #ddd; }
        th { background-color: #007bff; color: white; }
        .chart-section { background: #fff; padding: 20px; margin-bottom: 40px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.08); }
        .chart-container { margin-top: 20px; }
        canvas { max-width: 100%; height: auto; }
        #monthlyChart { max-width: 400px; margin: 0 auto; padding: 10px; }
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
    <h3>Live Water Usage Charts (Refreshes every 10s)</h3>
    <div class="chart-container"><canvas id="hourlyChart"></canvas></div>
    <div class="chart-container"><canvas id="dailyChart"></canvas></div>
    <div class="chart-container"><canvas id="weeklyChart"></canvas></div>
    <div class="chart-container"><canvas id="monthlyChart"></canvas></div>
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
                    ? ['#007bff','#28a745','#ffc107','#dc3545']
                    : color,
                borderColor: chartType === 'pie' ? '#fff' : 'rgba(0, 123, 255, 1)',
                borderWidth: 2,
                fill: chartType !== 'pie'
            }]
        },
        options: {
            responsive: true,
            animation: { duration: 1000, easing: 'easeOutQuart' },
            plugins: {
                tooltip: { callbacks: { label: ctx => ctx.label + ': ' + parseFloat(ctx.raw).toFixed(2) + ' L' }},
                legend: { position: chartType === 'pie' ? 'bottom' : 'top', labels: { color: '#333' } }
            },
            scales: chartType !== 'pie' ? {
                y: { beginAtZero: true, title: { display: true, text: 'Volume (Liters)' }},
                x: { ticks: { color: '#555' } }
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

    const hourlyTotal = data.hourly.data.reduce((a, b) => a + b, 0);
    const dailyTotal = data.daily.data.reduce((a, b) => a + b, 0);
    const weeklyTotal = data.weekly.data.reduce((a, b) => a + b, 0);
    const monthlyTotal = data.monthly.data.reduce((a, b) => a + b, 0);

    hourlyChart = renderChart('hourlyChart', 'line', 'Hourly Usage (L)', data.hourly.labels, data.hourly.data);
    dailyChart  = renderChart('dailyChart', 'bar', 'Daily Usage (L)', data.daily.labels, data.daily.data);
    weeklyChart = renderChart('weeklyChart', 'line', 'Weekly Usage (L)', data.weekly.labels, data.weekly.data);
    monthlyChart = renderChart('monthlyChart', 'pie', 'Usage Distribution', ['Hourly', 'Daily', 'Weekly', 'Monthly'], [hourlyTotal, dailyTotal, weeklyTotal, monthlyTotal]);
}

fetchDataAndDraw();
setInterval(fetchDataAndDraw, 10000);
</script>

</body>
</html>
