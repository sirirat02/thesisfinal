<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include __DIR__ . '/../db.php';

/* ===============================
   INSERT PLACE
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // รับค่าจากฟอร์มอย่างปลอดภัย
    $name        = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $province    = mysqli_real_escape_string($conn, trim($_POST['province'] ?? ''));
    $latitude    = mysqli_real_escape_string($conn, trim($_POST['latitude'] ?? ''));
    $longitude   = mysqli_real_escape_string($conn, trim($_POST['longitude'] ?? ''));
    $category_id = (int)($_POST['category_id'] ?? 0);

    // ค่าใช้จ่าย / ช่วงราคา
    $entry_fee = (float)($_POST['entry_fee'] ?? 0);
    $min_price = (float)($_POST['min_price'] ?? 0);
    $max_price = (float)($_POST['max_price'] ?? 0);

    // ตรวจสอบข้อมูลที่จำเป็น
    if ($name === '') {
        die('กรุณากรอกชื่อสถานที่');
    }

    if ($province === '') {
        die('กรุณากรอกจังหวัด');
    }

    /* ===============================
       INSERT PLACE
    =============================== */
    $sql = "
        INSERT INTO places
        (
            name,
            description,
            province,
            latitude,
            longitude,
            category_id,
            entry_fee,
            min_price,
            max_price
        )
        VALUES
        (
            '$name',
            '$description',
            '$province',
            '$latitude',
            '$longitude',
            '$category_id',
            '$entry_fee',
            '$min_price',
            '$max_price'
        )
    ";

    if (!$conn->query($sql)) {
        die('SQL Error: ' . $conn->error);
    }

    $place_id = $conn->insert_id;

    /* ===============================
       UPLOAD MULTIPLE IMAGES
       รูปแรก = COVER IMAGE
    =============================== */
    if (
        isset($_FILES['images']) &&
        isset($_FILES['images']['name']) &&
        is_array($_FILES['images']['name']) &&
        !empty($_FILES['images']['name'][0])
    ) {

        $uploadDir = __DIR__ . '/../uploads/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                die('ไม่สามารถสร้างโฟลเดอร์ uploads ได้');
            }
        }

        $firstImage = null;

        foreach ($_FILES['images']['name'] as $key => $imageName) {

            if ($_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmpName = $_FILES['images']['tmp_name'][$key];

            // ตรวจสอบว่าเป็นไฟล์รูปภาพจริง
            $imageInfo = @getimagesize($tmpName);
            if ($imageInfo === false) {
                continue;
            }

            $safeName = preg_replace(
                '/[^a-zA-Z0-9\._-]/',
                '_',
                basename($imageName)
            );

            $filename =
                time() .
                '_' .
                rand(1000, 9999) .
                '_' .
                $safeName;

            $target = $uploadDir . $filename;

            if (move_uploaded_file($tmpName, $target)) {

                // รูปแรก = cover
                if ($firstImage === null) {
                    $firstImage = $filename;
                }

                // ป้องกันชื่อไฟล์ที่มีอักขระพิเศษ
                $safeFilename = mysqli_real_escape_string($conn, $filename);

                // insert รูปลง DB
                $imageSql = "
                    INSERT INTO place_images
                    (
                        place_id,
                        image_name
                    )
                    VALUES
                    (
                        '$place_id',
                        '$safeFilename'
                    )
                ";

                if (!$conn->query($imageSql)) {
                    // ถ้า insert รูปไม่สำเร็จ ลบไฟล์ที่เพิ่งอัปโหลด
                    @unlink($target);
                }
            }
        }

        /* ===============================
           UPDATE COVER IMAGE
        =============================== */
        if ($firstImage !== null) {

            $safeCover = mysqli_real_escape_string(
                $conn,
                $firstImage
            );

            $coverSql = "
                UPDATE places
                SET cover_image = '$safeCover'
                WHERE id = '$place_id'
            ";

            if (!$conn->query($coverSql)) {
                die('Cover Image SQL Error: ' . $conn->error);
            }
        }
    }

    header('Location: places.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เพิ่มสถานที่</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body class="bg-gray-100 text-gray-800 min-h-screen">

<!-- TOPBAR -->
<div class="bg-white shadow-sm border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between flex-wrap gap-4">

        <!-- LEFT -->
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-2xl bg-blue-500 text-white flex items-center justify-center shadow">
                <i class="fa-solid fa-location-dot"></i>
            </div>

            <div>
                <h1 class="text-lg font-bold">Travel Admin</h1>
                <p class="text-sm text-gray-500">ระบบจัดการสถานที่ท่องเที่ยว</p>
            </div>
        </div>

        <!-- RIGHT -->
        <div class="flex gap-2">
            <a href="/admin/places.php"
               class="bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded-xl text-sm font-medium transition">
                <i class="fa-solid fa-arrow-left"></i>
                กลับ
            </a>

            <a href="/index.php"
               class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-semibold transition shadow">
                <i class="fa-solid fa-house"></i>
                หน้าเว็บ
            </a>
        </div>
    </div>
</div>

<!-- CONTENT -->
<div class="max-w-4xl mx-auto px-6 py-10">

    <!-- HEADER -->
    <div class="mb-8">
        <h2 class="text-3xl font-bold mb-2">
            <i class="fa-solid fa-plus text-blue-500"></i>
            เพิ่มสถานที่ท่องเที่ยว
        </h2>

        <p class="text-gray-500">
            เพิ่มข้อมูลสถานที่ใหม่เข้าสู่ระบบ
        </p>
    </div>

    <!-- CARD -->
    <div class="bg-white rounded-3xl shadow-lg p-8">

        <form method="post"
              enctype="multipart/form-data"
              class="space-y-6">

            <!-- NAME -->
            <div>
                <label class="block text-sm font-semibold mb-2">
                    ชื่อสถานที่ <span class="text-red-500">*</span>
                </label>

                <input
                    type="text"
                    name="name"
                    required
                    placeholder="เช่น ภูชี้ฟ้า"
                    class="w-full border border-gray-200 rounded-2xl px-4 py-3 focus:ring-2 focus:ring-blue-400 focus:outline-none">
            </div>

            <!-- DESCRIPTION -->
            <div>
                <label class="block text-sm font-semibold mb-2">
                    รายละเอียด
                </label>

                <textarea
                    name="description"
                    rows="5"
                    placeholder="รายละเอียดสถานที่ท่องเที่ยว..."
                    class="w-full border border-gray-200 rounded-2xl px-4 py-3 focus:ring-2 focus:ring-blue-400 focus:outline-none"></textarea>
            </div>

            <!-- PROVINCE / CATEGORY -->
            <div class="grid md:grid-cols-2 gap-5">

                <!-- PROVINCE -->
                <div>
                    <label class="block text-sm font-semibold mb-2">
                        จังหวัด <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="province"
                        required
                        placeholder="เช่น เชียงราย"
                        class="w-full border border-gray-200 rounded-2xl px-4 py-3 focus:ring-2 focus:ring-blue-400 focus:outline-none">
                </div>

                <!-- CATEGORY -->
                <div>
                    <label class="block text-sm font-semibold mb-2">
                        Category ID
                    </label>

                    <input
                        type="number"
                        name="category_id"
                        min="0"
                        value="0"
                        placeholder="เช่น 1"
                        class="w-full border border-gray-200 rounded-2xl px-4 py-3 focus:ring-2 focus:ring-blue-400 focus:outline-none">
                </div>

            </div>

            <!-- ENTRY FEE & PRICE RANGE -->
            <div class="grid md:grid-cols-3 gap-5">

                <!-- ENTRY FEE -->
                <div>
                    <label class="block text-sm font-semibold mb-2">
                        <i class="fa-solid fa-money-bill-wave text-green-500"></i>
                        ค่าเข้าพื้นฐาน (บาท)
                    </label>

                    <div class="relative">
                        <input
                            type="number"
                            name="entry_fee"
                            min="0"
                            step="0.01"
                            value="0"
                            placeholder="20"
                            class="w-full border border-gray-200 rounded-2xl px-4 py-3 pr-14 focus:ring-2 focus:ring-blue-400 focus:outline-none">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm">
                            บาท
                        </span>
                    </div>
                </div>

                <!-- MIN PRICE -->
                <div>
                    <label class="block text-sm font-semibold mb-2">
                        <i class="fa-solid fa-tag text-emerald-500"></i>
                        ราคาต่ำสุด (บาท)
                    </label>

                    <div class="relative">
                        <input
                            type="number"
                            name="min_price"
                            min="0"
                            step="0.01"
                            value="0"
                            placeholder="0"
                            class="w-full border border-gray-200 rounded-2xl px-4 py-3 pr-14 focus:ring-2 focus:ring-blue-400 focus:outline-none">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm">
                            บาท
                        </span>
                    </div>
                </div>

                <!-- MAX PRICE -->
                <div>
                    <label class="block text-sm font-semibold mb-2">
                        <i class="fa-solid fa-tags text-emerald-600"></i>
                        ราคาสูงสุด (บาท)
                    </label>

                    <div class="relative">
                        <input
                            type="number"
                            name="max_price"
                            min="0"
                            step="0.01"
                            value="0"
                            placeholder="100"
                            class="w-full border border-gray-200 rounded-2xl px-4 py-3 pr-14 focus:ring-2 focus:ring-blue-400 focus:outline-none">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm">
                            บาท
                        </span>
                    </div>
                </div>

            </div>
            <p class="text-xs text-gray-400 -mt-3">
                * หากไม่มีค่าเข้าชม ให้คงค่าเป็น 0 ทั้งหมด
            </p>

            <!-- LAT LNG -->
            <div class="grid md:grid-cols-2 gap-5">

                <!-- LAT -->
                <div>
                    <label class="block text-sm font-semibold mb-2">
                        Latitude
                    </label>

                    <input
                        type="text"
                        name="latitude"
                        placeholder="18.7883"
                        class="w-full border border-gray-200 rounded-2xl px-4 py-3 focus:ring-2 focus:ring-blue-400 focus:outline-none">
                </div>

                <!-- LNG -->
                <div>
                    <label class="block text-sm font-semibold mb-2">
                        Longitude
                    </label>

                    <input
                        type="text"
                        name="longitude"
                        placeholder="98.9853"
                        class="w-full border border-gray-200 rounded-2xl px-4 py-3 focus:ring-2 focus:ring-blue-400 focus:outline-none">
                </div>

            </div>

            <!-- IMAGE -->
            <div>
                <label class="block text-sm font-semibold mb-3">
                    <i class="fa-regular fa-images text-blue-500"></i>
                    รูปภาพสถานที่
                </label>

                <div class="border-2 border-dashed border-blue-300 rounded-3xl p-10 bg-blue-50">

                    <div class="text-center mb-6">
                        <i class="fa-regular fa-image text-5xl text-blue-400 mb-4"></i>

                        <h3 class="font-bold text-lg mb-2">
                            อัปโหลดรูปภาพสถานที่
                        </h3>

                        <p class="text-sm text-gray-500">
                            สามารถเลือกรูปได้หลายรูปพร้อมกัน
                        </p>

                        <p class="text-xs text-gray-400 mt-1">
                            รูปแรกจะถูกใช้เป็น Cover Image อัตโนมัติ
                        </p>
                    </div>

                    <!-- FILE INPUT -->
                    <input
                        type="file"
                        id="imageInput"
                        name="images[]"
                        multiple
                        accept="image/*"
                        class="block w-full text-sm text-gray-600 file:mr-4 file:py-3 file:px-5 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-500 file:text-white hover:file:bg-blue-600">

                    <!-- PREVIEW -->
                    <div id="previewContainer"
                         class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mt-6">
                    </div>
                </div>
            </div>

            <!-- BUTTON -->
            <div class="flex items-center gap-3 pt-4">

                <button
                    type="submit"
                    class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-2xl font-semibold transition shadow">
                    <i class="fa-solid fa-floppy-disk"></i>
                    บันทึกสถานที่
                </button>

                <a href="/admin/places.php"
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-3 rounded-2xl font-semibold transition">
                    ยกเลิก
                </a>

            </div>

        </form>
    </div>
</div>

<script>
const imageInput = document.getElementById('imageInput');
const previewContainer = document.getElementById('previewContainer');

let selectedFiles = [];

imageInput.addEventListener('change', function (e) {
    const files = Array.from(e.target.files);

    files.forEach(file => {
        selectedFiles.push(file);
    });

    renderPreview();
});

function renderPreview() {
    previewContainer.innerHTML = '';

    const dataTransfer = new DataTransfer();

    selectedFiles.forEach((file, index) => {
        dataTransfer.items.add(file);

        const reader = new FileReader();

        reader.onload = function (e) {
            const div = document.createElement('div');
            div.className = 'relative group';

            div.innerHTML = `
                <img
                    src="${e.target.result}"
                    class="w-full h-40 object-cover rounded-2xl shadow border border-gray-200">

                <button
                    type="button"
                    onclick="removeImage(${index})"
                    class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white w-10 h-10 rounded-full flex items-center justify-center shadow-lg opacity-0 group-hover:opacity-100 transition">
                    <i class="fa-solid fa-trash"></i>
                </button>

                ${index === 0 ? `
                    <div class="absolute bottom-2 left-2 bg-blue-500 text-white text-xs px-3 py-1 rounded-full shadow">
                        Cover
                    </div>
                ` : ''}
            `;

            previewContainer.appendChild(div);
        };

        reader.readAsDataURL(file);
    });

    imageInput.files = dataTransfer.files;
}

function removeImage(index) {
    selectedFiles.splice(index, 1);
    renderPreview();
}
</script>

</body>
</html>