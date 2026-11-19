<?php
$host = "sql300.infinityfree.com";
$user = "if0_42258386";
$pass = "m3iwXu9WgJ";
$db   = "if0_42258386_tourism_db";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8");
