<?php
session_start();
ini_set('display_errors',1); error_reporting(E_ALL);

$emailjs_service_id = "YOUR_EMAILJS_SERVICE_ID";
$emailjs_template_id = "YOUR_EMAILJS_TEMPLATE_ID";
$emailjs_public_key = "YOUR_EMAILJS_PUBLIC_KEY";

$conn = new mysqli("localhost","radius","Naren@123","radius");
if($conn->connect_error) die("DB connection failed: ".$conn->connect_error);

if(isset($_GET['dst']) && !isset($_SESSION['dst'])){
    $_SESSION['dst'] = $_GET['dst'];
}
if(isset($_GET['mikrotik_ip']) && !isset($_SESSION['mikrotik_ip'])){
    $_SESSION['mikrotik_ip'] = $_GET['mikrotik_ip'];
}
if(isset($_GET['ip']))  $_SESSION['client_ip']  = $_GET['ip'];
if(isset($_GET['mac'])) $_SESSION['client_mac'] = $_GET['mac'];

define('RADIUS_SECRET', 'testing123');

function radius_is_reachable(string $host = '127.0.0.1', int $port = 1812): bool {
    // 1. Process alive?
    $running = shell_exec("pgrep -x freeradius 2>/dev/null || pgrep -x radiusd 2>/dev/null");
    if (empty(trim((string)$running))) return false;
    // 2. Real auth test via radtest (proves FreeRADIUS is accepting requests)
    $secret = escapeshellarg(RADIUS_SECRET);
    $out = shell_exec("radtest ping_check ping_check 127.0.0.1 $port $secret 2>&1");
    if ($out && (
        stripos($out, 'Access-Accept') !== false ||
        stripos($out, 'Access-Reject') !== false
    )) {
        return true;
    }
    // 3. Fallback: process was found; accept if radtest not installed
    if ($out && stripos($out, 'not found') !== false) {
        return true; // radtest missing – trust process check
    }
    return false;
}
// ────────────────────────────────────────────────────────────────────────────


// ── OTP expiry: 5 minutes ────────────────────────────────────────────────────
define('OTP_TTL', 300); // seconds (5 minutes)

