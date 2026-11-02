<?php include 'db.php'; if(!isset($_SESSION['role']) || $_SESSION['role'] != 'ADMIN') header("Location: welcome.html"); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { display: flex; min-height: 100vh; background: #f1f5f9; color: #172033; }
        .sidebar { width: 220px; flex-shrink: 0; background: linear-gradient(165deg, #163c55, #225b72); color: white; padding: 22px 16px; display: flex; flex-direction: column; }
        .sidebar h2 { margin-bottom: 22px; text-align: center; }
        .sidebar a { color: rgba(255,255,255,.86); text-decoration: none; padding: 11px 12px; border-radius: 8px; margin-bottom: 6px; display: block; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,0.16); color: white; }
        .sidebar .logout { margin-top: auto; background: #d65b4a; text-align: center; padding: 12px; color: white; font-weight: 600; }
        .main { flex: 1; min-width: 0; padding: 22px; display: flex; gap: 18px; overflow-y: auto; }
        .map-container { width: 50%; min-width: 0; background: white; padding: 18px; border-radius: 14px; box-shadow: 0 8px 24px rgba(25,45,65,.08); }
        .map-container h3, .card h3 { margin-bottom: 14px; color: #1d3c50; }
        #map { width: 100%; height: calc(100vh - 100px); min-height: 520px; border-radius: 11px; }
        .right-panel { width: 50%; min-width: 0; display: flex; flex-direction: column; gap: 18px; }
        .card { background: white; padding: 19px; border-radius: 14px; box-shadow: 0 8px 24px rgba(25,45,65,.08); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 11px 9px; border-bottom: 1px solid #e8edf2; text-align: left; font-size: 13px; white-space: nowrap; }
        th { background: #f4f7fa; color: #33566a; font-weight: 600; }
        .HIGH { color: #d64545; font-weight: bold; } .MEDIUM { color: #d18b16; } .LOW { color: #16846d; } .PENDING { color: #748397; }
        button { padding: 8px 12px; border: none; border-radius: 7px; cursor: pointer; color: white; background: #327fa0; font-weight: 600; }
        button:hover { filter: brightness(.94); }
        .btn-danger { background: #d65b4a; }
        .btn-success { background: #168a72; white-space: nowrap; }
        input[type="text"], input[type="number"], input[type="password"], select { padding: 9px 10px; border: 1px solid #d5dee7; border-radius: 7px; margin-bottom: 5px; width: 100%; background: white; color: #172033; }
        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #e6f4ef; color: #167558; }
        .status-ABSENT, .status-UNASSIGNED { background: #fff0e8; color: #ad5428; }
        .status-STUCK { background: #fde8e8; color: #b52e35; }
        .status-ON-ROUTE { background: #e8efff; color: #3659a5; }
        .status-BREAKDOWN { background: #fde8e8; color: #b52e35; }
        @media (max-width: 1050px) {
            body { display: block; }
            .sidebar { width: 100%; flex-direction: row; align-items: center; gap: 8px; }
            .sidebar h2 { margin: 0 auto 0 0; }
            .sidebar a { margin: 0; }
            .main { flex-direction: column; }
            .map-container, .right-panel { width: 100%; }
            #map { height: 55vh; min-height: 340px; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2><i class="fas fa-recycle"></i> Admin</h2>
        <a href="admin.php" class="active"><i class="fas fa-home"></i> Dashboard</a>
        <a href="complaints.php"><i class="fas fa-exclamation-triangle"></i> Complaints</a>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main">
        <div class="map-container">
            <h3>Marudham Live Map</h3>
            <div id="map"></div>
        </div>

        <div class="right-panel">
            <div class="card">
                <h3><i class="fas fa-weight"></i> Update Bin Weight</h3>
                <table id="binsTable"><thead><tr><th>Location</th><th>Priority</th><th>Action</th></tr></thead><tbody></tbody></table>
            </div>
            <div class="card">
                <h3><i class="fas fa-truck"></i> Manage Vehicles</h3>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="vNum" placeholder="Vehicle No (TN-43...)">
                    <input type="number" id="vCap" placeholder="Capacity (kg)">
                    <button class="btn-success" onclick="addVehicle()">Add</button>
                </div>
                <table id="vehiclesTable">
                    <thead><tr><th>Vehicle</th><th>Driver</th><th>Vehicle status</th><th>Action</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="card">
                <h3><i class="fas fa-user-check"></i> Driver status</h3>
                <table id="driversTable">
                    <thead><tr><th>Driver</th><th>Vehicle</th><th>Status</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        var map = L.map('map').setView([11.4100, 76.6950], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        let markersLayer = L.layerGroup().addTo(map);

        function loadBins() {
            fetch('api.php?action=get_bins').then(res => res.json()).then(data => {
                let html = '<tr><th>Location</th><th>Priority</th><th>Action</th></tr>';
                markersLayer.clearLayers();

                data.forEach(b => {
                    html += `<tr><td>${b.location_name}</td><td class="${b.priority}">${b.priority}</td>
                             <td><input type="number" id="w${b.id}" style="width:60px; display:inline-block;"><button onclick="updateWeight(${b.id})">Update</button></td></tr>`;

                    L.circleMarker([b.latitude, b.longitude], {color: b.priority==='HIGH'?'red':'green'})
                     .addTo(markersLayer)
                     .bindTooltip(b.location_name, {permanent: true, direction: "top"});
                });
                L.circleMarker([11.4115, 76.6960], {color: 'blue', radius: 10, fillOpacity: 0.8})
                 .addTo(markersLayer).bindTooltip("Marudham Central Depot", {permanent: true, direction: "top"});
                document.getElementById('binsTable').innerHTML = html;
            });
        }

        function loadVehicles() {
            fetch('api.php?action=get_vehicles').then(res => res.json()).then(data => {
                let html = '<tr><th>Vehicle</th><th>Driver</th><th>Vehicle status</th><th>Action</th></tr>';
                data.forEach(v => {
                    let driverName = v.driver_name ? v.driver_name : "<span style='color:red;'>Unassigned</span>";
                    let vehicleAction = v.status === 'BREAKDOWN'
                        ? `<button class="btn-success" onclick="markVehicleRepaired(${v.id})">Mark repaired</button>`
                        : `<button class="btn-danger" onclick="removeVehicle(${v.id})">Remove</button>`;
                    html += `<tr><td>${v.vehicle_number}</td><td>${driverName}</td>
                             <td><span class="status-badge">${v.status}</span></td>
                             <td>${vehicleAction}</td></tr>`;
                });
                document.getElementById('vehiclesTable').innerHTML = html;
            });
        }

        function loadDrivers() {
            fetch('api.php?action=get_drivers').then(res => res.json()).then(data => {
                let html = '<tr><th>Driver</th><th>Vehicle</th><th>Status</th></tr>';
                data.forEach(driver => {
                    let statusClass = 'status-' + driver.driver_status.replace(' ', '-');
                    let vehicle = driver.vehicle_number || 'Spare';
                    html += `<tr>
                        <td>${driver.username}</td>
                        <td>${vehicle}</td>
                        <td><span class="status-badge ${statusClass}">${driver.driver_status}</span></td>
                    </tr>`;
                });
                document.getElementById('driversTable').innerHTML = html || '<tr><td colspan="3">No drivers found.</td></tr>';
            });
        }

        function updateWeight(id) {
            let w = document.getElementById('w'+id).value; if(!w) return;
            fetch('api.php?action=update_weight', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${id}&weight=${w}`})
            .then(res=>res.json()).then(data=>{ loadBins(); });
        }

        function addVehicle() {
            let v = document.getElementById('vNum').value;
            let c = document.getElementById('vCap').value;
            if(!v || !c) return alert("Enter details!");
            fetch('api.php?action=add_vehicle', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`vehicle_number=${v}&capacity=${c}`})
            .then(res=>res.json()).then(data=>{
                loadVehicles();
                loadDrivers();
                document.getElementById('vNum').value = '';
                document.getElementById('vCap').value = '';
                alert(data.msg);
            });
        }

        function markVehicleRepaired(vehicleId) {
            if (!confirm('Confirm that this vehicle has been repaired and is ready to use?')) return;
            fetch('api.php?action=update_vehicle_status', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `vehicle_id=${vehicleId}`
            }).then(res => res.json()).then(data => {
                alert(data.msg || 'Could not update vehicle status.');
                loadVehicles();
            });
        }

        function removeVehicle(id) {
            if(confirm("Are you sure?")) {
                fetch('api.php?action=remove_vehicle', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${id}`})
                .then(res=>res.json()).then(data=>{ loadVehicles(); loadDrivers(); });
            }
        }

        loadBins(); loadVehicles(); loadDrivers();
        window.setInterval(() => {
            loadVehicles();
            loadDrivers();
        }, 10000);
    </script>
</body>
</html>