To run a cURL request using PHP, you need to use the curl functions provided by PHP. Below is a simple example of how to perform a GET request with cURL in PHP:

php
Copy code
<?php
// Initialize a cURL session
$ch = curl_init();

// Set the URL for the cURL request
$url = 'https://msdfar.hynetz.com/dfar/backend/web/site/expire';
curl_setopt($ch, CURLOPT_URL, $url);

// Set the cURL option to return the response as a string
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Execute the cURL request and get the response
$response = curl_exec($ch);

// Check for cURL errors
if ($response === false) {
    $error = curl_error($ch);
    echo "cURL Error: $error";
} else {
    // Decode the JSON response (if the response is in JSON format)
    $data = json_decode($response, true);

    // Print the response data
    print_r($data);
}

// Close the cURL session
curl_close($ch);
?>