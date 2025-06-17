<?php
// vortextz-terminal.php — TAB‑indented CLI page.
// Fixes: PHP syntax error, ensures HTML/JS preview executes.
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

/* ───────────────────────────────────────────────────────────
    1.    Shared settings
   ──────────────────────────────────────────────────────────*/
    $CONFIG_FILE = __DIR__ . '/vortextz-terminal-config.txt';
    $ADMIN_CONFIG_FILE = __DIR__ . '/vortextz-admin-config.php';
    $EDITOR_CONFIG_FILE = __DIR__ . '/vortextz-editor-config.php';
    
    // Create admin config file if it doesn't exist
    if (!file_exists($ADMIN_CONFIG_FILE)) {
        $default_admin_config = <<<EOT
<?php
// Admin credentials - CHANGE THESE!
\$ADMIN_USERNAME = 'admin';
\$ADMIN_PASSWORD = 'password'; // Store hashed password in production
\$ADMIN_SESSION_TIMEOUT = 3600; // 1 hour
?>
EOT;
        file_put_contents($ADMIN_CONFIG_FILE, $default_admin_config);
    }
    
    // Include admin configuration
    require_once($ADMIN_CONFIG_FILE);
    
    // Create editor config file if it doesn't exist
    if (!file_exists($EDITOR_CONFIG_FILE)) {
        $default_editor_config = <<<EOT
<?php
// Editor configuration
\$EDITOR_TAB_SIZE = 4; // Number of spaces per tab
?>
EOT;
        file_put_contents($EDITOR_CONFIG_FILE, $default_editor_config);
    }
    
    // Include editor configuration
    require_once($EDITOR_CONFIG_FILE);
    
    // Start session for admin authentication
    session_start();

/* ───────────────────────────────────────────────────────────
    2.    CLI mode
   ──────────────────────────────────────────────────────────*/
    if (php_sapi_name() === 'cli') {
        $argv = $_SERVER['argv'];
        array_shift($argv);                      // remove script name
        $cmd = array_shift($argv) ?: 'help';

        $response = handle_cli_command($cmd . ' ' . implode(' ', $argv), $CONFIG_FILE, true);
        if (isset($response['error'])) {
            fwrite(STDERR, "Error: {$response['error']}\n");
            exit(1);
        }

        if (isset($response['list'])) echo $response['list'] . "\n";
        if (isset($response['code'])) echo $response['code'];
        if (isset($response['msg']))  echo $response['msg'] . "\n";
        exit(0);
    }

