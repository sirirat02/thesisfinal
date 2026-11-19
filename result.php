<?php
session_start();
include 'db.php';

/* =========================================================
   1. รับค่าจากฟอร์ม
   หมายเหตุ: ค่าที่ส่งมาในช่อง budget_total/budget ถือเป็น "งบต่อคน"
========================================================= */
$category_id = isset($_REQUEST['category']) ? (int)$_REQUEST['category'] : 0;
$group_size = isset($_REQUEST['group_size']) ? max((int)$_REQUEST['group_size'], 1) : 1;
$days = isset($_REQUEST['days']) ? max((int)$_REQUEST['days'], 1) : 1;

$start_province = trim($_REQUEST['start_province'] ?? ($_SESSION['start_province'] ?? ''));
$destination_province = trim($_REQUEST['destination_province'] ?? ($_SESSION['destination_province'] ?? ''));
$travel_style = trim($_REQUEST['travel_style'] ?? ($_SESSION['travel_style'] ?? ''));

$user_lat = trim($_REQUEST['user_lat'] ?? ($_SESSION['user_lat'] ?? ''));
$user_lng = trim($_REQUEST['user_lng'] ?? ($_SESSION['user_lng'] ?? ''));
$has_user_location = ($user_lat !== '' && $user_lng !== '' && is_numeric($user_lat) && is_numeric($user_lng));
if ($has_user_location) {
    $user_lat = (float)$user_lat;
    $user_lng = (float)$user_lng;
} else {
    $user_lat = null;
    $user_lng = null;
}

/* =========================================================
   2. งบประมาณ: งบต่อคน -> งบรวมของคณะ
========================================================= */
$budget_per_person = 0;
$budget_total = 0;

if (isset($_REQUEST['budget_per_person']) && $_REQUEST['budget_per_person'] !== '') {
    $budget_per_person = max((int)$_REQUEST['budget_per_person'], 0);
    $budget_total = $budget_per_person * $group_size;
} elseif (isset($_REQUEST['budget_total']) && $_REQUEST['budget_total'] !== '') {
    $budget_total = max((int)$_REQUEST['budget_total'], 0);
    $budget_per_person = ($group_size > 0) ? $budget_total / $group_size : $budget_total;
} elseif (isset($_REQUEST['budget']) && $_REQUEST['budget'] !== '') {
    $budget_total = max((int)$_REQUEST['budget'], 0);
    $budget_per_person = ($group_size > 0) ? $budget_total / $group_size : $budget_total;
} elseif (isset($_SESSION['budget_per_person']) && $_SESSION['budget_per_person'] !== '') {
    $budget_per_person = max((int)$_SESSION['budget_per_person'], 0);
    $budget_total = $budget_per_person * $group_size;
} elseif (isset($_SESSION['budget_total']) && $_SESSION['budget_total'] !== '') {
    $budget_total = max((int)$_SESSION['budget_total'], 0);
    $budget_per_person = ($group_size > 0) ? $budget_total / $group_size : $budget_total;
} elseif (isset($_SESSION['budget']) && $_SESSION['budget'] !== '') {
    $budget_total = max((int)$_SESSION['budget'], 0);
    $budget_per_person = ($group_size > 0) ? $budget_total / $group_size : $budget_total;
}

$_SESSION['budget_total'] = $budget_total;
$_SESSION['budget_per_person'] = $budget_per_person;
$_SESSION['group_size'] = $group_size;
$_SESSION['days'] = $days;
if ($start_province !== '') $_SESSION['start_province'] = $start_province;
if ($destination_province !== '') $_SESSION['destination_province'] = $destination_province;
if ($travel_style !== '') $_SESSION['travel_style'] = $travel_style;
if ($has_user_location) {
    $_SESSION['user_lat'] = $user_lat;
    $_SESSION['user_lng'] = $user_lng;
}

$budget_per_person_per_day = ($days > 0) ? $budget_per_person / $days : $budget_per_person;
$limit = max(6, min($days * 3, 12));
$clean_dest = trim(str_replace('จังหวัด', '', $destination_province));

/* =========================================================
   3. โหลดกฎจากตาราง rules
   กฎจะให้คะแนนเพิ่มเมื่อ:
   - travel_style ตรงกับหมวดหมู่/สไตล์ที่เลือก
   - งบรวมของคณะอยู่ในช่วง min_budget ถึง max_budget (ถ้าระบุ)
   - จำนวนคนไม่เกิน max_people (ถ้าระบุ)
   - จำนวนวันไม่น้อยกว่า min_days (ถ้าระบุ)
   หากไม่มีกฎตรง ระบบยังแนะนำตามตัวกรองพื้นฐานได้
========================================================= */
$activeRules = [];
$rulesAvailable = false;
$rulesSql = "SELECT id, rule_name, travel_style, min_budget, max_budget,
                    budget_center, budget_tolerance, max_people, min_days,
                    score, base_score
             FROM rules";
$rulesResult = @mysqli_query($conn, $rulesSql);
if ($rulesResult) {
    $rulesAvailable = true;
    while ($rule = mysqli_fetch_assoc($rulesResult)) {
        $ruleStyle = trim((string)($rule['travel_style'] ?? ''));
        $styleMatches = ($travel_style !== '' && mb_strtolower($ruleStyle, 'UTF-8') === mb_strtolower($travel_style, 'UTF-8'));
        if (!$styleMatches && $category_id > 0) {
            $catStmt = mysqli_prepare($conn, "SELECT name FROM categories WHERE id = ? LIMIT 1");
            if ($catStmt) {
                mysqli_stmt_bind_param($catStmt, 'i', $category_id);
                mysqli_stmt_execute($catStmt);
                $catRes = mysqli_stmt_get_result($catStmt);
                $catRow = $catRes ? mysqli_fetch_assoc($catRes) : null;
                mysqli_stmt_close($catStmt);
                if ($catRow && mb_strtolower(trim((string)$catRow['name']), 'UTF-8') === mb_strtolower($ruleStyle, 'UTF-8')) {
                    $styleMatches = true;
                }
            }
        }
        $budgetMatches = (($rule['min_budget'] === null || $rule['min_budget'] === '' || $budget_total >= (int)$rule['min_budget'])
            && ($rule['max_budget'] === null || $rule['max_budget'] === '' || (int)$rule['max_budget'] <= 0 || $budget_total <= (int)$rule['max_budget']));
        $peopleMatches = ($rule['max_people'] === null || $rule['max_people'] === '' || (int)$rule['max_people'] <= 0 || $group_size <= (int)$rule['max_people']);
        $daysMatch = ($rule['min_days'] === null || $rule['min_days'] === '' || (int)$rule['min_days'] <= 0 || $days >= (int)$rule['min_days']);
        $ruleScore = ($rule['score'] !== null && $rule['score'] !== '') ? (int)$rule['score'] : (int)($rule['base_score'] ?? 0);
        $rule['effective_score'] = $ruleScore;
        $rule['style_matches'] = $styleMatches;
        $rule['budget_matches'] = $budgetMatches;
        $rule['people_matches'] = $peopleMatches;
        $rule['days_match'] = $daysMatch;
        $rule['is_matched'] = ($styleMatches && $budgetMatches && $peopleMatches && $daysMatch);
        if ($rule['is_matched']) $activeRules[] = $rule;
    }
    mysqli_free_result($rulesResult);
}

