<?php
include 'db.php';
header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

function assignPendingBins($conn) {
    $driverResult = $conn->query("SELECT u.id, u.vehicle_id, v.max_capacity, COALESCE(v.current_load, 0) AS current_load FROM users u JOIN vehicles v ON u.vehicle_id = v.id WHERE u.role='DRIVER' AND u.is_available=1 AND u.vehicle_id IS NOT NULL AND v.max_capacity > COALESCE(v.current_load, 0) ORDER BY u.id");
    if (!$driverResult) {
        throw new RuntimeException($conn->error);
    }

    $drivers = [];
    while ($driver = $driverResult->fetch_assoc()) {
        $drivers[$driver['id']] = [
            'vehicle_id' => $driver['vehicle_id'],
            'max_capacity' => (float)$driver['max_capacity'] - (float)$driver['current_load'],
            'assigned_weight' => 0,
            'route_id' => null,
            'stop_order' => 0
        ];
    }
    if (!$drivers) {
        return;
    }

    $routeResult = $conn->query("SELECT r.id, r.driver_id, COALESCE(SUM(CASE WHEN rs.status='PENDING' THEN b.current_weight ELSE 0 END), 0) AS assigned_weight, COALESCE(MAX(rs.stop_order), 0) AS last_stop_order FROM routes r LEFT JOIN route_stops rs ON rs.route_id = r.id LEFT JOIN bins b ON b.id = rs.bin_id WHERE r.status='ACTIVE' GROUP BY r.id, r.driver_id ORDER BY r.id");
    if (!$routeResult) {
        throw new RuntimeException($conn->error);
    }
    while ($route = $routeResult->fetch_assoc()) {
        if (isset($drivers[$route['driver_id']])) {
            $drivers[$route['driver_id']]['route_id'] = $route['id'];
            $drivers[$route['driver_id']]['assigned_weight'] += (float)$route['assigned_weight'];
            $drivers[$route['driver_id']]['stop_order'] = (int)$route['last_stop_order'];
        }
    }

    $binResult = $conn->query("SELECT b.id, b.current_weight FROM bins b WHERE b.status='PENDING' AND b.current_weight > 0 AND b.location_name NOT LIKE '%Depot%' AND NOT EXISTS (SELECT 1 FROM route_stops rs JOIN routes r ON rs.route_id = r.id WHERE rs.bin_id = b.id AND rs.status='PENDING' AND r.status='ACTIVE') ORDER BY b.current_weight DESC, b.id");
    if (!$binResult) {
        throw new RuntimeException($conn->error);
    }

    $pendingBins = [];
    while ($bin = $binResult->fetch_assoc()) {
        $pendingBins[] = $bin;
    }

    $driverIds = array_keys($drivers);
    $nextDriverIndex = 0;
    foreach ($pendingBins as $bin) {
        $weight = (float)$bin['current_weight'];
        $selectedDriverId = null;
        for ($offset = 0; $offset < count($driverIds); $offset++) {
            $index = ($nextDriverIndex + $offset) % count($driverIds);
            $driverId = $driverIds[$index];
            if ($drivers[$driverId]['assigned_weight'] + $weight <= $drivers[$driverId]['max_capacity']) {
                $selectedDriverId = $driverId;
                $nextDriverIndex = ($index + 1) % count($driverIds);
                break;
            }
        }
        if ($selectedDriverId === null) {
            continue;
        }

        if ($drivers[$selectedDriverId]['route_id'] === null) {
            $vehicleId = (int)$drivers[$selectedDriverId]['vehicle_id'];
            if (!$conn->query("INSERT INTO routes (driver_id, vehicle_id, status) VALUES ($selectedDriverId, $vehicleId, 'ACTIVE')")) {
                throw new RuntimeException($conn->error);
            }
            $drivers[$selectedDriverId]['route_id'] = $conn->insert_id;
        }

        $routeId = (int)$drivers[$selectedDriverId]['route_id'];
        $stopOrder = ++$drivers[$selectedDriverId]['stop_order'];
        $binId = (int)$bin['id'];
        if (!$conn->query("INSERT INTO route_stops (route_id, bin_id, stop_order, status) VALUES ($routeId, $binId, $stopOrder, 'PENDING')")) {
            throw new RuntimeException($conn->error);
        }
        $drivers[$selectedDriverId]['assigned_weight'] += $weight;
    }
}

