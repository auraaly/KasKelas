<?php
require_once "config/auth.php";

if (is_logged_in()) {
    header("Location: /KasKelas/dashboard.php");
    exit;
}

header("Location: /KasKelas/login.php");
exit;
