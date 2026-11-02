<?php include 'db.php'; if(!isset($_SESSION['role']) || $_SESSION['role'] != 'DRIVER') header("Location: welcome.html"); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Driver Dashboard</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { display: flex; min-height: 100vh; background: #f1f5f9; color: #172033; }
        .sidebar { width: 360px; min-width: 320px; background: linear-gradient(160deg, #123b45, #176b62); color: white; padding: 26px 22px; overflow-y: auto; display: flex; flex-direction: column; }
        .sidebar h2 { margin-bottom: 20px; font-size: 23px; letter-spacing: .2px; }
        .vehicle-box { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.12); padding: 16px; border-radius: 12px; margin-bottom: 15px; font-size: 14px; line-height: 1.8; }
        .warning-box { background: #fff3cd; color: #815b00; padding: 12px; border-radius: 10px; margin-bottom: 15px; font-size: 13px; display: none; text-align: center; font-weight: 600; }
        #stops > p { padding: 14px; color: rgba(255,255,255,.85); background: rgba(255,255,255,.1); border-radius: 10px; }
        .stop-card { background: white; color: #172033; padding: 16px; margin-bottom: 12px; border-radius: 12px; border-left: 5px solid #16a085; box-shadow: 0 5px 16px rgba(8,30,35,.12); }
        .stop-card h4 { font-size: 15px; margin-bottom: 6px; color: #183c42; }
        .stop-card p { font-size: 13px; color: #64748b; margin-bottom: 12px; }
        .stop-card button, .btn-complaint { padding: 10px 12px; width: 100%; color: white; border: none; cursor: pointer; border-radius: 8px; font-weight: 600; transition: transform .15s, opacity .15s; }
        .stop-card button { background: #138a72; }
        .stop-card button:disabled { background: #94a3b8; cursor: not-allowed; }
        .stop-card button:not(:disabled):hover, .btn-complaint:hover { transform: translateY(-1px); opacity: .93; }
        .btn-complaint { background: #d65b4a; margin-top: 15px; }
        .btn-logout { display:block; text-align:center; margin-top:auto; background: rgba(10,25,30,.4); padding:11px; border-radius:8px; color:white; text-decoration:none; font-weight: 600; margin-bottom: 4px; }
        .main { flex: 1; min-width: 0; padding: 22px; }
        #map { width: 100%; height: calc(100vh - 44px); min-height: 420px; border-radius: 16px; box-shadow: 0 8px 26px rgba(25,45,65,.12); }
        @media (max-width: 760px) {
            body { display: block; }
            .sidebar { width: 100%; min-width: 0; max-height: 55vh; }
            .main { padding: 12px; }
            #map { height: 55vh; min-height: 320px; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Driver Dashboard</h2>
        <div class="vehicle-box" id="vehicleBox">Loading...</div>
        <div class="warning-box" id="warningBox"><i class="fas fa-exclamation-triangle"></i> Warning: Vehicle Nearing Capacity!</div>
        <div id="stops"></div>
        <button class="btn-complaint" onclick="submitComplaint()"><i class="fas fa-tools"></i> Report Breakdown / Issue</button>
        <a href="logout.php" class="btn-logout">Logout</a>
    </div>
    <div class="main"><div id="map"></div></div>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        var map = L.map('map').setView([11.4100, 76.6950], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        let routeLayer = L.layerGroup().addTo(map);
        let driverId = <?php echo $_SESSION['user_id']; ?>;

        function loadDashboard() {
            fetch('api.php?action=get_driver_dashboard').then(res => res.json()).then(data => {
                if(data.vehicle) {
                    let assignmentKey = `smartcity-driver-${driverId}-vehicle`;
                    if (localStorage.getItem(assignmentKey) !== String(data.vehicle.id)) {
                        alert(`A vehicle has been assigned to you: ${data.vehicle.vehicle_number}`);
                        localStorage.setItem(assignmentKey, String(data.vehicle.id));
                    }
                    let loadPercent = (data.vehicle.current_load / data.vehicle.max_capacity) * 100;
                    if(loadPercent >= 90) { document.getElementById('warningBox').style.display = 'block'; }
                    else { document.getElementById('warningBox').style.display = 'none'; }

                    document.getElementById('vehicleBox').innerHTML = `
                        <b>Vehicle:</b> ${data.vehicle.vehicle_number} <br>
                        <b>Capacity:</b> ${data.vehicle.current_load} / ${data.vehicle.max_capacity} kg <br>
                        <b>Status:</b> ${data.vehicle.status}
                    `;
                } else {
                    document.getElementById('vehicleBox').innerHTML = `<b style="color:red;">You are a Spare Driver...</b>`;
                }

                let html = '';
                let coords = [];
                routeLayer.clearLayers();
                
                data.stops.sort((a, b) => Number(b.current_weight) - Number(a.current_weight));
                data.stops.forEach((s, index) => {
                    coords.push([s.latitude, s.longitude]); 
                    let borderColor = s.priority === 'HIGH' ? '#e74c3c' : (s.priority === 'MEDIUM' ? '#f39c12' : '#2ecc71');
                    html += `<div class="stop-card" style="border-left-color: ${borderColor};">
                                <h4>${index + 1}. ${s.location_name} (${s.priority})</h4>
                                <p>Weight: <strong>${s.current_weight} kg</strong>${index === 0 ? ' · Next stop' : ''}</p>
                                <button ${index > 0 ? 'disabled' : ''} onclick="collect(${s.stop_id}, ${s.bin_id})">${index === 0 ? 'Collect & Proof' : 'Collect in order'}</button>
                             </div>`;
                });
                document.getElementById('stops').innerHTML = html || '<p>No pending stops.</p>';

                if(coords.length > 0) {
                    let depot = [11.4115, 76.6960];
                    let routeCoords = [depot, ...coords];
                    let osrmStr = routeCoords.map(c => `${c[1]},${c[0]}`).join(';');
                    fetch(`https://router.project-osrm.org/route/v1/driving/${osrmStr}?overview=full&geometries=geojson`)
                    .then(r => r.json()).then(osrm => {
                        if (!osrm.routes || !osrm.routes.length) {
                            throw new Error('No drivable route was returned.');
                        }
                        let line = osrm.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
                        L.polyline(line, {color: 'blue', weight: 5}).addTo(routeLayer);
                    }).catch(() => {
                        L.polyline(routeCoords, {color: 'blue', weight: 4, dashArray: '10, 10'}).addTo(routeLayer);
                    });
                }
            });

            fetch('api.php?action=get_bins').then(res => res.json()).then(bins => {
                bins.forEach(b => {
                    let color = b.priority === 'HIGH' ? 'red' : (b.priority === 'MEDIUM' ? 'orange' : 'green');
                    L.circleMarker([b.latitude, b.longitude], {color: color, radius: 8}).addTo(map)
                     .bindTooltip(b.location_name, {permanent: true, direction: "top"});
                });
                L.circleMarker([11.4115, 76.6960], {color: 'blue', radius: 10}).addTo(map)
                 .bindTooltip("Marudham Central Depot", {permanent: true, direction: "top"});
            });
        }

        function collect(stopId, binId) {
            let input = document.createElement('input'); input.type = 'file'; input.accept = 'image/*';
            input.onchange = (e) => {
                let reader = new FileReader();
                reader.onload = (ev) => {
                    fetch('api.php?action=collect', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`stop_id=${stopId}&bin_id=${binId}&image=${encodeURIComponent(ev.target.result)}`})
                    .then(res => res.json()).then(data => {
                        alert(data.msg);
                        if (data.status === 'success') location.reload();
                    });
                };
                reader.readAsDataURL(e.target.files[0]);
            };
            input.click();
        }

        function submitComplaint() {
            let msg = prompt("Enter issue details. Include 'breakdown' or 'stuck' if the vehicle cannot be driven:");
            if(msg) {
                fetch('api.php?action=submit_complaint', {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:`message=${encodeURIComponent(msg)}`
                }).then(res => res.json()).then(data => {
                    if(data.status === 'success') {
                        alert(data.msg || 'Issue reported.');
                        location.reload();
                    } else {
                        alert(data.msg || 'Could not report the issue.');
                    }
                });
            }
        }
        loadDashboard();
    </script>
</body>
</html>