function markDriverUnavailable($conn, $driverId, $reason) {
    $statusMessage = $reason === 'stuck' ? "Driver marked stuck." : "Driver marked absent.";
    $driverResult = $conn->query("SELECT vehicle_id FROM users WHERE id=$driverId AND role='DRIVER'");
    if (!$driverResult) {
        throw new RuntimeException($conn->error);
    }
    $driver = $driverResult->fetch_assoc();
    if (!$driver) {
        throw new RuntimeException("Driver not found.");
    }

    $vehicleId = (int)$driver['vehicle_id'];
    if ($vehicleId < 1) {
        if (!$conn->query("UPDATE users SET is_available=0 WHERE id=$driverId")) {
            throw new RuntimeException($conn->error);
        }
        return "$statusMessage No vehicle is currently assigned.";
    }

    $spareResult = $conn->query("SELECT id, username FROM users WHERE role='DRIVER' AND id<>$driverId AND vehicle_id IS NULL AND is_available=1 ORDER BY id LIMIT 1");
    if (!$spareResult) {
        throw new RuntimeException($conn->error);
    }
    $spare = $spareResult->fetch_assoc();
    if (!$conn->query("UPDATE users SET vehicle_id=NULL, is_available=0 WHERE id=$driverId")) {
        throw new RuntimeException($conn->error);
    }

    if ($spare) {
        $spareId = (int)$spare['id'];
        if (!$conn->query("UPDATE users SET vehicle_id=$vehicleId, is_available=1 WHERE id=$spareId")) {
            throw new RuntimeException($conn->error);
        }
        if (!$conn->query("UPDATE vehicles SET status='WORKING' WHERE id=$vehicleId")) {
            throw new RuntimeException($conn->error);
        }
        if (!$conn->query("UPDATE routes SET driver_id=$spareId WHERE vehicle_id=$vehicleId AND status='ACTIVE'")) {
            throw new RuntimeException($conn->error);
        }
        return "$statusMessage Vehicle and active route assigned to " . $spare['username'] . ".";
    }

    if (!$conn->query("UPDATE vehicles SET status='AVAILABLE' WHERE id=$vehicleId")) {
        throw new RuntimeException($conn->error);
    }
    return "$statusMessage Vehicle is available for a spare driver.";
}

