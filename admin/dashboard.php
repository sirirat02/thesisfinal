<?php
include '../db.php';

/* =========================
   SUMMARY
========================= */

$totalPlaces = $conn->query("
SELECT COUNT(*) total FROM places
")->fetch_assoc()['total'] ?? 0;

$totalUsers = $conn->query("
SELECT COUNT(*) total FROM users
")->fetch_assoc()['total'] ?? 0;

$totalRecommend = $conn->query("
SELECT COUNT(*) total FROM recommendation_logs
")->fetch_assoc()['total'] ?? 0;

$totalProvince = $conn->query("
SELECT COUNT(DISTINCT province) total FROM places
")->fetch_assoc()['total'] ?? 0;

/* =========================
   TOP 5 PLACES
========================= */

$topPlaces = $conn->query("
SELECT p.name, COUNT(*) total
FROM recommendation_logs r
JOIN places p ON p.id = r.place_id
GROUP BY r.place_id
ORDER BY total DESC
LIMIT 5
");

/* =========================
   TOP STYLE (with percentage)
========================= */

$styleResult = $conn->query("
SELECT style_name, COUNT(*) total
FROM recommendation_logs
WHERE style_name IS NOT NULL AND style_name <> ''
GROUP BY style_name
ORDER BY total DESC
");

$styleLabels = [];
$styleValues = [];
$allStyles = [];

while ($row = $styleResult->fetch_assoc()) {
    $styleLabels[] = $row['style_name'];
    $styleValues[] = $row['total'];
    $allStyles[] = $row;
}

$totalStyleCount = array_sum($styleValues);

$topStyles5 = array_slice($allStyles, 0, 5);

$mostStyle = !empty($allStyles) ? $allStyles[0] : null;

/* =========================
   TOP PLACES (with percentage)
========================= */

$placeLabels = [];
$placeValues = [];
$allPlaces = [];

$topPlacesChart = $conn->query("
SELECT p.name, COUNT(*) total
FROM recommendation_logs r
JOIN places p ON p.id = r.place_id
GROUP BY r.place_id
ORDER BY total DESC
LIMIT 5
");

while ($row = $topPlacesChart->fetch_assoc()) {
    $placeLabels[] = $row['name'];
    $placeValues[] = $row['total'];
    $allPlaces[] = $row;
}

$totalPlaceCount = array_sum($placeValues);

/* =========================
   USAGE CHART
========================= */

$chartLabels = [];
$chartValues = [];

$result = $conn->query("
SELECT DATE(created_at) day, COUNT(*) total
FROM recommendation_logs
GROUP BY DATE(created_at)
ORDER BY day
");

while ($row = $result->fetch_assoc()) {
    $chartLabels[] = $row['day'];
    $chartValues[] = $row['total'];
}

/* =========================
   PROVINCE DISTRIBUTION (for pie)
========================= */

$provinceLabels = [];
$provinceValues = [];

$provinceResult = $conn->query("
SELECT p.province, COUNT(*) total
FROM recommendation_logs r
JOIN places p ON p.id = r.place_id
GROUP BY p.province
ORDER BY total DESC
LIMIT 6
");

while ($row = $provinceResult->fetch_assoc()) {
    $provinceLabels[] = $row['province'];
    $provinceValues[] = $row['total'];
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Tourism Recommendation</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  * { font-family: 'IBM Plex Sans Thai', sans-serif; }
  .mono { font-family: 'IBM Plex Mono', monospace; }

  body { background: #F0F4F0; }

  /* Sidebar */
  .sidebar {
    background: linear-gradient(175deg, #1a3a2a 0%, #1e4d35 40%, #163328 100%);
  }

  .nav-active {
    background: rgba(255,255,255,0.12);
    border-left: 3px solid #6ee7b7;
  }

  .nav-item {
    border-left: 3px solid transparent;
    transition: all 0.2s ease;
  }

  .nav-item:hover {
    background: rgba(255,255,255,0.07);
    border-left: 3px solid rgba(110,231,183,0.4);
  }

  /* Cards */
  .stat-card {
    background: white;
    border-radius: 16px;
    border: 1px solid #e8f0ea;
    transition: transform 0.2s, box-shadow 0.2s;
  }
  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
  }

  /* Progress bar */
  .progress-bar {
    height: 6px;
    background: #e8f0ea;
    border-radius: 99px;
    overflow: hidden;
  }
  .progress-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 1s cubic-bezier(0.4,0,0.2,1);
  }

  /* Badge */
  .badge-rank {
    width: 28px; height: 28px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
  }

  /* Chart containers */
  .chart-card {
    background: white;
    border-radius: 20px;
    border: 1px solid #e8f0ea;
    padding: 24px;
  }

  /* Donut center label */
  .donut-wrap { position: relative; }
  .donut-center {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    text-align: center; pointer-events: none;
  }

  /* Scrollbar */
  ::-webkit-scrollbar { width: 6px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: #c6d9cb; border-radius: 3px; }

  /* Animated counter */
  .count-up { transition: all 0.5s; }

  .pill {
    display: inline-flex; align-items: center;
    padding: 2px 10px; border-radius: 99px;
    font-size: 11px; font-weight: 600;
  }
</style>
</head>

<body>

<!-- ===== SIDEBAR ===== -->
<div class="sidebar fixed left-0 top-0 w-60 h-screen text-white shadow-2xl flex flex-col">

  <!-- Logo -->
  <div class="px-6 py-7 border-b border-white/10">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center">
        <i class="fas fa-mountain-sun text-emerald-300 text-lg"></i>
      </div>
      <div>
        <p class="text-xs text-emerald-300 font-semibold tracking-widest uppercase">Admin</p>
        <h1 class="text-base font-bold leading-tight">Tourism Recommendation</h1>
      </div>
    </div>
  </div>

  <!-- Menu -->
  <nav class="flex-1 px-3 py-5 space-y-1">
    <p class="px-3 text-xs font-semibold text-emerald-400/60 uppercase tracking-wider mb-3">เมนูหลัก</p>

    <a href="dashboard.php" class="nav-item nav-active flex items-center gap-3 px-3 py-3 rounded-xl">
      <div class="w-8 h-8 rounded-lg bg-emerald-500/20 flex items-center justify-center">
        <i class="fas fa-chart-line text-emerald-300 text-sm"></i>
      </div>
      <span class="text-sm font-medium">Dashboard</span>
    </a>

    <a href="places.php" class="nav-item flex items-center gap-3 px-3 py-3 rounded-xl text-white/75 hover:text-white">
      <div class="w-8 h-8 rounded-lg bg-white/5 flex items-center justify-center">
        <i class="fas fa-map-marker-alt text-sm"></i>
      </div>
      <span class="text-sm font-medium">จัดการสถานที่</span>
    </a>
  </nav>

  <!-- Footer -->
  <div class="px-6 py-5 border-t border-white/10">
    <p class="text-xs text-emerald-300/60 text-center">
      <i class="fas fa-leaf mr-1"></i>Northern Thailand · <?= date('Y') ?>
    </p>
  </div>

</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="ml-60 min-h-screen">

  <!-- Top bar -->
  <div class="bg-white border-b border-gray-100 px-8 py-4 flex items-center justify-between sticky top-0 z-10">
    <div>
      <div class="flex items-center gap-2">
        <span class="text-xs font-semibold text-emerald-600 uppercase tracking-wider">ภาพรวมระบบ</span>
        <span class="pill bg-emerald-50 text-emerald-600">Live</span>
      </div>
      <h2 class="text-xl font-bold text-gray-800 mt-0.5">Dashboard</h2>
    </div>
    <!-- <div class="text-right">
      <p class="text-xs text-gray-400">อัปเดตล่าสุด</p>
      <p class="text-sm font-semibold text-gray-700 mono"><?= date('d M Y · H:i') ?></p>
    </div> -->
  </div>

  <div class="px-8 py-7">

    <!-- ===== KPI CARDS ===== -->
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-7">

      <!-- Places -->
      <div class="stat-card p-5">
        <div class="flex items-start justify-between mb-4">
          <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center">
            <i class="fas fa-map-marker-alt text-blue-500"></i>
          </div>
          <span class="pill bg-blue-50 text-blue-600">ทั้งหมด</span>
        </div>
        <p class="mono text-3xl font-bold text-gray-800"><?= number_format($totalPlaces) ?></p>
        <p class="text-sm text-gray-500 mt-1">สถานที่ในระบบ</p>
      </div>

      <!-- Users -->
      <div class="stat-card p-5">
        <div class="flex items-start justify-between mb-4">
          <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center">
            <i class="fas fa-users text-emerald-500"></i>
          </div>
          <span class="pill bg-emerald-50 text-emerald-600">สมาชิก</span>
        </div>
        <p class="mono text-3xl font-bold text-gray-800"><?= number_format($totalUsers) ?></p>
        <p class="text-sm text-gray-500 mt-1">ผู้ใช้งานทั้งหมด</p>
      </div>

      <!-- Recommendations -->
      <div class="stat-card p-5">
        <div class="flex items-start justify-between mb-4">
          <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center">
            <i class="fas fa-robot text-amber-500"></i>
          </div>
          <span class="pill bg-amber-50 text-amber-600">ระบบแนะนำ</span>
        </div>
        <p class="mono text-3xl font-bold text-gray-800"><?= number_format($totalRecommend) ?></p>
        <p class="text-sm text-gray-500 mt-1">การแนะนำทั้งหมด</p>
      </div>

      <!-- Provinces -->
      <div class="stat-card p-5">
        <div class="flex items-start justify-between mb-4">
          <div class="w-10 h-10 rounded-xl bg-violet-50 flex items-center justify-center">
            <i class="fas fa-map text-violet-500"></i>
          </div>
          <span class="pill bg-violet-50 text-violet-600">ครอบคลุม</span>
        </div>
        <p class="mono text-3xl font-bold text-gray-800"><?= number_format($totalProvince) ?></p>
        <p class="text-sm text-gray-500 mt-1">จังหวัดในระบบ</p>
      </div>

    </div>

    <!-- ===== CHARTS ROW ===== -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">

      <!-- Usage Trend (2/3 width) -->
      <div class="chart-card xl:col-span-2">
        <div class="flex items-center justify-between mb-5">
          <div>
            <h3 class="font-bold text-gray-800">แนวโน้มการใช้งาน</h3>
            <p class="text-xs text-gray-400 mt-0.5">จำนวนการแนะนำต่อวัน</p>
          </div>
          <div class="pill bg-blue-50 text-blue-600">
            <i class="fas fa-chart-area mr-1"></i>Timeline
          </div>
        </div>
        <div class="h-[240px]">
          <canvas id="usageChart"></canvas>
        </div>
      </div>

      <!-- Style Donut (1/3 width) -->
      <div class="chart-card">
        <div class="flex items-center justify-between mb-5">
          <div>
            <h3 class="font-bold text-gray-800">สไตล์ยอดนิยม</h3>
            <p class="text-xs text-gray-400 mt-0.5">% สัดส่วนทุกสไตล์</p>
          </div>
        </div>
        <div class="donut-wrap h-[200px] flex items-center justify-center">
          <canvas id="styleChart"></canvas>
          <div class="donut-center">
            <p class="mono text-lg font-bold text-gray-800"><?= count($allStyles) ?></p>
            <p class="text-xs text-gray-400">สไตล์</p>
          </div>
        </div>
        <!-- Legend with % -->
        <div class="mt-4 space-y-2">
          <?php
          $styleColors = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4'];
          foreach (array_slice($allStyles, 0, 4) as $i => $s):
            $pct = $totalStyleCount > 0 ? round($s['total'] / $totalStyleCount * 100, 1) : 0;
          ?>
          <div class="flex items-center gap-2">
            <span style="background:<?= $styleColors[$i] ?>" class="w-2.5 h-2.5 rounded-full flex-shrink-0"></span>
            <span class="text-xs text-gray-600 flex-1 truncate"><?= htmlspecialchars($s['style_name']) ?></span>
            <span class="mono text-xs font-bold text-gray-700"><?= $pct ?>%</span>
          </div>
          <?php endforeach; ?>
          <?php if (count($allStyles) > 4): ?>
          <p class="text-xs text-gray-400 text-right">+<?= count($allStyles) - 4 ?> อื่นๆ</p>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- ===== BOTTOM ROW ===== -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">

      <!-- Top 5 Places with % bar -->
      <div class="chart-card">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h3 class="font-bold text-gray-800">Top 5 สถานที่ยอดนิยม</h3>
            <p class="text-xs text-gray-400 mt-0.5">เทียบสัดส่วนการถูกแนะนำ</p>
          </div>
          <div class="pill bg-blue-50 text-blue-600">
            <i class="fas fa-trophy mr-1"></i>Ranking
          </div>
        </div>

        <div class="space-y-4">
          <?php
          $placeColors = ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444'];
          foreach ($allPlaces as $i => $place):
            $pct = $totalPlaceCount > 0 ? round($place['total'] / $totalPlaceCount * 100, 1) : 0;
          ?>
          <div>
            <div class="flex items-center justify-between mb-1.5">
              <div class="flex items-center gap-2.5">
                <div class="badge-rank" style="background:<?= $placeColors[$i] ?>18; color:<?= $placeColors[$i] ?>">
                  <?= $i + 1 ?>
                </div>
                <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($place['name']) ?></span>
              </div>
              <div class="flex items-center gap-2">
                <span class="mono text-xs text-gray-400"><?= number_format($place['total']) ?> ครั้ง</span>
                <span class="mono text-sm font-bold" style="color:<?= $placeColors[$i] ?>"><?= $pct ?>%</span>
              </div>
            </div>
            <div class="progress-bar">
              <div class="progress-fill" style="width:<?= $pct ?>%; background:<?= $placeColors[$i] ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Top 5 Styles table with % -->
      <div class="chart-card">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h3 class="font-bold text-gray-800">Top 5 สไตล์การท่องเที่ยว</h3>
            <p class="text-xs text-gray-400 mt-0.5">รูปแบบที่ผู้ใช้เลือกมากที่สุด</p>
          </div>
          <div class="pill bg-violet-50 text-violet-600">
            <i class="fas fa-chart-pie mr-1"></i>Style
          </div>
        </div>

        <div class="space-y-3">
          <?php
          $styleBarColors = ['#8b5cf6','#3b82f6','#10b981','#f59e0b','#ef4444'];
          foreach ($topStyles5 as $i => $s):
            $pct = $totalStyleCount > 0 ? round($s['total'] / $totalStyleCount * 100, 1) : 0;
          ?>
          <div>
            <div class="flex items-center justify-between mb-1.5">
              <div class="flex items-center gap-2.5">
                <div class="badge-rank" style="background:<?= $styleBarColors[$i] ?>18; color:<?= $styleBarColors[$i] ?>">
                  <?= $i + 1 ?>
                </div>
                <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($s['style_name']) ?></span>
              </div>
              <div class="flex items-center gap-2">
                <span class="mono text-xs text-gray-400"><?= number_format($s['total']) ?></span>
                <span class="mono text-sm font-bold" style="color:<?= $styleBarColors[$i] ?>"><?= $pct ?>%</span>
              </div>
            </div>
            <div class="progress-bar">
              <div class="progress-fill" style="width:<?= $pct ?>%; background:<?= $styleBarColors[$i] ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Insight box -->
        <?php if ($mostStyle): ?>
        <?php $topPct = $totalStyleCount > 0 ? round($mostStyle['total'] / $totalStyleCount * 100, 1) : 0; ?>
        <div class="mt-5 p-4 rounded-xl bg-violet-50 border border-violet-100">
          <p class="text-xs text-violet-500 font-semibold uppercase tracking-wider mb-1">
            <i class="fas fa-lightbulb mr-1"></i>Insight
          </p>
          <p class="text-sm text-violet-800">
            <strong><?= htmlspecialchars($mostStyle['style_name']) ?></strong>
            เป็นสไตล์ที่ได้รับความนิยมสูงสุด คิดเป็น
            <strong class="mono"><?= $topPct ?>%</strong>
            ของการแนะนำทั้งหมด
          </p>
        </div>
        <?php endif; ?>

      </div>

    </div>

  </div>
</div>

<!-- ===== SCRIPTS ===== -->
<script>
const labels       = <?= json_encode($chartLabels,    JSON_UNESCAPED_UNICODE) ?>;
const totals       = <?= json_encode($chartValues,    JSON_UNESCAPED_UNICODE) ?>;
const styleLabels  = <?= json_encode($styleLabels,    JSON_UNESCAPED_UNICODE) ?>;
const styleValues  = <?= json_encode($styleValues,    JSON_UNESCAPED_UNICODE) ?>;
const placeLabels  = <?= json_encode($placeLabels,    JSON_UNESCAPED_UNICODE) ?>;
const placeValues  = <?= json_encode($placeValues,    JSON_UNESCAPED_UNICODE) ?>;

const COLORS = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4'];

/* Usage Line */
new Chart(document.getElementById('usageChart'), {
  type: 'line',
  data: {
    labels,
    datasets: [{
      label: 'การแนะนำ',
      data: totals,
      borderColor: '#10b981',
      backgroundColor: 'rgba(16,185,129,0.08)',
      fill: true,
      tension: 0.45,
      pointRadius: 3,
      pointBackgroundColor: '#10b981',
      borderWidth: 2
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11 }, maxTicksLimit: 8 } },
      y: { beginAtZero: true, grid: { color: '#f0f4f0' }, ticks: { font: { size: 11 } } }
    }
  }
});

/* Style Donut */
if (document.getElementById('styleChart') && styleLabels.length) {
  const totalStyle = styleValues.reduce((a, b) => a + b, 0);

  new Chart(document.getElementById('styleChart'), {
    type: 'doughnut',
    data: {
      labels: styleLabels,
      datasets: [{
        data: styleValues,
        backgroundColor: COLORS,
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '68%',
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const value = ctx.raw ?? ctx.parsed;
              const pct = ((value / totalStyle) * 100).toFixed(1);
            
              return ` ${ctx.label}: ${pct}%`;
            }
          }
        }
      }
    }
  });
}
</script>
</body>
</html>