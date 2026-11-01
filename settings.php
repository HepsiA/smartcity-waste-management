<?php include 'db.php'; if(!isset($_SESSION['role']) || $_SESSION['role'] != 'ADMIN') header("Location: welcome.html"); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Settings</title>
    <style>
        body { font-family: sans-serif; display: flex; margin: 0; height: 100vh; background: #f4f6f9;}
        .sidebar { width: 220px; background: #1e3c72; color: white; padding: 20px; }
        .sidebar a { display: block; color: white; text-decoration: none; padding: 10px; margin-bottom: 5px; border-radius: 5px;}
        .sidebar a.active { background: rgba(255,255,255,0.2); }
        .main { flex: 1; padding: 30px; }
        .card { background: white; padding: 20px; border-radius: 10px; max-width: 500px; }
        input { padding: 10px; width: 100%; margin: 10px 0; border: 1px solid #ccc; border-radius: 5px; }
        button { padding: 10px; background: #3498db; color: white; border: none; cursor: pointer; width: 100%; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin</h2>
        <a href="admin.php">Dashboard</a>
        <a href="complaints.php">Complaints</a>
        <a href="settings.php" class="active">Settings</a>
        <a href="logout.php" style="margin-top: 50px; background: #e74c3c;">Logout</a>
    </div>
    <div class="main">
        <div class="card">
            <h2>Admin Settings</h2>
            <?php 
            $res = $conn->query("SELECT * FROM users WHERE role='ADMIN' LIMIT 1")->fetch_assoc();
            if($res['is_username_changed'] == 0) {
                echo '<p style="color: red;">You can change your username only ONCE.</p>';
                echo '<form action="api.php?action=update_username" method="POST">
                        <input type="text" name="new_username" placeholder="New Username" required>
                        <button type="submit">Change Username (Permanent)</button>
                      </form>';
            } else {
                echo '<p style="color: red;">Username is locked and cannot be changed anymore.</p>';
            }
            ?>
        </div>
    </div>
</body>
</html>