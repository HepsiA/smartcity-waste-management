<?php include 'db.php'; if(!isset($_SESSION['role']) || $_SESSION['role'] != 'ADMIN') header("Location: welcome.html"); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - Smart City Waste</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { display: flex; height: 100vh; background: #f4f6f9; }
        
        /* Sidebar Styles */
        .sidebar { width: 220px; background: #1e3c72; color: white; padding: 20px; display: flex; flex-direction: column;}
        .sidebar h2 { margin-bottom: 30px; text-align: center; }
        .sidebar a { display: block; color: white; text-decoration: none; padding: 12px; border-radius: 8px; margin-bottom: 10px; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,0.2); }
        .sidebar .logout { margin-top: auto; background: #e74c3c; text-align: center; }
        .sidebar .logout:hover { background: #c0392b; }

        /* Main Content Styles */
        .main { flex: 1; padding: 20px; display: flex; gap: 20px; overflow: hidden; }
        .map-container { width: 55%; background: white; padding: 15px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        #map { width: 100%; height: 80vh; border-radius: 10px; }
        
        .table-container { width: 45%; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); overflow-y: auto; }
        
        /* Table Styles */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: middle; }
        th { background: #f8f9fa; color: #1e3c72; position: sticky; top: 0; }
        
        /* Priority Colors */
        .HIGH { color: #e74c3c; font-weight: bold; } 
        .MEDIUM { color: #f39c12; font-weight: bold; } 
        .LOW { color: #2ecc71; font-weight: bold; }
        .PENDING { color: #7f8c8d; }

        /* Buttons and Inputs */
        button { padding: 10px; background: #3498db; color: white; border: none; cursor: pointer; border-radius: 5px; font-weight: bold; width: 100%; margin-bottom: 10px;}
        button:hover { background: #2980b9; }
        .btn-java { background: #e67e22; }
        .btn-java:hover { background: #d35400; }
        .btn-update { width: auto; padding: 6px 12px; background: #3498db; }
        input[type="number"] { width: 60px; padding: 6px; border: 1px solid #ccc; border-radius: 5px; margin-right: 5px;}
        
        .action-cell { display: flex; align-items: center; }
    </style>
</head>
<body>
    
    <!-- Sidebar -->
    <div class="sidebar">
        <h2><i class="fas fa-recycle"></i> Admin</h2>
        <a href="admin.php" class="active"><i class="fas fa-home"></i> Dashboard</a>
        <a href="complaints.php"><i class="fas fa-exclamation-triangle"></i> Complaints</a>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main">
        <!-- Left: Map -->
        <div class="map-container">
            <div id="map"></div>
        </div>
        
        <!-- Right: Table -->
        <div class="table-container">
            <h2><i class="fas fa-trash-alt"></i> Waste Collection</h2>
            <p style="font-size: 13px; color: gray; margin-bottom: 10px;">Update weights and generate routes using Java.</p>
            
            <button class="btn-java" onclick="generateRoute()"><i class="fas fa-route"></i> Auto Generate Route (Java)</button>
            
            <table id="table"></table>
        </div>
    </div>

    <!-- Leaflet Map JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Map Initialize Pandrom (Marudham / Ooty Area)
        var map = L.map('map').setView([11.4100, 76.6950], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap'
        }).addTo(map);

        let mapMarkers = [];

        // Bins Data Load Panna
        function loadBins() {
            fetch('api.php?action=get_bins').then(res => res.json()).then(data => {
                let html = '<tr><th>Location</th><th>Weight</th><th>Priority</th><th>Action</th></tr>';
                
                // Clear old markers
                mapMarkers.forEach(m => map.removeLayer(m));
                mapMarkers = [];

                data.forEach(b => {
                    html += `<tr>
                                <td>${b.location_name}</td>
                                <td>${b.current_weight} kg</td>
                                <td class="${b.priority}">${b.priority}</td>
                                <td class="action-cell">
                                    <input type="number" id="w${b.id}" placeholder="kg">
                                    <button class="btn-update" onclick="updateWeight(${b.id})">Update</button>
                                </td>
                             </tr>`;
                             
                    // Add Marker to Map
                    let color = b.priority === 'HIGH' ? 'red' : (b.priority === 'MEDIUM' ? 'orange' : 'green');
                    let marker = L.circleMarker([b.latitude, b.longitude], {
                        color: color, radius: 10, fillOpacity: 0.7
                    }).addTo(map).bindPopup(`<b>${b.location_name}</b><br>Priority: ${b.priority}`);
                    
                    mapMarkers.push(marker);
                });
                
                document.getElementById('table').innerHTML = html;
            });
        }

        // Weight Update Panna
        function updateWeight(id) {
            let w = document.getElementById('w'+id).value; 
            if(!w) return alert("Enter weight!");
            
            fetch('api.php?action=update_weight', {
                method:'POST', 
                headers:{'Content-Type':'application/x-www-form-urlencoded'}, 
                body:`id=${id}&weight=${w}`
            }).then(()=>loadBins()); // Update aana odane refresh
        }

        // Route Generate Panna (Java Call)
        function generateRoute() {
            fetch('api.php?action=generate_route')
            .then(res=>res.json())
            .then(data=>alert(data.msg));
        }

        // Page load aagum pothu bins-a load pannu
        loadBins();
    </script>
</body>
</html>