<?php
// auth/check_overdue.php

// Set absolute root path to ensure files are accessible from anywhere within the project
require_once $_SERVER['DOCUMENT_ROOT'] . '/library-system/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/library-system/config/email_engine.php';

// Check if the request is coming via POST (meaning the button was actually clicked)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify that the database connection variable is initialized properly
    if (!isset($conn)) {
        die("Database connection failed or variable name is incorrect.");
    }

    // Fetch all records where the book status is 'Issued' and the return date has passed the current date
    $query = "SELECT ib.return_date, s.roll_number, s.email, b.book_title 
          FROM issued_books ib
          JOIN students s ON ib.student_roll = s.roll_number
          JOIN books b ON ib.book_id = b.id
          WHERE ib.status = 'Issued' AND ib.return_date < CURDATE()";

    // Initialize prepared statement for secure query execution
    $stmt = $conn->prepare($query);

    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $emailsSent = 0;

        if ($result->num_rows > 0) {
            // Iterate through each overdue record sequentially
            while ($row = $result->fetch_assoc()) {
                $studentRoll = $row['roll_number'];
                $studentEmail = $row['email'];
                $bookTitle = $row['book_title'];
                $dueDate = $row['return_date'];

                // Calculate fine amount (Days overdue multiplied by Rs. 50 rate)
                $today = new DateTime();
                $due = new DateTime($dueDate);
                $diff = $today->diff($due);
                $daysLate = $diff->days;
                $currentFine = $daysLate * 50;

                // Construct the professional email notification template
                $subject = "URGENT: Library Book Overdue Reminder";
                $messageBody = "
                    <h3>Dear Student,</h3>
                    <p>This is a formal reminder that the library book <b>'$bookTitle'</b> issued to your account (Roll No: <b>$studentRoll</b>) was due for return on <b>$dueDate</b>.</p>
                    <p>Your return is currently overdue by <b>$daysLate day(s)</b>, and a total fine of <b>Rs. $currentFine</b> has been accumulated on your record.</p>
                    <p>Please visit the library counter immediately to return the book and clear your dues.</p>
                    <br>
                    <p>Best regards,<br><b>Library Admin Desk</b></p>
                ";

                // Execute the automated email engine function
                if (sendLibraryEmail($studentEmail, $studentRoll, $subject, $messageBody)) {
                    $emailsSent++;
                }
            }
            echo "Success: Alert emails have been successfully sent to $emailsSent student(s)!";
        } else {
            echo "All clear! No student currently has an overdue book.";
        }
        $stmt->close();
    } else {
        echo "Error: Failed to prepare the database query. " . $conn->error;
    }

} else {
    // Redirecting back to your correct dashboard path if page is refreshed or accessed directly
    header("Location: /library-system/dashboard.php"); 
    exit();
}
?>