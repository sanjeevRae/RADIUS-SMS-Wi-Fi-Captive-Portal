# RADIUS-SMS-Wi-Fi-Captive-Portal

A complete, production-ready captive portal solution that combines FreeRADIUS authentication with SMS OTP verification, enabling secure Wi-Fi access through MikroTik routers. Users authenticate using their mobile numbers, receive a one‑time password (OTP) via SMS, and gain temporary internet access.


## ✨ Features

- 📱 **SMS OTP Authentication** – Users log in with their mobile number and receive a one-time password via Aakash SMS API.
- 🔐 **FreeRADIUS Integration** – Robust RADIUS server on Ubuntu for authentication, authorization, and accounting.
- 🖧 **MikroTik Hotspot Gateway** – Industry‑standard router handles captive portal redirection and user sessions.
- 📊 **Session Management** – Temporary user accounts with configurable session timeouts and bandwidth limits.
- 🌐 **Walled Garden** – Allows unauthenticated access to captive portal pages and essential services.
- 🔄 **PAP Authentication** – Secure communication between MikroTik and FreeRADIUS over LAN.

---

## 🏗 Architecture

```
┌─────────────┐      ┌───────────────┐      ┌─────────────────┐
│ User Device │ <──> │ MikroTik      │ <──> │ FreeRADIUS      │
│ (Wi‑Fi)      │      │ Hotspot       │      │ (Ubuntu Server) │
└─────────────┘      └───────────────┘      └─────────────────┘
                             │                         │
                             │ (RADIUS)                 │ (MySQL)
                             ▼                         ▼
                      ┌───────────────┐      ┌─────────────────┐
                      │ Aakash SMS    │      │ MySQL Database  │
                      │ API Gateway   │      │ (Users, OTPs)   │
                      └───────────────┘      └─────────────────┘
                             ▲                         ▲
                             │                         │
                             └──────────┬──────────────┘
                                        │
                              ┌─────────▼─────────┐
                              │ Captive Portal    │
                              │ Web Server        │
                              │ (Apache/PHP)      │
                              └───────────────────┘
```

---

## 🔧 Technology Stack

| Component     | Technology                        | Purpose                              |
|---------------|-----------------------------------|--------------------------------------|
| RADIUS Server | FreeRADIUS 3.x on Ubuntu 22.04 LTS | Core authentication engine          |
| Router/AP     | MikroTik (RouterOS v7+)           | Hotspot gateway and captive portal   |
| SMS Gateway   | Aakash SMS API                    | OTP delivery to users                |
| Database      | MariaDB (mysql compatible)    | Store users, OTPs, sessions, RADIUS data, audit log |
| Web Server    | Apache/Nginx + PHP                | Host captive portal pages            |
| Backend       | PHP (cURL for SMS API)            | OTP generation & validation logic    |

---

## 📋 Prerequisites

- **Ubuntu 22.04 Server** – Static IP (e.g., `192.168.1.10/24`)
- **MikroTik Router** – With Hotspot feature and two Ethernet ports
- **Aakash SMS Account** – API credentials (auth token, sender ID)
- **Domain/Public IP** – For captive portal (optional)
- Basic knowledge of Linux, MySQL, and RouterOS

---

## 🔌 Installation & Configuration

### 1. FreeRADIUS Server (Ubuntu)

Install packages and configure MariaDB:

```bash
sudo apt update && sudo apt install -y freeradius freeradius-mysql mariadb-server
sudo mysql_secure_installation
```

Create database, user, and import the complete schema (FreeRADIUS tables + OTP audit log) using the included [`db_setup.sql`](db_setup.sql):

```bash
sudo mysql -u root -p < db_setup.sql
```

Or run the statements manually:

```sql
CREATE DATABASE radius CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'radius'@'localhost' IDENTIFIED BY 'Naren@123';
GRANT ALL PRIVILEGES ON radius.* TO 'radius'@'localhost';
FLUSH PRIVILEGES;
USE radius;
SOURCE db_setup.sql;
```

Enable SQL module (symlink) and configure `/etc/freeradius/3.0/clients.conf` with your MikroTik IP and secret:

```bash
sudo ln -s /etc/freeradius/3.0/mods-available/sql \
           /etc/freeradius/3.0/mods-enabled/sql
```

In `/etc/freeradius/3.0/mods-enabled/sql`, set:

```
dialect = "mysql"
server  = "localhost"
port    = 3306
login   = "radius"
password = "Naren@123"
radius_db = "radius"
```

---

### 2. MikroTik Router Setup

Assign WAN IP on `ether1`, create LAN bridge with IP `192.168.1.1/24`

Enable Hotspot on the bridge:

