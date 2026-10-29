<?php
$conn = new mysqli("localhost", "root", "", "smartcity_waste");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
session_start();
?>