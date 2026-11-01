<?php include 'db.php'; if(!isset($_SESSION['role']) || $_SESSION['role'] != 'ADMIN') header("Location: welcome.html"); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { display: flex; height: 100vh; background: #f4f6f9; }
        
        /* Sidebar Fix */
        .sidebar { width: 220px; background: #1e3c72; color: white; padding: 20px; display: flex; flex-direction: column; }
        .sidebar h2 { margin-bottom: 20px; text-align: center; }
        .sidebar a { color: white; text-decoration: none; padding: 10px; border-radius: 5px; margin-bottom: 5px; display: block; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,0.2); }
        .sidebar .logout { margin-top: auto; background: #e74c3c; text-align: center; padding: 12px; border-radius: 5px; color: white; text-decoration: none; font-weight: bold; display: block; transition: 0.3s;}
        .sidebar .logout:hover { background: #c0392b; }
        
        .main { flex: 1; padding: 20px; display: flex; gap: 20px; overflow-y: auto; }
        .map-container { width: 50%; background: white; padding: 15px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        #map { width: 100%; height: 80vh; border-radius: 10px; }
        
        .right-panel { width: 50%; display: flex; flex-direction: column; gap: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; color: #1e3c72; }
        .HIGH { color: red; font-weight: bold; } .MEDIUM { color: orange; } .LOW { color: green; } .PENDING { color: gray; }
        button { padding: 8px 12px; border: none; border-radius: 5px; cursor: pointer; color: white; background: #3498db; }
        .btn-danger { background: #e74c3c; }
        .btn-success { background: #2ecc71; }
        input[type="text"], input[type="number"], input[type="password"] { padding: 8px; border: 1px solid #ccc; border-radius: 5px; margin-bottom: 5px; width: 100%; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2><i class="fas fa-recycle"></i> Admin</h2>
        <a href="admin.php" class="active"><i class="fas fa-home"></i> Dashboard</a>
        <a href="complaints.php"><i class="fas fa-exclamation-triangle"></i> Complaints</a>
        <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main">
        <div class="map-container">
            <h3>Marudham Live Map</h3>
            <div id="map"></div>
        </div>
        
        <div class="right-panel">
            <!-- Weight Update Card -->
            <div class="card">
                <h3><i class="fas fa-weight"></i> Update Bin Weight</h3>
                <table id="binsTable"><thead><tr><th>Location</th><th>Priority</th><th>Action</th></tr></thead><tbody></tbody></table>
            </div>

            <!-- Vehicles Card -->
            <div class="card">
                <h3><i class="fas fa-truck"></i> Manage Vehicles</h3>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="vNum" placeholder="Vehicle No (TN-43...)">
                    <input type="number" id="vCap" placeholder="Capacity (kg)">
                    <button class="btn-success" onclick="addVehicle()">Add</button>
                </div>
                <table id="vehiclesTable"><thead><tr><th>Vehicle</th><th>Status</th><th>Action</th></tr></thead><tbody></tbody></table>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        var map = L.map('map').setView([11.4100, 76.6950], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        let markersLayer = L.layerGroup().addTo(map); // Map markers-a clear panna group

        function loadBins() {
            fetch('api.php?action=get_bins').then(res => res.json()).then(data => {
                let html = '<tr><th>Location</th><th>Priority</th><th>Action</th></tr>';
                markersLayer.clearLayers(); // Palaya markers-a clear pandrom

                data.forEach(b => {
                    html += `<tr><td>${b.location_name}</td><td class="${b.priority}">${b.priority}</td>
                             <td><input type="number" id="w${b.id}" style="width:60px; display:inline-block;"><button onclick="updateWeight(${b.id})">Update</button></td></tr>`;
                    
                    // Map Tooltip Fix
                    L.circleMarker([b.latitude, b.longitude], {color: b.priority==='HIGH'?'red':'green'})
                     .addTo(markersLayer)
                     .bindTooltip(b.location_name, {permanent: true, direction: "top", className: 'map-tooltip'});
                });
                                // Map-la Depot-a special-a add pandrom
                let depotLat = 11.4115;
                let depotLng = 76.6960;
                L.circleMarker([depotLat, depotLng], {color: 'blue', radius: 10, fillOpacity: 0.8})
                 .addTo(map)
                 .bindTooltip("Marudham Central Depot", {permanent: true, direction: "top"})
                 .bindPopup("<b>Marudham Central Depot</b><br>Start/End Point");
                document.getElementById('binsTable').innerHTML = html;
            });
        }

        function loadVehicles() {
            fetch('api.php?action=get_vehicles').then(res => res.json()).then(data => {
                let html = '<tr><th>Vehicle</th><th>Status</th><th>Action</th></tr>';
                data.forEach(v => {
                    html += `<tr><td>${v.vehicle_number}</td><td>${v.status}</td>
                             <td><button class="btn-danger" onclick="removeVehicle(${v.id})">Remove</button></td></tr>`;
                });
                document.getElementById('vehiclesTable').innerHTML = html;
            });
        }

        function updateWeight(id) {
            let w = document.getElementById('w'+id).value; if(!w) return;
            fetch('api.php?action=update_weight', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${id}&weight=${w}`})
            .then(res=>res.json()).then(data=>{ 
                loadBins(); // Alert-a azhichitten, direct-a bins-a reload pandrom
            });
        }

        function addVehicle() {
            let v = document.getElementById('vNum').value;
            let c = document.getElementById('vCap').value;
            if(!v || !c) return alert("Enter details!");
            fetch('api.php?action=add_vehicle', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`vehicle_number=${v}&capacity=${c}`})
            .then(res=>res.json()).then(data=>{ alert(data.msg); loadVehicles(); });
        }

        function removeVehicle(id) {
            if(confirm("Are you sure?")) {
                fetch('api.php?action=remove_vehicle', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${id}`})
                .then(res=>res.json()).then(data=>{ alert(data.msg); loadVehicles(); });
            }
        }

        loadBins(); loadVehicles();
    </script>
</body>
</html>