if(isset($_POST['otp_input'])){
    // Rate-limit: max 5 wrong attempts per session
    if (!isset($_SESSION['otp_attempts'])) $_SESSION['otp_attempts'] = 0;
    if ($_SESSION['otp_attempts'] >= 5) {
        unset($_SESSION['otp'], $_SESSION['email'], $_SESSION['otp_attempts'], $_SESSION['otp_time']);
        http_response_code(429);
        die('<p style="text-align:center;font-family:Arial">Too many failed attempts. <a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">Start over</a>.</p>');
    }

    $user_otp   = trim($_POST['otp_input']);
    $stored_otp = isset($_SESSION['otp'])      ? $_SESSION['otp']      : '';
    $email      = isset($_SESSION['email'])    ? $_SESSION['email']    : '';
    $otp_time   = isset($_SESSION['otp_time']) ? $_SESSION['otp_time'] : 0;

    // Check OTP expiry
    if (time() - $otp_time > OTP_TTL) {
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['otp_attempts']);
        $stored_otp = ''; // force mismatch
        error_log("OTP expired for $email");
    }

    error_log("OTP Verification - User: $user_otp, Email: $email");
    error_log("Session mikrotik_ip: " . (isset($_SESSION['mikrotik_ip']) ? $_SESSION['mikrotik_ip'] : 'NOT SET'));
    error_log("Session dst: " . (isset($_SESSION['dst']) ? $_SESSION['dst'] : 'NOT SET'));

    if($user_otp === $stored_otp && !empty($email)){
        $_SESSION['otp_attempts'] = 0; // reset on success
        // Use captured MikroTik IP or fallback to default
        $hotspot_ip = isset($_SESSION['mikrotik_ip']) ? $_SESSION['mikrotik_ip'] : "172.16.60.17";
        $dst = isset($_SESSION['dst']) ? $_SESSION['dst'] : '';

        error_log("OTP verified! Posting login to MikroTik at: $hotspot_ip, user: $email, dst: $dst");

        $check = $conn->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
        $check->bind_param("s", $email);
        $check->execute();
        $result = $check->get_result();
        $db_row = $result->fetch_assoc();
        error_log("Database check - stored password for $email: " . ($db_row ? $db_row['value'] : 'NOT FOUND'));
        $check->close();

        if (!radius_is_reachable()) {
            error_log("RADIUS server is NOT reachable – aborting MikroTik login for $email");
            // Gather quick diagnostic info to display
            $diag_proc   = trim((string)shell_exec("pgrep -x freeradius 2>/dev/null || pgrep -x radiusd 2>/dev/null || echo 'NOT RUNNING'"));
            $diag_listen = trim((string)shell_exec("ss -ulnp 2>/dev/null | grep -E '1812|1813' || echo 'Nothing on 1812'"));
            $secret      = escapeshellarg(RADIUS_SECRET);
            $diag_test   = trim((string)shell_exec("radtest ping_check ping_check 127.0.0.1 1812 $secret 2>&1 | head -5 || echo 'radtest not found'"));
            ?>
<!DOCTYPE html>
<html>
<head>
    <title>RADIUS Server Error</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center;
               align-items: center; min-height: 100vh; margin: 0;
               background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .container { background: white; padding: 40px; border-radius: 10px;
                     box-shadow: 0 10px 40px rgba(0,0,0,0.2); text-align: center; max-width: 460px; }
        .error { color: #721c24; background: #f8d7da; padding: 15px; border-radius: 5px;
                 text-align: left; margin-bottom: 20px; }
        .error code { background: #fff; padding: 4px 8px; display: block; margin: 6px 0;
                      border-radius: 3px; font-size: 12px; color: #333; }
        a { color: #667eea; text-decoration: none; font-weight: 600; }
        button { width: 100%; padding: 12px; background: linear-gradient(135deg,#667eea,#764ba2);
                 color: white; border: none; border-radius: 5px; font-size: 15px;
                 cursor: pointer; font-weight: 600; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h3 style="color:#c0392b">&#9888; RADIUS Server Unreachable</h3>
        <div class="error">
            <strong>Live diagnostics from this server:</strong><br><br>
            <b>Process:</b> <code><?php echo htmlspecialchars($diag_proc); ?></code>
            <b>Listening:</b> <code><?php echo htmlspecialchars($diag_listen); ?></code>
            <b>radtest:</b> <code><?php echo htmlspecialchars($diag_test); ?></code>
            <br>
            <strong>Common fixes:</strong><br><br>
            1. Start FreeRADIUS:<br>
            <code>sudo systemctl start freeradius</code>
            <code>sudo systemctl enable freeradius</code>
            <br>
            2. Make FreeRADIUS listen on all interfaces (not just 127.0.0.1).<br>
            Edit <code>/etc/freeradius/3.0/sites-enabled/default</code> &ndash; find the <code>listen</code> block and set:<br>
            <code>ipaddr = *</code>
            <br>
            3. Add MikroTik as a NAS client in <code>/etc/freeradius/3.0/clients.conf</code>:<br>
            <code>client mikrotik {
  ipaddr  = 192.168.100.1
  secret  = testing123
  shortname = mikrotik
}</code>
            <br>
            4. Open firewall from MikroTik:<br>
            <code>sudo ufw allow from 192.168.100.1 to any port 1812 proto udp</code>
            <code>sudo ufw reload</code>
            <br>
            5. Check MikroTik RADIUS config uses same secret:<br>
            <code>/radius print</code>
            <code>/radius set [find] secret=testing123</code>
            <br>
            6. Full diagnostics: open<br>
            <code>http://172.16.20.40/emailotp.php?diag=1</code>
        </div>
        <p>Your OTP is still valid. Once RADIUS is running, click below to retry.</p>
        <form method="post">
            <input type="hidden" name="otp_input" value="<?php echo htmlspecialchars($user_otp); ?>" />
            <button type="submit">Retry Authentication</button>
        </form>
    </div>
</body>
</html>
            <?php
            exit();
        }
           unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['otp_attempts']);

        session_regenerate_id(true);

        $login_url = "http://$hotspot_ip/login";
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Connecting to WiFi...</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                }
                .container {
                    background: white;
                    padding: 40px;
                    border-radius: 10px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    text-align: center;
                    max-width: 400px;
                }
                .spinner {
                    border: 4px solid #f3f3f3;
                    border-top: 4px solid #667eea;
                    border-radius: 50%;
                    width: 40px;
                    height: 40px;
                    animation: spin 1s linear infinite;
                    margin: 20px auto;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h3>Connecting to WiFi...</h3>
                <div class="spinner"></div>
                <p>Please wait while we authenticate you.</p>
            </div>
            <form id="login-form" method="post" action="<?php echo htmlspecialchars($login_url); ?>">
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($email); ?>" />
                <input type="hidden" name="password" value="<?php echo htmlspecialchars($user_otp); ?>" />
                <?php if (!empty($dst)): ?>
                <input type="hidden" name="dst" value="<?php echo htmlspecialchars($dst); ?>" />
                <?php endif; ?>
            </form>
            <script>
                setTimeout(function() {
                    document.getElementById('login-form').submit();
                }, 500);
            </script>
        </body>
        </html>
        <?php
        exit();
    } else {
        $_SESSION['otp_attempts']++;
        $attempts_left = 5 - $_SESSION['otp_attempts'];
        error_log("OTP MISMATCH - User entered: '$user_otp' (type: " . gettype($user_otp) . "), Expected: '$stored_otp' (type: " . gettype($stored_otp) . "), Attempts left: $attempts_left");
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>OTP Verification Failed</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                }
                .container {
                    background: white;
                    padding: 40px;
                    border-radius: 10px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    text-align: center;
                }
                .error { color: #721c24; background: #f8d7da; padding: 15px; border-radius: 5px; }
                a { color: #667eea; text-decoration: none; font-weight: 600; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="error">
                    <strong>Verification Failed</strong><br><br>
                    Invalid OTP or session expired.<br>
                    <?php if ($attempts_left > 0): ?>
                    Attempts remaining: <?php echo $attempts_left; ?><br><br>
                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">Try again</a>
                    <?php else: ?>
                    Too many failed attempts. Please <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">start over</a>.
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
}

if(isset($_POST['email'])){
    $email = $_POST['email'];
    $otp = (string)rand(1000,9999);

    $_SESSION['otp']          = $otp;
    $_SESSION['otp_time']     = time();
    $_SESSION['otp_attempts'] = 0;
    $_SESSION['email']        = $email;

    $del_stmt = $conn->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
    $del_stmt->bind_param("s", $email);
    $del_stmt->execute();
    $del_stmt->close();

    $stmt = $conn->prepare(
        "INSERT INTO radcheck (username, attribute, op, value)
         VALUES (?, 'Cleartext-Password', ':=', ?)"
    );
    $stmt->bind_param("ss",$email,$otp);

    if(!$stmt->execute()){
        error_log("Database insert failed: " . $stmt->error);
        die("Database error. Please contact administrator.");
    }
    $stmt->close();

    // (session already populated above)
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Sending OTP...</title>
        <script type="text/javascript" src="/emailjs.min.js"></script>
        <style>
            body {
                font-family: Arial, sans-serif;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .container {
                background: white;
                padding: 40px;
                border-radius: 10px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                text-align: center;
                max-width: 400px;
            }
            h3 { color: #333; margin-bottom: 20px; }
            .spinner {
                border: 4px solid #f3f3f3;
                border-top: 4px solid #667eea;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
                margin: 20px auto;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .success { color: #155724; background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .error { color: #721c24; background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0; text-align: left; }
            .error code { background: #fff; padding: 5px 10px; display: block; margin: 10px 0; border-radius: 3px; font-size: 12px; color: #333; }
            input[type="text"] {
                width: 100%;
                padding: 12px;
                border: 2px solid #e0e0e0;
                border-radius: 5px;
                font-size: 16px;
                margin: 10px 0;
                box-sizing: border-box;
            }
            button {
                width: 100%;
                padding: 14px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                cursor: pointer;
                font-weight: 600;
            }
            button:hover { opacity: 0.9; }
        </style>
    </head>
    <body>
        <div class="container">
            <h3>Sending OTP...</h3>
            <div class="spinner"></div>
            <div id="status"></div>
            <div id="otp-form" style="display:none;">
                <form method="post" action="/emailotp.php">
                    <input type="text" name="otp_input" placeholder="Enter 4-digit OTP" maxlength="4" required />
                    <button type="submit">Verify OTP</button>
                </form>
            </div>
        </div>

        <script>
            if (typeof emailjs === 'undefined') {
                document.querySelector('.spinner').style.display = 'none';
                document.getElementById('status').innerHTML = '<div class="error"><strong>EmailJS library failed to load!</strong><br><br>This means cdn.jsdelivr.net is blocked by your firewall.<br><br><strong>Solution:</strong><br>Add to MikroTik Walled Garden:<br><code>/ip hotspot walled-garden add dst-host=cdn.jsdelivr.net</code><br><code>/ip hotspot walled-garden add dst-host=api.emailjs.com</code><br><br><a href="?">Try again after adding</a></div>';
            } else {
                emailjs.init("<?php echo $emailjs_public_key; ?>");

                var timeout = setTimeout(function() {
                    document.querySelector('.spinner').style.display = 'none';
                    document.getElementById('status').innerHTML = '<div class="error">Request timeout. EmailJS API blocked by firewall.<br><br>Add to walled garden:<br><code>/ip hotspot walled-garden add dst-host=api.emailjs.com</code><br><br><a href="?">Try again</a></div>';
                }, 15000);

                console.log("Starting EmailJS send...");
                // Note: OTP and keys are NOT logged to avoid leaking them via DevTools

                emailjs.send("<?php echo $emailjs_service_id; ?>", "<?php echo $emailjs_template_id; ?>", {
                    to_email: "<?php echo $email; ?>",
                    otp_code: "<?php echo $otp; ?>",
                    message: "Your WiFi OTP is: <?php echo $otp; ?>"
                })
                .then(function(response) {
                    clearTimeout(timeout);
                    console.log("SUCCESS!", response);
                    document.querySelector('.spinner').style.display = 'none';
                    document.getElementById('status').innerHTML = '<div class="success">OTP sent successfully to <?php echo htmlspecialchars($email); ?>!</div>';
                    document.getElementById('otp-form').style.display = 'block';
                }, function(error) {
                    clearTimeout(timeout);
                    console.error("FAILED!", error);
                    document.querySelector('.spinner').style.display = 'none';

                    var errorMsg = '<div class="error"><strong>Error: Could not send email.</strong><br><br>';

                    if (!error.status || error.status === 0 || !error.text) {
                        errorMsg += '<strong>Connection blocked by firewall!</strong><br><br>' +
                            'The EmailJS API (api.emailjs.com) is being blocked.<br><br>' +
                            '<strong>Fix on MikroTik:</strong><br>' +
                            '<code>/ip hotspot walled-garden add dst-host=api.emailjs.com</code><br>' +
                            '<code>/ip hotspot walled-garden add dst-host=*.emailjs.com</code><br><br>' +
                            'After adding these rules, wait 30 seconds and ';
                    } else if (error.text && error.text.includes('Invalid grant')) {
                        errorMsg += '<strong>Gmail OAuth Expired!</strong><br><br>' +
                            'Your Gmail connection in EmailJS has expired.<br><br>' +
                            '<strong>Fix:</strong><br>' +
                            '1. Go to <a href="https://dashboard.emailjs.com/admin" target="_blank">EmailJS Dashboard</a><br>' +
                            '2. Click "Email Services"<br>' +
                            '3. Select your Gmail service<br>' +
                            '4. Click "Reconnect" or "Connect Account"<br>' +
                            '5. Complete Gmail authorization<br><br>';
                    } else if (error.status === 412) {
                        errorMsg += '<strong>EmailJS Configuration Error (412)</strong><br><br>' +
                            'There is a problem with your EmailJS service setup.<br><br>' +
                            'Error details: ' + (error.text || 'Unknown error') + '<br><br>';
                    } else {
                        errorMsg += 'Error details: ' + (error.text || 'Unknown error') + '<br>' +
                            'Status: ' + (error.status || 'N/A') + '<br><br>';
                    }

                    errorMsg += '<a href="?">Try again after fixing</a></div>';
                    document.getElementById('status').innerHTML = errorMsg;
                });
            }
        </script>
    </body>
    </html>
    <?php
    exit();
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>WiFi Login - Enter Email</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 100%;
        }
        h2 { color: #333; text-align: center; margin-bottom: 30px; }
        label { display: block; margin-bottom: 8px; color: #555; font-weight: 500; }
        input[type="email"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 16px;
            margin-bottom: 20px;
            box-sizing: border-box;
        }
        input[type="email"]:focus {
            outline: none;
            border-color: #667eea;
        }
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            font-weight: 600;
        }
        button:hover { opacity: 0.9; }
        .footer {
            text-align: center;
            margin-top: 20px;
            color: #888;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>WiFi OTP Login</h2>
        <form method="post">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" placeholder="Enter your email" required />
            <button type="submit">Send OTP</button>
        </form>
        <div class="footer">
            <p>Powered by MikroTik RouterOS</p>
        </div>
    </div>
</body>
</html>