// เลือกกฎที่ตรงและมีคะแนนสูงสุดไว้แสดง/ใช้อธิบายผล
$matchedRule = null;
foreach ($activeRules as $rule) {
    if ($matchedRule === null || $rule['effective_score'] > $matchedRule['effective_score']) $matchedRule = $rule;
}
$ruleScore = $matchedRule ? (int)$matchedRule['effective_score'] : 0;
$ruleName = $matchedRule ? (string)$matchedRule['rule_name'] : '';

/* =========================================================
   4. MAIN RECOMMENDATION
   เงื่อนไขแข็ง: จังหวัด (ถ้าระบุ), หมวดหมู่ (ถ้าระบุ),
   และราคาสูงสุดต่อคนไม่เกินงบต่อคน
   การจัดอันดับ: กฎที่ตรงก่อน -> popularity_score -> id
========================================================= */
$recommendedPlaces = [];
$exclude_ids = [0];
$where = [];
$params = [];
$types = '';

if ($clean_dest !== '') {
    $where[] = "p.province LIKE CONCAT('%', ?, '%')";
    $params[] = $clean_dest;
    $types .= 's';
}
if ($category_id > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $category_id;
    $types .= 'i';
}
// ใช้ max_price เป็นราคาสูงสุดต่อคนเพื่อไม่ให้แนะนำเกินงบ
// max_price = 0 จะถูกตีความเป็นราคาไม่เกิน min_price ตามโครงสร้างเดิม
$where[] = "(CASE WHEN COALESCE(p.max_price, 0) > 0 THEN p.max_price ELSE COALESCE(p.min_price, 0) END) <= ?";
$params[] = $budget_per_person;
$types .= 'i';

$whereSql = implode(" AND ", $where);
$mainSql = "SELECT p.*, c.name AS category_name,
    COALESCE((
        SELECT MAX(COALESCE(r.score, r.base_score, 0))
        FROM rules r
        WHERE (
            (NULLIF(?, '') IS NOT NULL AND LOWER(TRIM(r.travel_style)) = LOWER(TRIM(?)))
            OR LOWER(TRIM(r.travel_style)) = LOWER(TRIM(c.name))
        )
        AND (r.min_budget IS NULL OR r.min_budget <= ?)
        AND (r.max_budget IS NULL OR r.max_budget <= 0 OR r.max_budget >= ?)
        AND (r.max_people IS NULL OR r.max_people <= 0 OR r.max_people >= ?)
        AND (r.min_days IS NULL OR r.min_days <= 0 OR r.min_days <= ?)
    ), 0) AS rule_score
    FROM places p
    INNER JOIN categories c ON p.category_id = c.id
    WHERE $whereSql
    ORDER BY rule_score DESC, COALESCE(p.popularity_score, 0) DESC, p.id ASC
    LIMIT ?";

// Parameters in the correlated rule scoring expression come first.
$mainParams = [$travel_style, $travel_style, $budget_total, $budget_total, $group_size, $days];
$mainTypes = 'ssiiii';
$mainParams = array_merge($mainParams, $params, [$limit]);
$mainTypes .= $types . 'i';

$stmt = mysqli_prepare($conn, $mainSql);
if ($stmt) {
    $bind = [$mainTypes];
    foreach ($mainParams as $key => $value) $bind[] = &$mainParams[$key];
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (mysqli_stmt_execute($stmt)) {
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $recommendedPlaces[] = $row;
                $exclude_ids[] = (int)$row['id'];
            }
        }
    }
    mysqli_stmt_close($stmt);
}
$num_rows = count($recommendedPlaces);

/* =========================================================
   5. SAVE RECOMMENDATION LOG
========================================================= */
$user_id = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
if (!empty($recommendedPlaces)) {
    $insert = mysqli_prepare($conn, "INSERT INTO recommendation_logs (user_id, place_id, style_name) VALUES (?, ?, ?)");
    if ($insert) {
        foreach ($recommendedPlaces as $row) {
            $place_id = (int)$row['id'];
            $style_name = (string)$row['category_name'];
            mysqli_stmt_bind_param($insert, 'iis', $user_id, $place_id, $style_name);
            mysqli_stmt_execute($insert);
        }
        mysqli_stmt_close($insert);
    }
}

/* =========================================================
   6. EXTRA RECOMMENDATION
   สถานที่เพิ่มเติมไม่ผ่านตัวกรองงบประมาณเสมอไป จึงติดป้ายเตือน
========================================================= */
$extraPlaces = [];
function bindDynamicParams($stmt, $types, &$params) {
    $bind = [$types];
    foreach ($params as $key => &$value) $bind[] = &$value;
    return call_user_func_array([$stmt, 'bind_param'], $bind);
}
if (empty($exclude_ids)) $exclude_ids = [0];
$in_placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));
$exclude_types = str_repeat('i', count($exclude_ids));

if ($clean_dest !== '' && $category_id > 0) {
    $extraSql = "SELECT p.*, c.name AS category_name FROM places p INNER JOIN categories c ON p.category_id = c.id
        WHERE p.province LIKE CONCAT('%', ?, '%') AND p.category_id = ? AND p.id NOT IN ($in_placeholders)
        ORDER BY COALESCE(p.popularity_score, 0) DESC, p.id ASC LIMIT 3";
    $extraParams = array_merge([$clean_dest, $category_id], $exclude_ids);
    $extraTypes = 'si' . $exclude_types;
} elseif ($clean_dest !== '') {
    $extraSql = "SELECT p.*, c.name AS category_name FROM places p INNER JOIN categories c ON p.category_id = c.id
        WHERE p.province LIKE CONCAT('%', ?, '%') AND p.id NOT IN ($in_placeholders)
        ORDER BY COALESCE(p.popularity_score, 0) DESC, p.id ASC LIMIT 3";
    $extraParams = array_merge([$clean_dest], $exclude_ids);
    $extraTypes = 's' . $exclude_types;
} elseif ($category_id > 0) {
    $extraSql = "SELECT p.*, c.name AS category_name FROM places p INNER JOIN categories c ON p.category_id = c.id
        WHERE p.category_id = ? AND p.id NOT IN ($in_placeholders)
        ORDER BY COALESCE(p.popularity_score, 0) DESC, p.id ASC LIMIT 3";
    $extraParams = array_merge([$category_id], $exclude_ids);
    $extraTypes = 'i' . $exclude_types;
} else {
    $extraSql = "SELECT p.*, c.name AS category_name FROM places p INNER JOIN categories c ON p.category_id = c.id
        WHERE p.id NOT IN ($in_placeholders)
        ORDER BY COALESCE(p.popularity_score, 0) DESC, p.id ASC LIMIT 3";
    $extraParams = $exclude_ids;
    $extraTypes = $exclude_types;
}
$extraStmt = mysqli_prepare($conn, $extraSql);
if ($extraStmt) {
    bindDynamicParams($extraStmt, $extraTypes, $extraParams);
    if (mysqli_stmt_execute($extraStmt)) {
        $extraRes = mysqli_stmt_get_result($extraStmt);
        if ($extraRes) while ($extraRow = mysqli_fetch_assoc($extraRes)) $extraPlaces[] = $extraRow;
    }
    mysqli_stmt_close($extraStmt);
}
?>