/* ───────────────────────────────────────────────────────────
    3.    HTTP endpoints
   ──────────────────────────────────────────────────────────*/
    // CLI endpoint
    if (isset($_GET['cli'])) {
        $out = handle_cli_command(trim($_GET['cli']), $CONFIG_FILE, true, true);
        header('Content-Type: application/json');
        echo json_encode($out);
        exit;
    }
    
    // Admin login endpoint
    if (isset($_POST['admin_login'])) {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if ($username === $ADMIN_USERNAME && $password === $ADMIN_PASSWORD) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_login_time'] = time();
            header('Location: ?admin');
            exit;
        } else {
            header('Location: ?login&error=1');
            exit;
        }
    }
    
    // Admin logout endpoint
    if (isset($_GET['logout'])) {
        session_destroy();
        header('Location: ./');
        exit;
    }
    
    // Cleanup temp files endpoint
    if (isset($_POST['cleanup_blob'])) {
        $blobId = $_POST['blob_id'] ?? '';
        if (!empty($blobId)) {
            $tmpDir = __DIR__ . '/tmp';
            $tmpFile = $tmpDir . '/' . basename($blobId);
            if (file_exists($tmpFile) && is_file($tmpFile)) {
                unlink($tmpFile);
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }
    
    // Save editor tab size
    if (isset($_POST['save_tab_size']) && is_admin_authenticated()) {
        $tabSize = (int)$_POST['tab_size'];
        if ($tabSize < 1) $tabSize = 4; // Default to 4 if invalid
        
        $editor_config = "<?php\n// Editor configuration\n\$EDITOR_TAB_SIZE = $tabSize; // Number of spaces per tab\n?>";
        file_put_contents($EDITOR_CONFIG_FILE, $editor_config);
        
        header('Location: ?text_editor&tab_size_saved=1');
        exit;
    }
    
    // CRUD operations for listings
    if (isset($_POST['action']) && is_admin_authenticated()) {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'add_listing':
                $newCommand = '<' . $_POST['command_text'];
                $commands = file_exists($CONFIG_FILE) ? file($CONFIG_FILE, FILE_IGNORE_NEW_LINES) : [];
                $commands[] = $newCommand;
                file_put_contents($CONFIG_FILE, implode("\n", $commands));
                header('Location: ?admin&added=1');
                exit;
                
            case 'edit_listing':
                $index = (int)$_POST['index'];
                $newCommand = '<' . $_POST['command_text'];
                $commands = file($CONFIG_FILE, FILE_IGNORE_NEW_LINES);
                if (isset($commands[$index])) {
                    $commands[$index] = $newCommand;
                    file_put_contents($CONFIG_FILE, implode("\n", $commands));
                }
                header('Location: ?admin&edited=1');
                exit;
                
            case 'delete_listing':
                $index = (int)$_POST['index'];
                $commands = file($CONFIG_FILE, FILE_IGNORE_NEW_LINES);
                if (isset($commands[$index])) {
                    unset($commands[$index]);
                    file_put_contents($CONFIG_FILE, implode("\n", array_values($commands)));
                }
                header('Location: ?admin&deleted=1');
                exit;
                
            case 'create_directory':
                $dirType = $_POST['dir_type'] ?? '';
                $dirName = $_POST['dir_name'] ?? '';
                
                if (empty($dirName) || !in_array($dirType, ['input', 'output'])) {
                    header('Location: ?admin&error=invalid_directory');
                    exit;
                }
                
                // Sanitize directory name to prevent directory traversal
                $dirName = str_replace(['..', '/', '\\'], '', $dirName);
                $dirPath = __DIR__ . '/' . $dirName;
                
                if (!file_exists($dirPath)) {
                    mkdir($dirPath, 0777, true);
                    header('Location: ?admin&dir_created=1');
                } else {
                    header('Location: ?admin&dir_exists=1');
                }
                exit;
                
            case 'upload_file':
                $targetDir = $_POST['target_dir'] ?? '';
                
                // Sanitize directory name
                $targetDir = str_replace(['..', '/', '\\'], '', $targetDir);
                $dirPath = __DIR__ . '/' . $targetDir;
                
                if (!file_exists($dirPath) || !is_dir($dirPath)) {
                    header('Location: ?admin&error=invalid_target_dir');
                    exit;
                }
                
                if (!isset($_FILES['txt_file']) || $_FILES['txt_file']['error'] !== UPLOAD_ERR_OK) {
                    header('Location: ?admin&error=upload_failed');
                    exit;
                }
                
                // Validate file type
                $fileInfo = pathinfo($_FILES['txt_file']['name']);
                if (strtolower($fileInfo['extension']) !== 'txt') {
                    header('Location: ?admin&error=not_txt_file');
                    exit;
                }
                
                // Move uploaded file
                $targetFile = $dirPath . '/' . basename($_FILES['txt_file']['name']);
                if (move_uploaded_file($_FILES['txt_file']['tmp_name'], $targetFile)) {
                    header('Location: ?admin&file_uploaded=1');
                } else {
                    header('Location: ?admin&error=move_failed');
                }
                exit;
                
            case 'save_text_file':
				$targetDir = $_POST['target_dir'] ?? '';
				$fileName = $_POST['file_name'] ?? '';
				$fileContent = $_POST['file_content'] ?? '';
				
				// Validate and sanitize
				if (empty($targetDir) || empty($fileName)) {
					header('Location: ?text_editor&error=missing_params');
					exit;
				}
				
				// Sanitize directory and filename
				$targetDir = str_replace(['..', '/', '\\'], '', $targetDir);
				$fileName = str_replace(['..', '/', '\\'], '', $fileName);
				
				// Ensure filename has .txt extension
				if (!preg_match('/\.txt$/', $fileName)) {
					$fileName .= '.txt';
				}
				
				$dirPath = __DIR__ . '/' . $targetDir;
				if (!file_exists($dirPath) || !is_dir($dirPath)) {
					header('Location: ?text_editor&error=invalid_target_dir');
					exit;
				}
				
				$targetFile = $dirPath . '/' . $fileName;
				
				// Save file with tabs preserved (don't transform tabs to spaces)
				if (file_put_contents($targetFile, $fileContent) !== false) {
					header('Location: ?text_editor&file_saved=1');
				} else {
					header('Location: ?text_editor&error=save_failed');
				}
				exit;

                
            case 'load_file_for_edit':
                $filePath = $_POST['file_path'] ?? '';
                
                // Validate and sanitize
                if (empty($filePath)) {
                    echo json_encode(['error' => 'Missing file path']);
                    exit;
                }
                
                // Prevent directory traversal
                $filePath = str_replace(['..', '\\'], '', $filePath);
                $fullPath = __DIR__ . '/' . $filePath;
                
                if (!file_exists($fullPath) || !is_file($fullPath)) {
                    echo json_encode(['error' => 'File not found']);
                    exit;
                }
                
                $content = file_get_contents($fullPath);
                echo json_encode([
                    'success' => true,
                    'content' => $content,
                    'file_name' => basename($filePath)
                ]);
                exit;
        }
    }

/* ───────────────────────────────────────────────────────────
    4.    Core helper — dispatch
   ──────────────────────────────────────────────────────────*/
    function handle_cli_command(string $cmdLine, string $configFile, bool $createBlob = true, bool $httpMode = false): array {
        $argv = preg_split('/\s+/', trim($cmdLine));
        $cmd  = array_shift($argv) ?: '';

        $rawCmds = readCommandSequence($configFile);
        if (isset($rawCmds['error'])) return ['error' => $rawCmds['error']];

        $commands = [];
        foreach ($rawCmds as $raw) {
            $p = parseCommand($raw);
            if (isset($p['error'])) continue;
            $res = processCode($p);
            $commands[] = ['parsed' => $p, 'result' => $res];
        }

        switch ($cmd) {
            case 'list':
                $rows = [];
                foreach ($commands as $i => $item) {
                    $p   = $item['parsed'];
                    $idx = $i + 1;
                    $in  = "{$p['inputDirectory']}/{$p['inputFileBaseName']}.txt";
                    $out = "{$p['outputDirectory']}/{$p['outputFileBaseName']}.txt";
                    $rows[] = sprintf("%2d. %s -> %s (rows %d-%d, cols %d-%d)",
                        $idx, $in, $out,
                        $p['initialRow'], $p['finalRow'],
                        $p['initialColumn'], $p['finalColumn']
                    );
                }
                return ['list' => implode("\n", $rows)];

            case 'show':
                if (count($argv) < 1 || !ctype_digit($argv[0])) return ['error' => 'Usage: show <index>'];
                $num = (int)$argv[0];
                if ($num < 1 || $num > count($commands)) return ['error' => "Index out of range: $num"];
                $entry = $commands[$num - 1];
                if (isset($entry['result']['error'])) return ['error' => $entry['result']['error']];
                return [
                    'code' => $entry['result']['code'],
                    'lang' => detectLanguage($entry['result']['code'])
                ];

            case 'open':
                if (count($argv) < 1 || !ctype_digit($argv[0])) return ['error' => 'Usage: open <index>'];
                $num = (int)$argv[0];
                if ($num < 1 || $num > count($commands)) return ['error' => "Index out of range: $num"];
                $entry = $commands[$num - 1];
                if (isset($entry['result']['error'])) return ['error' => $entry['result']['error']];
                $blobUrl = create_blob_from_entry($entry, $num, $createBlob, $httpMode);
                return isset($blobUrl['error']) ? $blobUrl : ['url' => $blobUrl];

            case 'help':
                return [
                    'list' => "Available commands:\n" .
                            "  list - Show all available commands\n" .
                            "  show <index> - Display code for the specified index\n" .
                            "  open <index> - Open and preview the code at the specified index"
                ];

            default:
                return ['error' => 'Unknown command'];
        }
    }

/* ───────────────────────────────────────────────────────────
    5.    Utility helpers (read, parse, slice, lang)
   ──────────────────────────────────────────────────────────*/
    function readCommandSequence(string $filePath): array {
        if (!file_exists($filePath)) return ['error' => "Command file not found: $filePath"];
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $out = [];
        foreach ($lines as $ln) if ($ln !== '' && $ln[0] === '<') $out[] = substr($ln, 1);
        return $out;
    }

	function parseCommand(string $cmd): array {
		// Format: indexid.first_column.final_column.first_row.final_row.input_filename.input_directory_name.output_directory_name.output_filename
		$parts = explode('.', $cmd);
		if (count($parts) < 9) return ['error' => "Invalid command: $cmd - expected at least 9 parts"];
		
		$id = $parts[0];
		$initialColumn = (int)$parts[1];
		$finalColumn = (int)$parts[2];
		$initialRow = (int)$parts[3];
		$finalRow = (int)$parts[4];
		$inBase = $parts[5];
		$inDir = $parts[6];
		$outDir = $parts[7];
		
		// Handle potential periods in output filename by joining remaining parts
		$outBase = implode('.', array_slice($parts, 8));
		
		// Remove any trailing (<>) from output base name
		$outBase = preg_replace('/\(<\>\)$/', '', $outBase);
		
		return [
			'commandId' => $id,
			'initialColumn' => $initialColumn,
			'finalColumn' => $finalColumn,
			'initialRow' => $initialRow,
			'finalRow' => $finalRow,
			'inputFileBaseName' => $inBase,
			'inputDirectory' => $inDir,
			'outputDirectory' => $outDir,
			'outputFileBaseName' => $outBase
		];
	}

    function processCode(array $p): array {
        $src = "{$p['inputDirectory']}/{$p['inputFileBaseName']}.txt";
        if (!file_exists($src)) return ['error' => "File not found: $src"];
        $raw = file($src);
        $code = [];
        if (isset($raw[0]) && ctype_digit(trim($raw[0]))) {
            for ($i = 1; $i < count($raw); $i += 2) $code[] = $raw[$i];
        } else {
            $code = $raw;
        }
        $s = max(0, $p['initialRow'] - 1);
        $e = min(count($code) - 1, $p['finalRow'] - 1);
        $out = [];
        for ($i = $s; $i <= $e; $i++) {
            $ln = $code[$i];
            if ($p['initialColumn'] > 0 || $p['finalColumn'] < PHP_INT_MAX) {
                $st  = max(0, $p['initialColumn'] - 1);
                $len = $p['finalColumn'] - $st;
                $ln  = substr($ln, $st, $len);
            }
            $out[] = $ln;
        }
        $txt = implode('', $out);
        if ($p['outputDirectory'] && $p['outputFileBaseName']) {
            $dst = "{$p['outputDirectory']}/{$p['outputFileBaseName']}.txt";
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0777, true);
            file_put_contents($dst, $txt);
        }
        return ['success' => true, 'code' => $txt, 'command' => $p];
    }

    function detectLanguage(string $code): string {
        $h = ltrim($code);
        if (preg_match('/^</', $h)) return 'html';
        if (preg_match('/^(?:function|var|let|const|import|export|class|document\.|console\.|\(function)/i', $h)) return 'javascript';
        return 'plaintext';
    }

    function uid(): string { return 'c_' . uniqid(); }

