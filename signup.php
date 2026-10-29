<?php
include 'db.php';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $u = $_POST['username']; 
    $p = $_POST['password']; 
    $r = strtoupper($_POST['role']);
    
    $check = $conn->query("SELECT id FROM users WHERE username='$u'");
    if ($check->num_rows > 0) {
        echo "<script>alert('Username exists!'); window.location.href='welcome.html';</script>";
    } else {
        $conn->query("INSERT INTO users (username, password, role) VALUES ('$u', '$p', '$r')");
        echo "<script>alert('Account created! Please login.'); window.location.href='welcome.html';</script>";
    }
}
?>