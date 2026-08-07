<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "db_kas";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Koneksi database gagal!");
}
?>
