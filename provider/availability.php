
<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Only providers can access this page
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "provider"
) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

$provider_id = (int) $_SESSION["user_id"];
$provider_name = $_SESSION["full_name"] ?? "Provider";

$days = [
    "Monday",
    "Tuesday",
    "Wednesday",
    "Thursday",
    "Friday",
    "Saturday",
    "Sunday"
];

$message = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function availabilityEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function availabilityHasOverlap(
    mysqli $conn,
    int $provider_id,
    string $day,
    string $start_time,
    string $end_time,
    ?int $exclude_id = null
): bool {

    if ($exclude_id !== null) {

        $stmt = $conn->prepare(
            "SELECT id
             FROM availability
             WHERE provider_id = ?
               AND day_of_week = ?
               AND start_time < ?
               AND end_time > ?
               AND id != ?
             LIMIT 1"
        );

        if (!$stmt) {
            return true;
        }

        $stmt->bind_param(
            "isssi",
            $provider_id,
            $day,
            $end_time,
            $start_time,
            $exclude_id
        );

    } else {

        $stmt = $conn->prepare(
            "SELECT id
             FROM availability
             WHERE provider_id = ?
               AND day_of_week = ?
               AND start_time < ?
               AND end_time > ?
             LIMIT 1"
        );

        if (!$stmt) {
            return true;
        }

        $stmt->bind_param(
            "isss",
            $provider_id,
            $day,
            $end_time,
            $start_time
        );
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return true;
    }

    $result = $stmt->get_result();
    $has_overlap = $result->num_rows > 0;

    $stmt->close();

    return $has_overlap;
}

function validAvailabilityTimes(
    string $start_time,
    string $end_time
): bool {

    $pattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    if (
        !preg_match($pattern, $start_time) ||
        !preg_match($pattern, $end_time)
    ) {
        return false;
    }

    return $start_time < $end_time;
}

