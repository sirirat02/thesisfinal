<?php
session_start();
include __DIR__ . '/../db.php';

/* ===============================
   CHECK LOGIN
=============================== */
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

/* ===============================
   DELETE
=============================== */
if (isset($_GET['delete'])) {

    $id = (int)$_GET['delete'];

    // ลบรูปของสถานที่
    $conn->query("
        DELETE FROM place_images
        WHERE place_id = $id
    ");

    // ลบสถานที่
    $conn->query("
        DELETE FROM places
        WHERE id = $id
    ");

    header("Location: places.php");
    exit;
}

/* ===============================
   USER
=============================== */
$user = $_SESSION['user'];

$profileImage = !empty($user['profile_image'])
    ? "/uploads/profile/" . $user['profile_image']
    : null;

/* ===============================
   SEARCH
=============================== */
$keyword = isset($_GET['keyword'])
    ? trim($_GET['keyword'])
    : '';

/* ===============================
   PAGINATION
=============================== */
$limit = 10;

$page = isset($_GET['page'])
    ? (int)$_GET['page']
    : 1;

if ($page < 1) {
    $page = 1;
}

$start = ($page - 1) * $limit;

/* ===============================
   SEARCH CONDITION
=============================== */
$where = "";

if ($keyword !== '') {

    $safeKeyword = $conn->real_escape_string($keyword);

    $where = "
        WHERE
            p.name LIKE '%$safeKeyword%'
            OR p.province LIKE '%$safeKeyword%'
            OR c.name LIKE '%$safeKeyword%'
    ";
}

/* ===============================
   COUNT TOTAL
=============================== */
$totalQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM places p
    LEFT JOIN categories c
        ON p.category_id = c.id
    $where
");

$totalData = $totalQuery->fetch_assoc();

$totalPlaces = (int)$totalData['total'];

$totalPages = ceil($totalPlaces / $limit);

if ($totalPages < 1) {
    $totalPages = 1;
}

/*
 * ถ้าหน้าที่ส่งมามากกว่าจำนวนหน้าจริง
 * ให้กลับไปหน้าสุดท้าย
 */
if ($page > $totalPages) {
    $page = $totalPages;
    $start = ($page - 1) * $limit;
}

/* ===============================
   GET PLACES
=============================== */
$result = $conn->query("
    SELECT
        p.*,
        c.name AS category_name
    FROM places p
    LEFT JOIN categories c
        ON p.category_id = c.id
    $where
    ORDER BY p.province ASC, p.id ASC
    LIMIT $start, $limit
");
?>

<!DOCTYPE html>
<html lang="th">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>จัดการสถานที่</title>

<script src="https://cdn.tailwindcss.com"></script>

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

</head>

<body class="bg-gray-100 text-gray-800 min-h-screen">


<!-- =========================================================
     TOPBAR
========================================================= -->

<div class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-50">

    <div class="max-w-7xl mx-auto px-6 py-4
                flex items-center justify-between
                flex-wrap gap-4">


        <!-- LEFT -->

        <div class="flex items-center gap-3">

            <div class="w-12 h-12 rounded-2xl
                        bg-blue-500 text-white
                        flex items-center justify-center
                        shadow-md">

                <i class="fa-solid fa-location-dot text-lg"></i>

            </div>

            <div>

                <h1 class="text-xl font-bold text-gray-800">
                    Travel Admin
                </h1>

                <p class="text-sm text-gray-500">
                    ระบบจัดการสถานที่ท่องเที่ยว
                </p>

            </div>

        </div>


        <!-- RIGHT -->

        <div class="flex items-center gap-3 flex-wrap">


            <!-- PROFILE -->

            <a href="/profile.php"
               class="h-14 min-w-[190px]
                      px-4 rounded-2xl
                      bg-white border border-gray-200
                      hover:border-blue-300
                      hover:bg-blue-50
                      shadow-sm transition
                      flex items-center gap-3">

                <?php if ($profileImage): ?>

                    <img
                        src="<?= htmlspecialchars($profileImage) ?>"
                        class="w-10 h-10 rounded-full
                               object-cover border">

                <?php else: ?>

                    <div class="w-10 h-10 rounded-full
                                bg-gray-200 text-gray-500
                                flex items-center justify-center">

                        <i class="fa-solid fa-user"></i>

                    </div>

                <?php endif; ?>


                <div>

                    <div class="font-semibold text-gray-800">

                        <?= htmlspecialchars($user['username']) ?>

                    </div>

                    <div class="text-sm text-gray-500">

                        <?= htmlspecialchars($user['role']) ?>

                    </div>

                </div>

            </a>


            <!-- HOME -->

            <a href="/index.php"
               class="h-12 px-5 rounded-2xl
                      bg-gray-100 hover:bg-gray-200
                      text-gray-700 font-semibold
                      transition flex items-center gap-2">

                <i class="fa-solid fa-house"></i>

                หน้าเว็บ

            </a>


            <!-- DASHBOARD -->

            <a href="/admin/dashboard.php"
               class="h-12 px-5 rounded-2xl
                      bg-gray-100 hover:bg-gray-200
                      text-gray-700 font-semibold
                      transition flex items-center gap-2">

                <i class="fa-solid fa-chart-line"></i>

                Dashboard

            </a>


            <!-- ADD -->

            <a href="/admin/place_add.php"
               class="h-12 px-5 rounded-2xl
                      bg-blue-500 hover:bg-blue-600
                      text-white font-semibold
                      shadow-md transition
                      flex items-center gap-2">

                <i class="fa-solid fa-plus"></i>

                เพิ่มสถานที่

            </a>

        </div>

    </div>

</div>


<!-- =========================================================
     CONTENT
========================================================= -->

<div class="max-w-7xl mx-auto px-6 py-10">


    <div class="bg-white rounded-3xl shadow-lg overflow-hidden">


        <!-- =================================================
             HEADER
        ================================================= -->

        <div class="px-6 py-5 border-b border-gray-100">


            <div class="flex items-start
                        justify-between
                        flex-wrap gap-4">


                <!-- LEFT -->

                <div>

                    <h3 class="text-lg font-bold">

                        รายการสถานที่

                    </h3>

                    <p class="text-sm text-gray-500 mt-1">

                        ทั้งหมด <?= $totalPlaces ?> รายการ

                    </p>

                </div>


                <!-- RIGHT -->

                <div class="flex flex-col
                            items-end gap-3">


                    <!-- PAGE -->

                    <div class="bg-blue-50 text-blue-700
                                px-4 py-2 rounded-xl
                                text-sm font-semibold">

                        หน้า <?= $page ?> / <?= $totalPages ?>

                    </div>


                    <!-- SEARCH -->

                    <form method="GET">

                        <div class="flex items-center gap-2">


                            <!-- INPUT -->

                            <div class="relative w-[220px]">

                                <i class="fa-solid fa-magnifying-glass
                                          absolute left-3 top-1/2
                                          -translate-y-1/2
                                          text-gray-400 text-xs"></i>

                                <input
                                    type="text"
                                    name="keyword"
                                    value="<?= htmlspecialchars($keyword) ?>"
                                    placeholder="ค้นหาชื่อ จังหวัด หรือประเภท..."
                                    class="w-full pl-8 pr-3 py-2
                                           text-sm
                                           rounded-lg
                                           border border-gray-200
                                           focus:outline-none
                                           focus:ring-2
                                           focus:ring-blue-400">

                            </div>


                            <!-- SEARCH BUTTON -->

                            <button
                                type="submit"
                                class="px-3 py-2
                                       rounded-lg
                                       bg-blue-500
                                       hover:bg-blue-600
                                       text-white text-xs
                                       font-semibold
                                       transition">

                                <i class="fa-solid fa-magnifying-glass"></i>

                            </button>


                            <!-- RESET -->

                            <a href="places.php"
                               class="px-3 py-2
                                      rounded-lg
                                      bg-gray-100
                                      hover:bg-gray-200
                                      text-gray-700 text-xs
                                      font-semibold
                                      transition">

                                รีเซ็ต

                            </a>

                        </div>

                    </form>

                </div>

            </div>

        </div>


        <!-- =================================================
             TABLE
        ================================================= -->

        <div class="overflow-x-auto">

            <table class="w-full min-w-[1100px]">


                <!-- TABLE HEAD -->

                <thead class="bg-gray-50">

                    <tr class="text-left
                               text-gray-500
                               text-sm">


                        <th class="px-6 py-4 font-semibold">
                            ลำดับ
                        </th>


                        <th class="px-6 py-4 font-semibold">
                            รูปภาพ
                        </th>


                        <th class="px-6 py-4 font-semibold">
                            ชื่อสถานที่
                        </th>


                        <th class="px-6 py-4 font-semibold">
                            ประเภทสถานที่
                        </th>


                        <th class="px-6 py-4 font-semibold">
                            จังหวัด
                        </th>


                        <th class="px-6 py-4 font-semibold">
                            ราคา (ต่ำสุด - สูงสุด)
                        </th>


                        <th class="px-6 py-4
                                   font-semibold
                                   text-center">

                            จัดการ

                        </th>

                    </tr>

                </thead>


                <!-- TABLE BODY -->

                <tbody class="divide-y divide-gray-100">


                <?php
                $number = $start + 1;
                ?>


                <?php while ($row = $result->fetch_assoc()): ?>


                    <?php

                    /* ===============================
                       GET FIRST IMAGE
                    =============================== */

                    $placeId = (int)$row['id'];

                    $imgQuery = $conn->query("
                        SELECT *
                        FROM place_images
                        WHERE place_id = $placeId
                        LIMIT 1
                    ");

                    $imgData = $imgQuery
                        ? $imgQuery->fetch_assoc()
                        : null;


                    /* ===============================
                       PRICE
                    =============================== */

                    $minPrice = isset($row['min_price'])
                        ? (float)$row['min_price']
                        : 0;

                    $maxPrice = isset($row['max_price'])
                        ? (float)$row['max_price']
                        : 0;


                    /* ===============================
                       CATEGORY
                    =============================== */

                    $categoryName = !empty($row['category_name'])
                        ? $row['category_name']
                        : 'ไม่ระบุประเภท';

                    ?>


                    <!-- ROW -->

                    <tr class="hover:bg-gray-50 transition">


                        <!-- NUMBER -->

                        <td class="px-6 py-5
                                   font-bold text-gray-500">

                            #<?= $number++ ?>

                        </td>


                        <!-- IMAGE -->

                        <td class="px-6 py-5">

                            <?php if ($imgData): ?>

                                <img
                                    src="../uploads/<?= htmlspecialchars($imgData['image_name']) ?>"
                                    class="w-32 h-20
                                           object-cover
                                           rounded-2xl
                                           border
                                           border-gray-200
                                           shadow-sm">

                            <?php else: ?>

                                <div class="w-32 h-20
                                            rounded-2xl
                                            border-2
                                            border-dashed
                                            border-gray-300
                                            flex
                                            items-center
                                            justify-center
                                            text-gray-400
                                            text-xs">

                                    <div class="text-center">

                                        <i class="fa-regular
                                                  fa-image
                                                  text-lg mb-1"></i>

                                        <div>
                                            ไม่มีรูป
                                        </div>

                                    </div>

                                </div>

                            <?php endif; ?>

                        </td>


                        <!-- NAME -->

                        <td class="px-6 py-5">

                            <div class="font-bold
                                        text-gray-800
                                        text-lg">

                                <?= htmlspecialchars($row['name']) ?>

                            </div>

                        </td>


                        <!-- CATEGORY -->

                        <td class="px-6 py-5">

                            <span class="inline-flex
                                         items-center
                                         gap-2
                                         bg-purple-50
                                         text-purple-700
                                         text-sm
                                         px-4 py-2
                                         rounded-full
                                         font-semibold
                                         whitespace-nowrap">

                                <i class="fa-solid
                                          fa-layer-group"></i>

                                <?= htmlspecialchars($categoryName) ?>

                            </span>

                        </td>


                        <!-- PROVINCE -->

                        <td class="px-6 py-5">

                            <span class="inline-flex
                                         items-center
                                         gap-2
                                         bg-blue-50
                                         text-blue-700
                                         text-sm
                                         px-4 py-2
                                         rounded-full
                                         font-semibold
                                         whitespace-nowrap">

                                <i class="fa-solid fa-location-dot"></i>

                                <?= htmlspecialchars($row['province']) ?>

                            </span>

                        </td>


                        <!-- PRICE -->

                        <td class="px-6 py-5">

                            <div class="text-sm
                                        font-semibold
                                        text-emerald-600
                                        bg-emerald-50
                                        px-3 py-2
                                        rounded-xl
                                        inline-block
                                        whitespace-nowrap">


                                <?php if (
                                    $minPrice == 0 &&
                                    $maxPrice == 0
                                ): ?>

                                    <i class="fa-solid
                                              fa-ticket mr-1"></i>

                                    เข้าชมฟรี


                                <?php elseif (
                                    $minPrice == $maxPrice
                                ): ?>

                                    ฿<?= number_format($minPrice) ?>


                                <?php else: ?>

                                    ฿<?= number_format($minPrice) ?>
                                    -
                                    ฿<?= number_format($maxPrice) ?>

                                <?php endif; ?>


                            </div>

                        </td>


                        <!-- ACTION -->

                        <td class="px-6 py-5">

                            <div class="flex
                                        justify-center
                                        gap-2
                                        flex-wrap">


                                <!-- EDIT -->

                                <a href="/admin/place_edit.php?id=<?= $row['id'] ?>"
                                   class="bg-amber-400
                                          hover:bg-amber-500
                                          text-white
                                          px-4 py-2
                                          rounded-xl
                                          text-sm
                                          font-semibold
                                          transition">

                                    <i class="fa-solid fa-pen"></i>

                                    แก้ไข

                                </a>


                                <!-- DELETE -->

                                <a href="?delete=<?= $row['id'] ?>"
                                   onclick="return confirm('ลบสถานที่นี้จริงไหม?')"
                                   class="bg-red-500
                                          hover:bg-red-600
                                          text-white
                                          px-4 py-2
                                          rounded-xl
                                          text-sm
                                          font-semibold
                                          transition">

                                    <i class="fa-solid fa-trash"></i>

                                    ลบ

                                </a>

                            </div>

                        </td>


                    </tr>


                <?php endwhile; ?>


                <!-- NO DATA -->

                <?php if ($result->num_rows === 0): ?>

                    <tr>

                        <td colspan="7"
                            class="text-center py-20">

                            <div class="text-gray-400">

                                <i class="fa-regular
                                          fa-folder-open
                                          text-6xl mb-5"></i>

                                <div class="text-xl
                                            font-semibold
                                            mb-2">

                                    ไม่พบข้อมูลที่ค้นหา

                                </div>

                                <div class="text-sm">

                                    ลองค้นหาด้วยชื่อสถานที่
                                    จังหวัด หรือประเภทสถานที่

                                </div>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>


                </tbody>

            </table>

        </div>


        <!-- =================================================
             PAGINATION
        ================================================= -->

        <div class="px-6 py-6
                    border-t
                    border-gray-100">

            <div class="flex
                        justify-center
                        items-center
                        gap-2
                        flex-wrap">


                <!-- PREVIOUS -->

                <?php if ($page > 1): ?>

                    <a href="?page=<?= $page - 1 ?>&keyword=<?= urlencode($keyword) ?>"
                       class="px-4 py-2
                              rounded-xl
                              bg-gray-100
                              hover:bg-gray-200
                              text-gray-700
                              font-semibold
                              transition">

                        <i class="fa-solid
                                  fa-chevron-left"></i>

                    </a>

                <?php endif; ?>


                <!-- PAGE NUMBER -->

                <?php for (
                    $i = 1;
                    $i <= $totalPages;
                    $i++
                ): ?>

                    <a href="?page=<?= $i ?>&keyword=<?= urlencode($keyword) ?>"
                       class="px-4 py-2
                              rounded-xl
                              font-semibold
                              transition
                              <?= ($i == $page)
                                  ? 'bg-blue-500 text-white'
                                  : 'bg-gray-100 hover:bg-gray-200 text-gray-700'
                              ?>">

                        <?= $i ?>

                    </a>

                <?php endfor; ?>


                <!-- NEXT -->

                <?php if ($page < $totalPages): ?>

                    <a href="?page=<?= $page + 1 ?>&keyword=<?= urlencode($keyword) ?>"
                       class="px-4 py-2
                              rounded-xl
                              bg-gray-100
                              hover:bg-gray-200
                              text-gray-700
                              font-semibold
                              transition">

                        <i class="fa-solid
                                  fa-chevron-right"></i>

                    </a>

                <?php endif; ?>


            </div>

        </div>


    </div>

</div>


</body>
</html>