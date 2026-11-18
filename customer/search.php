
<?php
session_start();

require_once "../config/database.php";

// Only logged-in customers can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "customer"
) {
    header("Location: ../login.php");
    exit;
}

function escapeOutput($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$customer_name = $_SESSION["full_name"] ?? "Customer";

$service = trim($_GET["service"] ?? "");
$location = trim($_GET["location"] ?? "");

$has_searched = isset($_GET["service"], $_GET["location"]);

$results = false;
$search_error = "";
$result_count = 0;


// Generate flexible search terms and related words.
function getSearchTerms($search)
{
    $search = strtolower(trim($search));
    $search = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $search);

    $terms = [];

    if ($search !== "") {
        $terms[] = $search;
    }

    $words = preg_split('/\s+/', $search);

    $stop_words = [
        "and", "the", "for", "a", "an", "of",
        "to", "in", "with", "my", "need", "want",
        "service", "services", "please"
    ];

    $related_words = [
        "plumber" => ["plumber", "plumbers", "plumbing"],
        "plumbers" => ["plumber", "plumbers", "plumbing"],
        "plumbing" => ["plumber", "plumbers", "plumbing"],

        "clean" => ["clean", "cleaning", "cleaner", "cleaners"],
        "cleaning" => ["clean", "cleaning", "cleaner", "cleaners"],
        "cleaner" => ["clean", "cleaning", "cleaner", "cleaners"],

        "cook" => ["cook", "cooking", "chef", "catering", "meal"],
        "cooking" => ["cook", "cooking", "chef", "catering", "meal"],
        "chef" => ["cook", "cooking", "chef", "catering"],

        "repair" => ["repair", "repairs", "repairing", "fix", "fixing"],
        "repairs" => ["repair", "repairs", "repairing", "fix", "fixing"],
        "fix" => ["repair", "repairs", "fix", "fixing"],

        "electrician" => ["electrician", "electricians", "electrical", "electric"],
        "electricians" => ["electrician", "electricians", "electrical", "electric"],
        "electrical" => ["electrician", "electricians", "electrical", "electric"],

        "gardener" => ["gardener", "gardening", "garden", "landscaping"],
        "gardening" => ["gardener", "gardening", "garden", "landscaping"],

        "laundry" => ["laundry", "washing", "ironing"],
        "washing" => ["laundry", "washing", "ironing"],

        "delivery" => ["delivery", "deliver", "transport", "moving"],
        "deliver" => ["delivery", "deliver", "transport", "moving"],

        "organize" => ["organize", "organizing", "organization"],
        "organizing" => ["organize", "organizing", "organization"],

        "appliance" => ["appliance", "appliances", "repair", "repairs"],
        "appliances" => ["appliance", "appliances", "repair", "repairs"]
    ];

    foreach ($words as $word) {

        $word = trim($word);

        if ($word === "" || in_array($word, $stop_words, true)) {
            continue;
        }

        $terms[] = $word;

        if (isset($related_words[$word])) {
            foreach ($related_words[$word] as $related) {
                $terms[] = $related;
            }
        }
    }

    return array_values(array_unique($terms));
}


// Perform the provider search.
if ($has_searched && $service !== "" && $location !== "") {

    $search_terms = getSearchTerms($service);

    $search_conditions = [];
    $params = [];
    $types = "";

    foreach ($search_terms as $term) {

        $search_conditions[] = "
            LOWER(
                CONCAT_WS(
                    ' ',
                    services.service_name,
                    services.description,
                    provider_profiles.description
                )
            ) LIKE ?
        ";

        $params[] = "%" . $term . "%";
        $types .= "s";
    }

    $search_condition = implode(" OR ", $search_conditions);

    $sql = "SELECT
                users.id AS provider_id,
                users.full_name,
                provider_profiles.location,
                provider_profiles.description AS provider_description,
                services.service_name,
                services.description AS service_description

            FROM users

            INNER JOIN provider_profiles
                ON users.id = provider_profiles.user_id

            INNER JOIN services
                ON users.id = services.provider_id

            WHERE users.role = 'provider'
              AND ($search_condition)
              AND LOWER(provider_profiles.location) LIKE ?

            ORDER BY
                CASE
                    WHEN LOWER(services.service_name) LIKE ? THEN 1
                    WHEN LOWER(services.description) LIKE ? THEN 2
                    ELSE 3
                END,
                users.full_name ASC";

    $stmt = $conn->prepare($sql);

    if ($stmt) {

        $location_search = "%" . strtolower($location) . "%";
        $service_search = "%" . strtolower($service) . "%";

        $params[] = $location_search;
        $params[] = $service_search;
        $params[] = $service_search;

        $types .= "sss";

        $bind_params = [$types];

        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }

        call_user_func_array(
            [$stmt, "bind_param"],
            $bind_params
        );

        if ($stmt->execute()) {

            $results = $stmt->get_result();

            if ($results) {
                $result_count = $results->num_rows;
            } else {
                $search_error = "We couldn't retrieve your search results. Please try again.";
            }

        } else {
            $search_error = "We couldn't complete your search. Please try again.";
        }

        $stmt->close();

    } else {
        $search_error = "We couldn't complete your search. Please try again.";
    }

} elseif ($has_searched) {

    $search_error = "Please enter both a service and a location.";

}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Find a Service | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/customer.css">
</head>

