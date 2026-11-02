<?php include 'db.php'; if(!isset($_SESSION['role']) || $_SESSION['role'] != 'ADMIN') header("Location: welcome.html"); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin - Complaints</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: sans-serif; display: flex; margin: 0; height: 100vh; background: #f4f6f9;}
        .sidebar { width: 220px; background: #1e3c72; color: white; padding: 20px; }
        .sidebar a { display: block; color: white; text-decoration: none; padding: 10px; margin-bottom: 5px; border-radius: 5px;}
        .sidebar a.active { background: rgba(255,255,255,0.2); }
        .main { flex: 1; padding: 30px; overflow-y: auto; }
        table { width: 100%; border-collapse: collapse; background: white; margin-top: 15px; border-radius: 10px; overflow: hidden; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .status-UNRESOLVED { color: red; font-weight: bold; } .status-RESOLVED { color: green; font-weight: bold; }
        .proof-img { width: 80px; height: 60px; object-fit: cover; border-radius: 5px; }
        button { padding: 8px 12px; border: none; border-radius: 5px; cursor: pointer; color: white; background: #f39c12; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin</h2>
        <a href="admin.php">Dashboard</a>
        <a href="complaints.php" class="active">Complaints</a>
        <a href="logout.php" style="margin-top: 50px; background: #e74c3c;">Logout</a>
    </div>
    <div class="main">
        <h2>Driver Complaints</h2>
        <table>
            <thead><tr><th>Date</th><th>Driver</th><th>Message</th><th>Status</th><th>Proof</th><th>Action</th></tr></thead>
            <tbody id="tableBody"></tbody>
        </table>
    </div>
    <script>
        function loadComplaints() {
            fetch('api.php?action=get_complaints').then(res=>res.json()).then(data=>{
                let html = '';
                data.forEach(c => {
                    let proof = c.proof_image ? `<img src='uploads/${c.proof_image}' class='proof-img'>` : 'No Proof';
                    let action = c.status === 'UNRESOLVED' ? `<button onclick="resolve(${c.id})">Resolve</button>` : 'Done';
                    html += `<tr><td>${c.created_at}</td><td>Driver ${c.driver_id}</td><td>${c.message}</td><td class="status-${c.status}">${c.status}</td><td>${proof}</td><td>${action}</td></tr>`;
                });
                document.getElementById('tableBody').innerHTML = html;
            });
        }
        function resolve(id) {
            let input = document.createElement('input'); input.type = 'file'; input.accept = 'image/*';
            input.onchange = (e) => {
                let reader = new FileReader();
                reader.onload = (ev) => {
                    fetch('api.php?action=resolve_complaint', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${id}&image=${ev.target.result}`})
                    .then(res=>res.json()).then(data=>{ alert('Resolved!'); loadComplaints(); });
                };
                reader.readAsDataURL(e.target.files[0]);
            };
            input.click();
        }
        loadComplaints();
    </script>
</body>
</html>