```
/ip hotspot profile add name=hsprof1 hotspot-address=192.168.1.1
/ip hotspot add interface=bridge_lan profile=hsprof1
```

Add RADIUS client:

```
/radius add address=192.168.1.10 secret=testing123 service=hotspot
/ip hotspot profile set [find] use-radius=yes login-by=http-pap
```

---

### 3. Walled Garden Configuration

Allow access to your web server and DNS:

```
/ip hotspot walled-garden ip add dst-host=192.168.1.20 action=allow
/ip hotspot walled-garden ip add dst-host=8.8.8.8 action=allow
```

---

### 4. Captive Portal Web Pages

Place PHP files in `/var/www/html/`:

- `smsotp.php` – Collect mobile number, generate OTP, send via Aakash SMS

Replace login.html files in your `Microtick File`: 

- `login.html` – Redirects to the `smsotp.php` on `FreeRadus`

---

## 🔄 Authentication Flow

The sequence diagram below illustrates the complete authentication process from user connection to internet access:

![forgit](https://github.com/sanjeevRae/RADIUS-SMS-Wi-Fi-Captive-Portal/blob/main/Sequence%20Diagram.png)

**Flow Steps:**

1. User connects to Wi-Fi and is redirected to captive portal
2. User enters mobile number → OTP generated and sent via SMS
3. User submits OTP → validated against database
4. Temporary RADIUS user created with OTP as password
5. MikroTik sends RADIUS Access-Request (PAP) to FreeRADIUS
6. FreeRADIUS authenticates and returns Access-Accept with attributes
7. User gains internet access for the session duration

---

## 📌 Key Configuration Concepts

### PAP Authentication

- MikroTik uses PAP to send credentials to FreeRADIUS
- FreeRADIUS expects `Cleartext-Password` attribute in `radcheck` table
- Enable `login-by=http-pap` in MikroTik hotspot profile

### RADIUS Server Profile on MikroTik

- Defined under `/radius` with server IP and secret
- Hotspot profile must have `use-radius=yes`
- Optional: Set `accounting-backup` and `realm` settings

### IP Profile & Bandwidth Management

Control session duration and bandwidth via RADIUS attributes:

```
username   | attribute            | op | value
user123    | Session-Timeout      | := | 3600
user123    | MikroTik-Rate-Limit  | := | 2M/2M
```

---

## 🗄️ MariaDB Schema

All tables are defined and ready to import via [`db_setup.sql`](db_setup.sql).

### FreeRADIUS tables

| Table | Purpose |
|---|---|
| `radcheck` | Per-user authentication checks (OTP stored as `Cleartext-Password`) |
| `radreply` | Per-user reply attributes returned after auth |
| `radgroupcheck` / `radgroupreply` | Group-level checks and replies |
| `radusergroup` | User ↔ group mappings |
| `radacct` | Full accounting records (session start/stop, bytes) |
| `radpostauth` | Post-auth log – Accept or Reject per attempt |
| `nas` | Registered NAS devices (MikroTik routers) |

### Custom OTP audit table (`otp_log`)

Every OTP lifecycle event is automatically written by `smsotp.php`:

| `event` value | Triggered when |
|---|---|
| `requested` | User submits mobile number, OTP generated & SMS sent |
| `verified` | Correct OTP entered; MikroTik login form submitted |
| `failed` | Wrong OTP entered (each attempt logged separately) |
| `expired` | OTP TTL (5 min) exceeded before the correct OTP was entered |

Useful audit query:

```sql
-- OTP activity for the last 24 hours
SELECT mobile, event, ip_address, mikrotik_ip, created_at
FROM   otp_log
WHERE  created_at >= NOW() - INTERVAL 1 DAY
ORDER  BY created_at DESC;
```

---

## 🧪 Testing

- Test RADIUS locally: `radtest testuser testpass 127.0.0.1 0 testing123`
- From MikroTik: `/tool radius simulate hotspot user=testuser password=testpass address=192.168.1.100`
- Full flow: Connect a client, request OTP, verify and authenticate

---

## 🐞 Troubleshooting

| Issue                  | Solution                                              |
|------------------------|-------------------------------------------------------|
| RADIUS no response     | Check `systemctl status freeradius` and secrets match |
| OTP not delivered      | Verify SMS API credentials and mobile number          |
| Cannot reach portal    | Review walled garden rules                            |
| Authentication fails   | Ensure `Cleartext-Password` in `radcheck`             |

---

## 🔒 Security Notes

- Change default secrets (`testing123`, database passwords)
- Use HTTPS for captive portal (Let's Encrypt)
- Set OTP expiry (5 minutes) and limit retries
- Restrict FreeRADIUS access to MikroTik IP only
- Regularly update all packages

---

## 📄 License

This project is open‑source under the [MIT License](LICENSE).
