<?php
session_start();
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $u = trim($_POST['username']); 
    $p = trim($_POST['password']); 
    $r = strtoupper($_POST['role']);

    // Query to check user
    $sql = "SELECT * FROM users WHERE username='$u' AND password='$p' AND role='$r'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Session Variables Set Pandrom
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['username'];
        
        // Redirect to respective dashboard
        if ($user['role'] == 'ADMIN') {
            header("Location: admin.php");
            exit();
        } else {
            header("Location: driver.php");
            exit();
        }
    } else {
        echo "<script>alert('Invalid Credentials! Check your username, password, and role.'); window.location.href='welcome.html';</script>";
    }
}
?>