/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    // ADD AVAILABILITY

    if ($action === "add") {

        $selected_days = $_POST["days"] ?? [];

        $start_time = trim($_POST["start_time"] ?? "");
        $end_time = trim($_POST["end_time"] ?? "");

        if (!is_array($selected_days)) {
            $selected_days = [];
        }

        $selected_days = array_filter(
            $selected_days,
            function ($day) use ($days) {
                return is_string($day) && in_array($day, $days, true);
            }
        );

        $selected_days = array_values(array_unique($selected_days));

        if (empty($selected_days)) {

            $message = "Please select at least one day.";
            $message_type = "error";

        } elseif (!validAvailabilityTimes($start_time, $end_time)) {

            $message = "Please enter valid working hours. The end time must be after the start time.";
            $message_type = "error";

        } else {

            $has_overlap = false;

            foreach ($selected_days as $day) {

                if (
                    availabilityHasOverlap(
                        $conn,
                        $provider_id,
                        $day,
                        $start_time,
                        $end_time
                    )
                ) {
                    $has_overlap = true;
                    break;
                }
            }

            if ($has_overlap) {

                $message = "One or more selected days already have overlapping availability. Please check your saved hours.";
                $message_type = "error";

            } else {

                $conn->begin_transaction();

                $stmt = $conn->prepare(
                    "INSERT INTO availability
                    (provider_id, day_of_week, start_time, end_time)
                    VALUES (?, ?, ?, ?)"
                );

                if (!$stmt) {

                    $conn->rollback();

                    $message = "Something went wrong while preparing your availability.";
                    $message_type = "error";

                } else {

                    $success = true;

                    foreach ($selected_days as $day) {

                        $stmt->bind_param(
                            "isss",
                            $provider_id,
                            $day,
                            $start_time,
                            $end_time
                        );

                        if (!$stmt->execute()) {
                            $success = false;
                            break;
                        }
                    }

                    $stmt->close();

                    if ($success) {

                        $conn->commit();

                        header("Location: availability.php?status=added");
                        exit;

                    } else {

                        $conn->rollback();

                        $message = "Unable to save your availability. Please try again.";
                        $message_type = "error";
                    }
                }
            }
        }
    }

    // EDIT AVAILABILITY

    elseif ($action === "edit") {

        $slot_id = (int) ($_POST["slot_id"] ?? 0);

        $day = $_POST["day_of_week"] ?? "";
        $start_time = trim($_POST["start_time"] ?? "");
        $end_time = trim($_POST["end_time"] ?? "");

        if ($slot_id <= 0) {

            $message = "Invalid availability slot.";
            $message_type = "error";

        } elseif (!in_array($day, $days, true)) {

            $message = "Please select a valid day.";
            $message_type = "error";

        } elseif (!validAvailabilityTimes($start_time, $end_time)) {

            $message = "Please enter valid working hours.";
            $message_type = "error";

        } else {

            $check_stmt = $conn->prepare(
                "SELECT id
                 FROM availability
                 WHERE id = ? AND provider_id = ?
                 LIMIT 1"
            );

            if (!$check_stmt) {

                $message = "Unable to verify this availability slot.";
                $message_type = "error";

            } else {

                $check_stmt->bind_param(
                    "ii",
                    $slot_id,
                    $provider_id
                );

                $check_stmt->execute();

                $check_result = $check_stmt->get_result();
                $slot_exists = $check_result->num_rows > 0;

                $check_stmt->close();

                if (!$slot_exists) {

                    $message = "Availability slot not found.";
                    $message_type = "error";

                } elseif (
                    availabilityHasOverlap(
                        $conn,
                        $provider_id,
                        $day,
                        $start_time,
                        $end_time,
                        $slot_id
                    )
                ) {

                    $message = "These hours overlap with another saved availability slot.";
                    $message_type = "error";

                } else {

                    $update_stmt = $conn->prepare(
                        "UPDATE availability
                         SET day_of_week = ?,
                             start_time = ?,
                             end_time = ?
                         WHERE id = ? AND provider_id = ?"
                    );

                    if (!$update_stmt) {

                        $message = "Unable to update availability.";
                        $message_type = "error";

                    } else {

                        $update_stmt->bind_param(
                            "sssii",
                            $day,
                            $start_time,
                            $end_time,
                            $slot_id,
                            $provider_id
                        );

                        if ($update_stmt->execute()) {

                            $update_stmt->close();

                            header("Location: availability.php?status=updated");
                            exit;

                        } else {

                            $message = "Unable to update your availability.";
                            $message_type = "error";

                            $update_stmt->close();
                        }
                    }
                }
            }
        }
    }

    // DELETE AVAILABILITY

    elseif ($action === "delete") {

        $slot_id = (int) ($_POST["slot_id"] ?? 0);

        if ($slot_id <= 0) {

            $message = "Invalid availability slot.";
            $message_type = "error";

        } else {

            $delete_stmt = $conn->prepare(
                "DELETE FROM availability
                 WHERE id = ? AND provider_id = ?"
            );

            if (!$delete_stmt) {

                $message = "Unable to delete availability.";
                $message_type = "error";

            } else {

                $delete_stmt->bind_param(
                    "ii",
                    $slot_id,
                    $provider_id
                );

                if (
                    $delete_stmt->execute() &&
                    $delete_stmt->affected_rows > 0
                ) {

                    $delete_stmt->close();

                    header("Location: availability.php?status=deleted");
                    exit;

                } else {

                    $message = "Availability slot could not be found or deleted.";
                    $message_type = "error";

                    $delete_stmt->close();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/

if (isset($_GET["status"])) {

    if ($_GET["status"] === "added") {
        $message = "Your availability has been added successfully.";
        $message_type = "success";

    } elseif ($_GET["status"] === "updated") {
        $message = "Your availability has been updated successfully.";
        $message_type = "success";

    } elseif ($_GET["status"] === "deleted") {
        $message = "Your availability has been deleted successfully.";
        $message_type = "success";
    }
}

/*
|--------------------------------------------------------------------------
| LOAD AVAILABILITY SLOT BEING EDITED
|--------------------------------------------------------------------------
*/

$edit_slot = null;

if (isset($_GET["edit"])) {

    $edit_id = (int) $_GET["edit"];

    if ($edit_id > 0) {

        $edit_stmt = $conn->prepare(
            "SELECT id, day_of_week, start_time, end_time
             FROM availability
             WHERE id = ? AND provider_id = ?
             LIMIT 1"
        );

        if ($edit_stmt) {

            $edit_stmt->bind_param(
                "ii",
                $edit_id,
                $provider_id
            );

            $edit_stmt->execute();

            $edit_result = $edit_stmt->get_result();

            if ($edit_result->num_rows > 0) {
                $edit_slot = $edit_result->fetch_assoc();
            }

            $edit_stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| RETRIEVE SAVED AVAILABILITY
|--------------------------------------------------------------------------
*/

$availability_slots = [];

$stmt = $conn->prepare(
    "SELECT id, day_of_week, start_time, end_time
     FROM availability
     WHERE provider_id = ?
     ORDER BY FIELD(
        day_of_week,
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday'
     ), start_time ASC"
);

if ($stmt) {

    $stmt->bind_param("i", $provider_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $availability_slots[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| SIDEBAR COUNTS
|--------------------------------------------------------------------------
*/

$pending_count = 0;

$pending_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM bookings
     WHERE provider_id = ? AND status = 'pending'"
);

if ($pending_stmt) {

    $pending_stmt->bind_param("i", $provider_id);
    $pending_stmt->execute();

    $pending_result = $pending_stmt->get_result();
    $pending_row = $pending_result->fetch_assoc();

    $pending_count = (int) ($pending_row["total"] ?? 0);

    $pending_stmt->close();
}

$unread_count = 0;

$unread_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages m
     INNER JOIN conversations c
        ON m.conversation_id = c.id
     WHERE c.provider_id = ?
       AND m.sender_id <> ?
       AND m.is_read = 0"
);

if ($unread_stmt) {

    $unread_stmt->bind_param(
        "ii",
        $provider_id,
        $provider_id
    );

    $unread_stmt->execute();

    $unread_result = $unread_stmt->get_result();
    $unread_row = $unread_result->fetch_assoc();

    $unread_count = (int) ($unread_row["total"] ?? 0);

    $unread_stmt->close();
}

// Provider initials: same logic as the dashboard
$name_parts = preg_split('/\s+/', trim($provider_name));
$provider_initials = "";

foreach (array_slice($name_parts, 0, 2) as $part) {
    if ($part !== "") {
        $provider_initials .= strtoupper(substr($part, 0, 1));
    }
}

if ($provider_initials === "") {
    $provider_initials = "P";
}

$conn->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Availability | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/provider.css">

</head>

<body class="provider-availability-page">

<div class="provider-dashboard-layout">

    <!-- SIDEBAR: MATCHES PROVIDER DASHBOARD -->

    <aside class="provider-sidebar">

        <a href="../index.php" class="provider-brand">

            <div class="provider-brand-icon" aria-hidden="true">
                &#127968;
            </div>

            <div class="provider-brand-copy">

                <div class="provider-brand-name">
                    Home<span>Ease</span>
                </div>

                <div class="provider-brand-tagline">
                    CONNECT. BOOK. THRIVE.
                </div>

            </div>

        </a>

        <!-- Provider Profile -->

        <div class="provider-sidebar-profile">

            <div class="provider-avatar">
                <?php echo availabilityEscape($provider_initials); ?>
            </div>

            <div class="provider-profile-details">

                <h3>
                    <?php echo availabilityEscape($provider_name); ?>
                </h3>

                <p>Service Provider</p>

            </div>

        </div>

        <p class="provider-sidebar-label">Main Menu</p>

        <!-- Navigation -->

        <nav class="provider-sidebar-nav">

            <a href="dashboard.php">

                <span class="provider-nav-icon">&#127968;</span>
                <span>Dashboard</span>

            </a>

            <a href="profile.php">

                <span class="provider-nav-icon">&#128100;</span>
                <span>My Profile</span>

            </a>

            <a href="services.php">

                <span class="provider-nav-icon">&#128736;</span>
                <span>My Services</span>

            </a>

            <a href="availability.php" class="active" aria-current="page">

                <span class="provider-nav-icon">&#128197;</span>
                <span>Availability</span>

            </a>

            <a href="bookings.php">

                <span class="provider-nav-icon">&#128203;</span>
                <span>My Bookings</span>

                <?php if ($pending_count > 0): ?>

                    <span class="provider-nav-badge">
                        <?php echo $pending_count > 99 ? "99+" : $pending_count; ?>
                    </span>

                <?php endif; ?>

            </a>

            <a href="messages.php">

                <span class="provider-nav-icon">&#128172;</span>
                <span>Messages</span>

                <?php if ($unread_count > 0): ?>

                    <span class="provider-nav-badge">
                        <?php echo $unread_count > 99 ? "99+" : $unread_count; ?>
                    </span>

                <?php endif; ?>

            </a>

        </nav>

        <!-- Sidebar Bottom -->

        <div class="provider-sidebar-bottom">

            <a href="../logout.php">

                <span class="provider-nav-icon">&#10162;</span>
                <span>Logout</span>

            </a>

            <div class="provider-sidebar-footer">
                Your Skills.<br>
                Our <span>Community.</span>
            </div>

        </div>

    </aside>


    <!-- MAIN CONTENT -->

    <main class="provider-dashboard-content">

        <div class="provider-availability-main">

            <!-- SUCCESS AND ERROR MESSAGES -->

            <?php if (!empty($message)): ?>

                <div
                    class="availability-message <?php echo availabilityEscape($message_type); ?>"
                    role="status"
                >
                    <?php echo availabilityEscape($message); ?>
                </div>

            <?php endif; ?>


            <!-- REMINDER CARD -->

            <section class="availability-reminder-card">

                <div class="availability-reminder-icon">
                    &#128197;
                </div>

                <div class="availability-reminder-content">

                    <h2>Keep Your Schedule Up to Date</h2>

                    <p>
                        Make sure your working days and hours are accurate.
                        Customers use your availability to know when they can
                        book your services. Remember to update your schedule
                        whenever your availability changes.
                    </p>

                </div>

            </section>


            <!-- AVAILABILITY CONTENT -->

            <div class="provider-availability-layout">

                <!-- ADD OR EDIT FORM -->

                <section class="availability-form-card">

                    <div class="availability-section-heading">

                        <h2>
                            <?php echo $edit_slot ? "Edit Availability" : "Set Your Working Hours"; ?>
                        </h2>

                        <p>

                            <?php if ($edit_slot): ?>

                                Update the day and hours for this time slot.

                            <?php else: ?>

                                Select one or more days and assign the same working hours.

                            <?php endif; ?>

                        </p>

                    </div>


                    <?php if ($edit_slot): ?>

                        <form
                            method="POST"
                            action="availability.php?edit=<?php echo (int) $edit_slot["id"]; ?>"
                            class="availability-form"
                        >

                            <input type="hidden" name="action" value="edit">

                            <input
                                type="hidden"
                                name="slot_id"
                                value="<?php echo (int) $edit_slot["id"]; ?>"
                            >

                            <div class="availability-form-group">

                                <label for="day_of_week">Day of the week</label>

                                <select
                                    name="day_of_week"
                                    id="day_of_week"
                                    required
                                >

                                    <?php foreach ($days as $day): ?>

                                        <option
                                            value="<?php echo availabilityEscape($day); ?>"
                                            <?php echo $edit_slot["day_of_week"] === $day ? "selected" : ""; ?>
                                        >
                                            <?php echo availabilityEscape($day); ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <div class="availability-time-grid">

                                <div class="availability-form-group">

                                    <label for="start_time">Start time</label>

                                    <input
                                        type="time"
                                        name="start_time"
                                        id="start_time"
                                        value="<?php echo availabilityEscape(substr($edit_slot["start_time"], 0, 5)); ?>"
                                        required
                                    >

                                </div>

                                <div class="availability-form-group">

                                    <label for="end_time">End time</label>

                                    <input
                                        type="time"
                                        name="end_time"
                                        id="end_time"
                                        value="<?php echo availabilityEscape(substr($edit_slot["end_time"], 0, 5)); ?>"
                                        required
                                    >

                                </div>

                            </div>

                            <button type="submit" class="availability-submit-btn">
                                Save Changes
                            </button>

                            <a href="availability.php" class="availability-cancel-link">
                                Cancel Editing
                            </a>

                        </form>


                    <?php else: ?>

                        <form
                            method="POST"
                            action="availability.php"
                            class="availability-form"
                        >

                            <input type="hidden" name="action" value="add">

                            <div class="availability-form-group">

                                <label>Select your available days</label>

                                <p class="availability-form-hint availability-day-hint">
                                    You can select multiple days with the same working hours.
                                </p>

                                <div class="availability-day-options">

                                    <?php foreach ($days as $day): ?>

                                        <label class="availability-day-option">

                                            <input
                                                type="checkbox"
                                                name="days[]"
                                                value="<?php echo availabilityEscape($day); ?>"
                                            >

                                            <span>
                                                <?php echo availabilityEscape($day); ?>
                                            </span>

                                        </label>

                                    <?php endforeach; ?>

                                </div>

                            </div>


                            <div class="availability-time-grid">

                                <div class="availability-form-group">

                                    <label for="start_time">Start time</label>

                                    <input
                                        type="time"
                                        name="start_time"
                                        id="start_time"
                                        required
                                    >

                                </div>

                                <div class="availability-form-group">

                                    <label for="end_time">End time</label>

                                    <input
                                        type="time"
                                        name="end_time"
                                        id="end_time"
                                        required
                                    >

                                </div>

                            </div>


                            <p class="availability-form-hint">
                                Example: Select Monday, Tuesday and Wednesday, then set 8:00 AM to 5:00 PM.
                            </p>

                            <button type="submit" class="availability-submit-btn">
                                Add Availability
                            </button>

                        </form>

                    <?php endif; ?>

                </section>


                <!-- SAVED AVAILABILITY -->

                <section class="availability-list-section">

                    <div class="availability-list-heading">

                        <div>

                            <h2>Your Saved Hours</h2>

                            <p>
                                Manage the days and times customers can book you.
                            </p>

                        </div>

                    </div>


                    <?php if (!empty($availability_slots)): ?>

                        <div class="availability-slots-list">

                            <?php foreach ($availability_slots as $slot): ?>

                                <article class="availability-slot-card">

                                    <div class="availability-day-icon">
                                        <?php echo availabilityEscape(strtoupper(substr($slot["day_of_week"], 0, 1))); ?>
                                    </div>

                                    <div class="availability-slot-content">

                                        <h3>
                                            <?php echo availabilityEscape($slot["day_of_week"]); ?>
                                        </h3>

                                        <p>
                                            <?php echo date("g:i A", strtotime($slot["start_time"])); ?>
                                            -
                                            <?php echo date("g:i A", strtotime($slot["end_time"])); ?>
                                        </p>

                                        <span class="availability-slot-status">
                                            Available for bookings
                                        </span>

                                    </div>


                                    <div class="availability-slot-actions">

                                        <a
                                            href="availability.php?edit=<?php echo (int) $slot["id"]; ?>"
                                            class="availability-edit-btn"
                                        >
                                            Edit
                                        </a>

                                        <form
                                            method="POST"
                                            action="availability.php"
                                            onsubmit="return confirm('Are you sure you want to delete this availability slot?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="slot_id"
                                                value="<?php echo (int) $slot["id"]; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="availability-delete-btn"
                                            >
                                                Delete
                                            </button>

                                        </form>

                                    </div>

                                </article>

                            <?php endforeach; ?>

                        </div>


                    <?php else: ?>

                        <div class="availability-empty-state">

                            <div class="availability-empty-icon">
                                &#128197;
                            </div>

                            <h3>No availability added yet</h3>

                            <p>
                                Add your working days and hours using the form.
                                Customers will then be able to see when you are available.
                            </p>

                        </div>

                    <?php endif; ?>

                </section>

            </div>


            <a href="dashboard.php" class="availability-back-link">
                &larr; Back to Dashboard
            </a>

        </div>

    </main>

</div>

</body>
</html>