<!DOCTYPE html>
<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ผลลัพธ์แนะนำสถานที่ท่องเที่ยว</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    >

    <style>

        .line-clamp-1 {
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
        }

        .line-clamp-3 {
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }

    </style>

</head>


<body class="bg-gray-100 text-gray-800 min-h-screen flex flex-col">


<?php

$page_title = 'ผลลัพธ์แนะนำสถานที่';

include __DIR__ . '/includes/nav.php';

?>


<main class="flex-1 w-full max-w-7xl mx-auto px-6 py-10">


<?php if ($num_rows == 0): ?>


<!-- NO RESULT -->

<div class="max-w-2xl mx-auto bg-white rounded-3xl shadow-lg p-10 text-center">

    <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">

        <i class="fa-regular fa-face-sad-tear text-4xl text-gray-400"></i>

    </div>


    <h2 class="text-3xl font-bold mb-4">
        ไม่พบสถานที่
    </h2>


    <p class="text-gray-500 leading-8 mb-8">

        ไม่พบสถานที่ที่ตรงกับเงื่อนไขค่าใช้จ่าย
        หมวดหมู่ หรือจังหวัดที่เลือก

        <br>

        ลองเพิ่มงบประมาณ หรือเปลี่ยนจังหวัดปลายทางแล้วค้นหาใหม่อีกครั้ง

    </p>


    <a
        href="recommend.php"
        class="inline-flex items-center gap-2 bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-xl font-medium transition"
    >

        <i class="fa-solid fa-arrow-rotate-left"></i>

        ค้นหาใหม่

    </a>

</div>


<?php else: ?>


<!-- =====================================================
     HEADER
===================================================== -->

<div class="bg-white rounded-3xl shadow p-6 mb-8">

    <div class="flex flex-wrap justify-between items-center gap-5">

        <div>

            <h1 class="text-2xl font-bold mb-2">

                <i class="fa-solid fa-location-dot text-blue-500"></i>

                สถานที่แนะนำสำหรับคุณ

            </h1>


            <p class="text-gray-500 text-sm flex flex-wrap gap-2 items-center">

                <span>
                    ระบบใช้กฎการแนะนำร่วมกับหมวดหมู่ จังหวัด งบต่อคน และคะแนนความนิยม
                </span>

                <?php if (!empty($ruleName)): ?>
                    <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full text-xs font-semibold">
                        กฎที่ใช้: <?= htmlspecialchars($ruleName) ?> (คะแนน <?= (int)$ruleScore ?>)
                    </span>
                <?php else: ?>

                <?php endif; ?>

                <?php if (!empty($start_province)): ?>

                    <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">

                        📍 จุดเริ่มต้น:
                        <?= htmlspecialchars($start_province) ?>

                    </span>

                <?php endif; ?>


                <?php if (!empty($destination_province)): ?>

                    <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-semibold">

                        🎯 ปลายทาง:
                        <?= htmlspecialchars($destination_province) ?>

                    </span>

                <?php endif; ?>

            </p>

        </div>


        <a
            href="recommend.php"
            class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-3 rounded-xl font-medium transition"
        >

            <i class="fa-solid fa-magnifying-glass"></i>

            ค้นหาใหม่

        </a>

    </div>

</div>


<!-- =====================================================
     MAIN RECOMMEND
===================================================== -->

