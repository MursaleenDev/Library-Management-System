<?php
// 1. Start the session engine at the very top
session_start();

// 2. Include or Establish Database Connection Sync
if (file_exists('config/db.php')) {
    include('config/db.php');
} else {
    // Fallback database initialization matching your library_db schema
    $conn = mysqli_connect("localhost", "root", "", "library_db");
}

// Global Authentication Error Trackers
$staff_error = "";
$student_error = "";
$alert_msg = ""; // Used only for successful redirection notifications now

// 3. Handle Staff/Admin Login Authentication (UPDATED TO RED INLINE ERRORS)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['staff_login_btn'])) {
    $username = trim($_POST['staff_username']);
    $password = trim($_POST['staff_password']);

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, username, password FROM staff WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows == 1) {
            $user_data = $result->fetch_assoc();
            if ($password === $user_data['password']) {
                $_SESSION['user_id'] = $user_data['id'];
                $_SESSION['username'] = $user_data['username'];
                $_SESSION['role'] = 'staff';
                
                header("Location: index.php?view=dashboard");
                exit();
            } else {
                $staff_error = "Invalid secure password. Access denied.";
            }
        } else {
            $staff_error = "Invalid staff username terminal ID.";
        }
        $stmt->close();
    }
}

// 4. Handle Student/Member Login Authentication (UPDATED TO RED INLINE ERRORS)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['student_login_btn'])) {
    $roll_number = trim($_POST['student_roll']);
    $password = trim($_POST['student_password']);

    if (!empty($roll_number) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, roll_number, password FROM students WHERE roll_number = ? LIMIT 1");
        $stmt->bind_param("s", $roll_number);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows == 1) {
            $user_data = $result->fetch_assoc();
            if ($password === $user_data['password']) {
                $_SESSION['user_id'] = $user_data['id'];
                $_SESSION['username'] = $user_data['roll_number'];
                $_SESSION['role'] = 'student';
                
                header("Location: index.php");
                exit();
            } else {
                $student_error = "Invalid account password profile mismatch.";
            }
        } else {
            $student_error = "Roll number not registered in dynamic indexes.";
        }
        $stmt->close();
    }
}

// 5. Handle Logout Request
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: index.php");
    exit();
}

// 6. Process the Book Inventory Form (SECURED VIA PREPARED STATEMENTS)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_book_btn'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'staff') {
        $title = trim($_POST['book_title']);
        $author = trim($_POST['author']);
        $isbn = trim($_POST['isbn']);
        $status = "Available"; 

        if ($title !== '' && $author !== '' && $isbn !== '') {
            $stmt = $conn->prepare("INSERT INTO books (book_title, author, isbn, status) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $title, $author, $isbn, $status);

            if ($stmt->execute()) {
                $alert_msg = "alert('Success! Book added to the library database.'); window.location.href = 'index.php';";
            } else {
                $alert_msg = "alert('Error! Database operational failure.');";
            }
            $stmt->close();
        } else {
            $alert_msg = "alert('Validation Denied! All technical data fields must be populated.');";
        }
    } else {
        $alert_msg = "alert('Security Exception: Unauthorized Endpoint Access.');";
    }
}

// 7. Process Book Allocation / Issuing Engine (SECURED VIA PREPARED STATEMENTS)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['process_issue_btn'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'staff') {
        $st_roll = trim($_POST['issue_student_roll']);
        $b_id = intval($_POST['issue_book_id']);
        $days = intval($_POST['days_allowed']);
        
        $issue_date = date('Y-m-d');
        $return_date = date('Y-m-d', strtotime("+$days days"));

        $stmt_check = $conn->prepare("SELECT id FROM books WHERE id = ? AND status = 'Available' LIMIT 1");
        $stmt_check->bind_param("i", $b_id);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        
        if ($res_check->num_rows == 1) {
            $stmt_check->close();
            
            $stmt_issue = $conn->prepare("INSERT INTO issued_books (student_roll, book_id, issue_date, return_date, status, fine_amount) VALUES (?, ?, ?, ?, 'Issued', 0)");
            $stmt_issue->bind_param("siss", $st_roll, $b_id, $issue_date, $return_date);
            
            $stmt_book = $conn->prepare("UPDATE books SET status = 'Issued' WHERE id = ?");
            $stmt_book->bind_param("i", $b_id);

            if ($stmt_issue->execute() && $stmt_book->execute()) {
                $alert_msg = "alert('Allocation successful! Database matrices synchronized.'); window.location.href = 'index.php?view=dashboard';";
            } else {
                $alert_msg = "alert('Engine Error! Transaction aborted.');";
            }
            $stmt_issue->close();
            $stmt_book->close();
        } else {
            $stmt_check->close();
            $alert_msg = "alert('Allocation Denied. Book either non-existent or currently issued.');";
        }
    } else {
        $alert_msg = "alert('Security Exception: Unauthorized Action.');";
    }
}