function handleVehicleBreakdown($conn, $driverId) {
    $driverResult = $conn->query("SELECT vehicle_id FROM users WHERE id=$driverId AND role='DRIVER'");
    if (!$driverResult) {
        throw new RuntimeException($conn->error);
    }
    $driver = $driverResult->fetch_assoc();
    if (!$driver || !$driver['vehicle_id']) {
        if (!$conn->query("UPDATE users SET is_available=0 WHERE id=$driverId")) {
            throw new RuntimeException($conn->error);
        }
        return "Driver marked unavailable. No assigned vehicle was found to transfer.";
    }

    $vehicleId = (int)$driver['vehicle_id'];
    $stopsResult = $conn->query("SELECT rs.id AS stop_id, b.id AS bin_id, b.current_weight
                                 FROM route_stops rs
                                 JOIN routes r ON r.id=rs.route_id
                                 JOIN bins b ON b.id=rs.bin_id
                                 WHERE r.driver_id=$driverId AND r.vehicle_id=$vehicleId
                                   AND r.status='ACTIVE' AND rs.status='PENDING'
                                   AND b.status='PENDING' AND b.current_weight > 0
                                 ORDER BY b.current_weight DESC, rs.stop_order, rs.id");
    if (!$stopsResult) {
        throw new RuntimeException($conn->error);
    }
    $stops = [];
    while ($stop = $stopsResult->fetch_assoc()) {
        $stops[] = $stop;
    }

    if (!$conn->query("UPDATE users SET vehicle_id=NULL, is_available=0 WHERE id=$driverId")
        || !$conn->query("UPDATE vehicles SET status='BREAKDOWN' WHERE id=$vehicleId")) {
        throw new RuntimeException($conn->error);
    }

    $sparesResult = $conn->query("SELECT id FROM users WHERE role='DRIVER' AND vehicle_id IS NULL AND is_available=1 ORDER BY id");
    $vehiclesResult = $conn->query("SELECT id FROM vehicles WHERE status='AVAILABLE' ORDER BY id");
    if (!$sparesResult || !$vehiclesResult) {
        throw new RuntimeException($conn->error);
    }
    $spares = [];
    while ($spare = $sparesResult->fetch_assoc()) {
        $spares[] = $spare;
    }
    $availableVehicles = [];
    while ($vehicle = $vehiclesResult->fetch_assoc()) {
        $availableVehicles[] = $vehicle;
    }
    $pairCount = min(count($spares), count($availableVehicles));
    for ($i = 0; $i < $pairCount; $i++) {
        $spareId = (int)$spares[$i]['id'];
        $availableVehicleId = (int)$availableVehicles[$i]['id'];
        if (!$conn->query("UPDATE users SET vehicle_id=$availableVehicleId WHERE id=$spareId")
            || !$conn->query("UPDATE vehicles SET status='WORKING' WHERE id=$availableVehicleId")) {
            throw new RuntimeException($conn->error);
        }
    }

    $driversResult = $conn->query("SELECT u.id, u.vehicle_id, v.max_capacity, COALESCE(v.current_load, 0) AS current_load
                                  FROM users u JOIN vehicles v ON u.vehicle_id=v.id
                                  WHERE u.role='DRIVER' AND u.is_available=1 AND u.vehicle_id<>$vehicleId
                                    AND v.status='WORKING'
                                  ORDER BY u.id");
    if (!$driversResult) {
        throw new RuntimeException($conn->error);
    }
    $drivers = [];
    while ($candidate = $driversResult->fetch_assoc()) {
        $candidateId = (int)$candidate['id'];
        $drivers[$candidateId] = [
            'vehicle_id' => (int)$candidate['vehicle_id'],
            'capacity' => (float)$candidate['max_capacity'] - (float)$candidate['current_load'],
            'assigned_weight' => 0,
            'route_id' => null,
            'stop_order' => 0
        ];
    }

    $routesResult = $conn->query("SELECT r.id, r.driver_id,
                                  COALESCE(SUM(CASE WHEN rs.status='PENDING' THEN b.current_weight ELSE 0 END), 0) AS assigned_weight,
                                  COALESCE(MAX(rs.stop_order), 0) AS stop_order
                                  FROM routes r
                                  LEFT JOIN route_stops rs ON rs.route_id=r.id
                                  LEFT JOIN bins b ON b.id=rs.bin_id
                                  WHERE r.status='ACTIVE'
                                  GROUP BY r.id, r.driver_id ORDER BY r.id");
    if (!$routesResult) {
        throw new RuntimeException($conn->error);
    }
    while ($route = $routesResult->fetch_assoc()) {
        $candidateId = (int)$route['driver_id'];
        if (isset($drivers[$candidateId])) {
            $drivers[$candidateId]['assigned_weight'] += (float)$route['assigned_weight'];
            $drivers[$candidateId]['route_id'] = (int)$route['id'];
            $drivers[$candidateId]['stop_order'] = max($drivers[$candidateId]['stop_order'], (int)$route['stop_order']);
        }
    }

    $driverIds = array_keys($drivers);
    $nextDriverIndex = 0;
    $unassignedStopIds = [];
    foreach ($stops as $stop) {
        $weight = (float)$stop['current_weight'];
        $selectedDriverId = null;
        for ($offset = 0; $offset < count($driverIds); $offset++) {
            $index = ($nextDriverIndex + $offset) % count($driverIds);
            $candidateId = $driverIds[$index];
            if ($drivers[$candidateId]['assigned_weight'] + $weight <= $drivers[$candidateId]['capacity']) {
                $selectedDriverId = $candidateId;
                $nextDriverIndex = ($index + 1) % count($driverIds);
                break;
            }
        }

        if ($selectedDriverId === null) {
            $unassignedStopIds[] = (int)$stop['stop_id'];
            continue;
        }

        if ($drivers[$selectedDriverId]['route_id'] === null) {
            $targetVehicleId = $drivers[$selectedDriverId]['vehicle_id'];
            if (!$conn->query("INSERT INTO routes (driver_id, vehicle_id, status) VALUES ($selectedDriverId, $targetVehicleId, 'ACTIVE')")) {
                throw new RuntimeException($conn->error);
            }
            $drivers[$selectedDriverId]['route_id'] = $conn->insert_id;
        }

        $targetRouteId = (int)$drivers[$selectedDriverId]['route_id'];
        $stopOrder = ++$drivers[$selectedDriverId]['stop_order'];
        $stopId = (int)$stop['stop_id'];
        if (!$conn->query("UPDATE route_stops SET route_id=$targetRouteId, stop_order=$stopOrder WHERE id=$stopId AND status='PENDING'")) {
            throw new RuntimeException($conn->error);
        }
        $drivers[$selectedDriverId]['assigned_weight'] += $weight;
    }

    foreach ($unassignedStopIds as $stopId) {
        if (!$conn->query("DELETE FROM route_stops WHERE id=$stopId AND status='PENDING'")) {
            throw new RuntimeException($conn->error);
        }
    }
    if ($unassignedStopIds) {
        assignPendingBins($conn);
    }

    $stopCount = count($stops);
    $assignedCount = 0;
    if ($stopCount) {
        $binIds = array_map(function ($stop) { return (int)$stop['bin_id']; }, $stops);
        $idList = implode(',', $binIds);
        $assignedResult = $conn->query("SELECT COUNT(DISTINCT b.id) AS assigned_count
                                       FROM bins b
                                       JOIN route_stops rs ON rs.bin_id=b.id AND rs.status='PENDING'
                                       JOIN routes r ON r.id=rs.route_id AND r.status='ACTIVE'
                                       WHERE b.id IN ($idList) AND r.vehicle_id<>$vehicleId");
        if (!$assignedResult) {
            throw new RuntimeException($conn->error);
        }
        $assignedCount = (int)$assignedResult->fetch_assoc()['assigned_count'];
    }

    return "Vehicle marked breakdown. $assignedCount of $stopCount pending stops transferred to other vehicles."
        . ($assignedCount < $stopCount ? " Remaining stops are unassigned until another vehicle has capacity." : "");
}

// --- ADMIN ACTIONS ---

if ($action == 'get_bins') {
    $res = $conn->query("SELECT * FROM bins WHERE location_name NOT LIKE '%Depot%' ORDER BY id ASC");
    $bins = []; while($row = $res->fetch_assoc()) $bins[] = $row;
    echo json_encode($bins); exit();
}

if ($action == 'add_vehicle') {
    $vNum = trim($_POST['vehicle_number']); $capacity = $_POST['capacity'];
    $conn->query("INSERT INTO vehicles (vehicle_number, max_capacity, status) VALUES ('$vNum', $capacity, 'AVAILABLE')");
    $vid = $conn->insert_id;
    
    $spare = $conn->query("SELECT id, username FROM users WHERE role='DRIVER' AND vehicle_id IS NULL AND is_available=1 ORDER BY id LIMIT 1")->fetch_assoc();
    $message = "Vehicle added and waiting for a spare driver.";
    if($spare) {
        $conn->query("UPDATE users SET vehicle_id=$vid, is_available=1 WHERE id={$spare['id']}");
        $conn->query("UPDATE vehicles SET status='WORKING' WHERE id=$vid");
        $message = "Vehicle assigned to driver " . $spare['username'] . ".";
    }
    echo json_encode(["status" => "success", "msg" => $message]); exit();
}

if ($action == 'update_weight') {
    $id = $_POST['id']; $w = $_POST['weight'];
    $conn->query("UPDATE bins SET current_weight=$w WHERE id=$id");
    
    $res = $conn->query("SELECT * FROM bins WHERE location_name NOT LIKE '%Depot%'");
    while($row = $res->fetch_assoc()) {
        $percent = ($row['current_weight'] / $row['max_capacity']) * 100;
        $p = 'PENDING';
        if($percent > 80) $p = 'HIGH'; 
        elseif($percent >= 50) $p = 'MEDIUM'; 
        elseif($percent > 0) $p = 'LOW';
        $conn->query("UPDATE bins SET priority='$p' WHERE id={$row['id']}");
    }

    $conn->query("DELETE FROM route_stops");
    $conn->query("DELETE FROM routes");
    $conn->query("UPDATE bins SET status='PENDING'");
    assignPendingBins($conn);
    echo json_encode(["status" => "success"]); exit();
}

if ($action == 'remove_vehicle') {
    $id = $_POST['id'];
    $conn->query("UPDATE users SET vehicle_id=NULL, is_available=1 WHERE vehicle_id=$id");
    $conn->query("DELETE FROM vehicles WHERE id=$id");
    echo json_encode(["status" => "success"]); exit();
}

if ($action == 'update_vehicle_status') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        http_response_code(403);
        echo json_encode(["status" => "error", "msg" => "Admin access required."]);
        exit();
    }
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
    if ($vehicleId < 1) {
        http_response_code(400);
        echo json_encode(["status" => "error", "msg" => "Invalid vehicle."]);
        exit();
    }
    if (!$conn->query("UPDATE vehicles SET status='AVAILABLE' WHERE id=$vehicleId AND status='BREAKDOWN'")) {
        http_response_code(500);
        echo json_encode(["status" => "error", "msg" => "Could not mark the vehicle repaired."]);
        exit();
    }
    if ($conn->affected_rows !== 1) {
        http_response_code(409);
        echo json_encode(["status" => "error", "msg" => "Vehicle is not currently marked as breakdown."]);
        exit();
    }
    echo json_encode(["status" => "success", "msg" => "Vehicle marked repaired and available."]); exit();
}

if ($action == 'get_vehicles') {
    $res = $conn->query("SELECT v.*, u.id AS driver_id, u.username AS driver_name, u.is_available AS driver_available,
                         CASE
                           WHEN u.id IS NULL THEN 'UNASSIGNED'
                           WHEN EXISTS (SELECT 1 FROM complaints c WHERE c.driver_id=u.id AND COALESCE(c.status, 'PENDING') <> 'RESOLVED') THEN 'STUCK'
                           WHEN COALESCE(u.is_available, 1)=0 THEN 'ABSENT'
                           WHEN EXISTS (SELECT 1 FROM routes r JOIN route_stops rs ON rs.route_id=r.id WHERE r.driver_id=u.id AND r.status='ACTIVE' AND rs.status='PENDING') THEN 'ON ROUTE'
                           ELSE 'AVAILABLE'
                         END AS driver_status
                         FROM vehicles v LEFT JOIN users u ON v.id = u.vehicle_id ORDER BY v.id");
    if (!$res) {
        http_response_code(500);
        echo json_encode(["status" => "error", "msg" => "Could not load vehicles."]);
        exit();
    }
    $v = []; while($row = $res->fetch_assoc()) $v[] = $row;
    echo json_encode($v); exit();
}

if ($action == 'update_driver_status') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        http_response_code(403);
        echo json_encode(["status" => "error", "msg" => "Admin access required."]);
        exit();
    }
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($driverId < 1 || !in_array($status, ['AVAILABLE', 'ABSENT'], true)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "msg" => "Invalid driver status."]);
        exit();
    }
    $driverCheck = $conn->query("SELECT id FROM users WHERE id=$driverId AND role='DRIVER'");
    if (!$driverCheck || !$driverCheck->fetch_assoc()) {
        http_response_code($driverCheck ? 404 : 500);
        echo json_encode(["status" => "error", "msg" => "Could not find that driver."]);
        exit();
    }
    $available = $status === 'AVAILABLE' ? 1 : 0;
    if ($status === 'ABSENT') {
        $message = markDriverUnavailable($conn, $driverId, 'absent');
    } else {
        $driverResult = $conn->query("SELECT vehicle_id FROM users WHERE id=$driverId AND role='DRIVER'");
        if (!$driverResult) {
            http_response_code(500);
            echo json_encode(["status" => "error", "msg" => "Could not load driver."]);
            exit();
        }
        if (!$driver = $driverResult->fetch_assoc()) {
            http_response_code(404);
            echo json_encode(["status" => "error", "msg" => "Driver not found."]);
            exit();
        }
        $vehicleId = (int)$driver['vehicle_id'];
        if ($vehicleId < 1) {
            $vehicleResult = $conn->query("SELECT id FROM vehicles WHERE status='AVAILABLE' ORDER BY id LIMIT 1");
            if (!$vehicleResult) {
                http_response_code(500);
                echo json_encode(["status" => "error", "msg" => "Could not check for available vehicles."]);
                exit();
            }
            $vehicle = $vehicleResult->fetch_assoc();
            if ($vehicle) {
                $vehicleId = (int)$vehicle['id'];
                if (!$conn->query("UPDATE users SET vehicle_id=$vehicleId, is_available=$available WHERE id=$driverId")
                    || !$conn->query("UPDATE vehicles SET status='WORKING' WHERE id=$vehicleId")
                    || !$conn->query("UPDATE routes SET driver_id=$driverId WHERE vehicle_id=$vehicleId AND status='ACTIVE'")) {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "msg" => "Could not assign the available vehicle."]);
                    exit();
                }
                $message = "Driver marked available and assigned to vehicle.";
            } else {
                if (!$conn->query("UPDATE users SET is_available=$available WHERE id=$driverId")) {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "msg" => "Could not update driver status."]);
                    exit();
                }
                $message = "Driver marked available. No vehicle is currently available.";
            }
        } else {
            if (!$conn->query("UPDATE users SET is_available=$available WHERE id=$driverId")) {
                http_response_code(500);
                echo json_encode(["status" => "error", "msg" => "Could not update driver status."]);
                exit();
            }
            $message = "Driver marked available.";
        }
    }
    echo json_encode(["status" => "success", "msg" => $message]); exit();
}

