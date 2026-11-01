<?php include 'db.php'; if(!isset($_SESSION['role']) || $_SESSION['role'] != 'DRIVER') header("Location: welcome.html"); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Driver Dashboard</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { display: flex; height: 100vh; background: #f4f6f9; }
        .sidebar { width: 350px; background: #e67e22; color: white; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; }
        .sidebar h2 { margin-bottom: 15px; }
        
        .vehicle-box { background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; margin-bottom: 15px; font-size: 14px; }
        .warning-box { background: #e74c3c; color: white; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px; display: none; text-align: center; font-weight: bold; }
        
        .stop-card { background: white; color: black; padding: 15px; margin-bottom: 10px; border-radius: 10px; border-left: 5px solid #2ecc71; box-shadow: 0 2px 5px rgba(0,0,0,0.1);}
        .stop-card h4 { font-size: 16px; margin-bottom: 5px; color: #2c3e50;}
        .stop-card p { font-size: 13px; color: #7f8c8d; margin-bottom: 10px;}
        .stop-card button { padding: 8px; width: 100%; background: #2ecc71; color: white; border: none; cursor: pointer; border-radius: 5px; font-weight: bold;}
        
        .btn-logout { display:block; text-align:center; margin-top:auto; background:#e74c3c; padding:12px; border-radius:5px; color:white; text-decoration:none; font-weight: bold; margin-bottom: 10px;}
        
        .main { flex: 1; padding: 20px; }
        #map { width: 100%; height: 90vh; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Driver Dashboard</h2>
        <div class="vehicle-box" id="vehicleBox">Loading...</div>
        <div class="warning-box" id="warningBox"><i class="fas fa-exclamation-triangle"></i> Warning: Vehicle Nearing Capacity!</div>
        <div id="stops"></div>
        <a href="logout.php" class="btn-logout">Logout</a>
    </div>
    <div class="main"><div id="map"></div></div>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        var map = L.map('map').setView([11.4100, 76.6950], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        function loadDashboard() {
            fetch('api.php?action=get_driver_dashboard').then(res => res.json()).then(data => {
                if(data.vehicle) {
                    let loadPercent = (data.vehicle.current_load / data.vehicle.max_capacity) * 100;
                    if(loadPercent >= 90) {
                        document.getElementById('warningBox').style.display = 'block';
                    } else {
                        document.getElementById('warningBox').style.display = 'none';
                    }

                    document.getElementById('vehicleBox').innerHTML = `
                        <b>Vehicle:</b> ${data.vehicle.vehicle_number} <br>
                        <b>Capacity:</b> ${data.vehicle.current_load} / ${data.vehicle.max_capacity} kg <br>
                        <b>Status:</b> ${data.vehicle.status}
                    `;
                } else {
                    document.getElementById('vehicleBox').innerHTML = `<b style="color:#e74c3c;">You are a Spare Driver. Waiting for vehicle assignment...</b>`;
                }

                let html = '';
                let coords = [];
                
                data.stops.forEach(s => {
                    coords.push([s.latitude, s.longitude]); 

                    let borderColor = '#2ecc71'; 
                    if(s.priority === 'HIGH') borderColor = '#e74c3c';
                    else if(s.priority === 'MEDIUM') borderColor = '#f39c12';

                    html += `<div class="stop-card" style="border-left-color: ${borderColor};">
                                <h4>${s.location_name} (${s.priority})</h4>
                                <p>Weight: ${s.current_weight} kg</p>
                                <button onclick="collect(${s.stop_id}, ${s.bin_id}, ${s.current_weight})">Collect & Proof</button>
                             </div>`;
                });
                document.getElementById('stops').innerHTML = html || '<p>No pending stops.</p>';

                if(coords.length > 1) {
                    let osrmStr = coords.map(c => `${c[1]},${c[0]}`).join(';');
                    fetch(`https://router.project-osrm.org/route/v1/driving/${osrmStr}?overview=full&geometries=geojson`)
                    .then(r => r.json()).then(osrm => {
                        let line = osrm.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
                        L.polyline(line, {color: 'blue', weight: 5}).addTo(map);
                    }).catch(() => {
                        L.polyline(coords, {color: 'blue', weight: 4, dashArray: '10, 10'}).addTo(map);
                    });
                }
            });

            // Map-la 15 points + Depot
            fetch('api.php?action=get_bins').then(res => res.json()).then(bins => {
                bins.forEach(b => {
                    let color = 'gray';
                    if(b.priority === 'HIGH') color = 'red';
                    else if(b.priority === 'MEDIUM') color = 'orange';
                    else if(b.priority === 'LOW') color = 'green';

                    L.circleMarker([b.latitude, b.longitude], {color: color, radius: 8}).addTo(map)
                     .bindTooltip(b.location_name, {permanent: true, direction: "top"});
                });
                
                // Depot Marker (Blue)
                L.circleMarker([11.4115, 76.6960], {color: 'blue', radius: 10}).addTo(map)
                 .bindTooltip("Marudham Central Depot", {permanent: true, direction: "top"});
            });
        }

        function collect(stopId, binId, weight) {
            let input = document.createElement('input'); input.type = 'file'; input.accept = 'image/*';
            input.onchange = (e) => {
                let reader = new FileReader();
                reader.onload = (ev) => {
                    fetch('api.php?action=collect', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`stop_id=${stopId}&bin_id=${binId}&weight=${weight}&image=${ev.target.result}`})
                    .then(res => res.json()).then(data => { alert(data.msg); location.reload(); });
                };
                reader.readAsDataURL(e.target.files[0]);
            };
            input.click();
        }
        loadDashboard();
    </script>
</body>
</html>