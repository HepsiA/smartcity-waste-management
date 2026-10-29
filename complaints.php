<?php include 'db.php'; if(!isset($_SESSION['role']) || $_SESSION['role'] != 'ADMIN') header("Location: welcome.html"); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Complaints</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Poppins', sans-serif; display: flex; margin: 0; height: 100vh; background: #f4f6f9;}
        .sidebar { width: 220px; background: #1e3c72; color: white; padding: 20px; display: flex; flex-direction: column;}
        .sidebar h2 { margin-bottom: 30px; text-align: center; }
        .sidebar a { display: block; color: white; text-decoration: none; padding: 12px; border-radius: 8px; margin-bottom: 10px; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,0.2); }
        .sidebar .logout { margin-top: auto; background: #e74c3c; text-align: center; }
        
        .main { flex: 1; padding: 30px; overflow-y: auto; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; vertical-align: middle; }
        th { background: #f8f9fa; color: #1e3c72; }
        
        .status-UNRESOLVED { color: #e74c3c; font-weight: bold; }
        .status-RESOLVED { color: #2ecc71; font-weight: bold; }
        
        .btn { padding: 8px 12px; border: none; border-radius: 5px; cursor: pointer; color: white; font-size: 14px; font-weight: bold;}
        .btn-resolve { background: #f39c12; }
        .btn-resolve:hover { background: #d68910; }
        .proof-img { width: 80px; height: 60px; object-fit: cover; border-radius: 5px; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2><i class="fas fa-recycle"></i> Admin</h2>
        <a href="admin.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="complaints.php" class="active"><i class="fas fa-exclamation-triangle"></i> Complaints</a>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    
    <div class="main">
        <div class="card">
            <h2><i class="fas fa-tools"></i> Driver Complaints</h2>
            <p style="font-size: 13px; color: gray; margin-bottom: 10px;">Resolve issues by uploading proof (photo/video).</p>
            
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Driver</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Admin Proof</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = $conn->query("SELECT * FROM complaints ORDER BY created_at DESC");
                    if($res->num_rows == 0) {
                        echo "<tr><td colspan='6' style='text-align:center;'>No complaints found.</td></tr>";
                    }
                    while($row = $res->fetch_assoc()) {
                        $proof = $row['proof_image'] ? "<img src='uploads/{$row['proof_image']}' class='proof-img'>" : "<span style='color:gray;'>No Proof</span>";
                        
                        $actionBtn = "";
                        if($row['status'] == 'UNRESOLVED') {
                            $actionBtn = "<button class='btn btn-resolve' onclick='resolveIssue({$row['id']})'><i class='fas fa-check'></i> Resolve</button>";
                        } else {
                            $actionBtn = "<span style='color:green;'><i class='fas fa-check-circle'></i> Done</span>";
                        }

                        echo "<tr>
                            <td style='font-size:12px;'>{$row['created_at']}</td>
                            <td>Driver #{$row['driver_id']}</td>
                            <td>{$row['message']}</td>
                            <td class='status-{$row['status']}'>{$row['status']}</td>
                            <td>{$proof}</td>
                            <td>{$actionBtn}</td>
                        </tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function resolveIssue(id) {
            // Photo eduka input create pandrom
            let input = document.createElement('input'); 
            input.type = 'file'; 
            input.accept = 'image/*';
            
            input.onchange = (e) => {
                let file = e.target.files[0];
                if(!file) return;
                
                let reader = new FileReader();
                reader.onload = (ev) => {
                    let imgData = ev.target.result;
                    
                    // Backend-ku anuppuvom
                    fetch('api.php?action=resolve_complaint', {
                        method:'POST', 
                        headers:{'Content-Type':'application/x-www-form-urlencoded'}, 
                        body:`id=${id}&image=${imgData}`
                    })
                    .then(res => res.json())
                    .then(data => { 
                        if(data.status === 'success') {
                            alert('Complaint Resolved with Proof!'); 
                            location.reload(); // Page-a refresh pandrom
                        }
                    });
                };
                reader.readAsDataURL(file);
            };
            input.click(); // Photo select panna window open aagum
        }
    </script>
</body>
</html>