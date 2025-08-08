<?php
require_once 'config.php';

// Manually set test values
$_POST = [
    'device_id' => 'TEST12345678',
    'channel' => 0
];

// Include your original script
include 'get_serial.php';