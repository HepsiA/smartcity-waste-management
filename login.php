<?php
include 'db.php';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $u = $_POST['username']; 
    $p = $_POST['password']; 
    $r = strtoupper($_POST['role']);
    
    $res = $conn->query("SELECT * FROM users WHERE username='$u' AND password='$p' AND role='$r'");
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $_SESSION['user_id'] = $row['id']; 
        $_SESSION['role'] = $row['role'];
        if ($row['role'] == 'ADMIN') header("Location: admin.php");
        else header("Location: driver.php");
    } else {
        echo "<script>alert('Invalid Credentials!'); window.location.href='welcome.html';</script>";
    }
}
?>