<?php
require_once '/var/www/backend/config/config.php';

session_destroy();
header('Location: login.php');
exit;

