<?php
// config/db.php

// Database settings
$host = "localhost";
$user = "root";      
$password = "";      
$dbname = "library_db"; 

// Database connection establish 
$conn = mysqli_connect($host, $user, $password, $dbname);

if (!$conn) {
    die("Database se connection nahi ho saka: " . mysqli_connect_error());
}
?>