/* ───────────────────────────────────────────────────────────
    6.    blob builder
   ──────────────────────────────────────────────────────────*/
    function create_blob_from_entry(array $entry, int $num, bool $createBlob, bool $httpMode) {
        $code = $entry['result']['code'];
        $lang = detectLanguage($code);
        $blobId = uniqid('blob_', true) . '.html';

        if ($lang === 'html') {
            $html = preg_match('/<html/i', $code) ? $code : "<!DOCTYPE html><html><head><title>HTML Preview $num</title></head><body>$code</body></html>";
        } elseif ($lang === 'javascript') {
            $title = "JS Execution $num";
            $html  = "<!DOCTYPE html><html><head><title>$title</title></head><body><script>\n$code\n</script></body></html>";
        } else {
            $escaped = htmlspecialchars($code, ENT_QUOTES);
            $title   = "Code Preview $num";
            $html    = "<!DOCTYPE html><html><head><title>$title</title></head><body><pre>$escaped</pre></body></html>";
        }
        
        // Add cleanup script to detect tab closure
        $cleanupScript = <<<EOT
<script>
    // Track this blob for cleanup when tab closes
    const blobId = '$blobId';
    window.addEventListener('beforeunload', function() {
        navigator.sendBeacon('?', 'cleanup_blob=1&blob_id=' + blobId);
    });
</script>
EOT;
        
        // Insert cleanup script before closing body tag
        $html = str_replace('</body>', $cleanupScript . '</body>', $html);

        if (!$createBlob) return $html;

        $tmpDir  = __DIR__ . '/tmp';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
        $tmpFile = $tmpDir . '/' . $blobId;
        if (!file_put_contents($tmpFile, $html)) return ['error' => "Unable to write $tmpFile"];
        return $httpMode ? 'tmp/' . $blobId : "Opened $tmpFile";
    }
    
/* ───────────────────────────────────────────────────────────
    7.    Admin authentication
   ──────────────────────────────────────────────────────────*/
    function is_admin_authenticated(): bool {
        global $ADMIN_SESSION_TIMEOUT;
        
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            return false;
        }
        
        // Check session timeout
        if (time() - $_SESSION['admin_login_time'] > $ADMIN_SESSION_TIMEOUT) {
            // Session expired
            session_destroy();
            return false;
        }
        
        // Refresh login time
        $_SESSION['admin_login_time'] = time();
        return true;
    }
    
