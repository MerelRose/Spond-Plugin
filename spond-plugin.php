<?php
/*
Plugin Name: Spond Events
Description: Display Spond events on your WordPress site.
Version: 2.0
Author: Merel Rose de Vries
Icon: spond-icon.png
*/
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

class Spond {
    private $token;
    private $groups;
    private $clientsession;
    private $auth;
    private $authHeaders;
    private $username;
    private $password;
    private $api_url = "https://api.spond.com/core/v1/";
    private $accessToken;

    public function __construct($username, $password) {
        $this->username = $username;
        $this->password = $password;
    }

    public function authHeaders() {
        return [
            "content-type" => "application/json",
            "Authorization" => "Bearer " . $this->accessToken,
            "auth" => $this->auth,
        ];
    }

    public function login() {
        $login_url = $this->api_url . "login";
        $data = ["email" => $this->username, "password" => $this->password];
        $options = [
            'http' => [
                'header' => "Content-type: application/json",
                'method' => 'POST',
                'content' => json_encode($data),
            ],
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($login_url, false, $context);
    
        $login_result = json_decode($response, true);
    
        // Assuming loginToken is the access token
        $this->accessToken = $login_result["loginToken"];
    
        // Store login information in WordPress options
        update_option('spond_login_username', $this->username);
        update_option('spond_login_password', $this->password);
        update_option('spond_login_token', $this->accessToken);
    
        return $this->accessToken;
    }

    public function getAllGroupIds() {
        if (!$this->accessToken || !$this->login()) {
            // Return an empty array if the user is not logged in
            return [];
        }

        $url = $this->api_url . "groups/";

        $response = wp_remote_get($url, ["headers" => $this->authHeaders()]);

        // Log the complete API response for debugging
        $this->logToFile("Complete API Response in getAllGroupIds: " . print_r(wp_remote_retrieve_body($response), true));

        if (is_wp_error($response)) {
            // Handle error, e.g., log the error
            $this->logToFile("Error in getAllGroupIds: " . $response->get_error_message());
            return [];
        }

        // Get the response body
        $response_body = wp_remote_retrieve_body($response);

        // Log the decoded JSON response for debugging
        $this->logToFile("Decoded JSON Response in getAllGroupIds: " . print_r(json_decode($response_body, true), true));

        // Decode the JSON response
        $groups = json_decode($response_body, true);

        // Check if decoding was successful and 'data' key is present
        if ($groups === null || !isset($groups[0]["id"])) {
            // Handle JSON decoding error or missing 'data' key
            $this->logToFile("JSON decoding error or missing 'data' key in getAllGroupIds.");
            return [];
        }
        // Extract group IDs from the 'data' key
        $groupIds = array_column($groups, "id");

        // Log the extracted group IDs for debugging
        $this->logToFile("Extracted Group IDs in getAllGroupIds: " . print_r($groupIds, true));

        return $groupIds;
    }
    public function getGroups() {
        if (!$this->accessToken) {
            $this->login();
        }
        $url = $this->api_url . "groups/";
        $response = $this->clientsession->get($url, ["headers" => $this->authHeaders()]);
        $this->groups = json_decode($response->getBody(), true);
        return $this->groups;
    }

    public function getGroup($uid) {
        if (!$this->accessToken) {
            $this->login();
        }
        if (!$this->groups) {
            $this->getGroups();
        }
        foreach ($this->groups as $group) {
            if ($group["id"] == $uid) {
                return $group;
            }
        }
        throw new Exception("IndexError");
    }

    private function flattenArray($array) {
        $result = '';
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                //Flatten nested arrays
                $result .= $this->flattenArray($value);
            } else {
                $result .= "$key: $value, ";
            }
        }
        return rtrim($result, ', ');
    }
    public function get_events($group_id, $sortOrder = 'asc', $maxEvents = 10) {
        if (!$this->accessToken) {
            $this->login();
        }
    
        $url = $this->api_url . "sponds/?groupId=" . $group_id;
    
        $response = wp_remote_get($url, [
            'headers' => $this->authHeaders(),
        ]);
    
        // Check if the request was successful
        if (is_wp_error($response)) {
            // Handle error, e.g., log the error
            error_log("Error in get_events: " . $response->get_error_message());
            return false;
        }
    
        // Get the response body
        $response_body = wp_remote_retrieve_body($response);
    
        // Decode the JSON response
        $events = json_decode($response_body, true);
    
        // Check if decoding was successful
        if ($events === null && json_last_error() !== JSON_ERROR_NONE) {
            // Handle JSON decoding error, e.g., log an error or return false
            return false;
        }
    
        // Filter out events that have already passed
        $currentTimestamp = time();
        $events = array_filter($events, function ($event) use ($currentTimestamp) {
            return strtotime($event['endTimestamp']) > $currentTimestamp;
        });
    
        // Sort events based on startTimestamp in ascending or descending order
        usort($events, function ($a, $b) use ($sortOrder) {
            $timestampA = strtotime($a['startTimestamp']);
            $timestampB = strtotime($b['startTimestamp']);
    
            if ($sortOrder === 'asc') {
                return $timestampA - $timestampB;
            } else {
                return $timestampB - $timestampA;
            }
        });
    
        // Adjust the events array based on sorting order
        if ($sortOrder === 'desc') {
            // Show the last $maxEvents when sorting is 'desc'
            $events = array_slice($events, -$maxEvents);
        } else {
            // Show the first $maxEvents when sorting is 'asc'
            $events = array_slice($events, 0, $maxEvents);
        }
    
        echo "<table class='tables font-face'><th class='agenda'>Agenda</th>";
        $currentTimestamp = time(); // Get the current timestamp
    
        foreach ($events as $event) {
            // Get the end timestamp of the current event
            $endTimestamp = isset($event['endTimestamp']) ? $event['endTimestamp'] : 'N/A';
    
            // Check if the event is upcoming (endTimestamp > currentTimestamp)
            if ($endTimestamp != 'N/A' && strtotime($endTimestamp) > $currentTimestamp) {
                echo "<tr class='row' >";
                echo "<td class='font-face event'>" . (isset($event['heading']) ? $event['heading'] : 'N/A') . "</td>";
    
                // Convert to date
                $startTimestamp = isset($event['startTimestamp']) ? $event['startTimestamp'] : 'N/A';
                $startTimestamp = $this->date($startTimestamp);
                echo "<td class='font-face startTime'>$startTimestamp</td>";
    
                // Assume $event['startTimestamp'] and $endTimestamp are UTC timestamps
    
                // Create DateTime objects from UTC timestamps
                $startDateTime = new DateTime($event['startTimestamp'], new DateTimeZone('UTC'));
                $endDateTime = new DateTime($endTimestamp, new DateTimeZone('UTC'));
    
                // Set the time zone to Amsterdam
                $amsterdamTimeZone = new DateTimeZone('Europe/Amsterdam');
                $startDateTime->setTimezone($amsterdamTimeZone);
                $endDateTime->setTimezone($amsterdamTimeZone);
    
                // Display the converted start timestamp
                echo "<td class='font-face startDate'>{$startDateTime->format('H:i')}</td>";
    
                // Display a separator
                echo "<td class='font-face dash'>-</td>";
    
                // Display the converted end timestamp
                echo "<td class='font-face endDate'>{$endDateTime->format('H:i')}</td>";
                echo "</tr>";
    
                // Display the description
                $description = isset($event['description']) ? $event['description'] : 'N/A';
                echo "<td class='font-face description'>$description</td>";
            }
        }
        echo "</table>";
    }
    
      
    
    private function logToFile($message) {
        $logFile = plugin_dir_path(__FILE__) . 'custom_log.txt';
        error_log($message . "\n", 3, $logFile);
    }

    private function date($timestamp) {
        $dateTime = new DateTime($timestamp);
        return $dateTime->format('D d-m-Y');
    }

    private function convertTimestamp2($timestamp) {
        $dateTime = new DateTime($timestamp);
        return $dateTime->format('H:i');
    }
}