if ($action == 'get_drivers') {
    $res = $conn->query("SELECT u.id, u.username, u.vehicle_id, u.is_available,
                         v.vehicle_number,
                         CASE
                           WHEN EXISTS (SELECT 1 FROM complaints c WHERE c.driver_id=u.id AND COALESCE(c.status, 'PENDING') <> 'RESOLVED') THEN 'STUCK'
                           WHEN COALESCE(u.is_available, 1)=0 THEN 'ABSENT'
                           WHEN EXISTS (SELECT 1 FROM routes r JOIN route_stops rs ON rs.route_id=r.id WHERE r.driver_id=u.id AND r.status='ACTIVE' AND rs.status='PENDING') THEN 'ON ROUTE'
                           WHEN u.vehicle_id IS NULL THEN 'SPARE'
                           ELSE 'AVAILABLE'
                         END AS driver_status
                         FROM users u LEFT JOIN vehicles v ON v.id=u.vehicle_id
                         WHERE u.role='DRIVER' ORDER BY u.id");
    if (!$res) {
        http_response_code(500);
        echo json_encode(["status" => "error", "msg" => "Could not load driver statuses."]);
        exit();
    }
    $drivers = [];
    while ($row = $res->fetch_assoc()) $drivers[] = $row;
    echo json_encode($drivers); exit();
}

if ($action == 'get_complaints') {
    $res = $conn->query("SELECT * FROM complaints ORDER BY created_at DESC");
    $c = []; while($row = $res->fetch_assoc()) $c[] = $row;
    echo json_encode($c); exit();
}

if ($action == 'resolve_complaint') {
    $id = $_POST['id']; $img = $_POST['image'];
    $imgName = "resolve_".time().".png";
    file_put_contents("uploads/".$imgName, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $img)));
    $conn->query("UPDATE complaints SET status='RESOLVED', proof_image='$imgName' WHERE id=$id");
    $conn->query("UPDATE users u JOIN complaints c ON c.driver_id=u.id
                  SET u.is_available=CASE WHEN NOT EXISTS (
                      SELECT 1 FROM complaints open_c
                      WHERE open_c.driver_id=u.id AND open_c.id<>c.id AND COALESCE(open_c.status, 'PENDING')<>'RESOLVED'
                  ) THEN 1 ELSE 0 END WHERE c.id=$id");
    echo json_encode(["status" => "success"]); exit();
}

