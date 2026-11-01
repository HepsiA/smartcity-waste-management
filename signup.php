<?php
include 'db.php';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $u = trim($_POST['username']); 
    $p = trim($_POST['password']); 
    $r = strtoupper($_POST['role']);
    
    // Admin already irukka nu check pandrom
    if($r == 'ADMIN') {
        $adminCheck = $conn->query("SELECT id FROM users WHERE role='ADMIN'");
        if($adminCheck->num_rows > 0) {
            echo "<script>alert('Admin account already exists! Please login.'); window.location.href='welcome.html';</script>";
            exit();
        }
    }

    // Username already irukka nu check pandrom
    $check = $conn->query("SELECT id FROM users WHERE username='$u'");
    if ($check->num_rows > 0) {
        echo "<script>alert('Username already exists! Try another.'); window.location.href='welcome.html';</script>";
    } else {
        // Admin-ku is_username_changed = 1 (lock), Driver-ku = 0
        $isChanged = ($r == 'ADMIN') ? 1 : 0;
        
        $sql = "INSERT INTO users (username, password, role, is_username_changed) VALUES ('$u', '$p', '$r', $isChanged)";
        if ($conn->query($sql) === TRUE) {
            echo "<script>alert('Account created successfully! Please login.'); window.location.href='welcome.html';</script>";
        } else {
            echo "Error: " . $conn->error;
        }
    }
}
?>