<div class="mb-12">

    <div class="flex flex-col items-center justify-center gap-2 mb-12">

        <div class="w-12 h-12 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center mb-3">

            <i class="fa-solid fa-star"></i>

        </div>


        <h2 class="text-2xl font-bold">

            สถานที่ที่เหมาะกับเงื่อนไขของคุณ
            (พบ <?= $num_rows ?> แห่ง)

        </h2>


        <p class="text-sm text-gray-500">

            คัดกรองตามกฎ จังหวัด หมวดหมู่ และงบต่อคน จากนั้นเรียงสถานที่จากใกล้ไปไกลตามจุดเริ่มต้นที่เลือก

        </p>
        <p id="distanceSortNotice" class="text-sm text-emerald-700 mt-2" aria-live="polite">
            กำลังตรวจสอบตำแหน่งของคุณเพื่อเรียงสถานที่จากใกล้ไปไกล...
        </p>

    </div>


    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">


    <?php foreach ($recommendedPlaces as $row): ?>

    <?php

    $imagePath = 'images/no-image.jpg';

    if (!empty($row['cover_image'])) {

        $dbImage = trim($row['cover_image']);

        $fullPath = (
            strpos($dbImage, 'uploads/') === 0
        )
            ? $dbImage
            : 'uploads/' . $dbImage;

        if (file_exists(__DIR__ . '/' . $fullPath)) {

            $imagePath = $fullPath;
        }
    }


    $recommend_tags = [];


    if (
        $category_id > 0 &&
        (int)$row['category_id'] === $category_id
    ) {

        $recommend_tags[] = 'ตรงกับสไตล์ที่เลือก';
    }


    if (
        !empty($clean_dest) &&
        mb_strpos(
            $row['province'],
            $clean_dest
        ) !== false
    ) {

        $recommend_tags[] = 'อยู่ในจังหวัดที่เลือก';
    }


    if (
        (int)($row['popularity_score'] ?? 0) >= 20
    ) {

        $recommend_tags[] = 'สถานที่ยอดนิยม';
    }


    $minPrice = (float)($row['min_price'] ?? 0);

    $maxPrice = (float)($row['max_price'] ?? 0);

    $entryFee = (float)($row['entry_fee'] ?? 0);


    $calculationPrice = $minPrice;

    if (
        $calculationPrice <= 0 &&
        $entryFee > 0
    ) {

        $calculationPrice = $entryFee;
    }

    ?>


    <!-- CARD -->

    <div
        class="place-card group relative flex flex-col justify-between bg-white rounded-3xl overflow-hidden shadow-lg hover:shadow-2xl hover:-translate-y-1 transition duration-300"
        data-place-lat="<?= htmlspecialchars((string)($row['latitude'] ?? '')) ?>"
        data-place-lng="<?= htmlspecialchars((string)($row['longitude'] ?? '')) ?>"
        data-place-name="<?= htmlspecialchars((string)($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
    >


        <!-- SELECT BUTTON -->

        <div class="absolute top-4 right-4 z-20">

            <button
                type="button"

                class="select-place inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-semibold text-blue-600 bg-white/90 backdrop-blur-md border-2 border-blue-500 rounded-xl shadow-sm hover:bg-blue-600 hover:text-white hover:shadow-md active:scale-95 transition-all duration-200 cursor-pointer"

                data-id="<?= (int)$row['id'] ?>"

                data-name="<?= htmlspecialchars($row['name']) ?>"

                data-cost="<?= $calculationPrice ?>"

                data-min-price="<?= $minPrice ?>"

                data-max-price="<?= $maxPrice ?>"

                data-entry-fee="<?= $entryFee ?>"

                data-province="<?= htmlspecialchars($row['province']) ?>"

                data-lat="<?= htmlspecialchars($row['latitude'] ?? '') ?>"

                data-lng="<?= htmlspecialchars($row['longitude'] ?? '') ?>"
            >

                <span class="text-base">☐</span>

                <span>เลือก</span>

            </button>

        </div>


        <a
            href="place.php?id=<?= (int)$row['id'] ?>"
            class="block flex-1"
        >


            <div class="relative overflow-hidden">

                <img
                    src="<?= htmlspecialchars($imagePath) ?>"

                    alt="<?= htmlspecialchars($row['name']) ?>"

                    class="w-full h-64 object-cover bg-gray-200"

                    loading="lazy"
                >

            </div>


            <div class="p-6">


                <div class="mb-3">

                    <span class="bg-blue-50 text-blue-700 text-xs px-3 py-1 rounded-full font-medium">

                        <?= htmlspecialchars($row['category_name']) ?>

                    </span>

                </div>


                <h2
                    class="text-xl font-bold mb-2 line-clamp-1 group-hover:text-blue-600 transition"
                >

                    <?= htmlspecialchars($row['name']) ?>

                </h2>


                <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">

                    <i class="fa-solid fa-location-dot text-red-500"></i>

                    <?= htmlspecialchars($row['province']) ?>

                </div>

                <!-- ระยะทางจากจุดเริ่มต้น/GPS จะแสดงตรงนี้ในการ์ดแต่ละใบ -->
                <div class="distance-from-user inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2 mb-3" aria-live="polite">
                    <i class="fa-solid fa-route"></i>
                    <span>กำลังคำนวณระยะทางจากจุดเริ่มต้น...</span>
                </div>

                <div class="flex items-center gap-2 text-sm text-emerald-600 font-semibold mb-3">

                    <i class="fa-solid fa-ticket text-emerald-500"></i>


                    <?php

                    if (
                        $minPrice == 0 &&
                        $maxPrice == 0
                    ) {

                        echo "เข้าชมฟรี";

                    } elseif (
                        $minPrice == $maxPrice ||
                        $maxPrice == 0
                    ) {

                        echo number_format($minPrice) . " บาท/คน";

                    } else {

                        echo number_format($minPrice)
                            . " - "
                            . number_format($maxPrice)
                            . " บาท/คน";
                    }

                    ?>

                </div>


                <p
                    class="text-sm text-gray-600 leading-6 line-clamp-3 mb-4"
                >

                    <?= htmlspecialchars($row['description']) ?>

                </p>


                <div class="flex flex-wrap gap-2">

                    <?php foreach ($recommend_tags as $tag): ?>

                        <span class="bg-gray-100 text-gray-600 text-xs px-2.5 py-1 rounded-md">

                            <?= htmlspecialchars($tag) ?>

                        </span>

                    <?php endforeach; ?>

                </div>


            </div>

        </a>

    </div>


    <?php endforeach; ?>


    </div>

</div>


<!-- =====================================================
     CALCULATE BUTTON
===================================================== -->

<div class="flex justify-center mb-10">

    <button
        type="button"
        id="calculateBtn"
        class="bg-blue-600 hover:bg-blue-700 text-white text-xl font-bold px-10 py-5 rounded-2xl shadow-xl transition duration-300 hover:scale-105 cursor-pointer flex items-center gap-3"
    >

        <i class="fa-solid fa-calculator text-2xl"></i>

        <span>
            คำนวณค่าใช้จ่ายและเส้นทาง
        </span>

    </button>

</div>


<!-- =====================================================
     EXTRA RECOMMENDATION
===================================================== -->

<?php if (!empty($extraPlaces)): ?>

<div class="mt-16 border-t pt-10">


    <div class="flex flex-col items-center justify-center gap-2 mb-8 text-center">

        <div class="w-12 h-12 rounded-2xl bg-orange-100 text-orange-600 flex items-center justify-center mb-1">

            <i class="fa-solid fa-compass text-xl"></i>

        </div>


        <h2 class="text-2xl font-bold">

            สถานที่แนะนำเพิ่มเติม
            <?= !empty($destination_province)
                ? 'ในจังหวัด' . htmlspecialchars($destination_province)
                : ''
            ?>

        </h2>


        <p class="text-sm text-gray-500">

            ตัวเลือกเพิ่มเติมในจังหวัดและหมวดหมู่เดียวกัน
            <span class="text-orange-600 font-medium">
                (ราคาอาจเกินงบประมาณที่กำหนด)
            </span>

        </p>

    </div>


    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">


    <?php foreach ($extraPlaces as $extra): ?>

    <?php

    $extraImage = 'images/no-image.jpg';

    if (!empty($extra['cover_image'])) {

        $dbImage = trim($extra['cover_image']);

        $fullPath = (
            strpos($dbImage, 'uploads/') === 0
        )
            ? $dbImage
            : 'uploads/' . $dbImage;

        if (file_exists(__DIR__ . '/' . $fullPath)) {

            $extraImage = $fullPath;
        }
    }


    $eMinPrice = (float)($extra['min_price'] ?? 0);

    $eMaxPrice = (float)($extra['max_price'] ?? 0);

    ?>


    <a
        href="place.php?id=<?= (int)$extra['id'] ?>"

        class="group block bg-white rounded-3xl overflow-hidden shadow-lg hover:shadow-2xl hover:-translate-y-1 transition duration-300"
    >


        <img
            src="<?= htmlspecialchars($extraImage) ?>"

            alt="<?= htmlspecialchars($extra['name']) ?>"

            class="w-full h-56 object-cover bg-gray-200"

            loading="lazy"
        >


        <div class="p-6">


            <span class="bg-orange-50 text-orange-600 text-xs px-3 py-1 rounded-full font-medium">

                <?= htmlspecialchars($extra['category_name']) ?>

            </span>


            <h2
                class="text-lg font-bold mt-3 mb-2 line-clamp-1 group-hover:text-orange-600 transition"
            >

                <?= htmlspecialchars($extra['name']) ?>

            </h2>


            <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">

                <i class="fa-solid fa-location-dot text-gray-400"></i>

                <?= htmlspecialchars($extra['province']) ?>

            </div>


            <div class="flex items-center gap-2 text-xs text-emerald-600 font-semibold mb-3">

                <i class="fa-solid fa-ticket text-emerald-500"></i>


                <?php

                if (
                    $eMinPrice == 0 &&
                    $eMaxPrice == 0
                ) {

                    echo "เข้าชมฟรี";

                } elseif (
                    $eMinPrice == $eMaxPrice ||
                    $eMaxPrice == 0
                ) {

                    echo number_format($eMinPrice)
                        . " บาท/คน";

                } else {

                    echo number_format($eMinPrice)
                        . " - "
                        . number_format($eMaxPrice)
                        . " บาท/คน";
                }

                ?>

            </div>


            <p class="text-sm text-gray-600 leading-6 line-clamp-3">

                <?= htmlspecialchars($extra['description']) ?>

            </p>


        </div>

    </a>


    <?php endforeach; ?>


    </div>