/* ───────────────────────────────────────────────────────────
    8.    Directory and file helpers
   ──────────────────────────────────────────────────────────*/
    function get_all_directories(): array {
        $directories = [];
        $baseDir = __DIR__;
        
        // Get all directories in the current directory
        $items = scandir($baseDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $baseDir . '/' . $item;
            if (is_dir($path) && $item !== 'tmp') {
                $directories[] = $item;
            }
        }
        
        return $directories;
    }
    
    function get_directory_files(string $directory): array {
        $files = [];
        $path = __DIR__ . '/' . $directory;
        
        if (!is_dir($path)) return $files;
        
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $filePath = $path . '/' . $item;
            if (is_file($filePath) && pathinfo($item, PATHINFO_EXTENSION) === 'txt') {
                $files[] = [
                    'name' => $item,
                    'path' => $directory . '/' . $item,
                    'size' => filesize($filePath),
                    'modified' => date('Y-m-d H:i:s', filemtime($filePath))
                ];
            }
        }
        
        return $files;
    }

/* ───────────────────────────────────────────────────────────
    9.    HTML output based on request
   ──────────────────────────────────────────────────────────*/
    if (isset($_GET['admin'])) {
        // Show admin page if authenticated
        if (is_admin_authenticated()) {
            show_admin_page($CONFIG_FILE);
        } else {
            // Redirect to login
            header('Location: ?login');
            exit;
        }
    } elseif (isset($_GET['login'])) {
        // Show login page
        show_login_page();
    } elseif (isset($_GET['file_manager']) && is_admin_authenticated()) {
        // Show file manager page
        show_file_manager();
    } elseif (isset($_GET['text_editor']) && is_admin_authenticated()) {
        // Show text editor page
        show_text_editor();
    }

