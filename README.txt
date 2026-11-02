SmartCity Waste Management - Driver assignment fix

Files:
- api.php: assigns pending bins with positive weight to available drivers who have vehicles, respecting remaining vehicle capacity. Assignment runs after an admin updates a bin and fills missing assignments when a driver dashboard loads. Collection is enforced in descending bin-weight order. Vehicle breakdown reports take the vehicle out of service and transfer pending stops to other eligible vehicles/drivers.
- driver.php: displays assigned stops in descending weight order and maps the route from the depot, including when there is only one stop. Only the top stop can be collected at a time.
- admin.php: shows a driver roster and vehicle statuses, allows driver availability to be updated, lets admins mark a repaired breakdown vehicle available again, confirms vehicle assignments, and no longer links to Settings.

Installation:
1. Back up your existing htdocs/smartcity/api.php and driver.php.
2. Copy these two files into C:\xampp\htdocs\smartcity\ and replace the old files.
3. Keep your existing db.php unchanged.
4. Ensure Apache and MySQL are running.
5. In the admin dashboard, set a driver's status to Available if needed, then update a bin's weight to a positive value. Open the driver dashboard to assign any remaining pending stops and display the route. Drivers see an alert when a vehicle is assigned.

Important:
- Each vehicle can be assigned to only one driver. For two vehicles to split work across two drivers, the users table must contain two DRIVER accounts. The current screenshot showed only one DRIVER account, so the second vehicle cannot be used by a second driver until that account exists.
- Drivers must have a vehicle and be marked available to receive new stops. Vehicle breakdown/stuck reports take that vehicle out of service and move pending stops to other available vehicles/drivers within capacity. Stops without available capacity remain pending for later assignment. Spare drivers are paired with available vehicles and alerted on their next dashboard visit. Other issue reports or admin-marked absences may transfer the same vehicle and route to a spare driver. An admin can mark a repaired vehicle available again. Resolving an issue restores the reporting driver's availability. The code assumes the schema shown in your screenshots/repository: users(id, role, vehicle_id, is_available), vehicles(id, vehicle_number, max_capacity, current_load, status), bins(id, location_name, current_weight, max_capacity, priority, status, latitude, longitude), routes(id, driver_id, vehicle_id, status), route_stops(id, route_id, bin_id, stop_order, status, proof_image), complaints(driver_id, message, status, created_at, proof_image).
- This code is prepared from the repository files and database information provided, but it has not been run against your local MySQL instance. Back up first. If a column name differs in your local database, MySQL will report an error and the code will need to be adjusted.