// 8. Process Book Return & Automated Fine Calculation Engine
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['process_return_btn'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'staff') {
        $b_id = intval($_POST['return_book_id']);
        $actual_return_date = date('Y-m-d');
        $fine_computed = 0;

        $stmt_find = $conn->prepare("SELECT id, student_roll, return_date FROM issued_books WHERE book_id = ? AND status = 'Issued' LIMIT 1");
        $stmt_find->bind_param("i", $b_id);
        $stmt_find->execute();
        $res_find = $stmt_find->get_result();

        if ($res_find->num_rows == 1) {
            $trans_data = $res_find->fetch_assoc();
            $trans_id = $trans_data['id'];
            $target_roll = $trans_data['student_roll'];
            $deadline_date = $trans_data['return_date'];
            $stmt_find->close();

            // Check if return exceeds deadline
            if (strtotime($actual_return_date) > strtotime($deadline_date)) {
                $seconds_diff = strtotime($actual_return_date) - strtotime($deadline_date);
                $days_late = floor($seconds_diff / (60 * 60 * 24));
                $fine_computed = $days_late * 50; // Rs. 50 per day late fee rate
                
                // Update student balance ledger inside database
                $stmt_fine = $conn->prepare("UPDATE students SET balance = balance + ? WHERE roll_number = ?");
                $stmt_fine->bind_param("ds", $fine_computed, $target_roll);
                $stmt_fine->execute();
                $stmt_fine->close();
            }

            $stmt_return = $conn->prepare("UPDATE issued_books SET status = 'Returned', actual_return_date = ?, fine_amount = ? WHERE id = ?");
            $stmt_return->bind_param("sdi", $actual_return_date, $fine_computed, $trans_id);
            
            $stmt_inv = $conn->prepare("UPDATE books SET status = 'Available' WHERE id = ?");
            $stmt_inv->bind_param("i", $b_id);

            if ($stmt_return->execute() && $stmt_inv->execute()) {
                if ($fine_computed > 0) {
                    $alert_msg = "alert('Settlement Complete! Overdue detected. Fine Computed: Rs. " . $fine_computed . " assigned to Roll No: " . $target_roll . "'); window.location.href = 'index.php?view=dashboard';";
                } else {
                    $alert_msg = "alert('Success! Asset returned with zero penalty within deadline bounds.'); window.location.href = 'index.php?view=dashboard';";
                }
            } else {
                $alert_msg = "alert('Engine Error! Settlement processing aborted.');";
            }
            $stmt_return->close();
            $stmt_inv->close();
        } else {
            $stmt_find->close();
            $alert_msg = "alert('Return Denied! This Book ID is not matching any active output stream.');";
        }
    } else {
        $alert_msg = "alert('Security Exception: Unauthorized Endpoint Access.');";
    }
}

// 9. PROCESS MANUAL FINE REMINDER EMAIL ENGINE (WITH DELAY DAYS & FINE CALCULATION)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_reminder_email_btn'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'staff') {
        $target_student_roll = trim($_POST['student_roll_no']);
        
        // Fetch student email and overdue book details dynamically
        $stmt_email = $conn->prepare("SELECT s.email, i.return_date, b.book_title 
                                      FROM issued_books i
                                      JOIN students s ON i.student_roll = s.roll_number
                                      JOIN books b ON i.book_id = b.id
                                      WHERE i.student_roll = ? AND i.status = 'Issued' LIMIT 1");
        $stmt_email->bind_param("s", $target_student_roll);
        $stmt_email->execute();
        $res_email = $stmt_email->get_result();
        
        if($res_email && $res_email->num_rows == 1) {
            $st_data = $res_email->fetch_assoc();
            $student_real_email = $st_data['email']; 
            $book_title = $st_data['book_title'];
            $return_date = $st_data['return_date'];
            
            // Dynamic Date Math Calculation
            $current_date_stamp = date('Y-m-d');
            $seconds_diff = strtotime($current_date_stamp) - strtotime($return_date);
            $delay_days = floor($seconds_diff / (60 * 60 * 24));
            
            if ($delay_days < 1) { $delay_days = 1; } // Safety fallback
            $total_fine = $delay_days * 50; // $50 per day calculation
            
            // Core Dependency Mapping Sync
            require 'vendor/phpmailer/Exception.php';
            require 'vendor/phpmailer/PHPMailer.php';
            require 'vendor/phpmailer/SMTP.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            try {
                // Server Config Node Settings
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';             
                $mail->SMTPAuth   = true;
                $mail->Username   = 'mursaleenchauhan809@gmail.com'; 
                $mail->Password   = 'mpajcqwsqiuwremw';      
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                
                // Communication Vectors Envelope
                $mail->setFrom('mursaleenchauhan809@gmail.com', 'LibraryHub Control Desk');
                $mail->addAddress($student_real_email);           
                
                // Document Architecture Payload (Simple, Clear & Professional UI)
                $mail->isHTML(true);
                $mail->Subject = 'URGENT: Library Book Overdue Notice & Fine Details';
                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; padding: 25px; background-color: #f3f4f6; color: #1f2937; border-radius: 12px; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb;'>
                        <h2 style='color: #4f46e5; margin-bottom: 5px;'>LibraryHub Control Desk</h2>
                        <p style='font-size: 12px; color: #6b7280; margin-top: 0;'>Automated Overdue System</p>
                        <hr style='border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
                        
                        <p style='font-size: 14px; line-height: 1.6;'>Dear Student (Roll No: <b>$target_student_roll</b>),</p>
                        
                        <p style='font-size: 14px; line-height: 1.6;'>This is a formal notice that the library book <b>\"$book_title\"</b> issued to your profile is now overdue.</p>
                        
                        <!-- Dynamic Fine Ledger Table -->
                        <div style='background-color: #ffffff; padding: 15px; border: 1px solid #d1d5db; border-radius: 8px; margin: 20px 0;'>
                            <h4 style='margin: 0 0 10px 0; color: #1f2937; font-size: 13px; text-transform: uppercase; tracking-wider: 1px;'>Overdue Transaction Ledger</h4>
                            <table style='width: 100%; text-align: left; font-size: 13px; border-collapse: collapse;'>
                                <tr style='border-b: 1px solid #e5e7eb;'>
                                    <td style='padding: 6px 0; color: #6b7280;'>Book Title:</td>
                                    <td style='padding: 6px 0; font-weight: bold;'>$book_title</td>
                                </tr>
                                <tr style='border-b: 1px solid #e5e7eb;'>
                                    <td style='padding: 6px 0; color: #6b7280;'>Expected Due Date:</td>
                                    <td style='padding: 6px 0; font-weight: bold; color: #dc2626;'>$return_date</td>
                                </tr>
                                <tr style='border-b: 1px solid #e5e7eb;'>
                                    <td style='padding: 6px 0; color: #6b7280;'>Total Delay Duration:</td>
                                    <td style='padding: 6px 0; font-weight: bold; color: #dc2626;'>$delay_days Days Late</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0 0 0; color: #6b7280; font-weight: bold;'>Accumulated Fine ($50/day):</td>
                                    <td style='padding: 8px 0 0 0; font-weight: bold; color: #b91c1c; font-size: 15px;'>$$total_fine USD</td>
                                </tr>
                            </table>
                        </div>
                        
                        <div style='background-color: #fee2e2; padding: 15px; border-left: 4px solid #ef4444; border-radius: 6px; margin: 20px 0;'>
                            <p style='margin: 0; font-size: 14px; color: #991b1b; font-weight: bold;'>Required Action:</p>
                            <p style='margin: 5px 0 0 0; font-size: 13px; color: #7f1d1d;'>Please return the book immediately to stop additional daily fine accumulation.</p>
                        </div>
                        
                        <p style='font-size: 14px; line-height: 1.6;'>If you have recently returned this asset, please clear your outstanding balance directly at the main library desk.</p>
                        
                        <hr style='border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
                        <p style='font-size: 11px; color: #9ca3af; text-align: center;'>This is an automated system email. Please do not reply directly to this message.</p>
                    </div>
                ";
                
                $mail->send();
                $alert_msg = "alert('Network Stream Success! Email with delay metrics sent.'); window.location.href = 'index.php?view=dashboard';";
                
            } catch (Exception $e) {
                $alert_msg = "alert('SMTP Transmission Failure: " . $mail->ErrorInfo . "');";
            }
        } else {
            $alert_msg = "alert('Database Exception: Target Roll Number has no matching active issue stream.');";
        }
        $stmt_email->close();
    } else {
        $alert_msg = "alert('Security Exception: Unauthorized Access.');";
    }
}


