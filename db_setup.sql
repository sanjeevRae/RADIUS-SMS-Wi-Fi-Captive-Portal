
CREATE DATABASE IF NOT EXISTS radius
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE radius;

CREATE USER IF NOT EXISTS 'radius'@'localhost' IDENTIFIED BY 'Naren@123';
GRANT ALL PRIVILEGES ON radius.* TO 'radius'@'localhost';
FLUSH PRIVILEGES;

CREATE TABLE IF NOT EXISTS radcheck (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username  VARCHAR(64)  NOT NULL DEFAULT '',
    attribute VARCHAR(64)  NOT NULL DEFAULT '',
    op        CHAR(2)      NOT NULL DEFAULT ':=',
    value     VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- radreply: per-user reply attributes sent back after authentication
CREATE TABLE IF NOT EXISTS radreply (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username  VARCHAR(64)  NOT NULL DEFAULT '',
    attribute VARCHAR(64)  NOT NULL DEFAULT '',
    op        CHAR(2)      NOT NULL DEFAULT '=',
    value     VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- radgroupcheck: per-group authentication checks
CREATE TABLE IF NOT EXISTS radgroupcheck (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    groupname VARCHAR(64)  NOT NULL DEFAULT '',
    attribute VARCHAR(64)  NOT NULL DEFAULT '',
    op        CHAR(2)      NOT NULL DEFAULT ':=',
    value     VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- radgroupreply: per-group reply attributes
CREATE TABLE IF NOT EXISTS radgroupreply (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    groupname VARCHAR(64)  NOT NULL DEFAULT '',
    attribute VARCHAR(64)  NOT NULL DEFAULT '',
    op        CHAR(2)      NOT NULL DEFAULT '=',
    value     VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- radusergroup: maps users to groups with a priority
CREATE TABLE IF NOT EXISTS radusergroup (
    username  VARCHAR(64)  NOT NULL DEFAULT '',
    groupname VARCHAR(64)  NOT NULL DEFAULT '',
    priority  INT          NOT NULL DEFAULT 1,
    KEY idx_username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- radacct: accounting records (sessions)
CREATE TABLE IF NOT EXISTS radacct (
    radacctid          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    acctsessionid      VARCHAR(64)     NOT NULL DEFAULT '',
    acctuniqueid       VARCHAR(32)     NOT NULL DEFAULT '',
    username           VARCHAR(64)     NOT NULL DEFAULT '',
    realm              VARCHAR(64)             DEFAULT '',
    nasipaddress       VARCHAR(15)     NOT NULL DEFAULT '',
    nasportid          VARCHAR(32)             DEFAULT NULL,
    nasporttype        VARCHAR(32)             DEFAULT NULL,
    acctstarttime      DATETIME                DEFAULT NULL,
    acctupdatetime     DATETIME                DEFAULT NULL,
    acctstoptime       DATETIME                DEFAULT NULL,
    acctinterval       INT                     DEFAULT NULL,
    acctsessiontime    INT UNSIGNED            DEFAULT NULL,
    acctauthentic      VARCHAR(32)             DEFAULT NULL,
    connectinfo_start  VARCHAR(50)             DEFAULT NULL,
    connectinfo_stop   VARCHAR(50)             DEFAULT NULL,
    acctinputoctets    BIGINT UNSIGNED         DEFAULT NULL,
    acctoutputoctets   BIGINT UNSIGNED         DEFAULT NULL,
    calledstationid    VARCHAR(50)     NOT NULL DEFAULT '',
    callingstationid   VARCHAR(50)     NOT NULL DEFAULT '',
    acctterminatecause VARCHAR(32)     NOT NULL DEFAULT '',
    servicetype        VARCHAR(32)             DEFAULT NULL,
    framedprotocol     VARCHAR(32)             DEFAULT NULL,
    framedipaddress    VARCHAR(15)     NOT NULL DEFAULT '',
    PRIMARY KEY (radacctid),
    UNIQUE KEY acctuniqueid (acctuniqueid),
    KEY idx_username (username),
    KEY idx_nasipaddress (nasipaddress),
    KEY idx_acctsessionid (acctsessionid),
    KEY idx_acctstarttime (acctstarttime),
    KEY idx_acctstoptime (acctstoptime),
    KEY idx_callingstationid (callingstationid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- nas: known NAS (Network Access Server) devices, e.g. MikroTik routers
CREATE TABLE IF NOT EXISTS nas (
    id          INT           NOT NULL AUTO_INCREMENT,
    nasname     VARCHAR(128)  NOT NULL,
    shortname   VARCHAR(32)           DEFAULT NULL,
    type        VARCHAR(30)           DEFAULT 'other',
    ports       INT                   DEFAULT NULL,
    secret      VARCHAR(60)   NOT NULL DEFAULT 'testing123',
    server      VARCHAR(64)           DEFAULT NULL,
    community   VARCHAR(50)           DEFAULT NULL,
    description VARCHAR(200)          DEFAULT 'RADIUS Client',
    PRIMARY KEY (id),
    KEY idx_nasname (nasname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- radpostauth: post-authentication logging (Accept / Reject per attempt)
CREATE TABLE IF NOT EXISTS radpostauth (
    id         INT           NOT NULL AUTO_INCREMENT,
    username   VARCHAR(64)   NOT NULL DEFAULT '',
    pass       VARCHAR(64)   NOT NULL DEFAULT '',
    reply      VARCHAR(32)   NOT NULL DEFAULT '',
    authdate   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nasipaddress VARCHAR(15)          DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_username (username),
    KEY idx_authdate (authdate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Custom OTP audit / session-tracking table
-- ============================================================
CREATE TABLE IF NOT EXISTS otp_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mobile       VARCHAR(20)     NOT NULL,
    event        ENUM('requested','verified','failed','expired') NOT NULL,
    ip_address   VARCHAR(45)             DEFAULT NULL,  -- supports IPv6
    mac_address  VARCHAR(17)             DEFAULT NULL,
    mikrotik_ip  VARCHAR(45)             DEFAULT NULL,
    user_agent   VARCHAR(255)            DEFAULT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mobile     (mobile),
    KEY idx_event      (event),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log for all OTP lifecycle events';

-- ============================================================
-- Sample MikroTik NAS entry (update IP / secret as needed)
-- ============================================================
INSERT IGNORE INTO nas (nasname, shortname, type, secret, description)
VALUES ('172.16.60.17', 'mikrotik', 'other', 'testing123', 'MikroTik Hotspot Router');
