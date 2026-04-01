# MikroTik RADIUS Attributes Guide

## How MikroTik Enforces Session Limits

MikroTik routers use RADIUS reply attributes from the Access-Accept packet to enforce session limits. When a user successfully authenticates, FreeRADIUS sends these attributes back to MikroTik, which then enforces them.

---

## Session Timeout (Time Limit)

**RADIUS Attribute:** `Session-Timeout`  
**Value:** Time in seconds  
**How it works:**
- MikroTik starts a countdown timer when the user connects
- When the timer reaches zero, MikroTik automatically disconnects the user
- MikroTik sends an Accounting-Stop packet to RADIUS with `Acct-Terminate-Cause = Session-Timeout`

**Example:**
```
Session-Timeout = 3600    # 1 hour (3600 seconds)
Session-Timeout = 7200    # 2 hours
Session-Timeout = 86400   # 24 hours
```

**In OnLiFi:**
- Set in voucher group: `validity_hours`
- Converted to seconds: `validity_hours * 3600`
- Stored in `radreply` table as `Session-Timeout`

---

## Data Limit (Download + Upload)

**RADIUS Attribute:** `Mikrotik-Total-Limit`  
**Value:** Total bytes (download + upload combined)  
**How it works:**
- MikroTik tracks total data usage (input + output)
- When usage reaches the limit, MikroTik disconnects the user
- MikroTik sends an Accounting-Stop packet with `Acct-Terminate-Cause = User-Request`

**Example:**
```
Mikrotik-Total-Limit = 104857600    # 100 MB (100 * 1024 * 1024)
Mikrotik-Total-Limit = 1073741824   # 1 GB
Mikrotik-Total-Limit = 5368709120   # 5 GB
```

**In OnLiFi:**
- Set in voucher group: `data_limit_mb`
- Converted to bytes: `data_limit_mb * 1024 * 1024`
- Stored in `radreply` table as `Mikrotik-Total-Limit`

---

## Speed Limit (Bandwidth Control)

**RADIUS Attributes:** `Mikrotik-Rate-Limit`  
**Value:** String format: `upload/download [burst-upload/burst-download] [time] [priority]`  
**How it works:**
- MikroTik applies bandwidth shaping to the user's connection
- Limits are enforced continuously during the session
- User cannot exceed the specified speeds

**Example:**
```
Mikrotik-Rate-Limit = "512k/1M"           # 512 Kbps up / 1 Mbps down
Mikrotik-Rate-Limit = "1M/2M"             # 1 Mbps up / 2 Mbps down
Mikrotik-Rate-Limit = "2M/5M 4M/10M 8"    # With burst
```

**In OnLiFi:**
- Set in voucher group: `speed_limit_kbps`
- Converted to rate limit string: `{speed}k/{speed}k`
- Stored in `radreply` table as `Mikrotik-Rate-Limit`

---

## How MikroTik Tracks Usage

### 1. Accounting Start
When user connects:
```
MikroTik → FreeRADIUS: Accounting-Request (Start)
- Acct-Status-Type = Start
- Acct-Session-Id = unique session ID
- User-Name = voucher code
```

FreeRADIUS stores in `radacct` table:
- `acctstarttime` = NOW()
- `acctsessionid` = session ID
- `username` = voucher code

### 2. Interim Updates (every 5 minutes by default)
During session:
```
MikroTik → FreeRADIUS: Accounting-Request (Interim-Update)
- Acct-Status-Type = Interim-Update
- Acct-Session-Time = seconds connected
- Acct-Input-Octets = bytes downloaded
- Acct-Output-Octets = bytes uploaded
```

FreeRADIUS updates `radacct` table:
- `acctupdatetime` = NOW()
- `acctsessiontime` = current session time
- `acctinputoctets` = current download
- `acctoutputoctets` = current upload

### 3. Accounting Stop
When user disconnects (timeout, data limit, or manual):
```
MikroTik → FreeRADIUS: Accounting-Request (Stop)
- Acct-Status-Type = Stop
- Acct-Session-Time = total seconds
- Acct-Input-Octets = total bytes downloaded
- Acct-Output-Octets = total bytes uploaded
- Acct-Terminate-Cause = reason (Session-Timeout, User-Request, etc.)
```

FreeRADIUS updates `radacct` table:
- `acctstoptime` = NOW()
- `acctsessiontime` = final session time
- `acctinputoctets` = final download
- `acctoutputoctets` = final upload
- `acctterminatecause` = reason

---

## OnLiFi Implementation

### Database Tables

**`radreply` table** - Stores RADIUS reply attributes:
```sql
CREATE TABLE radreply (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL
);
```

**Example data for a voucher:**
```sql
INSERT INTO radreply (username, attribute, op, value) VALUES
('12345678', 'Session-Timeout', '=', '3600'),              -- 1 hour
('12345678', 'Mikrotik-Total-Limit', '=', '104857600'),    -- 100 MB
('12345678', 'Mikrotik-Rate-Limit', '=', '1M/2M');         -- 1/2 Mbps
```