<body class="customer-dashboard-page">

<div class="customer-layout">

    <!-- CUSTOMER SIDEBAR -->
    <aside class="customer-sidebar">

        <a href="../index.php" class="customer-brand">

            <span class="customer-brand-mark">H</span>

            <span class="customer-brand-name">
                Home<span>Ease</span>
            </span>

        </a>


        <div class="sidebar-user">

            <div class="sidebar-user-avatar">
                <?= escapeOutput(strtoupper(substr($customer_name, 0, 1))) ?>
            </div>

            <div class="sidebar-user-details">

                <strong><?= escapeOutput($customer_name) ?></strong>

                <span>Customer</span>

            </div>

        </div>


        <p class="sidebar-section-label">MAIN MENU</p>

        <nav class="customer-sidebar-nav">

            <a href="dashboard.php" class="sidebar-link">
                <span class="sidebar-icon">⌂</span>
                <span>Dashboard</span>
            </a>

            <a href="search.php" class="sidebar-link active" aria-current="page">
                <span class="sidebar-icon">⌕</span>
                <span>Find a Service</span>
            </a>

            <a href="bookings.php" class="sidebar-link">
                <span class="sidebar-icon">▤</span>
                <span>My Bookings</span>
            </a>

            <a href="messages.php" class="sidebar-link">
                <span class="sidebar-icon">✉</span>
                <span>My Messages</span>
            </a>

        </nav>


        <p class="sidebar-section-label sidebar-bottom-label">
            ACCOUNT
        </p>

        <nav class="customer-sidebar-nav">

            <a href="../logout.php" class="sidebar-link">
                <span class="sidebar-icon">↪</span>
                <span>Logout</span>
            </a>

        </nav>


        <div class="sidebar-footer">

            <div class="sidebar-footer-mark">H</div>

            <div>
                <strong>HomeEase</strong>
                <span>Making home services easier.</span>
            </div>

        </div>

    </aside>


    <!-- MAIN CONTENT -->
    <main class="customer-main-content">


        <!-- SEARCH HERO -->
        <section class="service-search-hero">

            <div>

                <span class="search-eyebrow">
                    FIND THE HELP YOU NEED
                </span>

                <h1>What can we help you with?</h1>

                <p>
                    Find home-service providers in your area.
                    Search by service and location to find the help you need.
                </p>

            </div>

            <div class="search-hero-decoration" aria-hidden="true">
                <span>H</span>
            </div>

        </section>


        <!-- SEARCH FORM -->
        <section class="service-search-card">

            <div class="service-search-heading">

                <h2>Find a provider</h2>

                <p>
                    Enter the service you need and your preferred location.
                </p>

            </div>


            <form
                method="GET"
                action="search.php"
                class="service-search-form"
            >

                <div class="search-field">

                    <label for="service">
                        Service needed
                    </label>

                    <input
                        type="text"
                        id="service"
                        name="service"
                        placeholder="e.g. Plumbing, Cleaning, Repairs"
                        value="<?= escapeOutput($service) ?>"
                        maxlength="100"
                        required
                    >

                </div>


                <div class="search-field">

                    <label for="location">
                        Your location
                    </label>

                    <input
                        type="text"
                        id="location"
                        name="location"
                        placeholder="e.g. Ngong, Kisii, Thika"
                        value="<?= escapeOutput($location) ?>"
                        maxlength="100"
                        required
                    >

                </div>


                <button type="submit" class="service-search-button">
                    Search
                    <span aria-hidden="true">→</span>
                </button>

            </form>

        </section>


        <!-- SEARCH RESULTS -->
        <?php if ($search_error !== ""): ?>

            <div class="service-search-error" role="alert">
                <?= escapeOutput($search_error) ?>
            </div>


        <?php elseif ($has_searched): ?>

            <section class="service-results-section">

                <div class="service-results-heading">

                    <div>

                        <span class="search-eyebrow">
                            YOUR SEARCH
                        </span>

                        <h2>Matching providers</h2>

                        <p>
                            <?= escapeOutput($service) ?>
                            <span>·</span>
                            <?= escapeOutput($location) ?>
                        </p>

                    </div>


                    <?php if ($results): ?>

                        <span class="service-results-count">
                            <?= $result_count ?>
                            <?= $result_count === 1 ? "match" : "matches" ?>
                        </span>

                    <?php endif; ?>

                </div>


                <?php if ($results && $result_count > 0): ?>

                    <div class="provider-results-grid">

                        <?php while ($provider = $results->fetch_assoc()): ?>

                            <article class="provider-result-card">

                                <div class="provider-card-top">

                                    <div class="provider-avatar">
                                        <?= escapeOutput(
                                            strtoupper(
                                                substr($provider["full_name"], 0, 1)
                                            )
                                        ) ?>
                                    </div>

                                    <span class="provider-label">
                                        Service Provider
                                    </span>

                                </div>


                                <h3>
                                    <?= escapeOutput($provider["full_name"]) ?>
                                </h3>


                                <span class="provider-service-tag">
                                    <?= escapeOutput($provider["service_name"]) ?>
                                </span>


                                <p class="provider-location">
                                    <span aria-hidden="true">⌖</span>
                                    <?= escapeOutput($provider["location"]) ?>
                                </p>


                                <?php if (!empty($provider["service_description"])): ?>

                                    <div class="provider-description">

                                        <strong>About this service</strong>

                                        <p>
                                            <?= escapeOutput(
                                                $provider["service_description"]
                                            ) ?>
                                        </p>

                                    </div>

                                <?php endif; ?>


                                <?php if (!empty($provider["provider_description"])): ?>

                                    <p class="provider-profile-summary">
                                        <?= escapeOutput(
                                            $provider["provider_description"]
                                        ) ?>
                                    </p>

                                <?php endif; ?>


                                <a
                                    href="provider.php?id=<?= (int) $provider["provider_id"] ?>"
                                    class="provider-view-btn"
                                >
                                    View Profile &amp; Book
                                    <span aria-hidden="true">→</span>
                                </a>

                            </article>

                        <?php endwhile; ?>

                    </div>


                <?php else: ?>

                    <div class="search-empty-state">

                        <div class="empty-state-icon" aria-hidden="true">
                            ⌕
                        </div>

                        <h3>No matching providers found</h3>

                        <p>
                            We couldn't find a provider matching your
                            service and location. Try a different service
                            word or another location.
                        </p>

                        <a href="search.php" class="search-reset-btn">
                            Clear Search
                        </a>

                    </div>

                <?php endif; ?>

            </section>


        <?php else: ?>

            <!-- SEARCH TIPS -->
            <section class="service-search-tips">

                <div class="service-search-tips-heading">

                    <span class="search-eyebrow">
                        QUICK TIP
                    </span>

                    <h2>Finding the right provider</h2>

                </div>


                <div class="search-tips-grid">

                    <article>

                        <span class="tip-number">01</span>

                        <h3>Choose a service</h3>

                        <p>
                            Search for plumbing, cleaning, electrical
                            work or repairs.
                        </p>

                    </article>


                    <article>

                        <span class="tip-number">02</span>

                        <h3>Enter your location</h3>

                        <p>
                            Tell us where you need the service.
                        </p>

                    </article>


                    <article>

                        <span class="tip-number">03</span>

                        <h3>View a provider</h3>

                        <p>
                            Review the provider's profile before booking.
                        </p>

                    </article>

                </div>

            </section>

        <?php endif; ?>


    </main>

</div>

</body>
</html>