// Build Search and Base Data Retrieval Queries (UPDATED FOR 2-3 WORDS PARTIAL MATCHING)
$search_box_val = "";
$query_str = "SELECT * FROM books";
if (isset($_GET['search_box']) && !empty(trim($_GET['search_box']))) {
    $search_box_val = trim($_GET['search_box']);
    // SQL check ko optimize kiya taake title, author ya isbn mein kahin bhi words match ho jayein
    $query_str .= " WHERE book_title LIKE ? OR author LIKE ? OR isbn LIKE ?";
}
$query_str .= " ORDER BY id DESC";

$stmt_books = $conn->prepare($query_str);
if (!empty($search_box_val)) {
    // Teeno fields ke liye wildcard assignment jo sirf 2-3 words par bhi trigger hoga
    $wildcard = "%" . $search_box_val . "%";
    $stmt_books->bind_param("sss", $wildcard, $wildcard, $wildcard);
}
$stmt_books->execute();
$books_result = $stmt_books->get_result();


// Determine Default UI Section View State
$initial_view = 'home';
if (isset($_GET['view']) && in_array($_GET['view'], ['home', 'dashboard'])) {
    if ($_GET['view'] == 'dashboard' && isset($_SESSION['role']) && $_SESSION['role'] == 'staff') {
        $initial_view = 'dashboard';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>LibraryHub | Enterprise Cloud Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            surface: '#1e293b',       
            surfaceCard: '#334155',   
            brand: '#6366f1',         
            brandLight: '#a5b4fc',    
            brandTeal: '#38bdf8',     
            borderClr: '#475569',     
            accentText: '#cbd5e1'     
          }
        }
      }
    }
  </script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #18181b; } 
    ::-webkit-scrollbar-thumb { background: #475569; border-radius: 20px; }
    .gradient-text {
      background: linear-gradient(135deg, #ffffff 40%, #a5b4fc 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .animate-fade-in { animation: fadeIn 0.35s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
  </style>
</head>

<body class="bg-zinc-900 text-slate-100 min-h-screen flex flex-col justify-between overflow-x-hidden">

  <?php if (!empty($alert_msg)) { echo "<script>{$alert_msg}</script>"; } ?>

  <div id="sidebarOverlay" class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs z-[60] hidden" onclick="closeSidebar()"></div>

  <!-- Staff Login Modal -->
  <div id="staffLoginModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-[100] flex items-center justify-center p-4 <?php echo empty($staff_error) ? 'hidden' : ''; ?>">
    <div class="bg-surface w-full max-w-md rounded-2xl p-6 shadow-2xl border <?php echo !empty($staff_error) ? 'border-rose-500' : 'border-borderClr'; ?> relative">
      <button onclick="closeModal('staffLoginModal')" class="absolute top-4 right-4 text-slate-300 hover:text-white"><i class="fas fa-times text-lg"></i></button>
      <div class="text-center mb-6">
        <div class="w-12 text-brandLight text-xl mx-auto mb-3"><i class="fas fa-user-shield"></i></div>
        <h3 class="font-bold text-xl text-white">Staff Administration Portal</h3>
      </div>
      
      <!-- Red Error Notification Box -->
      <?php if(!empty($staff_error)): ?>
        <div class="mb-4 bg-rose-500/10 border border-rose-500/30 text-rose-400 p-3 rounded-xl text-xs flex items-center gap-2">
          <i class="fas fa-exclamation-triangle"></i>
          <span><?php echo $staff_error; ?></span>
        </div>
      <?php endif; ?>

      <form action="index.php" method="POST" class="space-y-4">
        <div>
          <label class="block text-xs font-bold text-accentText uppercase mb-1.5 tracking-wider">Staff Username</label>
          <input type="text" name="staff_username" required placeholder="e.g., admin_mursaleen" class="w-full px-4 py-2.5 bg-zinc-950 border <?php echo !empty($staff_error) ? 'border-rose-500 focus:border-rose-500' : 'border-borderClr focus:border-brandLight'; ?> rounded-xl text-sm text-white outline-none transition-all">
        </div>
        <div>
          <label class="block text-xs font-bold text-accentText uppercase mb-1.5 tracking-wider">Secure Password</label>
          <input type="password" name="staff_password" required placeholder="••••••••" class="w-full px-4 py-2.5 bg-zinc-950 border <?php echo !empty($staff_error) ? 'border-rose-500 focus:border-rose-500' : 'border-borderClr focus:border-brandLight'; ?> rounded-xl text-sm text-white outline-none transition-all">
        </div>
        <button type="submit" name="staff_login_btn" class="w-full bg-brand text-white py-2.5 rounded-xl font-bold text-sm hover:bg-indigo-700 transition-all">Verify Credentials</button>
      </form>
    </div>
  </div>

  <!-- Student Login Modal -->
  <div id="studentLoginModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-[100] flex items-center justify-center p-4 <?php echo empty($student_error) ? 'hidden' : ''; ?>">
    <div class="bg-surface w-full max-w-md rounded-2xl p-6 shadow-2xl border <?php echo !empty($student_error) ? 'border-rose-500' : 'border-borderClr'; ?> relative">
      <button onclick="closeModal('studentLoginModal')" class="absolute top-4 right-4 text-slate-300 hover:text-white"><i class="fas fa-times text-lg"></i></button>
      <div class="text-center mb-6">
        <div class="w-12 text-emerald-300 text-xl mx-auto mb-3"><i class="fas fa-user-graduate"></i></div>
        <h3 class="font-bold text-xl text-white">Student Member Terminal</h3>
      </div>

      <!-- Red Error Notification Box -->
      <?php if(!empty($student_error)): ?>
        <div class="mb-4 bg-rose-500/10 border border-rose-500/30 text-rose-400 p-3 rounded-xl text-xs flex items-center gap-2">
          <i class="fas fa-exclamation-triangle"></i>
          <span><?php echo $student_error; ?></span>
        </div>
      <?php endif; ?>

      <form action="index.php" method="POST" class="space-y-4">
        <div>
          <label class="block text-xs font-bold text-accentText uppercase mb-1.5 tracking-wider">Student Roll Number</label>
          <input type="text" name="student_roll" required placeholder="e.g., 2024F-BCE-076" class="w-full px-4 py-2.5 bg-zinc-950 border <?php echo !empty($student_error) ? 'border-rose-500 focus:border-rose-500' : 'border-borderClr focus:border-brandLight'; ?> rounded-xl text-sm text-white outline-none transition-all">
        </div>
        <div>
          <label class="block text-xs font-bold text-accentText uppercase mb-1.5 tracking-wider">Account Password</label>
          <input type="password" name="student_password" required placeholder="••••••••" class="w-full px-4 py-2.5 bg-zinc-950 border <?php echo !empty($student_error) ? 'border-rose-500 focus:border-rose-500' : 'border-borderClr focus:border-brandLight'; ?> rounded-xl text-sm text-white outline-none transition-all">
        </div>
        <button type="submit" name="student_login_btn" class="w-full bg-emerald-600 text-white py-2.5 rounded-xl font-bold text-sm hover:bg-emerald-700 transition-all">Authorize Access</button>
      </form>
    </div>
  </div>

  <div id="inventoryModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-[100] flex items-center justify-center p-4 hidden">
    <div class="bg-surface w-full max-w-md rounded-2xl p-6 shadow-2xl border border-borderClr relative">
      <button onclick="closeModal('inventoryModal')" class="absolute top-4 right-4 text-slate-300 hover:text-white"><i class="fas fa-times"></i></button>
      <h3 class="font-bold text-lg mb-4 text-white"><i class="fas fa-plus-circle text-brandLight mr-2"></i>Append New Book Asset</h3>
      <form action="index.php" method="POST" class="space-y-4">
        <div>
          <label class="block text-xs font-bold text-accentText mb-1">Book Title</label>
          <input type="text" name="book_title" required placeholder="Data Structure and Algorithms" class="w-full px-4 py-2 bg-zinc-950 border border-borderClr rounded-xl text-sm text-white outline-none focus:border-brandLight">
        </div>
        <div>
          <label class="block text-xs font-bold text-accentText mb-1">Author Name</label>
          <input type="text" name="author" required placeholder="Morris" class="w-full px-4 py-2 bg-zinc-950 border border-borderClr rounded-xl text-sm text-white outline-none focus:border-brandLight">
        </div>
        <div>
          <label class="block text-xs font-bold text-accentText mb-1">ISBN Number</label>
          <input type="text" name="isbn" required placeholder="978-3-16-148410-1" class="w-full px-4 py-2 bg-zinc-950 border border-borderClr rounded-xl text-sm text-white outline-none focus:border-brandLight">
        </div>
        <button type="submit" name="add_book_btn" class="w-full bg-brand text-white py-2 rounded-xl font-bold text-sm hover:bg-indigo-700 transition-all">Save to Core Inventory</button>
      </form>
    </div>
  </div>

  <div id="issueBookModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-[100] flex items-center justify-center p-4 hidden">
    <div class="bg-surface w-full max-w-md rounded-2xl p-6 shadow-2xl border border-borderClr relative">
      <button onclick="closeModal('issueBookModal')" class="absolute top-4 right-4 text-slate-300 hover:text-white"><i class="fas fa-times"></i></button>
      <h3 class="font-bold text-lg mb-4 text-white"><i class="fas fa-hand-holding-hand text-emerald-400 mr-2"></i>Issue Book Node</h3>
      <form action="index.php" method="POST" class="space-y-4">
        <div>
          <label class="block text-xs font-bold text-accentText mb-1">Target Student Roll Number</label>
          <input type="text" name="issue_student_roll" required placeholder="e.g., 2024F-BCE-076" class="w-full px-4 py-2 bg-zinc-950 border border-borderClr rounded-xl text-sm text-white outline-none focus:border-brandLight">
        </div>
        <div>
          <label class="block text-xs font-bold text-accentText mb-1">Target Book ID Reference</label>
          <input type="number" name="issue_book_id" required placeholder="e.g., 7" class="w-full px-4 py-2 bg-zinc-950 border border-borderClr rounded-xl text-sm text-white outline-none focus:border-brandLight">
        </div>
        <div>
          <label class="block text-xs font-bold text-accentText mb-1">Circulation Period Bounds</label>
          <select name="days_allowed" class="w-full px-4 py-2 bg-zinc-950 border border-borderClr rounded-xl text-sm text-white outline-none focus:border-brandLight">
            <option value="7">7 Days (Standard Sync)</option>
            <option value="14">14 Days (Extended Module)</option>
            <option value="30">30 Days (Research Matrix)</option>
          </select>
        </div>
        <button type="submit" name="process_issue_btn" class="w-full bg-emerald-600 text-white py-2 rounded-xl font-bold text-sm hover:bg-emerald-700 transition-all">Confirm Allocation Mapping</button>
      </form>
    </div>
  </div>

  <div id="returnBookModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-[100] flex items-center justify-center p-4 hidden">
    <div class="bg-surface w-full max-w-md rounded-2xl p-6 shadow-2xl border border-borderClr relative">
      <button onclick="closeModal('returnBookModal')" class="absolute top-4 right-4 text-slate-300 hover:text-white"><i class="fas fa-times"></i></button>
      <h3 class="font-bold text-lg mb-2 text-white"><i class="fas fa-undo-alt text-brandLight mr-2"></i>Staff Return Desk</h3>
      <form action="index.php" method="POST" class="space-y-4">
        <div>
          <label class="block text-xs font-bold text-accentText mb-1">Target Book ID</label>
          <input type="number" name="return_book_id" required placeholder="e.g., 7" class="w-full px-4 py-2 bg-zinc-950 border border-borderClr rounded-xl text-sm text-white outline-none focus:border-brandLight font-mono">
        </div>
        <button type="submit" name="process_return_btn" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-2 rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2">
          <i class="fas fa-check-circle"></i> Process Return Ledger
        </button>
      </form>
    </div>
  </div>

  <div id="mobileSidebar" class="fixed top-0 left-0 h-full w-72 bg-surface shadow-2xl z-[70] transform -translate-x-full transition-transform duration-300 overflow-y-auto border-r border-borderClr">
    <div class="p-5 border-b border-borderClr">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-extrabold text-xl cursor-pointer" onclick="showSection('home'); closeSidebar();">
          <span class="gradient-text">Library</span><span class="text-brandLight">Hub</span>
        </h2>
        <button onclick="closeSidebar()" class="text-slate-300 hover:text-white p-1"><i class="fas fa-times text-xl"></i></button>
      </div>
      <div class="text-center py-2 text-white text-sm bg-zinc-950 border border-borderClr rounded-xl p-3">
        <?php if(isset($_SESSION['user_id'])): ?>
          <p class="font-medium text-brandLight"><?php echo htmlspecialchars($_SESSION['username']); ?></p>
          <p class="text-slate-400 text-xs mt-1 uppercase font-mono font-bold">[Node: <?php echo $_SESSION['role']; ?>]</p>
        <?php else: ?>
          <p class="font-medium">Welcome to LibraryHub</p>
          <p class="text-slate-400 text-xs mt-1">Guest Engine Active</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="py-4">
      <a onclick="showSection('home'); closeSidebar();" class="flex items-center gap-3 px-5 py-3.5 text-slate-200 hover:bg-slate-700 cursor-pointer"><i class="fas fa-home w-5 text-brandLight"></i> <span class="text-sm font-semibold">Home Portal Engine</span></a>
      <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'staff'): ?>
        <a onclick="showSection('dashboard'); closeSidebar();" class="flex items-center gap-3 px-5 py-3.5 text-slate-200 hover:bg-slate-700 cursor-pointer"><i class="fas fa-chart-pie w-5 text-brandLight"></i> <span class="text-sm font-semibold">Admin Command Matrix</span></a>
      <?php endif; ?>
      <hr class="border-borderClr/40 my-2">
      <?php if(isset($_SESSION['user_id'])): ?>
        <a href="index.php?action=logout" class="flex items-center gap-3 px-5 py-3.5 text-rose-400 hover:bg-slate-700 cursor-pointer"><i class="fas fa-sign-out-alt w-5"></i> <span class="text-sm font-semibold">Kill Active Session</span></a>
      <?php else: ?>
        <a onclick="openModal('staffLoginModal'); closeSidebar();" class="flex items-center gap-3 px-5 py-2.5 text-slate-200 hover:bg-slate-700 cursor-pointer"><i class="fas fa-user-shield w-5 text-brandLight"></i> <span class="text-sm font-semibold">Staff Authentication</span></a>
        <a onclick="openModal('studentLoginModal'); closeSidebar();" class="flex items-center gap-3 px-5 py-2.5 text-slate-200 hover:bg-slate-700 cursor-pointer"><i class="fas fa-user-graduate w-5 text-emerald-400"></i> <span class="text-sm font-semibold">Student Login Node</span></a>
      <?php endif; ?>
    </div>
  </div>

  <nav class="bg-surface/95 backdrop-blur-md border-b border-borderClr h-16 flex items-center justify-between px-4 sm:px-8 shadow-md sticky top-0 z-50">
    <div class="flex items-center gap-4">
      <button onclick="toggleSidebar()" class="text-slate-200 hover:text-white p-2 rounded-xl hover:bg-slate-700/60">
        <i class="fas fa-bars text-lg"></i>
      </button>
      <div class="logo cursor-pointer hidden sm:block" onclick="showSection('home')">
        <h2 class="font-extrabold text-xl tracking-tight"><span class="gradient-text">Library</span><span class="text-brandLight">Hub</span></h2>
      </div>
    </div>

    <div class="flex-grow max-w-md mx-6 hidden md:block">
        <form action="index.php" method="GET" class="relative">
            <input type="hidden" name="view" value="<?php echo $initial_view; ?>">
            <input type="text" name="search_box" value="<?php echo htmlspecialchars($search_box_val); ?>" placeholder="Search books by metadata index, title, author..." class="w-full bg-zinc-950 text-slate-200 placeholder-slate-500 text-xs rounded-xl pl-10 pr-4 py-2 border border-borderClr focus:outline-none focus:border-brandLight transition-all">
            <i class="fas fa-search absolute left-3.5 top-3 text-slate-500 text-xs"></i>
        </form>
    </div>

    <div class="flex items-center gap-1 bg-zinc-950/60 p-1.5 rounded-xl border border-borderClr/60">
      <button id="navTabHome" onclick="showSection('home')" class="px-4 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wider transition-all">
        <i class="fas fa-home mr-1.5 text-sm"></i>Home Portal
      </button>
      <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'staff'): ?>
        <button id="navTabDash" onclick="showSection('dashboard')" class="px-4 py-1.5 rounded-lg text-xs font-semibold uppercase tracking-wider transition-all">
          <i class="fas fa-chart-pie mr-1.5 text-sm"></i>Admin Dashboard
        </button>
      <?php endif; ?>
    </div>

    <div class="flex items-center gap-2">
      <?php if(isset($_SESSION['user_id'])): ?>
        <div class="flex items-center gap-3 bg-zinc-950 border border-borderClr px-3 py-1.5 rounded-xl">
          <span class="text-xs font-mono font-bold text-brandLight hidden sm:inline"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
          <a href="index.php?action=logout" class="text-xs font-bold text-rose-400 hover:text-rose-300 border-l border-borderClr pl-2 ml-1">Logout</a>
        </div>
      <?php else: ?>
        <button onclick="openModal('staffLoginModal')" class="hidden sm:inline-block px-3 py-1.5 bg-slate-800 border border-borderClr text-slate-200 rounded-xl text-xs font-bold hover:bg-slate-700 transition-colors mr-1">Staff Access</button>
        <button onclick="openModal('studentLoginModal')" class="px-3 py-1.5 bg-brand text-white rounded-xl text-xs font-bold hover:bg-indigo-700 transition-colors">Student Terminal</button>
      <?php endif; ?>
    </div>
  </nav>

  <div class="p-4 md:hidden bg-zinc-900 border-b border-borderClr/40">
      <form action="index.php" method="GET" class="relative">
          <input type="hidden" name="view" value="<?php echo $initial_view; ?>">
          <input type="text" name="search_box" value="<?php echo htmlspecialchars($search_box_val); ?>" placeholder="Search system database..." class="w-full bg-zinc-950 text-slate-200 placeholder-slate-500 text-xs rounded-xl pl-10 pr-4 py-2 border border-borderClr focus:outline-none">
          <i class="fas fa-search absolute left-3.5 top-3 text-slate-500 text-xs"></i>
      </form>
  </div>

  <main id="mainContent" class="flex-grow pb-16 max-w-[1300px] mx-auto w-full px-4 sm:px-6 mt-8">
    
    <?php if($initial_view == 'home'): ?>
    <div id="homeSection" class="animate-fade-in space-y-10">
      
      <div class="border border-borderClr bg-surface rounded-3xl p-8 sm:p-14 text-center shadow-2xl relative overflow-hidden">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold font-mono bg-brand/20 border border-brand/40 text-brandLight mb-5 tracking-wider uppercase">
          <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse"></span> Prepared Statements Engine Security Set
        </span>
        <h1 class="text-3xl sm:text-5xl font-extrabold mb-4 text-white tracking-tight max-w-3xl mx-auto leading-tight">
          Enterprise Cloud Library Control Subsystem
        </h1>
        <p class="text-slate-300 text-xs sm:text-sm mb-8 max-w-xl mx-auto leading-relaxed font-medium">
          Automate system data processing workflows, map allocation nodes, track dynamic late returns penalties and configure audit files in unified schemas.
        </p>
      </div>

      <div class="space-y-6">
          <div class="flex items-center justify-between border-b border-borderClr/40 pb-3">
              <h3 class="text-base font-bold text-white flex items-center gap-2"><i class="fas fa-cubes text-brandTeal"></i> Core Book Repository Assets</h3>
              <span class="text-xs font-mono bg-zinc-950 text-slate-400 px-3 py-1 border border-borderClr rounded-lg"><?php echo $books_result->num_rows; ?> Vector Nodes</span>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              <?php if ($books_result->num_rows > 0): ?>
                  <?php while($book = $books_result->fetch_assoc()): ?>
                      <?php 
                        $is_avail = ($book['status'] == 'Available');
                        $card_border_cls = $is_avail ? 'hover:border-emerald-500/60' : 'hover:border-rose-500/60';
                        $badge_cls = $is_avail ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : 'bg-rose-500/10 text-rose-400 border border-rose-500/30';
                        $title_color_cls = $is_avail ? 'text-slate-100' : 'text-slate-400 line-through decoration-slate-600/60';
                      ?>
                      <div class="bg-surface border border-borderClr/70 p-6 rounded-2xl shadow-sm transition-all duration-300 relative group flex flex-col justify-between <?php echo $card_border_cls; ?>">
                          <div>
                              <div class="flex justify-between items-start mb-4">
                                  <span class="px-2.5 py-0.5 rounded-lg text-[10px] font-extrabold uppercase tracking-wider font-mono flex items-center gap-1.5 <?php echo $badge_cls; ?>">
                                      <span class="w-1.5 h-1.5 rounded-full <?php echo $is_avail ? 'bg-emerald-400 animate-pulse':'bg-rose-400'; ?>"></span>
                                      <?php echo $book['status']; ?>
                                  </span>
                                  <span class="text-[11px] font-mono font-bold text-slate-500 bg-zinc-950/80 px-2 py-0.5 rounded border border-borderClr/40">ID: #BK-<?php echo $book['id']; ?></span>
                              </div>
                              <h4 class="font-bold text-base transition-colors duration-200 <?php echo $title_color_cls; ?> group-hover:text-white"><?php echo htmlspecialchars($book['book_title']); ?></h4>
                              <p class="text-xs text-slate-400 mt-1.5 flex items-center gap-1"><span class="text-slate-500">By</span> <span class="text-slate-300 font-medium"><?php echo htmlspecialchars($book['author']); ?></span></p>
                          </div>
                          <div class="mt-5 pt-3.5 border-t border-borderClr/30 flex justify-between items-center text-[11px] font-mono text-slate-500">
                              <span>ISBN Interface</span>
                              <span class="text-slate-400 font-semibold"><?php echo htmlspecialchars($book['isbn']); ?></span>
                          </div>
                      </div>
                  <?php endwhile; ?>
              <?php else: ?>
                  <div class="col-span-full bg-surface border border-dashed border-borderClr p-12 rounded-2xl text-center text-slate-400">
                      <i class="fas fa-folder-open text-2xl mb-2 text-slate-600"></i>
                      <p class="text-sm font-medium">Zero matching records found inside database indexes.</p>
                  </div>
              <?php endif; ?>
          </div>
      </div>

      <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'student'): ?>
      <div id="studentHubSection" class="animate-fade-in space-y-6 pt-4">
        <div class="border border-emerald-500/30 bg-surface rounded-3xl p-6 sm:p-8 shadow-2xl">
          <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
              <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold font-mono bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 uppercase tracking-wider">
                <i class="fas fa-graduation-cap"></i> Verified Member Matrix Node
              </span>
              <h2 class="text-xl sm:text-2xl font-bold text-white mt-2 tracking-tight">Welcome Back, Student Core Profile</h2>
              <p class="text-slate-400 text-xs font-mono mt-1">Roll Identifier: <?php echo htmlspecialchars($_SESSION['username']); ?></p>
            </div>
            
            <?php
            $stmt_bal = $conn->prepare("SELECT balance FROM students WHERE roll_number = ? LIMIT 1");
            $stmt_bal->bind_param("s", $_SESSION['username']);
            $stmt_bal->execute();
            $res_bal = $stmt_bal->get_result();
            $student_balance = 0.00;
            if($res_bal && $res_bal->num_rows == 1) {
                $bal_row = $res_bal->fetch_assoc();
                $student_balance = $bal_row['balance'];
            }
            $stmt_bal->close();
            ?>
            <div class="bg-zinc-950 px-4 py-3 border border-borderClr rounded-2xl flex items-center gap-4 w-full sm:w-auto">
              <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider font-mono">Arrears / Pending Fine Ledger</p>
                <p class="text-lg font-bold <?php echo ($student_balance > 0) ? 'text-rose-400':'text-emerald-400'; ?>">$<?php echo number_format($student_balance, 2); ?></p>
              </div>
              <div class="w-8 h-8 bg-amber-500/20 text-amber-300 rounded-lg flex items-center justify-center text-sm"><i class="fas fa-wallet"></i></div>
            </div>
          </div>
        </div>

        <div class="bg-surface border border-borderClr rounded-3xl p-6 shadow-xl">
          <h3 class="text-sm font-bold text-white uppercase tracking-wider mb-4"><i class="fas fa-book-reader mr-2 text-emerald-400"></i>Your Personal Circulation Log</h3>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
              <thead>
                <tr class="border-b border-borderClr text-slate-400">
                  <th class="pb-3">Book Reference ID</th>
                  <th class="pb-3">Allocation Date</th>
                  <th class="pb-3">Target Deadline</th>
                  <th class="pb-3">Operational Status</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $stmt_stud_books = $conn->prepare("SELECT book_id, issue_date, return_date, status FROM issued_books WHERE student_roll = ? ORDER BY id DESC");
                $stmt_stud_books->bind_param("s", $_SESSION['username']);
                $stmt_stud_books->execute();
                $stud_books_res = $stmt_stud_books->get_result();
                while($s_row = $stud_books_res->fetch_assoc()):
                ?>
                <tr class="border-b border-borderClr/40 text-slate-200">
                  <td class="py-3 font-mono">#BK-<?php echo $s_row['book_id']; ?></td>
                  <td class="py-3"><?php echo $s_row['issue_date']; ?></td>
                  <td class="py-3 text-rose-300"><?php echo $s_row['return_date']; ?></td>
                  <td class="py-3"><span class="px-2 py-0.5 rounded bg-zinc-950 border text-[10px] font-bold"><?php echo $s_row['status']; ?></span></td>
                </tr>
                <?php endwhile; $stmt_stud_books->close(); ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <?php endif; ?>

    <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'staff' && $initial_view == 'dashboard'): ?>
    <div id="adminMatrixSection" class="animate-fade-in space-y-8">
      <div class="flex flex-wrap gap-4 items-center justify-between">
          <h2 class="text-2xl font-bold text-white tracking-tight"><i class="fas fa-gauge-high mr-2 text-brandLight"></i>Admin Command Module</h2>
          <div class="flex gap-2">
              <button onclick="openModal('inventoryModal')" class="bg-brand text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-700 transition-all"><i class="fas fa-plus mr-1.5"></i> Add Asset</button>
              <button onclick="openModal('issueBookModal')" class="bg-emerald-600 text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-emerald-700 transition-all"><i class="fas fa-exchange-alt mr-1.5"></i> Issue Node</button>
              <button onclick="openModal('returnBookModal')" class="bg-amber-600 text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-amber-700 transition-all"><i class="fas fa-undo mr-1.5"></i> Return Desk</button>
          </div>
      </div>

      <div class="bg-surface border border-borderClr rounded-3xl p-6 shadow-xl">
        <div class="mb-4">
            <h3 class="text-sm font-bold text-white uppercase tracking-wider"><i class="fas fa-clock mr-2 text-rose-400"></i>Active Overdue Allocation Streams</h3>
            <p class="text-xs text-slate-400 mt-1">Manual notification triggers for students exceeding grace parameters.</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead>
              <tr class="border-b border-borderClr text-slate-400 uppercase tracking-wider font-mono text-[10px]">
                <th class="pb-3">Book ID</th>
                <th class="pb-3">Student Roll</th>
                <th class="pb-3">Allocation Date</th>
                <th class="pb-3">Deadline Bounds</th>
                <th class="pb-3 text-center">Action Framework</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $current_date_stamp = date('Y-m-d');
              // Query updated to capture correct status configuration
              $stmt_overdue = $conn->prepare("SELECT book_id, student_roll, issue_date, return_date FROM issued_books WHERE status = 'Issued' AND return_date < ? ORDER BY return_date ASC");
              $stmt_overdue->bind_param("s", $current_date_stamp);
              $stmt_overdue->execute();
              $overdue_res = $stmt_overdue->get_result();
              
              if($overdue_res->num_rows == 0):
              ?>
              <tr>
                  <td colspan="5" class="py-6 text-center text-slate-400 font-medium">All database vectors synchronized. Zero overdue allocations detected.</td>
              </tr>
              <?php 
              else:
                  while($o_row = $overdue_res->fetch_assoc()):
              ?>
              <tr class="border-b border-borderClr/40 text-slate-200 hover:bg-slate-800/30 transition-colors">
                <td class="py-3.5 font-mono text-brandTeal">#BK-<?php echo $o_row['book_id']; ?></td>
                <td class="py-3.5 font-semibold text-white"><?php echo htmlspecialchars($o_row['student_roll']); ?></td>
                <td class="py-3.5 text-slate-400"><?php echo $o_row['issue_date']; ?></td>
                <td class="py-3.5 text-rose-400 font-bold font-mono"><?php echo $o_row['return_date']; ?></td>
                <td class="py-3.5 text-center">
                    <form action="index.php" method="POST" class="inline-block">
                        <input type="hidden" name="student_roll_no" value="<?php echo htmlspecialchars($o_row['student_roll']); ?>">
                        <button type="submit" name="send_reminder_email_btn" class="bg-brand hover:bg-indigo-700 text-white px-3 py-1.5 rounded-xl text-[11px] font-bold transition-all flex items-center gap-1.5 mx-auto">
                            <i class="fas fa-paper-plane text-[10px]"></i> Send Fine Reminder
                        </button>
                    </form>
                </td>
              </tr>
              <?php 
                  endwhile;
              endif; 
              $stmt_overdue->close();
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </main>

  <footer class="bg-zinc-950 border-t border-borderClr/60 pt-10 pb-6 text-xs text-slate-400 mt-auto">
      <div class="max-w-[1300px] mx-auto w-full px-4 sm:px-6 grid grid-cols-1 md:grid-cols-3 gap-8 mb-8 text-left">
          <div>
              <h4 class="font-bold text-white text-sm mb-3 tracking-tight">LibraryHub Enterprise</h4>
              <p class="text-slate-400 leading-relaxed max-w-sm text-[11px]">An automated digital ledger framework designed for computer engineering environments to govern transaction matrix points and security compliance parameters.</p>
          </div>
          <div>
              <h4 class="font-mono text-[11px] font-bold text-indigo-400 uppercase tracking-widest mb-3">System Node Architecture</h4>
              <ul class="space-y-1.5 font-mono text-[11px]">
                  <li class="flex items-center gap-2 text-slate-300"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Core Engine: PHP 8.x</li>
                  <li class="flex items-center gap-2 text-slate-300"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Database Cluster: MySQL</li>
                  <li class="flex items-center gap-2 text-slate-300"><span class="w-1.5 h-1.5 rounded-full bg-indigo-400"></span> Crypto Layer: SQL Injection Proof</li>
              </ul>
          </div>
          <div>
              <h4 class="font-mono text-[11px] font-bold text-indigo-400 uppercase tracking-widest mb-3">Active Operational Health</h4>
              <div class="bg-zinc-900 border border-borderClr/40 rounded-xl p-3 space-y-1 font-mono text-[11px]">
                  <p class="flex justify-between"><span class="text-slate-500">Database Status:</span> <span class="text-emerald-400 font-bold flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> ONLINE</span></p>
                  <p class="flex justify-between"><span class="text-slate-500">Active Latency:</span> <span class="text-slate-300">14 ms (Stable)</span></p>
                  <p class="flex justify-between"><span class="text-slate-500">Token Engine:</span> <span class="text-brandLight">SESSION_SECURE</span></p>
              </div>
          </div>
      </div>
      <div class="max-w-[1300px] mx-auto w-full px-4 sm:px-6 border-t border-borderClr/20 pt-4 flex flex-col sm:flex-row items-center justify-between gap-3 text-[11px] font-mono">
          <p>&copy; <?php echo date('Y'); ?> LibraryHub Control Systems. All cryptographic data schemas locked and operational.</p>
          <div class="flex gap-4 text-slate-500">
              <span class="hover:text-slate-300 transition-colors">v2.4.0-Stable</span>
          </div>
      </div>
  </footer>

  <script>
    function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
    function toggleSidebar() {
        const sidebar = document.getElementById('mobileSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
    }
    function closeSidebar() {
        document.getElementById('mobileSidebar').classList.add('-translate-x-full');
        document.getElementById('sidebarOverlay').classList.add('hidden');
    }
    function showSection(section) {
        window.location.href = 'index.php?view=' + section;
    }
    
    // UI Highlight Sync Configuration Script
    const urlParams = new URLSearchParams(window.location.search);
    const viewState = urlParams.get('view') || 'home';
    const tabHome = document.getElementById('navTabHome');
    const tabDash = document.getElementById('navTabDash');
    
    if(viewState === 'dashboard' && tabDash) {
        tabDash.className = "px-4 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wider bg-brand text-white shadow-sm";
        if(tabHome) tabHome.className = "px-4 py-1.5 rounded-lg text-xs font-semibold uppercase tracking-wider text-slate-400 hover:text-white";
    } else if(tabHome) {
        tabHome.className = "px-4 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wider bg-brand text-white shadow-sm";
        if(tabDash) tabDash.className = "px-4 py-1.5 rounded-lg text-xs font-semibold uppercase tracking-wider text-slate-400 hover:text-white";
    }
  </script>
</body>
</html>