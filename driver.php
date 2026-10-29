<?php include 'db.php'; if(!isset($_SESSION['role']) || $_SESSION['role'] != 'DRIVER') header("Location: welcome.html"); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Driver Dashboard</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Poppins', sans-serif; display: flex; margin: 0; height: 100vh; }
        .sidebar { width: 320px; background: #e67e22; color: white; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; }
        .main { flex: 1; padding: 20px; }
        #map { width: 100%; height: 90vh; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.2); }
        .stop-card { background: white; color: black; padding: 15px; margin-bottom: 10px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .stop-card.next { border-left: 5px solid #2ecc71; }
        .stop-card h3 { margin: 0 0 5px 0; font-size: 16px; }
        button { padding: 10px; width: 100%; margin-top: 10px; background: #2ecc71; color: white; border: none; cursor: pointer; border-radius: 5px; font-weight: bold; }
        .btn-complaint { background: #e74c3c; margin-top: auto; margin-bottom: 20px; }
        .btn-complaint:hover { background: #c0392b; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Driver Route</h2>
        <div id="stops" style="flex-grow: 1;"></div>
        
        <!-- Complaint Button -->
        <button class="btn-complaint" onclick="reportIssue()"><i class="fas fa-exclamation-triangle"></i> Report Breakdown / Issue</button>
        <a href="logout.php" style="color:white; text-align:center; margin-top:10px; text-decoration:none;">Logout</a>
    </div>
    <div class="main"><div id="map"></div></div>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        var map = L.map('map').setView([11.4100, 76.6950], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        // Driver ID session-la irundhu edukirom
        let driverId = <?php echo isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0; ?>;

        function loadRoute() {
            fetch('api.php?action=get_driver_route').then(res => res.json()).then(data => {
                if(data.length === 0) { document.getElementById('stops').innerHTML = '<p>No route assigned.</p>'; return; }
                let html = ''; let coords = [];
                data.forEach((s, index) => {
                    coords.push([s.latitude, s.longitude]);
                    let isNext = index === 0 ? 'next' : '';
                    html += `<div class="stop-card ${isNext}">
                                <h3>Step ${s.stop_order}: ${s.location_name}</h3>
                                <p style="font-size:13px; color:gray;">Weight: ${s.current_weight} kg</p>
                                <button onclick="collect(${s.stop_id}, ${s.bin_id})">Collect & Proof</button>
                             </div>`;
                    L.marker([s.latitude, s.longitude]).addTo(map).bindPopup(`Step ${s.stop_order}`);
                });
                document.getElementById('stops').innerHTML = html;

                if(coords.length > 1) {
                    let osrmStr = coords.map(c => `${c[1]},${c[0]}`).join(';');
                    fetch(`https://router.project-osrm.org/route/v1/driving/${osrmStr}?overview=full&geometries=geojson`)
                    .then(r => r.json()).then(osrm => {
                        let line = osrm.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
                        L.polyline(line, {color: 'orange', weight: 5}).addTo(map);
                    }).catch(() => { L.polyline(coords, {color: 'orange', weight: 5}).addTo(map); });
                }
            });
        }

        function collect(stopId, binId) {
            let input = document.createElement('input'); input.type = 'file'; input.accept = 'image/*';
            input.onchange = (e) => {
                let reader = new FileReader();
                reader.onload = (ev) => {
                    fetch('api.php?action=collect', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`stop_id=${stopId}&bin_id=${binId}&image=${ev.target.result}`})
                    .then(res => res.json()).then(data => { alert('Collected!'); loadRoute(); });
                };
                reader.readAsDataURL(e.target.files[0]);
            };
            input.click();
        }

        // Complaint submit panna function
        function reportIssue() {
            let msg = prompt("Enter issue details (e.g., Vehicle Breakdown, Route Blocked):");
            if(msg && msg.trim() !== "") {
                fetch('api.php?action=submit_complaint', {
                    method: 'POST', 
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
                    body: `driver_id=${driverId}&message=${msg}`
                }).then(res => res.json()).then(data => { 
                    if(data.status === 'success') alert('Complaint sent to Admin!'); 
                });
            }
        }
        loadRoute();
    </script>
</body>
</html>