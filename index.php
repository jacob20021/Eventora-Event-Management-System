
<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$username = "root";
$password = "";
$database = "showcase_event_db";

$conn = null;
$dbError = "";
$error = "";
$success = "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function redirectTo(string $page): never {
    header("Location: index.php?page=" . urlencode($page));
    exit;
}

function validDate(string $date): bool {
    $d = DateTime::createFromFormat("!Y-m-d", $date);
    return $d !== false && $d->format("Y-m-d") === $date;
}

$allowedPages = ["dashboard", "events", "clients", "bookings"];
$page = $_GET["page"] ?? "dashboard";

if (!in_array($page, $allowedPages, true)) {
    $page = "dashboard";
}

/* Connect and prepare the database */
try {
    $conn = new mysqli($host, $username, $password);
    $conn->set_charset("utf8mb4");

    $conn->query(
        "CREATE DATABASE IF NOT EXISTS `showcase_event_db`
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    $conn->select_db($database);

    $conn->query("
        CREATE TABLE IF NOT EXISTS events (
            event_id INT AUTO_INCREMENT PRIMARY KEY,
            event_name VARCHAR(150) NOT NULL,
            event_date DATE NOT NULL,
            venue VARCHAR(150) NOT NULL,
            capacity INT NOT NULL DEFAULT 100,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS clients (
            client_id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            phone VARCHAR(30) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS bookings (
            booking_id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            event_id INT NOT NULL,
            guests INT NOT NULL DEFAULT 1,
            booking_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_booking_client
                FOREIGN KEY (client_id) REFERENCES clients(client_id)
                ON DELETE CASCADE,
            CONSTRAINT fk_booking_event
                FOREIGN KEY (event_id) REFERENCES events(event_id)
                ON DELETE CASCADE,
            CONSTRAINT unique_client_event UNIQUE (client_id, event_id)
        ) ENGINE=InnoDB
    ");

    /* Basic migration for an older bookings table */
    $column = $conn->query("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = 'showcase_event_db'
          AND TABLE_NAME = 'bookings'
          AND COLUMN_NAME = 'guests'
    ")->fetch_assoc();

    if ((int)$column["total"] === 0) {
        $conn->query("
            ALTER TABLE bookings
            ADD COLUMN guests INT NOT NULL DEFAULT 1
        ");
    }

    /* Insert sample records only into empty tables */
    if ((int)$conn->query("SELECT COUNT(*) AS n FROM events")->fetch_assoc()["n"] === 0) {
        $conn->query("
            INSERT INTO events (event_name, event_date, venue, capacity, description)
            VALUES
            ('Creative Tech Expo', DATE_ADD(CURDATE(), INTERVAL 7 DAY),
             'Crystal Convention Hall', 200, 'A fictional technology showcase.'),
            ('Future Leaders Summit', DATE_ADD(CURDATE(), INTERVAL 14 DAY),
             'Aurora Conference Centre', 150, 'A fictional leadership event.'),
            ('Digital Art Festival', DATE_ADD(CURDATE(), INTERVAL 21 DAY),
             'Blue Horizon Art Space', 120, 'A fictional digital art exhibition.'),
            ('Startup Connect', DATE_ADD(CURDATE(), INTERVAL 30 DAY),
             'Innovation Pavilion', 250, 'A fictional networking event.'),
            ('Community Music Night', DATE_SUB(CURDATE(), INTERVAL 5 DAY),
             'Sunset Garden Arena', 180, 'A sample past event.')
        ");
    }

    if ((int)$conn->query("SELECT COUNT(*) AS n FROM clients")->fetch_assoc()["n"] === 0) {
        $conn->query("
            INSERT INTO clients (client_name, email, phone)
            VALUES
            ('Alex Morgan', 'alex.morgan@example.test', '000-100-2001'),
            ('Jamie Taylor', 'jamie.taylor@example.test', '000-100-2002'),
            ('Jordan Lee', 'jordan.lee@example.test', '000-100-2003'),
            ('Casey Parker', 'casey.parker@example.test', '000-100-2004')
        ");
    }
} catch (Throwable $ex) {
    // Log the technical error privately; show a generic message to the user.
    error_log("Eventora database setup error: " . $ex->getMessage());
    $dbError = "The database could not be prepared. Check that WAMP MySQL is running, your credentials are correct, and the SQL schema matches this application.";
}

/* Process form submissions */
if ($conn && $dbError === "" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $postedToken = $_POST["csrf_token"] ?? "";

    if (!is_string($postedToken) ||
        !hash_equals($_SESSION["csrf_token"], $postedToken)) {
        $error = "Your session token is invalid or expired. Refresh the page and try again.";
    } else {
        try {
            switch ($action) {
                case "add_event":
                    $name = trim($_POST["event_name"] ?? "");
                    $date = trim($_POST["event_date"] ?? "");
                    $venue = trim($_POST["venue"] ?? "");
                    $capacity = filter_var(
                        $_POST["capacity"] ?? null,
                        FILTER_VALIDATE_INT,
                        ["options" => ["min_range" => 1, "max_range" => 100000]]
                    );
                    $description = trim($_POST["description"] ?? "");

                    if ($name === "" || mb_strlen($name) > 150 ||
                        $venue === "" || mb_strlen($venue) > 150 ||
                        !validDate($date) || $capacity === false ||
                        mb_strlen($description) > 5000) {
                        throw new Exception("Check the event name, date, venue, capacity and description.");
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO events (event_name, event_date, venue, capacity, description)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("sssis", $name, $date, $venue, $capacity, $description);
                    $stmt->execute();
                    $stmt->close();

                    redirectTo("events");

                case "delete_event":
                    $id = filter_var($_POST["id"] ?? null, FILTER_VALIDATE_INT);

                    if (!$id || $id < 1) {
                        throw new Exception("Invalid event selected.");
                    }

                    $stmt = $conn->prepare("DELETE FROM events WHERE event_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $deleted = $stmt->affected_rows;
                    $stmt->close();

                    if (!$deleted) {
                        throw new Exception("That event no longer exists.");
                    }

                    redirectTo("events");

                case "add_client":
                    $name = trim($_POST["client_name"] ?? "");
                    $email = strtolower(trim($_POST["email"] ?? ""));
                    $phone = trim($_POST["phone"] ?? "");

                    if ($name === "" || mb_strlen($name) > 100 ||
                        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                        mb_strlen($email) > 150 || mb_strlen($phone) > 30) {
                        throw new Exception("Enter a valid client name, email and phone number.");
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO clients (client_name, email, phone)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->bind_param("sss", $name, $email, $phone);
                    $stmt->execute();
                    $stmt->close();

                    redirectTo("clients");

                case "delete_client":
                    $id = filter_var($_POST["id"] ?? null, FILTER_VALIDATE_INT);

                    if (!$id || $id < 1) {
                        throw new Exception("Invalid client selected.");
                    }

                    $stmt = $conn->prepare("DELETE FROM clients WHERE client_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $deleted = $stmt->affected_rows;
                    $stmt->close();

                    if (!$deleted) {
                        throw new Exception("That client no longer exists.");
                    }

                    redirectTo("clients");

                case "add_booking":
                    $clientId = filter_var($_POST["client_id"] ?? null, FILTER_VALIDATE_INT);
                    $eventId = filter_var($_POST["event_id"] ?? null, FILTER_VALIDATE_INT);
                    $guests = filter_var(
                        $_POST["guests"] ?? null,
                        FILTER_VALIDATE_INT,
                        ["options" => ["min_range" => 1, "max_range" => 100000]]
                    );

                    if (!$clientId || !$eventId || $guests === false ||
                        $clientId < 1 || $eventId < 1) {
                        throw new Exception("Select a valid client and event, and enter a valid guest count.");
                    }

                    /*
                     * Lock the event row during capacity checking to reduce
                     * the chance of concurrent bookings exceeding capacity.
                     */
                    $conn->begin_transaction();

                    $stmt = $conn->prepare("
                        SELECT capacity
                        FROM events
                        WHERE event_id = ?
                        FOR UPDATE
                    ");
                    $stmt->bind_param("i", $eventId);
                    $stmt->execute();
                    $eventRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$eventRow) {
                        throw new Exception("The selected event does not exist.");
                    }

                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(guests), 0) AS booked
                        FROM bookings
                        WHERE event_id = ?
                    ");
                    $stmt->bind_param("i", $eventId);
                    $stmt->execute();
                    $booked = (int)$stmt->get_result()->fetch_assoc()["booked"];
                    $stmt->close();

                    if ($booked + $guests > (int)$eventRow["capacity"]) {
                        throw new Exception(
                            "Not enough capacity remains. Available places: " .
                            max(0, (int)$eventRow["capacity"] - $booked) . "."
                        );
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO bookings (client_id, event_id, guests)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->bind_param("iii", $clientId, $eventId, $guests);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    redirectTo("bookings");

                case "delete_booking":
                    $id = filter_var($_POST["id"] ?? null, FILTER_VALIDATE_INT);

                    if (!$id || $id < 1) {
                        throw new Exception("Invalid booking selected.");
                    }

                    $stmt = $conn->prepare("DELETE FROM bookings WHERE booking_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $deleted = $stmt->affected_rows;
                    $stmt->close();

                    if (!$deleted) {
                        throw new Exception("That booking no longer exists.");
                    }

                    redirectTo("bookings");

                default:
                    throw new Exception("Unknown action.");
            }
        } catch (Throwable $ex) {
            if ($conn->errno || $ex instanceof mysqli_sql_exception) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                    // There may be no active transaction.
                }
            }

            error_log("Eventora form error: " . $ex->getMessage());

            if ($ex instanceof mysqli_sql_exception && (int)$ex->getCode() === 1062) {
                $error = "This email or client-event booking already exists.";
            } elseif ($ex instanceof mysqli_sql_exception) {
                $error = "The database could not complete that action. Check the data and try again.";
            } else {
                $error = $ex->getMessage();
            }
        }
    }
}

/* Load data for the interface */
$stats = ["events" => 0, "clients" => 0, "bookings" => 0, "guests" => 0];
$upcomingEvents = $allEvents = $allClients = $allBookings = [];
$clientOptions = $eventOptions = [];

if ($conn && $dbError === "") {
    try {
        $stats["events"] = (int)$conn->query(
            "SELECT COUNT(*) n FROM events"
        )->fetch_assoc()["n"];

        $stats["clients"] = (int)$conn->query(
            "SELECT COUNT(*) n FROM clients"
        )->fetch_assoc()["n"];

        $stats["bookings"] = (int)$conn->query(
            "SELECT COUNT(*) n FROM bookings"
        )->fetch_assoc()["n"];

        $stats["guests"] = (int)$conn->query(
            "SELECT COALESCE(SUM(guests), 0) n FROM bookings"
        )->fetch_assoc()["n"];

        $upcomingEvents = $conn->query("
            SELECT e.*, COALESCE(SUM(b.guests), 0) AS booked_guests
            FROM events e
            LEFT JOIN bookings b ON e.event_id = b.event_id
            WHERE e.event_date >= CURDATE()
            GROUP BY e.event_id
            ORDER BY e.event_date ASC
            LIMIT 5
        ")->fetch_all(MYSQLI_ASSOC);

        $allEvents = $conn->query("
            SELECT e.*, COALESCE(SUM(b.guests), 0) AS booked_guests
            FROM events e
            LEFT JOIN bookings b ON e.event_id = b.event_id
            GROUP BY e.event_id
            ORDER BY e.event_date DESC
        ")->fetch_all(MYSQLI_ASSOC);

        $allClients = $conn->query("
            SELECT c.*, COUNT(b.booking_id) AS booking_count
            FROM clients c
            LEFT JOIN bookings b ON c.client_id = b.client_id
            GROUP BY c.client_id
            ORDER BY c.client_name
        ")->fetch_all(MYSQLI_ASSOC);

        $allBookings = $conn->query("
            SELECT b.*, c.client_name, e.event_name, e.event_date
            FROM bookings b
            INNER JOIN clients c ON b.client_id = c.client_id
            INNER JOIN events e ON b.event_id = e.event_id
            ORDER BY b.booking_date DESC
        ")->fetch_all(MYSQLI_ASSOC);

        $clientOptions = $conn->query("
            SELECT client_id, client_name FROM clients ORDER BY client_name
        ")->fetch_all(MYSQLI_ASSOC);

        $eventOptions = $conn->query("
            SELECT event_id, event_name, event_date, capacity
            FROM events ORDER BY event_date
        ")->fetch_all(MYSQLI_ASSOC);

    } catch (Throwable $ex) {
        error_log("Eventora data loading error: " . $ex->getMessage());
        $dbError = "The application could not load its data. Check that your database tables match the SQL schema.";
    }
}

$titles = [
    "dashboard" => "Dashboard",
    "events" => "Event Management",
    "clients" => "Client Management",
    "bookings" => "Booking Management"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Eventora | <?= e($titles[$page]) ?></title>
<style>
:root {
    --primary:#6558e8; --primary-dark:#4d40c9; --bg:#f5f6fc;
    --text:#22243a; --muted:#777b91; --border:#e8e9f1;
    --white:#fff; --danger:#b52f40; --green:#167a55;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif}
.layout{display:flex;min-height:100vh}
.sidebar{width:245px;flex-shrink:0;padding:28px 18px;background:#24243c;color:white}
.brand{margin:4px 10px 8px;font-size:25px;font-weight:800;letter-spacing:-1px}
.brand span{color:#a89eff}
.brand-subtitle{margin:0 10px 35px;color:#b5b5ce;font-size:11px;letter-spacing:1px}
.nav-label{margin:20px 10px 12px;color:#9999b6;font-size:11px;text-transform:uppercase;letter-spacing:1.5px}
.nav-link{display:block;margin:6px 0;padding:13px 14px;border-radius:10px;color:#d4d4e5;text-decoration:none;font-size:14px}
.nav-link:hover,.nav-link.active{background:var(--primary);color:white}
.sidebar-note{margin:40px 8px 0;padding:15px;border-radius:12px;background:#33334f;color:#d0d0e5;font-size:12px;line-height:1.7}
.main{flex:1;min-width:0;padding:30px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:28px}
h1{margin:0 0 7px;font-size:27px}h2{margin:0 0 18px;font-size:18px}
.subtitle{color:var(--muted);font-size:13px;line-height:1.6}
.demo-badge{padding:10px 15px;border-radius:25px;background:#e9e6ff;color:var(--primary-dark);font-size:12px;font-weight:bold;white-space:nowrap}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-bottom:24px}
.stat-card,.panel{padding:22px;border:1px solid var(--border);border-radius:15px;background:white;box-shadow:0 4px 18px #23234608}
.stat-icon{display:flex;align-items:center;justify-content:center;width:38px;height:38px;margin-bottom:14px;border-radius:11px;background:#efedff;color:var(--primary);font-size:20px}
.stat-title{margin-bottom:12px;color:var(--muted);font-size:13px}.stat-number{font-size:30px;font-weight:800}
.panel{margin-bottom:24px}
.panel-header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}
.panel-header h2{margin:0}
.btn{display:inline-block;padding:11px 15px;border:0;border-radius:8px;background:var(--primary);color:white;text-decoration:none;font-size:13px;font-weight:bold;cursor:pointer}
.btn:hover{background:var(--primary-dark)}.btn-danger{background:#fff0f1;color:var(--danger)}.btn-danger:hover{background:#ffe0e3}.btn-small{padding:8px 11px;font-size:12px}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:14px 12px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
th{background:#fafaff;color:var(--muted);font-weight:600}tbody tr:last-child td{border-bottom:0}
.event-name{font-weight:bold}
.pill{display:inline-block;padding:6px 10px;border-radius:20px;background:#e6f7ef;color:var(--green);font-size:11px}
.pill-full{background:#fff0e5;color:#b86418}
.forms-grid{display:grid;grid-template-columns:minmax(250px,.85fr) minmax(0,1.5fr);gap:22px;align-items:start}
.form-group{margin-bottom:15px}label{display:block;margin-bottom:7px;color:#53566e;font-size:13px;font-weight:bold}
input,select,textarea{width:100%;padding:12px;border:1px solid #dedfeb;border-radius:8px;background:white;color:var(--text);font:13px Arial,sans-serif}
textarea{min-height:90px;resize:vertical}
input:focus,select:focus,textarea:focus{outline:2px solid #dedaff;border-color:var(--primary)}
.alert{margin-bottom:20px;padding:14px 16px;border-radius:9px;background:#fff0f1;color:#a52c3a;overflow-wrap:anywhere;font-size:13px}
.empty{padding:28px;text-align:center;color:var(--muted)}
footer{padding:15px 0 5px;text-align:center;color:var(--muted);font-size:12px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:1050px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.forms-grid{grid-template-columns:1fr}}
@media(max-width:700px){.layout{display:block}.sidebar{width:100%;padding:18px}.brand-subtitle{margin-bottom:18px}.nav-label,.sidebar-note{display:none}.navigation{display:flex;flex-wrap:wrap;gap:5px}.nav-link{margin:0;padding:10px;font-size:12px}.main{padding:18px}.stat-card{padding:15px}.stats{gap:10px}.stat-number{font-size:25px}h1{font-size:23px}.topbar{align-items:flex-start}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="brand">event<span>ora.</span></div>
    <div class="brand-subtitle">EVENT MANAGEMENT SYSTEM</div>
    <div class="nav-label">Main menu</div>
    <nav class="navigation">
        <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="index.php?page=dashboard">▦ &nbsp; Dashboard</a>
        <a class="nav-link <?= $page === 'events' ? 'active' : '' ?>" href="index.php?page=events">▣ &nbsp; Events</a>
        <a class="nav-link <?= $page === 'clients' ? 'active' : '' ?>" href="index.php?page=clients">♙ &nbsp; Clients</a>
        <a class="nav-link <?= $page === 'bookings' ? 'active' : '' ?>" href="index.php?page=bookings">▤ &nbsp; Bookings</a>
    </nav>
    
</aside>

<main class="main">
<div class="topbar">
    <div><h1><?= e($titles[$page]) ?></h1><div class="subtitle">Manage events, clients and reservations in one place.</div></div>
    <div class="demo-badge">V 0.0.1</div>
</div>

<?php if ($dbError !== ""): ?>
    <div class="alert"><strong>Database issue</strong><br><?= e($dbError) ?></div>
<?php elseif ($error !== ""): ?>
    <div class="alert" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($conn && $dbError === "" && $page === "dashboard"): ?>
<div class="stats">
    <div class="stat-card"><div class="stat-icon">▣</div><div class="stat-title">Total Events</div><div class="stat-number"><?= $stats["events"] ?></div></div>
    <div class="stat-card"><div class="stat-icon">♙</div><div class="stat-title">Total Clients</div><div class="stat-number"><?= $stats["clients"] ?></div></div>
    <div class="stat-card"><div class="stat-icon">▤</div><div class="stat-title">Total Bookings</div><div class="stat-number"><?= $stats["bookings"] ?></div></div>
    <div class="stat-card"><div class="stat-icon">♧</div><div class="stat-title">Guests Booked</div><div class="stat-number"><?= $stats["guests"] ?></div></div>
</div>

<section class="panel">
<div class="panel-header"><h2>Upcoming Events</h2><a class="btn" href="index.php?page=events">Manage Events</a></div>
<div class="table-wrap"><table>
<thead><tr><th>Event</th><th>Date</th><th>Venue</th><th>Guests</th><th>Status</th></tr></thead>
<tbody>
<?php if (!$upcomingEvents): ?>
<tr><td colspan="5" class="empty">No upcoming events. Add an event to get started.</td></tr>
<?php else: foreach ($upcomingEvents as $event): $full = (int)$event["booked_guests"] >= (int)$event["capacity"]; ?>
<tr>
<td class="event-name"><?= e($event["event_name"]) ?></td>
<td><?= e(date("d M Y", strtotime($event["event_date"]))) ?></td>
<td><?= e($event["venue"]) ?></td>
<td><?= (int)$event["booked_guests"] ?> / <?= (int)$event["capacity"] ?></td>
<td><span class="pill <?= $full ? 'pill-full' : '' ?>"><?= $full ? "Capacity reached" : "Available" ?></span></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</section>

<section class="panel"><h2>Quick Actions</h2><p class="subtitle">Choose a section to manage your demo data.</p>
<div class="actions"><a class="btn" href="index.php?page=events">Manage Events</a><a class="btn" href="index.php?page=clients">Manage Clients</a><a class="btn" href="index.php?page=bookings">Manage Bookings</a></div></section>

<?php elseif ($conn && $dbError === "" && $page === "events"): ?>
<div class="forms-grid">
<section class="panel"><h2>Add New Event</h2>
<form method="POST" action="index.php?page=events">
<input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
<input type="hidden" name="action" value="add_event">
<div class="form-group"><label for="event_name">Event Name</label><input id="event_name" name="event_name" maxlength="150" required></div>
<div class="form-group"><label for="event_date">Event Date</label><input id="event_date" type="date" name="event_date" required></div>
<div class="form-group"><label for="venue">Venue</label><input id="venue" name="venue" maxlength="150" required></div>
<div class="form-group"><label for="capacity">Guest Capacity</label><input id="capacity" type="number" name="capacity" min="1" max="100000" value="100" required></div>
<div class="form-group"><label for="description">Description</label><textarea id="description" name="description" maxlength="5000"></textarea></div>
<button class="btn" type="submit">Add Event</button>
</form></section>

<section class="panel"><h2>All Events</h2><div class="table-wrap"><table>
<thead><tr><th>Event</th><th>Date</th><th>Venue</th><th>Guests</th><th>Action</th></tr></thead><tbody>
<?php if (!$allEvents): ?><tr><td colspan="5" class="empty">No events yet.</td></tr>
<?php else: foreach ($allEvents as $event): ?>
<tr><td class="event-name"><?= e($event["event_name"]) ?></td><td><?= e(date("d M Y", strtotime($event["event_date"]))) ?></td><td><?= e($event["venue"]) ?></td><td><?= (int)$event["booked_guests"] ?> / <?= (int)$event["capacity"] ?></td><td>
<form method="POST" action="index.php?page=events" onsubmit="return confirm('Delete this event and its bookings?');">
<input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="delete_event"><input type="hidden" name="id" value="<?= (int)$event["event_id"] ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button>
</form></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div></section>
</div>

<?php elseif ($conn && $dbError === "" && $page === "clients"): ?>
<div class="forms-grid">
<section class="panel"><h2>Add New Client</h2>
<form method="POST" action="index.php?page=clients">
<input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="add_client">
<div class="form-group"><label for="client_name">Client Name</label><input id="client_name" name="client_name" maxlength="100" required></div>
<div class="form-group"><label for="email">Email Address</label><input id="email" type="email" name="email" maxlength="150" required></div>
<div class="form-group"><label for="phone">Phone Number (optional)</label><input id="phone" name="phone" maxlength="30"></div>
<button class="btn" type="submit">Add Client</button>
</form></section>

<section class="panel"><h2>All Clients</h2><div class="table-wrap"><table>
<thead><tr><th>Client</th><th>Email</th><th>Phone</th><th>Bookings</th><th>Action</th></tr></thead><tbody>
<?php if (!$allClients): ?><tr><td colspan="5" class="empty">No clients yet.</td></tr>
<?php else: foreach ($allClients as $client): ?>
<tr><td class="event-name"><?= e($client["client_name"]) ?></td><td><?= e($client["email"]) ?></td><td><?= e($client["phone"] ?: "—") ?></td><td><?= (int)$client["booking_count"] ?></td><td>
<form method="POST" action="index.php?page=clients" onsubmit="return confirm('Delete this client and their bookings?');">
<input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="delete_client"><input type="hidden" name="id" value="<?= (int)$client["client_id"] ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button>
</form></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div></section>
</div>

<?php elseif ($conn && $dbError === "" && $page === "bookings"): ?>
<div class="forms-grid">
<section class="panel"><h2>Create Booking</h2>
<?php if (!$clientOptions || !$eventOptions): ?>
<p class="subtitle">Add at least one client and one event before creating a booking.</p>
<?php else: ?>
<form method="POST" action="index.php?page=bookings">
<input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="add_booking">
<div class="form-group"><label for="client_id">Select Client</label><select id="client_id" name="client_id" required><option value="">Choose a client</option>
<?php foreach ($clientOptions as $client): ?><option value="<?= (int)$client["client_id"] ?>"><?= e($client["client_name"]) ?></option><?php endforeach; ?>
</select></div>
<div class="form-group"><label for="event_id">Select Event</label><select id="event_id" name="event_id" required><option value="">Choose an event</option>
<?php foreach ($eventOptions as $event): ?><option value="<?= (int)$event["event_id"] ?>"><?= e($event["event_name"]) ?> — <?= e(date("d M Y", strtotime($event["event_date"]))) ?></option><?php endforeach; ?>
</select></div>
<div class="form-group"><label for="guests">Number of Guests</label><input id="guests" type="number" name="guests" min="1" max="100000" value="1" required></div>
<button class="btn" type="submit">Create Booking</button>
</form>
<?php endif; ?>
</section>

<section class="panel"><h2>All Bookings</h2><div class="table-wrap"><table>
<thead><tr><th>Booking</th><th>Client</th><th>Event</th><th>Date</th><th>Guests</th><th>Action</th></tr></thead><tbody>
<?php if (!$allBookings): ?><tr><td colspan="6" class="empty">No bookings yet.</td></tr>
<?php else: foreach ($allBookings as $booking): ?>
<tr><td>#<?= (int)$booking["booking_id"] ?></td><td><?= e($booking["client_name"]) ?></td><td class="event-name"><?= e($booking["event_name"]) ?></td><td><?= e(date("d M Y", strtotime($booking["event_date"]))) ?></td><td><?= (int)$booking["guests"] ?></td><td>
<form method="POST" action="index.php?page=bookings" onsubmit="return confirm('Delete this booking?');">
<input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="delete_booking"><input type="hidden" name="id" value="<?= (int)$booking["booking_id"] ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button>
</form></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div></section>
</div>
<?php endif; ?>

<footer>Eventora · Fictional Showcase Mini Project · PHP &amp; MySQL</footer>
</main>
</div>
</body>
</html>
<?php
if ($conn instanceof mysqli) {
    $conn->close();
}
?>