/* ───────────────────────────────────────────────────────────
    10.    Page rendering functions
   ──────────────────────────────────────────────────────────*/
    function show_login_page() {
        $error_message = isset($_GET['error']) ? '<p class="error">Invalid username or password</p>' : '';
        
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <link rel="stylesheet" href="web-cli.css">
    <style>
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }
        .btn {
            padding: 10px 15px;
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
        }
        .error {
            color: red;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Admin Login</h2>
        $error_message
        <form method="post" action="">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" name="admin_login" class="btn">Login</button>
        </form>
        <p><a href="./">Back to Terminal</a></p>
    </div>
</body>
</html>
HTML;
        exit;
    }
    
    function show_admin_page($configFile) {
        // Messages
        $success_message = '';
        $error_message = '';
        
        if (isset($_GET['added'])) $success_message = '<p class="success">Listing added successfully!</p>';
        if (isset($_GET['edited'])) $success_message = '<p class="success">Listing updated successfully!</p>';
        if (isset($_GET['deleted'])) $success_message = '<p class="success">Listing deleted successfully!</p>';
        if (isset($_GET['dir_created'])) $success_message = '<p class="success">Directory created successfully!</p>';
        if (isset($_GET['dir_exists'])) $error_message = '<p class="error">Directory already exists!</p>';
        if (isset($_GET['file_uploaded'])) $success_message = '<p class="success">File uploaded successfully!</p>';
        if (isset($_GET['file_saved'])) $success_message = '<p class="success">File saved successfully!</p>';
        
        if (isset($_GET['error'])) {
            $error_type = $_GET['error'];
            switch ($error_type) {
                case 'invalid_directory':
                    $error_message = '<p class="error">Invalid directory name or type!</p>';
                    break;
                case 'invalid_target_dir':
                    $error_message = '<p class="error">Target directory does not exist!</p>';
                    break;
                case 'upload_failed':
                    $error_message = '<p class="error">File upload failed!</p>';
                    break;
                case 'not_txt_file':
                    $error_message = '<p class="error">Only .txt files are allowed!</p>';
                    break;
                case 'move_failed':
                    $error_message = '<p class="error">Failed to move uploaded file!</p>';
                    break;
                case 'missing_params':
                    $error_message = '<p class="error">Missing required parameters!</p>';
                    break;
                case 'save_failed':
                    $error_message = '<p class="error">Failed to save file!</p>';
                    break;
                default:
                    $error_message = '<p class="error">An error occurred!</p>';
            }
        }
        
        // Read all commands from config file
        $allCommands = file_exists($configFile) ? file($configFile, FILE_IGNORE_NEW_LINES) : [];
        
        // Prepare the listings table
        $listings_table = '<table class="listings-table">
            <thead>
                <tr>
                    <th>Index</th>
                    <th>Command</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($allCommands as $index => $command) {
            if (empty(trim($command)) || $command[0] !== '<') continue;
            
            $escapedCommand = htmlspecialchars(substr($command, 1));
            $listings_table .= "<tr>
                <td>$index</td>
                <td>$escapedCommand</td>
                <td>
                    <button onclick=\"editListing($index, '" . addslashes($escapedCommand) . "')\">Edit</button>
                    <button onclick=\"deleteListing($index)\">Delete</button>
                </td>
            </tr>";
        }
        
        $listings_table .= '</tbody></table>';
        
        // Get all directories for the directory management section
        $directories = get_all_directories();
        $directory_options = '';
        foreach ($directories as $dir) {
            $directory_options .= "<option value=\"$dir\">$dir</option>";
        }
        
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="web-cli.css">
    <style>
        .admin-container {
            max-width: 1000px;
            margin: 20px auto;
            padding: 20px;
        }
        .nav-bar {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .success {
            color: green;
            font-weight: bold;
        }
        .error {
            color: red;
            font-weight: bold;
        }
        .listings-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .listings-table th, .listings-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .listings-table th {
            background-color: #f2f2f2;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 70%;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group textarea {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
            height: 100px;
        }
        .btn {
            padding: 10px 15px;
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
            margin-right: 5px;
        }
        .btn-danger {
            background-color: #f44336;
        }
        .tabs {
            overflow: hidden;
            border: 1px solid #ccc;
            background-color: #f1f1f1;
            margin-bottom: 20px;
        }
        .tabs button {
            background-color: inherit;
            float: left;
            border: none;
            outline: none;
            cursor: pointer;
            padding: 14px 16px;
            transition: 0.3s;
            font-size: 17px;
        }
        .tabs button:hover {
            background-color: #ddd;
        }
        .tabs button.active {
            background-color: #ccc;
        }
        .tab-content {
            display: none;
            padding: 20px;
            border: 1px solid #ccc;
            border-top: none;
        }
        .directory-section {
            margin-bottom: 30px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="nav-bar">
            <h1>Admin Dashboard</h1>
            <div>
                <a href="?file_manager" class="btn">File Manager</a>
                <a href="?text_editor" class="btn">Text Editor</a>
                <a href="./" class="btn">Terminal</a>
                <a href="?logout" class="btn btn-danger">Logout</a>
            </div>
        </div>
        
        $success_message
        $error_message
        
        <div class="tabs">
            <button class="tab-links active" onclick="openTab(event, 'listings')">Listings</button>
            <button class="tab-links" onclick="openTab(event, 'directories')">Directories</button>
        </div>
        
        <div id="listings" class="tab-content" style="display:block">
            <h2>Manage Listings</h2>
            <button onclick="showAddModal()" class="btn">Add New Listing</button>
            
            <h3>Current Listings</h3>
            $listings_table
        </div>
        
        <div id="directories" class="tab-content">
            <h2>Directory Management</h2>
            
            <div class="directory-section">
                <h3>Create New Directory</h3>
                <form method="post" action="">
                    <div class="form-group">
                        <label for="dir_type">Directory Type:</label>
                        <select id="dir_type" name="dir_type" required>
                            <option value="input">Input Directory</option>
                            <option value="output">Output Directory</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="dir_name">Directory Name:</label>
                        <input type="text" id="dir_name" name="dir_name" required>
                    </div>
                    <input type="hidden" name="action" value="create_directory">
                    <button type="submit" class="btn">Create Directory</button>
                </form>
            </div>
            
            <div class="directory-section">
                <h3>Upload File</h3>
                <form method="post" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="target_dir">Target Directory:</label>
                        <select id="target_dir" name="target_dir" required>
                            $directory_options
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="txt_file">Select .txt File:</label>
                        <input type="file" id="txt_file" name="txt_file" accept=".txt" required>
                    </div>
                    <input type="hidden" name="action" value="upload_file">
                    <button type="submit" class="btn">Upload File</button>
                </form>
            </div>
        </div>
        
        <!-- Add Listing Modal -->
        <div id="addModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeAddModal()">&times;</span>
                <h2>Add New Listing</h2>
                <form method="post" action="">
                    <div class="form-group">
                        <label for="command_text">Command Text:</label>
                        <textarea id="command_text" name="command_text" required placeholder="Format: id.col1,col2.row1,row2.inFile.inDir.outDir.outFile"></textarea>
                    </div>
                    <input type="hidden" name="action" value="add_listing">
                    <button type="submit" class="btn">Add Listing</button>
                </form>
            </div>
        </div>
        
        <!-- Edit Listing Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeEditModal()">&times;</span>
                <h2>Edit Listing</h2>
                <form method="post" action="">
                    <div class="form-group">
                        <label for="edit_command_text">Command Text:</label>
                        <textarea id="edit_command_text" name="command_text" required></textarea>
                    </div>
                    <input type="hidden" name="action" value="edit_listing">
                    <input type="hidden" id="edit_index" name="index" value="">
                    <button type="submit" class="btn">Update Listing</button>
                </form>
            </div>
        </div>
        
        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeDeleteModal()">&times;</span>
                <h2>Confirm Deletion</h2>
                <p>Are you sure you want to delete this listing?</p>
                <form method="post" action="">
                    <input type="hidden" name="action" value="delete_listing">
                    <input type="hidden" id="delete_index" name="index" value="">
                    <button type="button" onclick="closeDeleteModal()" class="btn">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
        
        <script>
            // Modal handling
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');
            
            function showAddModal() {
                addModal.style.display = 'block';
            }
            
            function closeAddModal() {
                addModal.style.display = 'none';
            }
            
            function editListing(index, command) {
                document.getElementById('edit_index').value = index;
                document.getElementById('edit_command_text').value = command;
                editModal.style.display = 'block';
            }
            
            function closeEditModal() {
                editModal.style.display = 'none';
            }
            
            function deleteListing(index) {
                document.getElementById('delete_index').value = index;
                deleteModal.style.display = 'block';
            }
            
            function closeDeleteModal() {
                deleteModal.style.display = 'none';
            }
            
            // Tab handling
            function openTab(evt, tabName) {
                var i, tabcontent, tablinks;
                tabcontent = document.getElementsByClassName("tab-content");
                for (i = 0; i < tabcontent.length; i++) {
                    tabcontent[i].style.display = "none";
                }
                tablinks = document.getElementsByClassName("tab-links");
                for (i = 0; i < tablinks.length; i++) {
                    tablinks[i].className = tablinks[i].className.replace(" active", "");
                }
                document.getElementById(tabName).style.display = "block";
                evt.currentTarget.className += " active";
            }
            
            // Close modals when clicking outside
            window.onclick = function(event) {
                if (event.target == addModal) closeAddModal();
                if (event.target == editModal) closeEditModal();
                if (event.target == deleteModal) closeDeleteModal();
            }
        </script>
    </div>
</body>
</html>
HTML;
        exit;
    }
    
function show_file_manager() {
    $directories = get_all_directories();
    
    $directory_list = '';
    foreach ($directories as $dir) {
        $directory_list .= "<div class='directory-item' onclick='loadDirectoryFiles(\"$dir\")'>
            <i class='folder-icon'>📁</i> $dir
        </div>";
    }
    
echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>File Manager</title>
    <link rel="stylesheet" href="web-cli.css">
    <style>
        .file-manager-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }
        .nav-bar {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .btn {
            padding: 10px 15px;
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
            margin-right: 5px;
            text-decoration: none;
        }
        .btn-danger {
            background-color: #f44336;
        }
        .file-explorer {
            display: flex;
            border: 1px solid #ddd;
            margin-top: 20px;
        }
        .directory-panel {
            width: 250px;
            border-right: 1px solid #ddd;
            padding: 10px;
            height: 500px;
            overflow-y: auto;
        }
        .file-panel {
            flex: 1;
            padding: 10px;
            height: 500px;
            overflow-y: auto;
        }
        .directory-item {
            padding: 8px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        .directory-item:hover {
            background-color: #f5f5f5;
        }
        .file-item {
            padding: 8px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
        }
        .file-item:hover {
            background-color: #f5f5f5;
        }
        .folder-icon {
            margin-right: 5px;
            color: #ffc107;
        }
        .file-icon {
            margin-right: 5px;
            color: #2196F3;
        }
        .file-actions {
            display: flex;
            gap: 10px;
        }
        .success {
            color: green;
            font-weight: bold;
        }
        .error {
            color: red;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="file-manager-container">
        <div class="nav-bar">
            <h1>File Manager</h1>
            <div>
                <a href="?admin" class="btn">Dashboard</a>
                <a href="?text_editor" class="btn">Text Editor</a>
                <a href="./" class="btn">Terminal</a>
                <a href="?logout" class="btn btn-danger">Logout</a>
            </div>
        </div>
        
        <div id="messages"></div>
        
        <div class="file-explorer">
            <div class="directory-panel">
                <h3>Directories</h3>
                $directory_list
            </div>
            <div class="file-panel">
                <h3 id="current-directory">Select a directory</h3>
                <div id="file-list"></div>
            </div>
        </div>
        
        <script>
            // Load directory files
            function loadDirectoryFiles(directory) {
                document.getElementById('current-directory').textContent = directory;
                document.getElementById('file-list').innerHTML = 'Loading...';
                
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=load_directory_files&directory=' + encodeURIComponent(directory)
                })
                .then(response => response.json())
                .then(files => {
                    const fileList = document.getElementById('file-list');
                    fileList.innerHTML = '';
                    
                    if (!Array.isArray(files) || !files.length) {
                        fileList.innerHTML = '<p>No files found in this directory</p>';
                        return;
                    }
                    
                    files.forEach(file => {
                        const fileItem = document.createElement('div');
                        fileItem.className = 'file-item';
                        
                        const fileInfoElement = document.createElement('span');
                        fileInfoElement.innerHTML = `<i class='file-icon'>📄</i> \${file.name} <small>(\${formatFileSize(file.size)}, modified: \${file.modified})</small>`;
                        
                        const fileActions = document.createElement('div');
                        fileActions.className = 'file-actions';
                        
                        const viewBtn = document.createElement('button');
                        viewBtn.textContent = 'Edit';
                        viewBtn.className = 'btn';
                        viewBtn.onclick = () => window.location.href = `?text_editor&file=\${file.path}`;
                        
                        const deleteBtn = document.createElement('button');
                        deleteBtn.textContent = 'Delete';
                        deleteBtn.className = 'btn btn-danger';
                        deleteBtn.onclick = () => deleteFile(file.path);
                        
                        fileActions.appendChild(viewBtn);
                        fileActions.appendChild(deleteBtn);
                        
                        fileItem.appendChild(fileInfoElement);
                        fileItem.appendChild(fileActions);
                        fileList.appendChild(fileItem);
                    });
                })
                .catch(error => {
                    console.error('Error loading directory files:', error);
                    document.getElementById('file-list').innerHTML = '<p class="error">Error loading files</p>';
                    showMessage('Error loading files: ' + error.message, 'error');
                });
            }
            
            // Delete file
            function deleteFile(filePath) {
                if (!confirm('Are you sure you want to delete this file?')) return;
                
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=delete_file&path=' + encodeURIComponent(filePath)
                })
                .then(response => response.text())
                .then(data => {
                    const isError = data.startsWith('ERROR:');
                    showMessage(data, isError ? 'error' : 'success');
                    
                    if (!isError) {
                        // Reload the current directory
                        loadDirectoryFiles(document.getElementById('current-directory').textContent);
                    }
                })
                .catch(error => {
                    console.error('Error deleting file:', error);
                    showMessage('Error deleting file: ' + error.message, 'error');
                });
            }
            
            // Helper functions
            function formatFileSize(bytes) {
                bytes = parseInt(bytes, 10);
                if (bytes < 1024) return bytes + ' bytes';
                if (bytes < 1048576) return (bytes / 1024).toFixed(2) + ' KB';
                return (bytes / 1048576).toFixed(2) + ' MB';
            }
            
            function showMessage(message, type) {
                const messagesDiv = document.getElementById('messages');
                messagesDiv.innerHTML = `<p class="\${type}">\${message}</p>`;
                setTimeout(() => {
                    messagesDiv.innerHTML = '';
                }, 5000);
            }
        </script>
    </div>
</body>
</html>
HTML;
    exit;
}

	// Add this function to handle tab key in textareas
	function add_tab_handling_script() {
		return <<<JS
	<script>
		// Enable tab character in textareas
		function enableTabInTextarea(textarea) {
			textarea.addEventListener('keydown', function(e) {
				if (e.key === 'Tab') {
					e.preventDefault();
					
					// Get cursor position
					const start = this.selectionStart;
					const end = this.selectionEnd;
					
					// Handle selection - indent or unindent multiple lines
					if (start !== end && this.value.substring(start, end).includes('\\n')) {
						const selectedText = this.value.substring(start, end);
						const lines = selectedText.split('\\n');
						
						// Check if we're indenting or unindenting (shift+tab)
						if (e.shiftKey) {
							// Unindent - remove one tab from the beginning of each line if it exists
							const modifiedLines = lines.map(line => 
								line.startsWith('\\t') ? line.substring(1) : line
							);
							const modifiedText = modifiedLines.join('\\n');
							
							// Insert the modified text
							this.value = this.value.substring(0, start) + modifiedText + this.value.substring(end);
							this.selectionStart = start;
							this.selectionEnd = start + modifiedText.length;
						} else {
							// Indent - add a tab to the beginning of each line
							const modifiedLines = lines.map(line => '\\t' + line);
							const modifiedText = modifiedLines.join('\\n');
							
							// Insert the modified text
							this.value = this.value.substring(0, start) + modifiedText + this.value.substring(end);
							this.selectionStart = start;
							this.selectionEnd = start + modifiedText.length;
						}
					} else {
						// No selection or selection within a single line - insert a tab character
						this.value = this.value.substring(0, start) + '\\t' + this.value.substring(end);
						this.selectionStart = this.selectionEnd = start + 1;
					}
				}
			});
		}
		
		// Initialize tab handling for all code editor textareas
		document.addEventListener('DOMContentLoaded', function() {
			const editors = document.querySelectorAll('.editor-textarea');
			editors.forEach(enableTabInTextarea);
		});
		
		// Save tabs function - ensure tabs are preserved when saving content
		function getEditorContentWithTabs() {
			const editor = document.getElementById('file_content');
			return editor.value; // Return raw value with tabs preserved
		}
		
		// Override form submission to ensure tabs are preserved
		document.addEventListener('DOMContentLoaded', function() {
			const editorForm = document.querySelector('.editor-form form');
			if (editorForm) {
				editorForm.addEventListener('submit', function(e) {
					// If we need special processing before submission, we can do it here
					// For now, the default behavior will preserve tabs correctly
				});
			}
		});
	</script>
	JS;
	}
    
	// Modify the show_text_editor function to include the tab handling script
	function show_text_editor() {
		global $EDITOR_TAB_SIZE;
		
		$directories = get_all_directories();
		$directory_options = '';
		foreach ($directories as $dir) {
			$directory_options .= "<option value=\"$dir\">$dir</option>";
		}
		
		// Check if we're loading a file for editing
		$file_content = '';
		$file_name = '';
		$selected_dir = '';
		
		if (isset($_GET['file'])) {
			$filePath = $_GET['file'];
			$fullPath = __DIR__ . '/' . $filePath;
			
			if (file_exists($fullPath) && is_file($fullPath)) {
				$file_content = htmlspecialchars(file_get_contents($fullPath), ENT_QUOTES, 'UTF-8', true);
				$file_name = basename($filePath);
				$selected_dir = dirname($filePath);
				
				// Update the directory select options to select the current directory
				$directory_options = '';
				foreach ($directories as $dir) {
					$selected = ($dir === $selected_dir) ? 'selected' : '';
					$directory_options .= "<option value=\"$dir\" $selected>$dir</option>";
				}
			}
		}
		
		// Handle success/error messages
		$success_message = '';
		$error_message = '';
		
		if (isset($_GET['file_saved'])) {
			$success_message = '<p class="success">File saved successfully!</p>';
		} elseif (isset($_GET['tab_size_saved'])) {
			$success_message = '<p class="success">Tab size updated successfully!</p>';
		} elseif (isset($_GET['error'])) {
			$error_type = $_GET['error'];
			switch ($error_type) {
				case 'missing_params':
					$error_message = '<p class="error">Missing required parameters!</p>';
					break;
				case 'invalid_target_dir':
					$error_message = '<p class="error">Target directory does not exist!</p>';
					break;
				case 'save_failed':
					$error_message = '<p class="error">Failed to save file!</p>';
					break;
				default:
					$error_message = '<p class="error">An error occurred!</p>';
			}
		}
		
		// Get tab handling script
		$tab_handling_script = add_tab_handling_script();
		
		echo <<<HTML
	<!DOCTYPE html>
	<html lang="en">
	<head>
		<meta charset="UTF-8">
		<title>Text Editor</title>
		<link rel="stylesheet" href="web-cli.css">
		<style>
			.editor-container {
				max-width: 1000px;
				margin: 20px auto;
				padding: 20px;
			}
			.nav-bar {
				display: flex;
				justify-content: space-between;
				margin-bottom: 20px;
			}
			.btn {
				padding: 10px 15px;
				background-color: #4CAF50;
				color: white;
				border: none;
				cursor: pointer;
				margin-right: 5px;
				text-decoration: none;
			}
			.btn-danger {
				background-color: #f44336;
			}
			.editor-form {
				margin-top: 20px;
			}
			.form-group {
				margin-bottom: 15px;
			}
			.form-group label {
				display: block;
				margin-bottom: 5px;
			}
			.form-group select, .form-group input {
				width: 100%;
				padding: 8px;
				box-sizing: border-box;
			}
			.editor-textarea {
				width: 100%;
				height: 400px;
				padding: 10px;
				box-sizing: border-box;
				font-family: monospace;
				font-size: 14px;
				line-height: 1.5;
				resize: vertical;
				tab-size: $EDITOR_TAB_SIZE;
				-moz-tab-size: $EDITOR_TAB_SIZE;
				-o-tab-size: $EDITOR_TAB_SIZE;
				white-space: pre;
				overflow-wrap: normal;
				overflow-x: auto;
			}
			.success {
				color: green;
				font-weight: bold;
			}
			.error {
				color: red;
				font-weight: bold;
			}
			.settings-panel {
				margin-top: 20px;
				padding: 15px;
				border: 1px solid #ddd;
				border-radius: 5px;
				margin-bottom: 20px;
			}
			.settings-form {
				display: flex;
				align-items: center;
			}
			.settings-form label {
				margin-right: 10px;
			}
			.settings-form input {
				width: 60px;
				margin-right: 10px;
			}
			.tab-info {
				margin-top: 10px;
				font-size: 12px;
				color: #666;
			}
		</style>
		$tab_handling_script
	</head>
	<body>
		<div class="editor-container">
			<div class="nav-bar">
				<h1>Text Editor</h1>
				<div>
					<a href="?admin" class="btn">Dashboard</a>
					<a href="?file_manager" class="btn">File Manager</a>
					<a href="./" class="btn">Terminal</a>
					<a href="?logout" class="btn btn-danger">Logout</a>
				</div>
			</div>
			
			$success_message
			$error_message
			
			<div class="settings-panel">
				<h3>Editor Settings</h3>
				<form method="post" action="" class="settings-form">
					<label for="tab_size">Tab Size:</label>
					<input type="number" id="tab_size" name="tab_size" value="$EDITOR_TAB_SIZE" min="1" max="8">
					<input type="hidden" name="save_tab_size" value="1">
					<button type="submit" class="btn">Save Tab Size</button>
				</form>
				<div class="tab-info">
					<p>Use Tab key to insert tabs. Use Shift+Tab to unindent. Current tab size: $EDITOR_TAB_SIZE spaces.</p>
				</div>
			</div>
			
			<div class="editor-form">
				<form method="post" action="">
					<div class="form-group">
						<label for="target_dir">Directory:</label>
						<select id="target_dir" name="target_dir" required>
							$directory_options
						</select>
					</div>
					<div class="form-group">
						<label for="file_name">File Name:</label>
						<input type="text" id="file_name" name="file_name" value="$file_name" required>
					</div>
					<div class="form-group">
						<label for="file_content">File Content:</label>
						<textarea id="file_content" name="file_content" class="editor-textarea" placeholder="Enter file content here...">$file_content</textarea>
					</div>
					<input type="hidden" name="action" value="save_text_file">
					<button type="submit" class="btn">Save File</button>
				</form>
			</div>
        <script>
			// Load directory files
			function loadDirectoryFiles(directory) {
				document.getElementById('current-directory').textContent = directory;
				document.getElementById('file-list').innerHTML = 'Loading...';
				
				fetch('', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: 'action=load_directory_files&directory=' + encodeURIComponent(directory)
				})
				.then(response => response.json())
				.then(files => {
					const fileList = document.getElementById('file-list');
					fileList.innerHTML = '';
					
					if (!Array.isArray(files) || !files.length) {
						fileList.innerHTML = '<p>No files found in this directory</p>';
						return;
					}
					
					files.forEach(({name, size, modified, path}) => {
						const fileItem = document.createElement('div');
						fileItem.className = 'file-item';
						
						const fileInfoElement = document.createElement('span');
						fileInfoElement.innerHTML = `<i class='file-icon'>📄</i> \${name} <small>(\${formatFileSize(size)}, modified: \${modified})</small>`;
						
						const fileActions = document.createElement('div');
						fileActions.className = 'file-actions';
						
						const deleteBtn = document.createElement('button');
						deleteBtn.textContent = 'Delete';
						deleteBtn.className = 'btn btn-danger';
						deleteBtn.onclick = () => deleteFile(path);
						
						fileActions.appendChild(deleteBtn);
						
						fileItem.appendChild(fileInfoElement);
						fileItem.appendChild(fileActions);
						fileList.appendChild(fileItem);
					});
				})
				.catch(error => {
					console.error('Error loading directory files:', error);
					document.getElementById('file-list').innerHTML = '<p class="error">Error loading files</p>';
				});
			}

			// Delete file
			function deleteFile(filePath) {
				if (!confirm('Are you sure you want to delete this file?')) return;
				
				fetch('', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: 'action=delete_file&path=' + encodeURIComponent(filePath)
				})
				.then(response => response.text())
				.then(data => {
					showMessage(data, data.startsWith('ERROR:') ? 'error' : 'success');
					if (!data.startsWith('ERROR:')) {
						// Reload the current directory
						loadDirectoryFiles(document.getElementById('current-directory').textContent);
					}
				})
				.catch(error => {
					console.error('Error deleting file:', error);
					showMessage('Error deleting file: ' + error.message, 'error');
				});
			}
        </script>
    </div>