**`radacct` table** - Stores accounting records:
```sql
CREATE TABLE radacct (
    radacctid BIGINT PRIMARY KEY AUTO_INCREMENT,
    acctsessionid VARCHAR(64) NOT NULL,
    acctuniqueid VARCHAR(32) NOT NULL,
    username VARCHAR(64) NOT NULL,
    nasipaddress VARCHAR(15) NOT NULL,
    acctstarttime DATETIME NULL,
    acctupdatetime DATETIME NULL,
    acctstoptime DATETIME NULL,
    acctsessiontime INT NULL,
    acctinputoctets BIGINT NULL,
    acctoutputoctets BIGINT NULL,
    acctterminatecause VARCHAR(32) NULL,
    calledstationid VARCHAR(50) NULL,
    callingstationid VARCHAR(50) NULL,
    framedipaddress VARCHAR(15) NULL
);
```

---

## FreeRadiusService.php Implementation

The `FreeRadiusService` syncs voucher attributes to RADIUS:

```php
private function insertRadreply(string $username, int $validityHours, ?int $dataLimitMb, ?int $speedLimitKbps): void
{
    // Session timeout (time limit)
    $sessionTimeout = $validityHours * 3600;
    DB::connection('tenant')->table('radreply')->updateOrInsert(
        ['username' => $username, 'attribute' => 'Session-Timeout'],
        ['op' => '=', 'value' => (string)$sessionTimeout]
    );

    // Data limit (if set)
    if ($dataLimitMb) {
        $totalLimit = $dataLimitMb * 1024 * 1024;
        DB::connection('tenant')->table('radreply')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Mikrotik-Total-Limit'],
            ['op' => '=', 'value' => (string)$totalLimit]
        );
    }

    // Speed limit (if set)
    if ($speedLimitKbps) {
        $rateLimit = "{$speedLimitKbps}k/{$speedLimitKbps}k";
        DB::connection('tenant')->table('radreply')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit'],
            ['op' => '=', 'value' => $rateLimit]
        );
    }
}
```

---

## Perl Module Implementation

The Perl module reads reply attributes from `radreply` and sends them to MikroTik:

```perl
# Get reply attributes from radreply
$sth = $dbh->prepare(q{
    SELECT attribute, value, op
    FROM radreply
    WHERE username = ?
});

$sth->execute($username);

while (my $row = $sth->fetchrow_hashref()) {
    my $attr = $row->{attribute};
    my $val = $row->{value};
    
    # Set reply attributes
    $RAD_REPLY{$attr} = $val;
    
    &radiusd::radlog(1, "PERL: Found radreply: $attr = $val for user $username");
}
```

---

## Testing Limits

### Test Session Timeout
1. Create voucher with `validity_hours = 1` (1 hour)
2. User connects
3. After 1 hour, MikroTik disconnects automatically
4. Check `radacct` table: `acctterminatecause = 'Session-Timeout'`

### Test Data Limit
1. Create voucher with `data_limit_mb = 100` (100 MB)
2. User connects and downloads/uploads data
3. When total usage reaches 100 MB, MikroTik disconnects
4. Check `radacct` table: `acctinputoctets + acctoutputoctets ≈ 104857600`

### Test Speed Limit
1. Create voucher with `speed_limit_kbps = 1024` (1 Mbps)
2. User connects
3. Run speed test - should be limited to ~1 Mbps
4. Speed limit enforced throughout session

---

## Common Issues

### Issue: Accounting not working
**Symptom:** "RADIUS account request not sent: no-response" in MikroTik logs  
**Cause:** FreeRADIUS not responding to accounting requests  
**Solution:**
1. Check FreeRADIUS debug logs: `sudo freeradius -X`
2. Look for "PERL ACCOUNTING" messages
3. Verify `radacct` table exists in tenant database
4. Check Perl module accounting function

### Issue: Session timeout not working
**Symptom:** User stays connected beyond time limit  
**Cause:** `Session-Timeout` not in `radreply` table  
**Solution:**
1. Check `radreply` table: `SELECT * FROM radreply WHERE username = 'VOUCHER_CODE'`
2. Should have: `Session-Timeout = {validity_hours * 3600}`
3. Re-sync voucher: Delete and recreate, or manually insert

### Issue: Speed limit not applied
**Symptom:** User has unlimited speed  
**Cause:** `Mikrotik-Rate-Limit` not in `radreply` or wrong format  
**Solution:**
1. Check `radreply` table for `Mikrotik-Rate-Limit`
2. Format must be: `{speed}k/{speed}k` (e.g., "1M/2M")
3. MikroTik must support rate limiting (some models don't)

---

## Summary

**How MikroTik knows when to disconnect:**
1. **Time limit:** MikroTik receives `Session-Timeout` in Access-Accept, starts countdown timer
2. **Data limit:** MikroTik receives `Mikrotik-Total-Limit`, tracks usage, disconnects when reached
3. **Speed limit:** MikroTik receives `Mikrotik-Rate-Limit`, applies bandwidth shaping continuously

**How MikroTik reports usage:**
1. Sends Accounting-Start when user connects
2. Sends Interim-Updates every 5 minutes with current usage
3. Sends Accounting-Stop when user disconnects with final usage

**All limits are enforced by MikroTik, not FreeRADIUS. FreeRADIUS only provides the limit values and records the usage.**
