<?php
session_start();
ini_set('display_errors',1); error_reporting(E_ALL);


//$aakash_api_token = "ac49b0ecbac1a2a37b3671d9521c1b1adf423e07cb32de7c797083af6ff77d280";
$env = parse_ini_file(__DIR__ . '/.env');
$aakash_api_token = $env['AAKASH_API_TOKEN'] ?? '';
$aakash_sms_url   = "https://sms.aakashsms.com/sms/v3/send";

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
    $running = shell_exec("pgrep -x freeradius 2>/dev/null || pgrep -x radiusd 2>/dev/null");
    if (empty(trim((string)$running))) return false;
    $secret = escapeshellarg(RADIUS_SECRET);
    $out = shell_exec("radtest ping_check ping_check 127.0.0.1 $port $secret 2>&1");
    if ($out && (
        stripos($out, 'Access-Accept') !== false ||
        stripos($out, 'Access-Reject') !== false
    )) {
        return true;
    }
    if ($out && stripos($out, 'not found') !== false) {
        return true;
    }
    return false;
}

function send_sms_otp(string $mobile, string $otp, string $api_token, string $sms_url): array {
    $message = "Your WiFi OTP is: $otp. Valid for 5 minutes. Do not share with anyone.";
    $payload = json_encode([
        "auth_token" => $api_token,
        "to"         => $mobile,
        "text"       => $message
    ]);

    $ch = curl_init($sms_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || !empty($curl_err)) {
        return ['success' => false, 'error' => "cURL error: $curl_err"];
    }

    $result = json_decode($response, true);

    if (isset($result['error']) && $result['error'] == true) {
        $msg = $result['message'] ?? 'Unknown error from Aakash SMS';
        return ['success' => false, 'error' => $msg];
    }

    if ($http_code < 200 || $http_code >= 300) {
        return ['success' => false, 'error' => "HTTP $http_code – $response"];
    }

    return ['success' => true];
} 

define('OTP_TTL', 300);