function spond_shortcode($atts) {
    wp_enqueue_style('spond-style', plugin_dir_url(__FILE__) . 'spond-style.css', array(), filemtime(plugin_dir_path(__FILE__) . 'spond-style.css'));
    // Extract shortcode attributes, set default values
    $atts = shortcode_atts(
        array(
            'sorting' => 'asc', // Default sorting order
            'max_events' => 10,   // Default maximum events to show
        ),
        $atts,
        'spond_events'
    );

    // Empty string to store the events
    $events_output = '';

    // Check if the user is logged in using options
    $username = get_option('spond_login_username') ?: '';
    $password = get_option('spond_login_password') ?: '';
    $savedSelectedGroupId = get_option('spond_selected_group_id', '');

    // Get the sorting order and maximum events from the shortcode attributes
    $sortingOrder = strtolower($atts['sorting']);
    $validSortingOrders = array('asc', 'desc');
    $sortingOrder = in_array($sortingOrder, $validSortingOrders) ? $sortingOrder : 'asc';

    $maxEvents = intval($atts['max_events']);
    $maxEvents = $maxEvents > 0 ? $maxEvents : 10; // Ensure maxEvents is a positive integer

    if (!empty($username) && !empty($password)) {
        // Display events
        $spond = new Spond($username, $password);
        $spond->login();

        // Capture the events in the output string
        ob_start();
        $spond->get_events($savedSelectedGroupId, $sortingOrder, $maxEvents);
        $events_output = ob_get_clean();
    } else {
        // User is not logged in
        $events_output = 'Username and/or password not found.';
    }

    // Return the events content
    return $events_output;
}



