<?php
require_once "config/auth.php";

logout_user();

header("Location: /KasKelas/login.php");
exit;
