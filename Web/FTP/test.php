<?php
$servername = "localhost";
$username = "db_mhulshof";
$password = "1l4S7^9pr";
$dbname = "db_mhulshof";

//This file exists to test whether the database is operational and connection is possible
// use through browser at: https://htionline.tue.nl/f8622112/test.php

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
echo "Connected successfully";
?>
