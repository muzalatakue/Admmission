<?php
// backend.php - Integrated backend for Crystal Pre-School System
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'crystal_preschool');
define('DB_USER', 'root');
define('DB_PASS', '');

// Error handling
ini_set('display_errors', 0);
error_reporting(E_ALL);

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            $this->connection->set_charset("utf8mb4");
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            die(json_encode(['success' => false, 'message' => 'Database connection failed']));
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            $this->logError("Prepare failed: " . $this->connection->error);
            return false;
        }
        
        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            $this->logError("Execute failed: " . $stmt->error);
            return false;
        }
        
        return $stmt;
    }
    
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $values = array_values($data);
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = $this->query($sql, $values);
        
        if ($stmt) {
            $id = $stmt->insert_id;
            $stmt->close();
            return $id;
        }
        return false;
    }
    
    public function select($table, $conditions = [], $order = '', $limit = '') {
        $sql = "SELECT * FROM $table";
        $params = [];
        
        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $key => $value) {
                $where[] = "$key = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        if ($order) {
            $sql .= " ORDER BY $order";
        }
        
        if ($limit) {
            $sql .= " LIMIT $limit";
        }
        
        $stmt = $this->query($sql, $params);
        if (!$stmt) return [];
        
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $rows;
    }
    
    public function update($table, $data, $conditions) {
        $set = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $set[] = "$key = ?";
            $params[] = $value;
        }
        
        $where = [];
        foreach ($conditions as $key => $value) {
            $where[] = "$key = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where);
        $stmt = $this->query($sql, $params);
        
        if ($stmt) {
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected;
        }
        return false;
    }
    
    private function logError($message) {
        error_log("[" . date('Y-m-d H:i:s') . "] " . $message . "\n", 3, 'error.log');
    }
}

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function register($name, $email, $phone, $password) {
        // Check if user exists
        $existing = $this->db->select('users', ['email' => $email]);
        if (!empty($existing)) {
            return ['success' => false, 'message' => 'Email already registered'];
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Create user
        $userId = $this->db->insert('users', [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $hashedPassword,
            'role' => 'parent',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        if ($userId) {
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role'] = 'parent';
            
            return [
                'success' => true,
                'message' => 'Registration successful',
                'user' => [
                    'id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => 'parent'
                ]
            ];
        }
        
        return ['success' => false, 'message' => 'Registration failed'];
    }
    
    public function login($email, $password) {
        $users = $this->db->select('users', ['email' => $email]);
        
        if (empty($users)) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        $user = $users[0];
        
        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'Invalid password'];
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        
        // Update last login
        $this->db->update('users', 
            ['last_login' => date('Y-m-d H:i:s')], 
            ['id' => $user['id']]
        );
        
        unset($user['password']);
        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => $user
        ];
    }
    
    public function logout() {
        session_destroy();
        return ['success' => true, 'message' => 'Logged out'];
    }
    
    public function isAuthenticated() {
        return isset($_SESSION['user_id']);
    }
    
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        $users = $this->db->select('users', ['id' => $_SESSION['user_id']]);
        if (empty($users)) {
            return null;
        }
        
        $user = $users[0];
        unset($user['password']);
        return $user;
    }
}

class ApplicationManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function submitApplication($userId, $data) {
        // Generate application ID
        $appId = 'CRY' . date('Ymd') . strtoupper(substr(md5(uniqid()), 0, 6));
        
