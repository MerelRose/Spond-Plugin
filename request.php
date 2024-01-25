<?php
error_reporting(E_ALL);

// include 'spond-class.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$spond = new Spond($username, $password);
try {
    // Call the login method to authenticate    
    $bearer = $spond->login();
}

catch (Exception $e) {
}