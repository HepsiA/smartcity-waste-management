<?php
include 'db.php';
header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

// --- ADMIN ACTIONS ---

// 1. Admin: Get Bins (Depot-a thavira)
if ($action == 'get_bins') {
    $res = $conn->query("SELECT * FROM bins WHERE location_name NOT LIKE '%Depot%' ORDER BY id ASC");
    $bins = []; while($row = $res->fetch_assoc()) $bins[] = $row;
    echo json_encode($bins); exit();
}

// 2. Admin: Add Vehicle (Auto Assign to Spare Driver)
// 3. Add Vehicle (Auto Assign to Spare Driver)
if ($action == 'add_vehicle') {
    $vNum = trim($_POST['vehicle_number']); 
    $capacity = $_POST['capacity'];
    
    // 1. Vehicle-a Insert pandrom
    $conn->query("INSERT INTO vehicles (vehicle_number, max_capacity, status) VALUES ('$vNum', $capacity, 'AVAILABLE')");
    $vid = $conn->insert_id;

    // 2. Spare-a irukkura oru driver-a thedrom
    $spare = $conn->query("SELECT id FROM users WHERE role='DRIVER' AND vehicle_id IS NULL LIMIT 1")->fetch_assoc();
    
    if($spare) {
        $driverId = $spare['id'];
        // 3. Andha driver-oda vehicle_id-a update pandrom
        $conn->query("UPDATE users SET vehicle_id=$vid WHERE id=$driverId");
        // 4. Vehicle status-a WORKING ku maathrom
        $conn->query("UPDATE vehicles SET status='WORKING' WHERE id=$vid");
        
        echo json_encode(["status" => "success", "msg" => "Vehicle Added & Auto-Assigned to Spare Driver!"]); 
    } else {
        echo json_encode(["status" => "success", "msg" => "Vehicle Added. No spare drivers available."]); 
    }
    exit();
}