// Register the shortcode
add_shortcode('spond_events', 'spond_shortcode');

// Add menu item
add_action('admin_menu', 'spondplugin');

function spondplugin() {
    add_menu_page(
        'Spond-plugin',
        'Spond-plugin',
        'manage_options',
        'your_plugin_menu',
        'your_plugin_page'
    );
}


// Create plugin page content
function your_plugin_page() {
    wp_enqueue_style('spond-style', plugin_dir_url(__FILE__) . 'spond-style.css', array(), filemtime(plugin_dir_path(__FILE__) . 'spond-style.css'));

    add_action('wp_enqueue_scripts', 'enqueue_styles');
    $username = get_option('spond_login_username') ?: '';
    $password = get_option('spond_login_password') ?: '';
    $maxEvents = isset($_POST['max_events']) ? intval($_POST['max_events']) : 10; // Default to 10 if not set

    if (isset($_POST['username']) && isset($_POST['password'])) {
        update_option('spond_login_username', $_POST['username']);
        update_option('spond_login_password', $_POST['password']);
        $username = get_option('spond_login_username') ?: '';
        $password = get_option('spond_login_password') ?: '';

    } elseif ($username && $password && isset($_POST['logout'])) {
        delete_option('spond_login_password');
        delete_option('spond_login_username');
        $username = '';
        $password = '';
    }
    $selectedGroupId = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';
    $selectedSorting = isset($_POST['sorting']) ? sanitize_text_field($_POST['sorting']) : '';

    // Check if the form has been submitted
    if ($_POST && isset($_POST['group_id'])) {
        // Update the option value for the selected group ID and sorting
        update_option('spond_selected_group_id', $selectedGroupId);
        update_option('spond_selected_sorting', $selectedSorting);
    }

    // Retrieve the selected group ID and sorting from the options
    $savedSelectedGroupId = get_option('spond_selected_group_id', '');
    $savedSelectedSorting = get_option('spond_selected_sorting', '');

    // Check if the user is logged in using options
    if (!empty($username) && !empty($password)) {
        // User is logged in, display logout button
        echo '<form action="" method="post">';
        echo '<input type="hidden" name="logout" value="true">';
        echo '<button class="btn-grad" type="submit">Logout</button>';
        echo '</form>';

        // Display events
        $spond = new Spond($username, $password);
        $spond->login();
        
        // Display the form with the dropdowns
        echo '<form action="" method="post">';
        echo '<div>';
        echo '<div class="selector">';

        // Display the dropdown for the sorting
        echo '<b class="text" for="sorting"><b>Select Sorting Order: </b></b>';
        echo '<select class="drop" name="sorting" required>';
        $sortOrders = array('asc' => 'asc', 'desc' => 'desc');
        foreach ($sortOrders as $key => $value) {
            echo '<option value="' . $key . '"' . selected($savedSelectedSorting, $key, false) . '>' . $value . '</option>';
        }
        echo '</select><br><br>';

        // Display the dropdown of group IDs
        echo '<b class="text" for="group_id"><b>Select Group Id: </b></b>';
        echo '<select class="drop" name="group_id" required>';
        $groupIds = $spond->getAllGroupIds();
        foreach ($groupIds as $groupId) {
            echo '<option value="' . $groupId . '"' . selected($savedSelectedGroupId, $groupId, false) . '>' . $groupId . '</option>';
        }
        echo '</select><br><br>';

        // Display the input field for maximum events
        echo '<b class="text" for="max_events"><b>Select Maximum Events: </b></b>';
        echo '<input type="number" class="drop" name="max_events" value="' . esc_attr($maxEvents) . '" min="1" required>';

        echo '<br><button class="btn-grad right" type="submit">Fetch Events</button>';
        echo '</div>';
        echo '</form>';
        echo '<b class="text2"><b>Preview</b></b> <b class="timezone">(Europe/Amsterdam Timezone)</b>';
            //echo "Server Time: " . date("Y-m-d H:i:s", time()) . "<br>";
            echo '<br>To show the events use one of the shortcodes:';
            echo '<br>[spond_events sorting="desc" max_events="' . esc_attr($maxEvents) . '"]';
            echo '<br>[spond_events sorting="asc" max_events="' . esc_attr($maxEvents) . '"]';
            echo '<hr class="line">';
            // echo '<img src="' . plugins_url('spond-logo.png', __FILE__) . '" alt="product icon" class="mini-logo">';
            echo '<a href="https://www.spond.com" target="_blank"><img src="' . plugins_url('spond-logo.png', __FILE__) . '" alt="product icon" class="mini-logo"></a>';
        echo '<div class="preview">';

            echo '<div class="group">';
                // Display events for the selected group
                if (!empty($savedSelectedGroupId)) {
                    // Get and display events for the selected group
                    ob_start();
                    $spond->get_events($savedSelectedGroupId, $savedSelectedSorting, $maxEvents);
                    echo ob_get_clean();
                }
            echo  '</div>';
        echo '</div>';
    } else {
        // User is not logged in, display the login form
        echo '<img src="' . plugins_url('spond-logo.png', __FILE__) . '" alt="product icon" class="logo">';
        echo '<p class="login-text">Login with your spond account.</p>';
        echo '<form action="" method="post">';
        echo '<div>';

        echo '<div class="container">';
        echo '<label class="labels" for="username"><b>Username</b></label>';
        echo '<input class="inputs" type="text" placeholder="Enter Username" name="username" required value="' . $username . '">';

        echo '<label class="labels" for="password"><b>Password</b></label>';
        echo '<input class="inputs" type="password" placeholder="Enter Password" name="password" required value="' . $password . '">';

        echo '<button class="btn-grad" type="submit">Login</button><br><br>';
        echo '<div class="register"><d>New To Spond? </d><a href="https://www.spond.com/try-it-its-free/" target="_blank" class="register-link"> Create an account</a></div>';
        echo '</div>';
        echo '</form>';

    }
}