if(isset($_POST['otp_input'])){
    if (!isset($_SESSION['otp_attempts'])) $_SESSION['otp_attempts'] = 0;
    if ($_SESSION['otp_attempts'] >= 5) {
        unset($_SESSION['otp'], $_SESSION['mobile'], $_SESSION['otp_attempts'], $_SESSION['otp_time']);
        http_response_code(429);
        die('<p style="text-align:center;font-family:Arial">Too many failed attempts. <a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">Start over</a>.</p>');
    }

    $user_otp   = trim($_POST['otp_input']);
    $stored_otp = isset($_SESSION['otp'])      ? $_SESSION['otp']      : '';
    $mobile     = isset($_SESSION['mobile'])   ? $_SESSION['mobile']   : '';
    $otp_time   = isset($_SESSION['otp_time']) ? $_SESSION['otp_time'] : 0;

    if (time() - $otp_time > OTP_TTL) {
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['otp_attempts']);
        $stored_otp = '';
        error_log("OTP expired for $mobile");
    }

    error_log("OTP Verification - User: $user_otp, Mobile: $mobile");
    error_log("Session mikrotik_ip: " . (isset($_SESSION['mikrotik_ip']) ? $_SESSION['mikrotik_ip'] : 'NOT SET'));
    error_log("Session dst: " . (isset($_SESSION['dst']) ? $_SESSION['dst'] : 'NOT SET'));

    if($user_otp === $stored_otp && !empty($mobile)){
        $_SESSION['otp_attempts'] = 0;
        $hotspot_ip = isset($_SESSION['mikrotik_ip']) ? $_SESSION['mikrotik_ip'] : "172.16.60.17";
        $dst = isset($_SESSION['dst']) ? $_SESSION['dst'] : '';

        error_log("OTP verified! Posting login to MikroTik at: $hotspot_ip, user: $mobile, dst: $dst");

        $check = $conn->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
        $check->bind_param("s", $mobile);
        $check->execute();
        $result = $check->get_result();
        $db_row = $result->fetch_assoc();
        error_log("Database check - stored password for $mobile: " . ($db_row ? $db_row['value'] : 'NOT FOUND'));
        $check->close();

        if (!radius_is_reachable()) {
            error_log("RADIUS server is NOT reachable – aborting MikroTik login for $mobile");
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
            2. Make FreeRADIUS listen on all interfaces.<br>
            Edit <code>/etc/freeradius/3.0/sites-enabled/default</code> and set:<br>
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
            5. Full diagnostics: open<br>
            <code>http://172.16.20.40/smsotp.php?diag=1</code>
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
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($mobile); ?>" />
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
        error_log("OTP MISMATCH - User entered: '$user_otp', Expected: '$stored_otp', Mobile: $mobile, Attempts left: $attempts_left");
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

if(isset($_POST['mobile'])){
    $mobile = trim($_POST['mobile']);
    if (!preg_match('/^\d{7,15}$/', $mobile)) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invalid Number</title>
            <style>
                body { font-family: Arial, sans-serif; display: flex; justify-content: center;
                       align-items: center; min-height: 100vh; margin: 0;
                       background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
                .container { background: white; padding: 40px; border-radius: 10px;
                             box-shadow: 0 10px 40px rgba(0,0,0,0.2); text-align: center; max-width: 400px; }
                .error { color: #721c24; background: #f8d7da; padding: 15px; border-radius: 5px; }
                a { color: #667eea; text-decoration: none; font-weight: 600; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="error">
                    <strong>Invalid Mobile Number</strong><br><br>
                    Please enter a valid mobile number (digits only).<br><br>
                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">Go back</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit();
    }

    $otp = (string)rand(1000, 9999);

    $_SESSION['otp']          = $otp;
    $_SESSION['otp_time']     = time();
    $_SESSION['otp_attempts'] = 0;
    $_SESSION['mobile']       = $mobile;

    $del_stmt = $conn->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
    $del_stmt->bind_param("s", $mobile);
    $del_stmt->execute();
    $del_stmt->close();

    $stmt = $conn->prepare(
        "INSERT INTO radcheck (username, attribute, op, value)
         VALUES (?, 'Cleartext-Password', ':=', ?)"
    );
    $stmt->bind_param("ss", $mobile, $otp);
    if (!$stmt->execute()) {
        error_log("Database insert failed: " . $stmt->error);
        die("Database error. Please contact administrator.");
    }
    $stmt->close();
    $sms_result = send_sms_otp($mobile, $otp, $aakash_api_token, $aakash_sms_url);
    error_log("Aakash SMS result for $mobile: " . json_encode($sms_result));

    if (!$sms_result['success']) {
        error_log("SMS sending failed: " . $sms_result['error']);
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>OTP Sent</title>
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
                width: 100%;
            }
            h3 { color: #333; margin-bottom: 20px; }
            .success { color: #155724; background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .error   { color: #721c24; background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0; text-align: left; }
            .error code { background: #fff; padding: 5px 10px; display: block; margin: 10px 0;
                          border-radius: 3px; font-size: 12px; color: #333; }
            .info { color: #856404; background: #fff3cd; padding: 12px 15px; border-radius: 5px;
                    margin: 10px 0; font-size: 14px; }
            input[type="text"] {
                width: 100%;
                padding: 14px;
                border: 2px solid #e0e0e0;
                border-radius: 5px;
                font-size: 20px;
                text-align: center;
                letter-spacing: 8px;
                margin: 10px 0;
                box-sizing: border-box;
            }
            input[type="text"]:focus {
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
            .resend { margin-top: 15px; font-size: 14px; color: #888; }
            .resend a { color: #667eea; text-decoration: none; font-weight: 600; }
            #countdown { font-weight: bold; color: #667eea; }
        </style>
    </head>
    <body>
        <div class="container">
            <h3>Enter OTP</h3>

            <?php if ($sms_result['success']): ?>
            <div class="success">
                OTP sent successfully to <strong><?php echo htmlspecialchars($mobile); ?></strong>!
            </div>
            <?php else: ?>
            <div class="error">
                <strong>SMS could not be sent.</strong><br>
                Error: <?php echo htmlspecialchars($sms_result['error']); ?><br><br>
                Please check Aakash SMS configuration or contact the administrator.
            </div>
            <?php endif; ?>

            <div class="info">OTP expires in <span id="countdown">5:00</span></div>

            <form method="post" action="/smsotp.php">
                <input type="text" name="otp_input" placeholder="_ _ _ _" maxlength="4"
                       required autofocus inputmode="numeric" pattern="\d{4}" />
                <button type="submit">Verify OTP</button>
            </form>

            <div class="resend">
                Didn't receive it? <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">Resend OTP</a>
            </div>
        </div>

        <script>
            var seconds = 300;
            var el = document.getElementById('countdown');
            var timer = setInterval(function() {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(timer);
                    el.textContent = 'Expired';
                    el.style.color = '#c0392b';
                    return;
                }
                var m = Math.floor(seconds / 60);
                var s = seconds % 60;
                el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            }, 1000);
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
    <title>WiFi Login - Enter Mobile</title>
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
        input[type="tel"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 16px;
            margin-bottom: 20px;
            box-sizing: border-box;
        }
        input[type="tel"]:focus {
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
        <form method="post" autocomplete="off">
            <label for="mobile">Phone Number</label>
            <input type="tel" id="mobile" name="mobile"
                   placeholder="Enter your phone number"
                   pattern="\d{7,15}" required
                   autocomplete="tel" inputmode="numeric" />
            <button type="submit">Send OTP</button>
        </form>
        <div class="footer">
            <p></p>
        </div>
    </div>
</body>
</html>