</div>

<?php endif; ?>


<?php endif; ?>


</main>


<!-- =====================================================
     SUMMARY MODAL
===================================================== -->

<div
    id="summaryModal"
    class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-sm overflow-y-auto"
>

    <div class="flex items-center justify-center min-h-screen p-4">

        <div
            class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl p-6 sm:p-8 relative"
        >

            <h2 class="text-2xl font-bold mb-4 flex items-center gap-2 text-blue-600">

                <i class="fa-solid fa-route"></i>

                สรุปการเดินทางและงบประมาณ

            </h2>


            <div
                class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-xl text-blue-800 text-sm flex items-center gap-2"
            >

                <i class="fa-solid fa-location-crosshairs text-blue-600 text-base"></i>

                <span>

                    <strong>จุดเริ่มต้นของคุณ:</strong>

                    <span id="startLocationText">

                        <?= !empty($start_province)
                            ? htmlspecialchars($start_province)
                            : 'ตำแหน่งปัจจุบัน'
                        ?>

                    </span>

                </span>

            </div>


            <div
                class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 bg-gray-50 p-4 rounded-2xl border border-gray-100"
            >

                <div class="text-center">

                    <span class="text-xs text-gray-500 block">
                        งบประมาณรวม
                    </span>

                    <span class="text-lg font-bold text-gray-800">

                        <?= number_format($budget_total) ?>

                        บาท

                    </span>

                </div>


                <div class="text-center">

                    <span class="text-xs text-gray-500 block">
                        ค่าเข้าสถานที่รวม
                    </span>

                    <span
                        id="popupTotal"
                        class="text-lg font-bold text-blue-600"
                    >
                        0
                    </span>

                    บาท

                </div>


                <div class="text-center">

                    <span class="text-xs text-gray-500 block">
                        คงเหลือ / ส่วนต่าง
                    </span>

                    <span
                        id="popupBalance"
                        class="text-lg font-bold"
                    >
                        0 บาท
                    </span>

                </div>

            </div>


            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4 text-center text-xs">

                <div class="bg-blue-50 border border-blue-100 rounded-xl p-3">

                    <span class="block text-gray-500">
                        งบต่อคน
                    </span>

                    <strong class="text-blue-700">

                        <?= number_format($budget_per_person, 2) ?>

                        บาท

                    </strong>

                </div>


                <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-3">

                    <span class="block text-gray-500">
                        งบต่อคนต่อวัน
                    </span>

                    <strong class="text-indigo-700">

                        <?= number_format($budget_per_person_per_day, 2) ?>

                        บาท

                    </strong>

                </div>

            </div>


            <p class="text-xs text-gray-500 text-center mb-4">

                * ค่าใช้จ่ายด้านล่างคำนวณจากราคาต่ำสุดของสถานที่
                × จำนวนผู้เดินทาง
                โดยเป็นค่าใช้จ่ายเฉพาะสถานที่ที่เลือก
                ไม่รวมค่าอาหาร ที่พัก การเดินทาง
                และค่าใช้จ่ายส่วนบุคคล

            </p>


            <div
                id="popupBudgetStatus"
                class="mb-4 text-center font-medium py-2 px-4 rounded-xl"
            ></div>


            <div class="mb-4">

                <h3 class="text-sm font-semibold text-gray-600 mb-2">

                    สถานที่ที่คุณเลือก:

                </h3>


                <ul
                    id="selectedPlacesList"
                    class="text-sm text-gray-700 space-y-1 max-h-60 overflow-y-auto bg-gray-50 p-3 rounded-xl border border-gray-200"
                >
                </ul>

            </div>


            <div class="mb-6 rounded-2xl overflow-hidden shadow-inner border border-gray-200">

                <iframe
                    id="googleMapIframe"
                    width="100%"
                    height="250"
                    style="border:0;"
                    loading="lazy"
                    allowfullscreen
                    src=""
                >
                </iframe>

            </div>


            <div class="flex justify-between items-center gap-3">

                <a
                    id="openGoogleMapsBtn"
                    href="#"
                    target="_blank"
                    class="px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl transition font-medium text-sm inline-flex items-center gap-2"
                >

                    <i class="fa-solid fa-map-location-dot"></i>

                    ดูเส้นทางบน Google Maps

                </a>


                <button
                    id="closePopup"
                    type="button"
                    class="px-6 py-2.5 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-xl transition font-medium text-sm"
                >

                    ปิด

                </button>

            </div>

        </div>

    </div>

</div>