// --- DRIVER ACTIONS ---

if ($action == 'get_driver_dashboard') {
    $did = $_SESSION['user_id'];
    $vCheck = $conn->query("SELECT vehicle_id, is_available FROM users WHERE id=$did")->fetch_assoc();
    if($vCheck && $vCheck['vehicle_id'] == NULL && $vCheck['is_available']) {
        $freeVehicle = $conn->query("SELECT id FROM vehicles WHERE status='AVAILABLE' LIMIT 1")->fetch_assoc();
        if($freeVehicle) {
            $vid = $freeVehicle['id'];
            $conn->query("UPDATE users SET vehicle_id=$vid, is_available=1 WHERE id=$did");
            $conn->query("UPDATE vehicles SET status='WORKING' WHERE id=$vid");
            $conn->query("UPDATE routes SET driver_id=$did WHERE vehicle_id=$vid AND status='ACTIVE'");
        }
    }

    assignPendingBins($conn);

    $v = $conn->query("SELECT * FROM vehicles WHERE id = (SELECT vehicle_id FROM users WHERE id=$did)")->fetch_assoc();
    
    $stops = [];
    if($v) {
        $res = $conn->query("SELECT rs.id as stop_id, b.id as bin_id, b.location_name, b.latitude, b.longitude, b.current_weight, b.priority 
                             FROM route_stops rs JOIN routes r ON rs.route_id = r.id JOIN bins b ON rs.bin_id = b.id
                             WHERE r.driver_id=$did AND rs.status='PENDING' AND r.status='ACTIVE' ORDER BY b.current_weight DESC, rs.stop_order ASC, rs.id ASC");
        while($row = $res->fetch_assoc()) $stops[] = $row;
    }
    echo json_encode(["vehicle" => $v, "stops" => $stops]); exit();
}

if ($action == 'collect') {
    $sid = (int)($_POST['stop_id'] ?? 0);
    $bid = (int)($_POST['bin_id'] ?? 0);
    $img = $_POST['image'] ?? '';
    $did = (int)$_SESSION['user_id'];
    $nextStopResult = $conn->query("SELECT rs.id AS stop_id, b.id AS bin_id, b.current_weight
                                    FROM route_stops rs
                                    JOIN routes r ON rs.route_id=r.id
                                    JOIN bins b ON rs.bin_id=b.id
                                    WHERE r.driver_id=$did AND r.status='ACTIVE' AND rs.status='PENDING'
                                    ORDER BY b.current_weight DESC, rs.stop_order ASC, rs.id ASC LIMIT 1");
    if (!$nextStopResult) {
        http_response_code(500);
        echo json_encode(["status" => "error", "msg" => "Could not verify the next stop."]);
        exit();
    }
    $nextStop = $nextStopResult->fetch_assoc();
    if (!$nextStop || (int)$nextStop['stop_id'] !== $sid || (int)$nextStop['bin_id'] !== $bid) {
        http_response_code(409);
        echo json_encode(["status" => "error", "msg" => "Collect the highest-weight stop at the top of your list first."]);
        exit();
    }
    if ($img === '') {
        http_response_code(400);
        echo json_encode(["status" => "error", "msg" => "Collection proof is required."]);
        exit();
    }
    $weight = (float)$nextStop['current_weight'];
    $imgName = "proof_".time().".png";
    file_put_contents("uploads/".$imgName, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $img)));
    
    $conn->query("UPDATE route_stops SET status='COMPLETED', proof_image='$imgName' WHERE id=$sid");
    $conn->query("UPDATE bins SET current_weight=0, priority='PENDING', status='COLLECTED' WHERE id=$bid");
    
    $v = $conn->query("SELECT * FROM vehicles WHERE id=(SELECT vehicle_id FROM users WHERE id=$did)")->fetch_assoc();
    $newLoad = $v['current_load'] + $weight;
    $conn->query("UPDATE vehicles SET current_load=$newLoad WHERE id={$v['id']}");
    
    $msg = "Collected!";
    if($newLoad >= ($v['max_capacity'] * 0.9)) $msg = "Warning: Vehicle Nearing Capacity!";
    echo json_encode(["status" => "success", "msg" => $msg]); exit();
}

if ($action == 'submit_complaint') {
    $driverId = (int)$_SESSION['user_id'];
    $msg = trim($_POST['message'] ?? '');
    if ($msg === '') {
        http_response_code(400);
        echo json_encode(["status" => "error", "msg" => "Issue details are required."]);
        exit();
    }
    $escapedMessage = $conn->real_escape_string($msg);
    $conn->begin_transaction();
    try {
        if (!$conn->query("INSERT INTO complaints (driver_id, message) VALUES ($driverId, '$escapedMessage')")) {
            throw new RuntimeException($conn->error);
        }
        if (preg_match('/\b(breakdown|broken|stuck|not starting|won.t start|engine failure)\b/i', $msg)) {
            $assignmentMessage = handleVehicleBreakdown($conn, $driverId);
        } else {
            $assignmentMessage = markDriverUnavailable($conn, $driverId, 'stuck');
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        error_log("Driver issue handoff failed for driver $driverId: " . $error->getMessage());
        http_response_code(500);
        echo json_encode(["status" => "error", "msg" => "Could not report the issue or reassign the work. Please contact the admin."]);
        exit();
    }
    echo json_encode(["status" => "success", "msg" => "Issue reported. " . $assignmentMessage]); exit();
}
?>