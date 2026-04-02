<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . getDashboardForRole($_SESSION['role']));
} else {
    header('Location: login.php');
}
exit;