</body>
</html>
HTML;
        exit;
    }
    
/* ───────────────────────────────────────────────────────────
    11.    AJAX endpoints for directory & file operations
   ──────────────────────────────────────────────────────────*/
    if (isset($_POST['action']) && $_POST['action'] === 'load_directory_files' && is_admin_authenticated()) {
        $directory = $_POST['directory'] ?? '';
        
        // Sanitize directory name
        $directory = str_replace(['..', '/', '\\'], '', $directory);
        
        $files = get_directory_files($directory);
        header('Content-Type: application/json');
        echo json_encode($files);
        exit;
    }

	if (isset($_POST['action']) && $_POST['action'] === 'delete_file' && is_admin_authenticated()) {
		$relPath = $_POST['path'] ?? '';
		// basic sanitisation -- keeps everything inside the script folder
		$relPath = str_replace(['..', '\\', "\0"], '', $relPath);
		$absPath = __DIR__ . '/' . $relPath;

		if (!is_file($absPath) || !file_exists($absPath)) {
			echo 'ERROR: File not found';
			exit;
		}
		if (unlink($absPath)) {
			echo 'File deleted';
		} else {
			echo 'ERROR: Unable to delete file';
		}
		exit;
	}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Terminal — CLI‑only</title>
    <link rel="stylesheet" href="web-cli.css">
    <style>
        body{font-family:monospace;margin:1rem}
        #cliOut{white-space:pre;border:1px solid #888;padding:6px;max-height:260px;overflow:auto}
        #preview{width:100%;height:60vh;border:1px solid #888;margin-top:1rem;display:none}
        .admin-link {
            float: right;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <h1>Terminal</h1>
    <a href="?admin" class="admin-link">Admin</a>
    <input id="cli" placeholder="list | show N | open N" size="40" autofocus>
    <br>
    <button id="cliRun">Run</button>
    <pre id="cliOut"></pre>
    <iframe id="preview"></iframe>

    <script>
        const $=s=>document.querySelector(s);
        const preview=$('#preview');
        function runCli(){
            const cmd=$('#cli').value.trim();
            if(!cmd) return;
            $('#cliOut').textContent='…';
            fetch('?cli='+encodeURIComponent(cmd))
                .then(r=>r.json())
                .then(d=>{
                    if(d.error){$('#cliOut').textContent='Error: '+d.error;preview.style.display='none';return;}
                    if(d.list){$('#cliOut').textContent=d.list;preview.style.display='none';return;}
                    if(d.code){$('#cliOut').textContent=d.code;preview.style.display='none';return;}
                    if(d.url){
                        const blobId = d.url.split('/').pop(); // Extract blob ID from URL
                        window.open(d.url,'_blank');
                        preview.src=d.url;
                        preview.style.display='block';
                        $('#cliOut').textContent='Rendered & opened: '+d.url;
                        
                        // Setup cleanup when the preview iframe is unloaded
                        preview.onload = function() {
                            preview.contentWindow.addEventListener('beforeunload', function() {
                                fetch('', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: 'cleanup_blob=1&blob_id=' + encodeURIComponent(blobId)
                                });
                            });
                        };
                        return;
                    }
                    $('#cliOut').textContent=JSON.stringify(d);
                })
                .catch(e=>$('#cliOut').textContent='Fetch error: '+e);
        }
        $('#cliRun').onclick=runCli;
        $('#cli').addEventListener('keydown',e=>e.key==='Enter'&&runCli());
    </script>
</body>
</html>