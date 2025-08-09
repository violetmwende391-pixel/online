<?php
// Enable error reporting for debugging (show all errors, warnings, and notices)
ini_set('display_errors', 1);   // Display errors in the browser
error_reporting(E_ALL);  
require_once 'config.php';
// RESETT BUTTON 
if (isset($_POST['reset_volumes'])) {
    // Get latest total_volume per meter
    $stmt = $pdo->query("
        SELECT f.meter_id, MAX(f.recorded_at) as latest
        FROM flow_data f
        GROUP BY f.meter_id
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $meterId = $row['meter_id'];
        $latestTime = $row['latest'];

        // Get latest total_volume
        $volumeStmt = $pdo->prepare("
            SELECT total_volume FROM flow_data
            WHERE meter_id = ? AND recorded_at = ?
        ");
        $volumeStmt->execute([$meterId, $latestTime]);
        $latestVolume = floatval($volumeStmt->fetchColumn());

        // Insert a new entry resetting volume to zero
        $insertStmt = $pdo->prepare("
            INSERT INTO flow_data (meter_id, flow_rate, volume, total_volume, balance, valve_status, recorded_at)
            VALUES (?, 0, 0, 0, 0, 'closed', NOW())
        ");
        $insertStmt->execute([$meterId]);
    }

    $resetMessage = "✅ Volume values have been reset successfully.";
}


//RESET BUTON ABOVE 

if (!is_admin_logged_in()) {
    redirect('admin_login.php');
}

// ==============================
// CURRENT LEAKAGE STATUS
// ==============================
$supplyStmt = $pdo->prepare("SELECT meter_id FROM meters WHERE meter_serial = 'main_supply'");
$supplyStmt->execute();
$supplyResult = $supplyStmt->fetch();

$supplyVolume = 0;
$consumerTotal = 0;
$leakage = 0;
$leakageStatus = "❌ Supply meter not found";

if ($supplyResult) {
    $supplyMeterId = $supplyResult['meter_id'];

    $supplyVolumeStmt = $pdo->prepare("
        SELECT total_volume 
        FROM flow_data 
        WHERE meter_id = ? 
        ORDER BY recorded_at DESC 
        LIMIT 1
    ");
    $supplyVolumeStmt->execute([$supplyMeterId]);
    $supplyVolume = floatval($supplyVolumeStmt->fetchColumn());

    $consumerVolumesStmt = $pdo->query("
        SELECT meter_id, MAX(recorded_at) AS latest
        FROM flow_data
        WHERE meter_id != $supplyMeterId
        GROUP BY meter_id
    ");

    while ($row = $consumerVolumesStmt->fetch(PDO::FETCH_ASSOC)) {
        $latestStmt = $pdo->prepare("
            SELECT total_volume FROM flow_data
            WHERE meter_id = ? AND recorded_at = ?
        ");
        $latestStmt->execute([$row['meter_id'], $row['latest']]);
        $volume = floatval($latestStmt->fetchColumn());
        $consumerTotal += $volume;
    }

    $leakage = $supplyVolume - $consumerTotal;
    $tolerance = 5.0;
    $leakageStatus = ($leakage > $tolerance) ? '⚠️ POSSIBLE LEAK DETECTED' : '✅ No leakage';
}

// ==============================
// LEAKAGE HISTORY DATA FOR GRAPH
// ==============================
$leakageHistory = [];
$historyQuery = $pdo->prepare("
    SELECT TO_CHAR(recorded_at, 'YYYY-MM-DD HH24:MI') AS time,
           MAX(CASE WHEN m.meter_serial = 'main_supply' THEN f.total_volume ELSE NULL END) AS supply,
           SUM(CASE WHEN m.meter_serial != 'main_supply' THEN f.total_volume ELSE 0 END) AS consumption
    FROM flow_data f
    JOIN meters m ON m.meter_id = f.meter_id
    GROUP BY time
    ORDER BY time DESC
    LIMIT 20
");
$historyQuery->execute();
$leakageHistory = $historyQuery->fetchAll(PDO::FETCH_ASSOC);
$leakageHistory = array_reverse($leakageHistory); // oldest to newest
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leakage Detection - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<?php include 'admin_header.php'; ?>
<div class="container-fluid mt-4">
    <div class="row">
        <?php include 'admin_sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="pt-3 pb-2 mb-3 border-bottom d-flex justify-content-between align-items-center">
                <h2>Leakage Detection</h2>
                <span class="badge bg-info text-dark fs-5"><?= $leakageStatus ?></span>
            </div>


            
<?php if (isset($resetMessage)): ?>
    <div class="alert alert-success"><?= $resetMessage ?></div>
<?php endif; ?>

<form method="POST" onsubmit="return confirm('Are you sure you want to reset all volume values to zero?');">
    <button type="submit" name="reset_volumes" class="btn btn-warning mb-3">
        🔄 Reset Volumes to Zero
    </button>
</form>



            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card border-primary shadow-sm">
                        <div class="card-body text-center">
                            <h5 class="card-title">Supply Volume</h5>
                            <p class="fs-4"><?= number_format($supplyVolume, 3) ?> L</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-success shadow-sm">
                        <div class="card-body text-center">
                            <h5 class="card-title">Consumer Volume</h5>
                            <p class="fs-4"><?= number_format($consumerTotal, 3) ?> L</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-danger shadow-sm">
                        <div class="card-body text-center">
                            <h5 class="card-title">Leakage</h5>
                            <p class="fs-4"><?= number_format($leakage, 3) ?> L</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-5">
                <div class="card-header">
                    <h5 class="mb-0">Leakage History Chart</h5>
                </div>
                <div class="card-body">
                    <canvas id="leakageChart" height="100"></canvas>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
const ctx = document.getElementById('leakageChart').getContext('2d');
const chart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($leakageHistory, 'time')) ?>,
        datasets: [
            {
                label: 'Supply (L)',
                data: <?= json_encode(array_map(fn($r) => round(floatval($r['supply']), 3), $leakageHistory)) ?>,
                borderColor: 'rgba(54, 162, 235, 1)',
                tension: 0.3
            },
            {
                label: 'Consumption (L)',
                data: <?= json_encode(array_map(fn($r) => round(floatval($r['consumption']), 3), $leakageHistory)) ?>,
                borderColor: 'rgba(75, 192, 192, 1)',
                tension: 0.3
            },
            {
                label: 'Leakage (L)',
                data: <?= json_encode(array_map(fn($r) => round(floatval($r['supply']) - floatval($r['consumption']), 3), $leakageHistory)) ?>,
                borderColor: 'rgba(255, 99, 132, 1)',
                tension: 0.3
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
            title: {
                display: true,
                text: 'Leakage vs Supply and Consumption over Time'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                title: { display: true, text: 'Liters' }
            },
            x: {
                title: { display: true, text: 'Time' }
            }
        }
    }
});
</script>
</body>
</html>