        $applicationData = [
            'application_id' => $appId,
            'user_id' => $userId,
            'child_first_name' => $data['childFirstName'],
            'child_last_name' => $data['childLastName'],
            'child_dob' => $data['childDob'],
            'child_gender' => $data['childGender'],
            'parent_name' => $data['parentName'],
            'parent_relationship' => $data['parentRelationship'],
            'parent_email' => $data['parentEmail'],
            'parent_phone' => $data['parentPhone'],
            'preferred_branch' => $data['preferredBranch'],
            'program_type' => $data['programType'],
            'status' => 'pending',
            'submitted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $id = $this->db->insert('applications', $applicationData);
        
        if ($id) {
            // Send email notification
            $this->sendNotificationEmail($applicationData);
            
            return [
                'success' => true,
                'message' => 'Application submitted successfully',
                'application_id' => $appId
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to submit application'];
    }
    
    public function getUserApplications($userId) {
        return $this->db->select('applications', 
            ['user_id' => $userId], 
            'submitted_at DESC'
        );
    }
    
    public function getAllApplications($filters = []) {
        $sql = "SELECT a.*, u.name as user_name, u.email as user_email 
                FROM applications a 
                JOIN users u ON a.user_id = u.id";
        
        $conditions = [];
        $params = [];
        
        if (!empty($filters['status'])) {
            $conditions[] = "a.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['branch'])) {
            $conditions[] = "a.preferred_branch = ?";
            $params[] = $filters['branch'];
        }
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $sql .= " ORDER BY a.submitted_at DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . intval($filters['limit']);
        }
        
        $stmt = $this->db->query($sql, $params);
        if (!$stmt) return [];
        
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $rows;
    }
    
    public function updateStatus($applicationId, $status) {
        return $this->db->update('applications',
            ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
            ['application_id' => $applicationId]
        );
    }
    
    public function getStatistics() {
        $stats = [];
        
        // Total applications
        $result = $this->db->query("SELECT COUNT(*) as total FROM applications");
        $stats['total'] = $result->get_result()->fetch_assoc()['total'];
        $result->close();
        
        // Applications by status
        $result = $this->db->query("SELECT status, COUNT(*) as count FROM applications GROUP BY status");
        $statusStats = $result->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($statusStats as $stat) {
            $stats[$stat['status']] = $stat['count'];
        }
        $result->close();
        
        // Applications by branch
        $result = $this->db->query("SELECT preferred_branch, COUNT(*) as count FROM applications GROUP BY preferred_branch");
        $branchStats = $result->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($branchStats as $stat) {
            $stats['branch_' . $stat['preferred_branch']] = $stat['count'];
        }
        $result->close();
        
        return $stats;
    }
    
    private function sendNotificationEmail($application) {
        // Email sending logic
        $to = $application['parent_email'];
        $subject = "Crystal Pre-School - Application Submitted";
        
        $message = "
            <html>
            <head>
                <title>Application Confirmation</title>
            </head>
            <body>
                <h2>Application Submitted Successfully!</h2>
                <p>Dear " . htmlspecialchars($application['parent_name']) . ",</p>
                <p>Thank you for submitting an admission application for " . 
                   htmlspecialchars($application['child_first_name'] . ' ' . $application['child_last_name']) . ".</p>
                <p><strong>Application ID:</strong> " . $application['application_id'] . "</p>
                <p><strong>Status:</strong> Pending Review</p>
                <p>We will contact you within 3-5 business days regarding the next steps.</p>
                <br>
                <p>Best regards,<br>Crystal Pre-School Admissions Team</p>
            </body>
            </html>
        ";
        
        // Send email (in production, use PHPMailer or similar)
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: Crystal Pre-School <crystallearning@gmail.com>" . "\r\n";
        
        @mail($to, $subject, $message, $headers);
    }
}

// Initialize database tables
function initializeDatabase() {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            phone VARCHAR(20) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('parent','admin','staff') DEFAULT 'parent',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_login DATETIME,
            INDEX idx_email (email),
            INDEX idx_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS applications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            application_id VARCHAR(20) UNIQUE NOT NULL,
            user_id INT NOT NULL,
            child_first_name VARCHAR(50) NOT NULL,
            child_last_name VARCHAR(50) NOT NULL,
            child_dob DATE NOT NULL,
            child_gender ENUM('male','female','other') NOT NULL,
            parent_name VARCHAR(100) NOT NULL,
            parent_relationship VARCHAR(50) NOT NULL,
            parent_email VARCHAR(100) NOT NULL,
            parent_phone VARCHAR(20) NOT NULL,
            preferred_branch ENUM('section-b2','stand-561') NOT NULL,
            program_type ENUM('full-day','half-day-am','half-day-pm') NOT NULL,
            status ENUM('pending','reviewed','approved','rejected') DEFAULT 'pending',
            submitted_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            notes TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_application_id (application_id),
            INDEX idx_user_id (user_id),
            INDEX idx_status (status),
            INDEX idx_branch (preferred_branch)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS branches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_code VARCHAR(20) UNIQUE NOT NULL,
            name VARCHAR(100) NOT NULL,
            address TEXT NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(100),
            facilities TEXT,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    
    foreach ($tables as $sql) {
        try {
            $conn->query($sql);
        } catch (Exception $e) {
            // Table might already exist
        }
    }
    
    // Insert default branches with updated addresses
    $existing = $db->select('branches');
    if (empty($existing)) {
        $db->insert('branches', [
            'branch_code' => 'section-b2',
            'name' => 'Section B2',
            'address' => 'Next to Salvation Army Church, Mnele Village, Polokwane, Limpopo, South Africa',
            'phone' => '078 318 7635',
            'email' => 'crystallearning@gmail.com',
            'facilities' => 'Modern classrooms, science lab, computer lab, library',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        $db->insert('branches', [
            'branch_code' => 'stand-561',
            'name' => 'Stand No. 561',
            'address' => 'Mnele Village, Polokwane, Limpopo, South Africa',
            'phone' => '078 318 7635',
            'email' => 'crystallearning@gmail.com',
            'facilities' => 'Spacious classrooms, art studio, music room, sports field',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}

// Main request handler
function handleRequest() {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    $auth = new Auth();
    $appManager = new ApplicationManager();
    
    switch ($action) {
        case 'register':
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($name) || empty($email) || empty($phone) || empty($password)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required']);
                return;
            }
            
            $result = $auth->register($name, $email, $phone, $password);
            echo json_encode($result);
            break;
            
        case 'login':
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($email) || empty($password)) {
                echo json_encode(['success' => false, 'message' => 'Email and password are required']);
                return;
            }
            
            $result = $auth->login($email, $password);
            echo json_encode($result);
            break;
            
        case 'logout':
            $result = $auth->logout();
            echo json_encode($result);
            break;
            
        case 'check_auth':
            if ($auth->isAuthenticated()) {
                $user = $auth->getCurrentUser();
                echo json_encode(['success' => true, 'authenticated' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => true, 'authenticated' => false]);
            }
            break;
            
        case 'submit_application':
            if (!$auth->isAuthenticated()) {
                echo json_encode(['success' => false, 'message' => 'Authentication required']);
                return;
            }
            
            $user = $auth->getCurrentUser();
            $data = [
                'childFirstName' => $_POST['childFirstName'] ?? '',
                'childLastName' => $_POST['childLastName'] ?? '',
                'childDob' => $_POST['childDob'] ?? '',
                'childGender' => $_POST['childGender'] ?? '',
                'parentName' => $_POST['parentName'] ?? '',
                'parentRelationship' => $_POST['parentRelationship'] ?? '',
                'parentEmail' => $_POST['parentEmail'] ?? '',
                'parentPhone' => $_POST['parentPhone'] ?? '',
                'preferredBranch' => $_POST['preferredBranch'] ?? '',
                'programType' => $_POST['programType'] ?? ''
            ];
            
            // Validate required fields
            foreach ($data as $key => $value) {
                if (empty($value)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    return;
                }
            }
            
            $result = $appManager->submitApplication($user['id'], $data);
            echo json_encode($result);
            break;
            
        case 'get_applications':
            if (!$auth->isAuthenticated()) {
                echo json_encode(['success' => false, 'message' => 'Authentication required']);
                return;
            }
            
            $user = $auth->getCurrentUser();
            
            if ($user['role'] === 'admin') {
                $filters = [
                    'status' => $_GET['status'] ?? '',
                    'branch' => $_GET['branch'] ?? '',
                    'limit' => $_GET['limit'] ?? 50
                ];
                $applications = $appManager->getAllApplications($filters);
            } else {
                $applications = $appManager->getUserApplications($user['id']);
            }
            
            echo json_encode(['success' => true, 'applications' => $applications]);
            break;
            
        case 'update_status':
            if (!$auth->isAuthenticated()) {
                echo json_encode(['success' => false, 'message' => 'Authentication required']);
                return;
            }
            
            $user = $auth->getCurrentUser();
            if ($user['role'] !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Admin access required']);
                return;
            }
            
            $applicationId = $_POST['applicationId'] ?? '';
            $status = $_POST['status'] ?? '';
            
            if (empty($applicationId) || empty($status)) {
                echo json_encode(['success' => false, 'message' => 'Application ID and status are required']);
                return;
            }
            
            $result = $appManager->updateStatus($applicationId, $status);
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update status']);
            }
            break;
            
        case 'get_statistics':
            if (!$auth->isAuthenticated()) {
                echo json_encode(['success' => false, 'message' => 'Authentication required']);
                return;
            }
            
            $user = $auth->getCurrentUser();
            if ($user['role'] !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Admin access required']);
                return;
            }
            
            $stats = $appManager->getStatistics();
            echo json_encode(['success' => true, 'statistics' => $stats]);
            break;
            
        case 'get_branches':
            $db = Database::getInstance();
            $branches = $db->select('branches', [], 'name ASC');
            echo json_encode(['success' => true, 'branches' => $branches]);
            break;
            
        case 'initialize':
            initializeDatabase();
            echo json_encode(['success' => true, 'message' => 'Database initialized']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
}

// Handle the request
try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        // Handle preflight requests
        http_response_code(200);
        exit;
    }
    
    handleRequest();
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>