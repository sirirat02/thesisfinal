
<?php
session_start();
include 'db.php';

/* =========================================================
   1. CLEAR FILTERS
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['form_action'] ?? '') === 'clear'
) {
    unset($_SESSION['old_form'], $_SESSION['form_errors']);

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/* =========================================================
   2. HANDLE FORM SUBMIT
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $planning_days = max((int)($_POST['planning_days'] ?? 1), 1);
    $group_size = max((int)($_POST['group_size'] ?? 1), 1);
    $budget_total = max((int)($_POST['budget_total'] ?? 100), 100);

    $travel_style = trim($_POST['travel_style'] ?? '');
    $start_province = trim($_POST['start_province'] ?? '');
    $destination_province = trim($_POST['destination_province'] ?? '');

    $user_lat = trim($_POST['user_lat'] ?? '');
    $user_lng = trim($_POST['user_lng'] ?? '');

    if ($start_province !== 'CURRENT_LOCATION') {
        $user_lat = '';
        $user_lng = '';
    }

    /* VALIDATE */
    $errors = [];

    if (empty($_POST['planning_days']) || (int)$_POST['planning_days'] < 1) {
        $errors[] = 'จำนวนวันต้องมากกว่า 0';
    }

    if (empty($_POST['group_size']) || (int)$_POST['group_size'] < 1) {
        $errors[] = 'จำนวนคนต้องมากกว่า 0';
    }

    if (
        !isset($_POST['budget_total']) ||
        !is_numeric($_POST['budget_total']) ||
        (float)$_POST['budget_total'] < 100
    ) {
        $errors[] = 'งบประมาณต้องเริ่มต้น 100 บาทขึ้นไป';
    }

    if ($travel_style === '') {
        $errors[] = 'กรุณาเลือกสไตล์การท่องเที่ยว';
    }

    if ($start_province === '') {
        $errors[] = 'กรุณาเลือกจุดเริ่มต้นเดินทาง';
    }

    if (
        $start_province === 'CURRENT_LOCATION' &&
        ($user_lat === '' || $user_lng === '')
    ) {
        $errors[] = 'ไม่พบพิกัดตำแหน่งปัจจุบัน กรุณาอนุญาตสิทธิ์ GPS หรือเลือกจังหวัดแทน';
    }

    /* SAVE FORM ON ERROR */
    if (!empty($errors)) {

        $_SESSION['form_errors'] = $errors;

        $_SESSION['old_form'] = [
            'planning_days'        => $planning_days,
            'group_size'           => $group_size,
            'budget_total'         => $budget_total,
            'travel_style'         => $travel_style,
            'start_province'       => $start_province,
            'destination_province' => $destination_province,
            'user_lat'             => $user_lat,
            'user_lng'             => $user_lng
        ];

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    /* FIND CATEGORY */
    $category_id = null;

    $stmt = mysqli_prepare(
        $conn,
        'SELECT id FROM categories WHERE name LIKE ? LIMIT 1'
    );

    if (!$stmt) {
        die('ไม่สามารถค้นหาหมวดหมู่ได้');
    }

    $search_style = '%' . $travel_style . '%';

    mysqli_stmt_bind_param($stmt, 's', $search_style);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        $category_id = (int)$row['id'];
    }

    mysqli_stmt_close($stmt);

    /* CATEGORY NOT FOUND */
    if ($category_id === null) {

        $_SESSION['form_errors'] = [
            'ไม่พบหมวดหมู่สไตล์การท่องเที่ยวที่เลือกในระบบ'
        ];

        $_SESSION['old_form'] = [
            'planning_days'        => $planning_days,
            'group_size'           => $group_size,
            'budget_total'         => $budget_total,
            'travel_style'         => $travel_style,
            'start_province'       => $start_province,
            'destination_province' => $destination_province,
            'user_lat'             => $user_lat,
            'user_lng'             => $user_lng
        ];

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    /* SAVE SEARCH HISTORY */
    if (isset($_SESSION['user']['id'])) {

        $user_id = (int)$_SESSION['user']['id'];

        $sql = "
            INSERT INTO search_history
            (
                user_id,
                planning_days,
                group_size,
                budget_total,
                travel_style,
                category_id
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'iiiisi',
                $user_id,
                $planning_days,
                $group_size,
                $budget_total,
                $travel_style,
                $category_id
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    /* SAVE LAST FORM */
    $_SESSION['old_form'] = [
        'planning_days'        => $planning_days,
        'group_size'           => $group_size,
        'budget_total'         => $budget_total,
        'travel_style'         => $travel_style,
        'start_province'       => $start_province,
        'destination_province' => $destination_province,
        'user_lat'             => $user_lat,
        'user_lng'             => $user_lng
    ];

    /* REDIRECT TO RESULT */
    $display_start_province = $start_province;

    if ($start_province === 'CURRENT_LOCATION') {
        $display_start_province = 'ตำแหน่งปัจจุบัน';
    }

    header(
        'Location: result.php?' . http_build_query([
            'start_province'       => $display_start_province,
            'destination_province' => $destination_province,
            'category'             => $category_id,
            'budget_total'         => $budget_total,
            'group_size'           => $group_size,
            'days'                 => $planning_days,
            'user_lat'              => $user_lat,
            'user_lng'              => $user_lng
        ])
    );

    exit;
}

/* =========================================================
   3. OLD FORM DATA
========================================================= */
$old = $_SESSION['old_form'] ?? [];

if (
    !isset($old['budget_total']) ||
    (int)$old['budget_total'] < 100
) {
    $old['budget_total'] = 100;
}

/* TARGET PROVINCES */
$target_provinces = [
    'พะเยา',
    'เชียงใหม่',
    'เชียงราย',
    'ตาก',
    'แม่ฮ่องสอน'
];

/* ALL THAI PROVINCES */
$thai_provinces = [
    'กรุงเทพมหานคร','กระบี่','กาญจนบุรี','กาฬสินธุ์','กำแพงเพชร',
    'ขอนแก่น','จันทบุรี','ฉะเชิงเทรา','ชลบุรี','ชัยนาท',
    'ชัยภูมิ','ชุมพร','เชียงราย','เชียงใหม่','ตรัง',
    'ตราด','ตาก','นครนายก','นครปฐม','นครพนม',
    'นครราชสีมา','นครศรีธรรมราช','นครสวรรค์','นนทบุรี','นราธิวาส',
    'น่าน','บึงกาฬ','บุรีรัมย์','ปทุมธานี','ประจวบคีรีขันธ์',
    'ปราจีนบุรี','ปัตตานี','พระนครศรีอยุธยา','พังงา','พัทลุง',
    'พิจิตร','พิษณุโลก','เพชรบุรี','เพชรบูรณ์','แพร่',
    'พะเยา','ภูเก็ต','มหาสารคาม','มุกดาหาร','แม่ฮ่องสอน',
    'ยะลา','ยโสธร','ร้อยเอ็ด','ระนอง','ระยอง',
    'ราชบุรี','ลพบุรี','ลำปาง','ลำพูน','เลย',
    'ศรีสะเกษ','สกลนคร','สงขลา','สตูล','สมุทรปราการ',
    'สมุทรสงคราม','สมุทรสาคร','สระแก้ว','สระบุรี','สิงห์บุรี',
    'สุโขทัย','สุพรรณบุรี','สุราษฎร์ธานี','สุรินทร์','หนองคาย',
    'หนองบัวลำภู','อ่างทอง','อำนาจเจริญ','อุดรธานี','อุตรดิตถ์',
    'อุทัยธานี','อุบลราชธานี'
];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>แนะนำสถานที่ท่องเที่ยวภาคเหนือ</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    >
</head>

<body
    class="min-h-screen flex flex-col bg-cover bg-center bg-no-repeat"
    style="background-image: url('images/ThailandTravelNorthjpg.jpg');"
>

<?php
$page_title = 'แนะนำสถานที่ท่องเที่ยวภาคเหนือ';
$nav_transparent = true;
include __DIR__ . '/includes/nav.php';
?>

<main class="flex-1 flex items-center justify-center px-4 py-10">

    <div class="w-full max-w-lg bg-white/95 backdrop-blur-sm rounded-3xl shadow-2xl overflow-hidden">

        <!-- HEADER -->
        <div class="px-6 py-5 border-b text-center bg-blue-50">
            <h1 class="text-2xl font-bold text-blue-700">
                ✨ แนะนำสถานที่ท่องเที่ยวภาคเหนือ
            </h1>

            <p class="text-sm text-gray-600 mt-2">
                กรอกข้อมูลเพื่อให้ระบบวิเคราะห์แนวทางการท่องเที่ยว
            </p>
        </div>

        <!-- ERROR -->
        <?php if (!empty($_SESSION['form_errors'])): ?>
            <div class="mx-6 mt-5 bg-red-50 border border-red-200 text-red-700 rounded-xl p-4 text-sm">
                <ul class="list-disc pl-5 space-y-1">
                    <?php foreach ($_SESSION['form_errors'] as $error): ?>
                        <li>
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php unset($_SESSION['form_errors']); ?>
        <?php endif; ?>

        <!-- FORM -->
        <div class="px-6 py-5">

            <form method="post" id="travelForm" class="space-y-5 text-sm">

                <!-- SMALL CLEAR BUTTON: TOP RIGHT -->
                <div class="flex justify-end -mt-1 -mb-2">
                    <button
                        type="submit"
                        name="form_action"
                        value="clear"
                        formnovalidate
                        id="clearBtn"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5
                               text-xs font-medium text-gray-500
                               bg-gray-100 hover:bg-red-50 hover:text-red-600
                               border border-transparent hover:border-red-200
                               rounded-full transition duration-200"
                        title="ล้างตัวกรองทั้งหมด"
                    >
                        <i class="fa-solid fa-rotate-left"></i>
                        ล้างตัวกรอง
                    </button>
                </div>

                <!-- GPS -->
                <input
                    type="hidden"
                    name="user_lat"
                    id="user_lat"
                    value="<?= htmlspecialchars($old['user_lat'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >

                <input
                    type="hidden"
                    name="user_lng"
                    id="user_lng"
                    value="<?= htmlspecialchars($old['user_lng'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >

                <!-- START PROVINCE -->
                <div>
                    <label class="block mb-2 font-medium text-gray-700">
                        จุดเริ่มต้นเดินทาง / จังหวัดเริ่มต้น
                        <span class="text-red-500">*</span>
                    </label>

                    <select
                        name="start_province"
                        id="start_province"
                        required
                        class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-400 font-medium"
                    >
                        <option value="">-- เลือกจุดเริ่มต้นเดินทาง --</option>

                        <option
                            value="CURRENT_LOCATION"
                            <?= (($old['start_province'] ?? '') === 'CURRENT_LOCATION') ? 'selected' : '' ?>
                        >
                            📍 ตำแหน่งปัจจุบันของคุณ
                        </option>

                        <option disabled>────────── เลือกจังหวัด ──────────</option>

                        <?php foreach ($thai_provinces as $province): ?>
                            <option
                                value="<?= htmlspecialchars($province, ENT_QUOTES, 'UTF-8') ?>"
                                <?= (($old['start_province'] ?? '') === $province) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($province, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div
                        id="locationStatus"
                        class="mt-1.5 text-xs text-gray-500 hidden"
                    ></div>
                </div>

                <!-- DESTINATION -->
                <div>
                    <label class="block mb-1 font-medium text-gray-700">
                        จังหวัดที่ต้องการไปเที่ยว
                        <span class="text-xs text-gray-400 font-normal">(ไม่ระบุได้)</span>
                    </label>

                    <select
                        name="destination_province"
                        id="destination_province"
                        class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white"
                    >
                        <option value="">-- ทั้งหมด 5 จังหวัด --</option>

                        <?php foreach ($target_provinces as $province): ?>
                            <option
                                value="<?= htmlspecialchars($province, ENT_QUOTES, 'UTF-8') ?>"
                                <?= (($old['destination_province'] ?? '') === $province) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($province, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- DAYS -->
                <div>
                    <label class="block mb-2 font-medium text-gray-700">
                        เที่ยวกี่วัน
                    </label>

                    <input
                        type="number"
                        name="planning_days"
                        id="planning_days"
                        required
                        min="1"
                        value="<?= htmlspecialchars($old['planning_days'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-400"
                    >
                </div>

                <!-- GROUP SIZE -->
                <div>
                    <label class="block mb-2 font-medium text-gray-700">
                        เดินทางกี่คน
                    </label>

                    <input
                        type="number"
                        name="group_size"
                        id="group_size"
                        required
                        min="1"
                        value="<?= htmlspecialchars($old['group_size'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-400"
                    >
                </div>

                <!-- BUDGET -->
                <div>
                    <label class="block mb-2 font-medium text-gray-700">
                        ค่าใช้จ่าย (บาท) <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="number"
                        name="budget_total"
                        id="budget_total"
                        required
                        min="100"
                        step="1"
                        value="<?= htmlspecialchars($old['budget_total'] ?? 100, ENT_QUOTES, 'UTF-8') ?>"
                        class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-400"
                    >

                    <p class="mt-1.5 text-xs text-gray-500">
                        * กำหนดขั้นต่ำ 100 บาทขึ้นไป ค่าใช้จ่ายนี้ครอบคลุมเฉพาะสถานที่ที่เลือกไว้เท่านั้น
                        ไม่รวมค่าอาหาร ที่พัก การเดินทาง หรือค่าใช้จ่ายส่วนตัวอื่น ๆ
                    </p>
                </div>

                <!-- TRAVEL STYLE -->
                <div>
                    <label class="block mb-2 font-medium text-gray-700">
                        สไตล์การท่องเที่ยว
                    </label>

                    <select
                        name="travel_style"
                        id="travel_style"
                        required
                        class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-400"
                    >
                        <option value="">-- เลือกสไตล์การท่องเที่ยว --</option>

                        <?php foreach (['ธรรมชาติ', 'วัฒนธรรม', 'คาเฟ่', 'เมือง'] as $style): ?>
                            <option
                                value="<?= htmlspecialchars($style, ENT_QUOTES, 'UTF-8') ?>"
                                <?= (($old['travel_style'] ?? '') === $style) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($style, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- RECOMMEND BUTTON -->
                <button
                    type="submit"
                    name="form_action"
                    value="recommend"
                    id="submitBtn"
                    class="w-full bg-blue-500 hover:bg-blue-600 transition duration-200 text-white py-3 rounded-full font-bold text-base shadow-md"
                >
                    <i class="fa-solid fa-map-location-dot mr-2"></i>
                    แนะนำสถานที่
                </button>

            </form>
        </div>
    </div>
</main>

<!-- LOADING -->
<div
    id="loadingScreen"
    class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center"
>
    <div class="bg-white rounded-3xl px-10 py-8 shadow-2xl text-center max-w-sm w-full">
        <div class="flex justify-center mb-5">
            <div class="w-16 h-16 border-4 border-blue-200 border-t-blue-500 rounded-full animate-spin"></div>
        </div>

        <h2 class="text-xl font-bold text-blue-600 mb-2">
            กรุณารอสักครู่...
        </h2>

        <p class="text-gray-500 text-sm">
            ระบบกำลังค้นหาสถานที่ที่เหมาะกับคุณ
        </p>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('travelForm');
    const loadingScreen = document.getElementById('loadingScreen');
    const submitBtn = document.getElementById('submitBtn');

    const startProvinceSelect = document.getElementById('start_province');
    const latInput = document.getElementById('user_lat');
    const lngInput = document.getElementById('user_lng');
    const statusText = document.getElementById('locationStatus');
    const budgetInput = document.getElementById('budget_total');

    /* FETCH CURRENT LOCATION */
    function fetchLocation() {

        if (!navigator.geolocation) {
            statusText.textContent = 'เบราว์เซอร์ไม่รองรับ GPS';
            statusText.className =
                'mt-1.5 text-xs text-red-500 font-medium block';
            return;
        }

        statusText.textContent = 'กำลังค้นหาตำแหน่งปัจจุบัน...';
        statusText.className =
            'mt-1.5 text-xs text-blue-600 font-medium block';

        navigator.geolocation.getCurrentPosition(
            function (position) {

                if (startProvinceSelect.value !== 'CURRENT_LOCATION') {
                    return;
                }

                latInput.value = position.coords.latitude;
                lngInput.value = position.coords.longitude;

                statusText.textContent =
                    `ดึงตำแหน่งสำเร็จ (${position.coords.latitude.toFixed(4)}, ${position.coords.longitude.toFixed(4)})`;

                statusText.className =
                    'mt-1.5 text-xs text-green-600 font-medium block';
            },

            function (error) {

                console.warn('Geolocation error:', error);

                if (startProvinceSelect.value !== 'CURRENT_LOCATION') {
                    return;
                }

                statusText.textContent =
                    'ไม่สามารถดึงตำแหน่งได้ กรุณาอนุญาต GPS หรือเลือกจังหวัดแทน';

                statusText.className =
                    'mt-1.5 text-xs text-red-500 font-medium block';
            },

            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    }

    /* START PROVINCE CHANGE */
    startProvinceSelect.addEventListener('change', function () {

        if (this.value === 'CURRENT_LOCATION') {
            fetchLocation();
        } else {
            latInput.value = '';
            lngInput.value = '';

            statusText.textContent = '';
            statusText.className =
                'mt-1.5 text-xs text-gray-500 hidden';
        }
    });

    /* INITIAL GPS CHECK */
    if (startProvinceSelect.value === 'CURRENT_LOCATION') {
        fetchLocation();
    } else {
        latInput.value = '';
        lngInput.value = '';
    }

    /* HIDE LOADING WHEN PAGE RESTORED */
    window.addEventListener('pageshow', function () {
        loadingScreen.classList.add('hidden');
        submitBtn.disabled = false;
    });

    /* FORM SUBMIT */
    form.addEventListener('submit', function (e) {

        const clickedButton = e.submitter;

        // CLEAR: ไม่ตรวจสอบฟอร์ม GPS และไม่เปิดหน้ารอ
        if (clickedButton && clickedButton.value === 'clear') {
            loadingScreen.classList.add('hidden');
            return;
        }

        // ตรวจสอบงบประมาณ
        if (
            budgetInput.value === '' ||
            Number(budgetInput.value) < 100
        ) {
            e.preventDefault();
            alert('กรุณากรอกงบประมาณ 100 บาทขึ้นไป');
            budgetInput.focus();
            return;
        }

        // ล้างพิกัดหากไม่ได้เลือกตำแหน่งปัจจุบัน
        if (startProvinceSelect.value !== 'CURRENT_LOCATION') {
            latInput.value = '';
            lngInput.value = '';
        } else if (!latInput.value || !lngInput.value) {
            e.preventDefault();

            alert(
                'กรุณาอนุญาตสิทธิ์ GPS หรือเลือกจังหวัดเริ่มต้นแทน'
            );

            return;
        }

        loadingScreen.classList.remove('hidden');
        submitBtn.disabled = true;
    });
});
</script>

</body>
</html>