<script>
/* =========================================================
   HAVERSINE DISTANCE
========================================================= */
function calculateDistance(lat1, lon1, lat2, lon2) {
    if (lat1 === null || lon1 === null || lat2 === null || lon2 === null) return 0;
    if (!Number.isFinite(lat1) || !Number.isFinite(lon1) || !Number.isFinite(lat2) || !Number.isFinite(lon2)) return 0;

    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

/* =========================================================
   PARSE PRICE
========================================================= */
function parseEntryFee(value) {
    if (value === null || value === undefined) return 0;
    const text = String(value).trim();
    const cleanText = text.replace(/,/g, '');
    const match = cleanText.match(/\d+(?:\.\d+)?/);
    return match ? (parseFloat(match[0]) || 0) : 0;
}

/* =========================================================
   DOM READY
========================================================= */
document.addEventListener("DOMContentLoaded", function () {

    const budget = <?= (float)($budget_total ?? 0) ?>;
    const groupSize = <?= (int)($group_size ?? 1) ?>;
    const startProvince = <?= json_encode($start_province ?? "", JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    // แยก "จุดเริ่มต้นที่ผู้ใช้เลือก" ออกจาก GPS จริงของอุปกรณ์
    // ถ้าเลือกจังหวัดเอง ห้ามใช้พิกัด GPS ปัจจุบันมาแทนจุดเริ่มต้น
    const isCurrentLocationStart =
        !startProvince ||
        /^(current_location|current location|ตำแหน่งปัจจุบัน|ตำแหน่งปัจจุบันของฉัน|gps)$/i.test(startProvince.trim());

    let userCurrentLat = (isCurrentLocationStart && <?= !empty($has_user_location) ? 'true' : 'false' ?>)
        ? <?= !empty($has_user_location) ? (float)$user_lat : 'null' ?> : null;
    let userCurrentLng = (isCurrentLocationStart && <?= !empty($has_user_location) ? 'true' : 'false' ?>)
        ? <?= !empty($has_user_location) ? (float)$user_lng : 'null' ?> : null;

    const routeOriginLabel = isCurrentLocationStart
        ? 'ตำแหน่งปัจจุบัน (GPS)'
        : startProvince.trim();

    // แปลงชื่อจังหวัด/สถานที่ที่ผู้ใช้กรอกให้เป็นพิกัด เพื่อใช้เรียงระยะทาง
    // ใช้ Nominatim (OpenStreetMap) โดยไม่ต้องใช้ Google Maps API key
    async function geocodeSelectedStart() {
        if (isCurrentLocationStart || !startProvince.trim()) return;

        try {
            const query = startProvince.trim() + ', Thailand';
            const response = await fetch(
                'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=th&q=' + encodeURIComponent(query),
                { headers: { 'Accept': 'application/json' } }
            );
            if (!response.ok) throw new Error('Geocoding request failed');

            const results = await response.json();
            if (results.length > 0) {
                const lat = Number(results[0].lat);
                const lng = Number(results[0].lon);
                if (Number.isFinite(lat) && Number.isFinite(lng)) {
                    userCurrentLat = lat;
                    userCurrentLng = lng;
                    sortPlaceCardsByDistance(userCurrentLat, userCurrentLng);
                    const heading = document.getElementById('distanceSortNotice');
                    if (heading) heading.textContent = 'ใช้จุดเริ่มต้นที่เลือก: ' + routeOriginLabel + ' และเรียงสถานที่จากใกล้ไปไกล';
                }
            } else {
                const heading = document.getElementById('distanceSortNotice');
                if (heading) heading.textContent = 'หาพิกัดของ "' + routeOriginLabel + '" ไม่พบ ใช้ชื่อจุดเริ่มต้นบน Google Maps แทน';
            }
        } catch (error) {
            console.warn('Geocoding จุดเริ่มต้นไม่สำเร็จ:', error);
            const heading = document.getElementById('distanceSortNotice');
            if (heading) heading.textContent = 'แปลงจุดเริ่มต้นเป็นพิกัดไม่สำเร็จ แต่ยังเปิดเส้นทางจาก "' + routeOriginLabel + '" บน Google Maps ได้';
        }
    }

    // เรียงการ์ดสถานที่จากใกล้ไปไกล โดยวัดระยะเส้นตรงจากตำแหน่งผู้ใช้
    // หากสถานที่ไม่มีพิกัด จะย้ายไปอยู่ท้ายรายการ และคงลำดับเดิมไว้
    function sortPlaceCardsByDistance(originLat, originLng) {
        if (!Number.isFinite(originLat) || !Number.isFinite(originLng)) return;

        const grid = document.querySelector('.place-card')?.parentElement;
        if (!grid) return;

        const cards = Array.from(grid.querySelectorAll('.place-card'));
        cards.forEach((card, index) => {
            card.dataset.originalOrder = card.dataset.originalOrder ?? String(index);
        });

        cards.sort((a, b) => {
            const aLat = Number(a.dataset.placeLat);
            const aLng = Number(a.dataset.placeLng);
            const bLat = Number(b.dataset.placeLat);
            const bLng = Number(b.dataset.placeLng);
            const aValid = a.dataset.placeLat !== '' && a.dataset.placeLng !== '' && Number.isFinite(aLat) && Number.isFinite(aLng);
            const bValid = b.dataset.placeLat !== '' && b.dataset.placeLng !== '' && Number.isFinite(bLat) && Number.isFinite(bLng);

            if (aValid && bValid) {
                const da = calculateDistance(originLat, originLng, aLat, aLng);
                const db = calculateDistance(originLat, originLng, bLat, bLng);
                return da - db || Number(a.dataset.originalOrder) - Number(b.dataset.originalOrder);
            }
            if (aValid) return -1;
            if (bValid) return 1;
            return Number(a.dataset.originalOrder) - Number(b.dataset.originalOrder);
        });

        cards.forEach((card, index) => {
            const distanceLabel = card.querySelector('.distance-from-user');
            const distanceText = distanceLabel ? distanceLabel.querySelector('span') : null;
            const lat = Number(card.dataset.placeLat);
            const lng = Number(card.dataset.placeLng);
            const hasCoordinates = card.dataset.placeLat !== '' && card.dataset.placeLng !== '' && Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180;

            if (hasCoordinates && distanceLabel) {
                const distance = calculateDistance(originLat, originLng, lat, lng);
                const message = `ระยะทาง ${distance.toFixed(1)} กิโลเมตร · ลำดับที่ ${index + 1}`;
                if (distanceText) {
                    distanceText.textContent = message;
                } else {
                    distanceLabel.textContent = message;
                }
                distanceLabel.classList.remove('text-amber-700', 'bg-amber-50', 'border-amber-100');
                distanceLabel.classList.add('text-emerald-700', 'bg-emerald-50', 'border-emerald-100');
            } else if (distanceLabel) {
                if (distanceText) distanceText.textContent = 'ไม่มีพิกัดสถานที่ จึงคำนวณระยะทางไม่ได้';
                else distanceLabel.textContent = 'ไม่มีพิกัดสถานที่ จึงคำนวณระยะทางไม่ได้';
                distanceLabel.classList.remove('text-emerald-700', 'bg-emerald-50', 'border-emerald-100');
                distanceLabel.classList.add('text-amber-700', 'bg-amber-50', 'border-amber-100');
            }
            grid.appendChild(card);
        });

        const heading = document.getElementById('distanceSortNotice');
        if (heading) heading.textContent = 'เรียงสถานที่จากใกล้ไปไกล โดยวัดระยะเส้นตรงจากจุดเริ่มต้นของคุณ';
    }

    const calculateBtn = document.getElementById("calculateBtn");
    const modal = document.getElementById("summaryModal");
    const closeBtn = document.getElementById("closePopup");

    let selectedPlaces = [];

    // ขอ GPS เฉพาะเมื่อผู้ใช้เลือก "ตำแหน่งปัจจุบัน" เท่านั้น
    // หากกรอกจังหวัดเอง เช่น กรุงเทพฯ จะไม่ให้ GPS ของนครสวรรค์มาแทนจุดเริ่มต้น
    if (isCurrentLocationStart && (userCurrentLat === null || userCurrentLng === null) && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function (position) {
                userCurrentLat = position.coords.latitude;
                userCurrentLng = position.coords.longitude;
                sortPlaceCardsByDistance(userCurrentLat, userCurrentLng);
                console.log('ใช้ GPS เป็นจุดเริ่มต้น:', userCurrentLat, userCurrentLng);
            },
            function (error) {
                console.warn('อ่าน GPS ไม่สำเร็จ:', error.message);
                const heading = document.getElementById('distanceSortNotice');
                if (heading) heading.textContent = 'ยังเข้าถึง GPS ไม่ได้ กรุณาอนุญาตตำแหน่ง หรือกลับไปเลือกจังหวัดเป็นจุดเริ่มต้น';
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    } else if (!isCurrentLocationStart) {
        // เลือกจังหวัด/สถานที่เอง: ใช้จังหวัดที่กรอก ไม่ใช่ GPS ปัจจุบัน
        geocodeSelectedStart();
    }

    if (isCurrentLocationStart && userCurrentLat !== null && userCurrentLng !== null) {
        sortPlaceCardsByDistance(userCurrentLat, userCurrentLng);
    }

    if (closeBtn && modal) {
        closeBtn.addEventListener("click", function () {
            modal.classList.add('hidden');
        });
    }

    /* =================================================
       SELECT PLACE
    ================================================= */
    document.querySelectorAll(".select-place").forEach(function (btn) {
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            e.stopPropagation();

            const minPrice = parseFloat(this.dataset.minPrice) || 0;
            const entryFee = parseEntryFee(this.dataset.entryFee);
            let calculationCost = minPrice > 0 ? minPrice : (entryFee > 0 ? entryFee : 0);

            const place = {
                id: this.dataset.id,
                name: this.dataset.name,
                province: this.dataset.province,
                cost: calculationCost,
                minPrice: minPrice,
                maxPrice: parseFloat(this.dataset.maxPrice) || 0,
                entryFee: entryFee,
                lat: parseFloat(this.dataset.lat) || null,
                lng: parseFloat(this.dataset.lng) || null
            };

            if (this.classList.contains("selected")) {
                this.classList.remove("selected", "bg-blue-600", "text-white");
                this.classList.add("bg-white/90", "text-blue-600");
                this.innerHTML = '<span class="text-base">☐</span> <span>เลือก</span>';
                selectedPlaces = selectedPlaces.filter(p => p.id != place.id);
            } else {
                this.classList.add("selected", "bg-blue-600", "text-white");
                this.classList.remove("bg-white/90", "text-blue-600");
                this.innerHTML = '<span class="text-base">☑</span> <span>เลือกแล้ว</span>';
                selectedPlaces.push(place);
            }
        });
    });

    /* =================================================
       OPTIMIZE ROUTE
    ================================================= */
    function optimizeRoute(places, startLat, startLng) {
        if (places.length <= 1) return [...places];

        const remaining = [...places];
        const route = [];
        let currentLat = startLat;
        let currentLng = startLng;

        while (remaining.length > 0) {
            let nearestIndex = -1;
            let nearestDistance = Infinity;

            remaining.forEach(function (place, index) {
                if (place.lat === null || place.lng === null) return;
                const distance = calculateDistance(currentLat, currentLng, place.lat, place.lng);
                if (distance < nearestDistance) {
                    nearestDistance = distance;
                    nearestIndex = index;
                }
            });

            if (nearestIndex === -1) {
                route.push(...remaining);
                break;
            }

            const nearestPlace = remaining.splice(nearestIndex, 1)[0];
            route.push(nearestPlace);
            currentLat = nearestPlace.lat;
            currentLng = nearestPlace.lng;
        }

        return route;
    }

    /* =================================================
       ROUTE DETAILS
    ================================================= */
    function calculateRouteDetails(routePlaces) {
        const details = [];
        let totalDistance = 0;

        if (userCurrentLat !== null && userCurrentLng !== null && routePlaces.length > 0) {
            const first = routePlaces[0];
            if (first.lat !== null && first.lng !== null) {
                const distance = calculateDistance(userCurrentLat, userCurrentLng, first.lat, first.lng);
                details.push({
                    from: routeOriginLabel,
                    to: first.name,
                    distance: distance
                });
                totalDistance += distance;
            }
        }

        for (let i = 0; i < routePlaces.length - 1; i++) {
            const p1 = routePlaces[i];
            const p2 = routePlaces[i + 1];
            if (p1.lat !== null && p1.lng !== null && p2.lat !== null && p2.lng !== null) {
                const distance = calculateDistance(p1.lat, p1.lng, p2.lat, p2.lng);
                details.push({
                    from: p1.name,
                    to: p2.name,
                    distance: distance
                });
                totalDistance += distance;
            }
        }

        return { details: details, totalDistance: totalDistance };
    }

    /* =================================================
       BUILD MAP + SUMMARY
    ================================================= */
    function buildAndShowMap() {
        let totalCost = 0;
        let listHtml = '';
        let routePlaces = [...selectedPlaces];
        let routeOptimized = false;

        if (userCurrentLat !== null && userCurrentLng !== null && selectedPlaces.length > 0) {
            routePlaces = optimizeRoute(selectedPlaces, userCurrentLat, userCurrentLng);
            routeOptimized = true;
        }

        routePlaces.forEach(function (p, index) {
            const placeTotalCost = p.cost * groupSize;
            totalCost += placeTotalCost;

            let priceText = '';
            if (p.minPrice === 0 && p.maxPrice === 0) {
                priceText = 'ฟรี';
            } else if (p.minPrice === p.maxPrice || p.maxPrice === 0) {
                priceText = `${p.minPrice.toLocaleString()} บาท/คน`;
            } else {
                priceText = `${p.minPrice.toLocaleString()} - ${p.maxPrice.toLocaleString()} บาท/คน`;
            }

            listHtml += `
                <li class="py-3 border-b border-gray-100 last:border-0">
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-9 h-9 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold shadow-sm">
                            ${index + 1}
                        </div>
                        <div class="flex-1 min-w-0">
                            <strong class="text-gray-800 block">${p.name}</strong>
                            <span class="block text-xs text-gray-500 mt-1">
                                <i class="fa-solid fa-location-dot text-red-400"></i> ${p.province}
                            </span>
                            <span class="block text-xs text-emerald-600 mt-1 font-medium">
                                ค่าเข้า: ${priceText}
                            </span>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <span class="font-semibold text-gray-700">${placeTotalCost.toLocaleString()} บาท</span>
                            <small class="block text-xs text-gray-400">${p.cost.toLocaleString()} บาท/คน × ${groupSize} คน</small>
                        </div>
                    </div>
                </li>
            `;
        });

        const routeResult = calculateRouteDetails(routePlaces);
        const totalDistance = routeResult.totalDistance;
        const routeDetails = routeResult.details;
        const hasDist = routeDetails.length > 0 && totalDistance > 0;

        const popupTotal = document.getElementById('popupTotal');
        if (popupTotal) popupTotal.innerText = totalCost.toLocaleString();

        const selectedPlacesList = document.getElementById('selectedPlacesList');
        if (selectedPlacesList) selectedPlacesList.innerHTML = listHtml;

        const balance = budget - totalCost;
        const popupBalance = document.getElementById('popupBalance');
        const popupStatus = document.getElementById('popupBudgetStatus');

        let statusMessage = '';

        if (popupBalance && popupStatus) {
            if (balance >= 0) {
                popupBalance.innerText = '+' + balance.toLocaleString() + ' บาท';
                popupBalance.className = 'text-lg font-bold text-green-600';
                statusMessage = `✅ งบประมาณเพียงพอสำหรับค่าเข้าสถานที่ที่เลือก (คงเหลือ ${balance.toLocaleString()} บาท)`;
                popupStatus.className = 'mb-4 text-center font-medium py-2 px-4 rounded-xl bg-green-100 text-green-700 border border-green-200';
            } else {
                popupBalance.innerText = balance.toLocaleString() + ' บาท';
                popupBalance.className = 'text-lg font-bold text-red-600';
                statusMessage = `❌ งบประมาณไม่พอสำหรับค่าเข้าสถานที่ที่เลือก (ขาดอีก ${Math.abs(balance).toLocaleString()} บาท)`;
                popupStatus.className = 'mb-4 text-center font-medium py-2 px-4 rounded-xl bg-red-100 text-red-700 border border-red-200';
            }
        }

        if (routePlaces.length > 1 && popupStatus) {
            if (routeOptimized) {
                statusMessage += `<br><span class="text-sm text-blue-700 font-semibold mt-2 inline-block">ระบบจัดลำดับสถานที่ตามระยะทาง โดยเริ่มจากจุดที่ใกล้ที่สุด</span>`;
            } else {
                statusMessage += `<br><span class="text-xs text-gray-600 mt-1 inline-block">📍 สถานที่บางแห่งไม่มีพิกัด จึงไม่สามารถจัดลำดับจากระยะทางได้ทั้งหมด</span>`;
            }
        }

        if (hasDist && popupStatus) {
            statusMessage += `<br><span class="text-xs text-gray-600 mt-1 inline-block">🚗 ระยะทางโดยรวมประมาณ: <strong>${totalDistance.toFixed(1)} กม.</strong></span>`;
            if (totalDistance > 150) {
                statusMessage += `<br><span class="text-xs text-amber-600 font-semibold">⚠️ สถานที่อยู่ค่อนข้างไกลกัน อาจต้องใช้เวลาเดินทางเพิ่มขึ้น</span>`;
            }
        }

        if (routeDetails.length > 0 && popupStatus) {
            statusMessage += `<div class="mt-3 pt-3 border-t border-gray-200 text-left"><div class="text-xs font-bold text-gray-700 mb-2">📏 ระยะทางแต่ละช่วง</div>`;
            routeDetails.forEach(function (item) {
                statusMessage += `
                    <div class="flex items-center justify-between gap-2 text-xs text-gray-600 py-1">
                        <span class="truncate">${item.from} → ${item.to}</span>
                        <strong class="text-blue-600 whitespace-nowrap">${item.distance.toFixed(1)} กม.</strong>
                    </div>
                `;
            });
            statusMessage += `</div>`;
        }

        if (popupStatus) popupStatus.innerHTML = statusMessage;

        /* =================================================
           MAPS URL GENERATION (ปรับปรุงใหม่แก้ไขรูปโลก)
        ================================================= */
        // จุดเริ่มต้นบนแผนที่ต้องเป็นจุดที่ผู้ใช้เลือกก่อนเสมอ
        // ถ้า geocode สำเร็จ ใช้พิกัด; ถ้าไม่สำเร็จ ใช้ชื่อจังหวัดเป็น fallback
        let originParam = '';
        if (userCurrentLat !== null && userCurrentLng !== null) {
            originParam = `${userCurrentLat},${userCurrentLng}`;
        } else if (!isCurrentLocationStart && startProvince !== '' && startProvince !== null) {
            originParam = encodeURIComponent(startProvince.trim());
        }

        // แปลงพิกัดสถานที่
        const encodedDestinations = routePlaces.map(function (p) {
            if (p.lat && p.lng) {
                return `${p.lat},${p.lng}`;
            }
            return encodeURIComponent(`${p.name || ''} ${p.province || ''}`.trim());
        }).filter(item => item !== '');

        // 1. ลิงก์สำหรับปุ่ม "ดูเส้นทางบน Google Maps"
        let mapsDirectUrl = 'https://www.google.com/maps/dir/';
        if (originParam !== '') {
            mapsDirectUrl += originParam + '/';
        }
        mapsDirectUrl += encodedDestinations.join('/');

        // 2. ลิงก์สำหรับ iframe แผนที่
        let embedMapUrl = '';
        if (encodedDestinations.length > 0) {
            const destinationParam = encodedDestinations.join('+to:');
            
            if (originParam !== '') {
                embedMapUrl = `https://maps.google.com/maps?saddr=${originParam}&daddr=${destinationParam}&output=embed`;
            } else {
                // หากไม่มีจุดเริ่มต้น จะให้แผนที่โฟกัสสถานที่แรกไปตามลำดับทันที ป้องกันหน้าจอรูปโลก
                embedMapUrl = `https://maps.google.com/maps?daddr=${destinationParam}&output=embed`;
            }
        }

        const openMapsBtn = document.getElementById('openGoogleMapsBtn');
        if (openMapsBtn) openMapsBtn.href = mapsDirectUrl;

        const iframeMap = document.getElementById('googleMapIframe');
        if (iframeMap) iframeMap.src = embedMapUrl;

        if (modal) modal.classList.remove('hidden');
    }

    /* =================================================
       CALCULATE BUTTON
    ================================================= */
    if (calculateBtn) {
        calculateBtn.onclick = function () {
            if (selectedPlaces.length === 0) {
                alert("กรุณาเลือกสถานที่อย่างน้อย 1 แห่งก่อนคำนวณ");
                return;
            }

            const startLocText = document.getElementById("startLocationText");
            if (startLocText) {
                if (isCurrentLocationStart) {
                    startLocText.innerText = (userCurrentLat !== null && userCurrentLng !== null)
                        ? 'ตำแหน่งปัจจุบันของคุณ (GPS)'
                        : 'ตำแหน่งปัจจุบัน (ยังอ่าน GPS ไม่ได้)';
                } else {
                    startLocText.innerText = startProvince;
                }
            }

            buildAndShowMap();
        };
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>


</body>
</html>