// 3. Admin: Update Weight & Auto Generate Route
if ($action == 'update_weight') {
    $id = $_POST['id']; $w = $_POST['weight'];
    $conn->query("UPDATE bins SET current_weight=$w WHERE id=$id");
    
    // Recalculate Priority
    $res = $conn->query("SELECT * FROM bins WHERE location_name NOT LIKE '%Depot%'");
    while($row = $res->fetch_assoc()) {
        $percent = ($row['current_weight'] / $row['max_capacity']) * 100;
        $p = 'PENDING';
        if($percent > 80) $p = 'HIGH'; 
        elseif($percent >= 50) $p = 'MEDIUM'; 
        elseif($percent > 0) $p = 'LOW';
        $conn->query("UPDATE bins SET priority='$p' WHERE id={$row['id']}");
    }

    // Clear old routes
    $conn->query("DELETE FROM routes"); 
    $conn->query("DELETE FROM route_stops");
    $conn->query("UPDATE bins SET status='PENDING'");
    
    // Get pending bins (Weight-wise sorted)
    $bins = $conn->query("SELECT * FROM bins WHERE status='PENDING' AND current_weight > 0 AND location_name NOT LIKE '%Depot%' ORDER BY current_weight DESC");
    $pendingBins = [];
    while($row = $bins->fetch_assoc()) $pendingBins[] = $row;

    if(!empty($pendingBins)) {
        // Get Drivers + Vehicle Capacity
        $drivers = $conn->query("SELECT u.id, u.vehicle_id, v.max_capacity FROM users u 
                                 JOIN vehicles v ON u.vehicle_id = v.id 
                                 WHERE u.role='DRIVER' AND u.vehicle_id IS NOT NULL AND u.is_available=1");
        $driverList = []; while($d = $drivers->fetch_assoc()) $driverList[] = $d;

        if(count($driverList) > 0) {
            $dIndex = 0; $currentRouteId = null; $routeOrder = 1; $currentCapacity = 0; 

            foreach($pendingBins as $b) {
                // Split Route Logic (If Capacity Full)
                if($currentCapacity + $b['current_weight'] > $driverList[$dIndex]['max_capacity']) {
                    $dIndex = ($dIndex + 1) % count($driverList); 
                    $currentRouteId = null; $routeOrder = 1; $currentCapacity = 0;
                }

                $driver = $driverList[$dIndex];
                
                if($currentRouteId == null) {
                    $conn->query("INSERT INTO routes (driver_id, vehicle_id) VALUES ({$driver['id']}, {$driver['vehicle_id']})");
                    $currentRouteId = $conn->insert_id;
                }

                $conn->query("INSERT INTO route_stops (route_id, bin_id, stop_order) VALUES ($currentRouteId, {$b['id']}, $routeOrder)");
                $routeOrder++;
                $currentCapacity += $b['current_weight'];
            }
        }
    }
    echo json_encode(["status" => "success", "msg" => "Route Auto-Generated!"]); exit();
}

// 4. Admin: Remove Vehicle
if ($action == 'remove_vehicle') {
    $id = $_POST['id'];
    $conn->query("UPDATE users SET vehicle_id=NULL WHERE vehicle_id=$id");
    $conn->query("DELETE FROM vehicles WHERE id=$id");
    echo json_encode(["status" => "success", "msg" => "Vehicle Removed!"]); exit();
}

// 5. Admin: Get Vehicles
if ($action == 'get_vehicles') {
    $res = $conn->query("SELECT v.*, u.username as driver_name FROM vehicles v LEFT JOIN users u ON v.id = u.vehicle_id");
    $v = []; while($row = $res->fetch_assoc()) $v[] = $row;
    echo json_encode($v); exit();
}

// --- DRIVER ACTIONS ---

// 1. Driver: Get Dashboard
if ($action == 'get_driver_dashboard') {
    $did = $_SESSION['user_id'];
    $v = $conn->query("SELECT * FROM vehicles WHERE id = (SELECT vehicle_id FROM users WHERE id=$did)")->fetch_assoc();
    
    $stops = [];
    if($v) {
        $res = $conn->query("SELECT rs.id as stop_id, b.id as bin_id, b.location_name, b.latitude, b.longitude, b.current_weight, b.priority 
                             FROM route_stops rs 
                             JOIN routes r ON rs.route_id = r.id 
                             JOIN bins b ON rs.bin_id = b.id 
                             WHERE r.driver_id=$did AND rs.status='PENDING' AND r.status='ACTIVE' ORDER BY rs.stop_order ASC");
        while($row = $res->fetch_assoc()) $stops[] = $row;
    }
    echo json_encode(["vehicle" => $v, "stops" => $stops]); exit();
}

// 2. Driver: Collect Waste
if ($action == 'collect') {
    $sid = $_POST['stop_id']; $bid = $_POST['bin_id']; $img = $_POST['image']; $weight = $_POST['weight'];
    $imgName = "proof_".time().".png";
    file_put_contents("uploads/".$imgName, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $img)));
    
    $conn->query("UPDATE route_stops SET status='COMPLETED', proof_image='$imgName' WHERE id=$sid");
    $conn->query("UPDATE bins SET current_weight=0, priority='PENDING', status='COLLECTED' WHERE id=$bid");
    
    $did = $_SESSION['user_id'];
    $v = $conn->query("SELECT * FROM vehicles WHERE id=(SELECT vehicle_id FROM users WHERE id=$did)")->fetch_assoc();
    $newLoad = $v['current_load'] + $weight;
    $conn->query("UPDATE vehicles SET current_load=$newLoad WHERE id={$v['id']}");
    
    $msg = "Collected!";
    if($newLoad >= ($v['max_capacity'] * 0.9)) $msg = "Warning: Vehicle Nearing Capacity!";
    echo json_encode(["status" => "success", "msg" => $msg]); exit();
}

// 3. Driver: Submit Complaint
if ($action == 'submit_complaint') {
    $driverId = $_POST['driver_id']; $msg = $_POST['message'];
    $conn->query("INSERT INTO complaints (driver_id, message) VALUES ('$driverId', '$msg')");
    echo json_encode(["status" => "success"]); exit();
}
?>