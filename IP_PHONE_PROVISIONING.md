# IP Phone Provisioning Configuration Specification

## Document Information

**Purpose:** General specification for creating valid configuration files for IP phones
**Supported Vendors:** SNOM, Yealink, Fanvil
**Document Version:** 1.0
**Date:** January 2026

---

## Table of Contents

1. [Overview](#overview)
2. [SNOM Phone Configuration](#snom-phone-configuration)
3. [Yealink Phone Configuration](#yealink-phone-configuration)
4. [Fanvil Phone Configuration](#fanvil-phone-configuration)
5. [Common SIP Parameters](#common-sip-parameters)
6. [Configuration File Examples](#configuration-file-examples)

---

## 1. Overview

This document specifies the configuration file formats required by SNOM, Yealink, and Fanvil IP phones. Each vendor requires a specific file format and parameter structure to successfully provision their devices.

### 1.1 General Principles

- Configuration files contain SIP account credentials and phone settings
- Each vendor uses a different file format and parameter naming convention
- Files are typically delivered to phones via HTTP/HTTPS
- Phones apply configuration and reboot to register with the SIP server

### 1.2 File Naming Convention

Configuration files are typically named using the phone's MAC address:
- **Format:** `<MAC_ADDRESS>.<extension>`
- **MAC Address:** 12 hexadecimal characters without separators (e.g., `44dbd2123456`)
- **Extension:** Vendor-specific (`.xml`, `.txt`, `.cfg`)

**Examples:**
- SNOM: `0004131234ab.xml`
- Yealink: `44dbd2123456.txt`
- Fanvil: `0c383e789012.txt`

---

## 2. SNOM Phone Configuration

### 2.1 File Format

- **Format:** XML (Extensible Markup Language)
- **File Extension:** `.xml`
- **Encoding:** UTF-8
- **Structure:** Hierarchical XML with `<settings>` root element

### 2.2 Basic Structure

```xml
<?xml version="1.0" encoding="utf-8"?>
<settings>
    <phone-settings>
        <!-- SIP account parameters -->
        <!-- Phone settings -->
    </phone-settings>
</settings>
```

### 2.3 SIP Account Parameters

SNOM phones support multiple SIP accounts, indexed numerically. Each parameter requires an `idx` attribute (1-12) and `perm` attribute (typically "RW" for read-write).

#### 2.3.1 Core SIP Parameters

| XML Tag | Description | Example Value | Required |
|---------|-------------|---------------|----------|
| `user_pname` | Phone number/extension | `201` | Yes |
| `user_name` | SIP authentication username | `201` | Yes |
| `user_realname` | Display name (Caller ID) | `John Smith` | Yes |
| `user_pass` | SIP password | `secretpass123` | Yes |
| `user_host` | SIP server hostname/IP | `172.16.32.153` | Yes |
| `user_srtp` | SRTP encryption | `off` or `on` | No |
| `user_mailbox` | Voicemail access code | `*97` | No |
| `user_dp_str` | Dial plan string | `!([^#]%2b)#!sip:\1@\d!d` | No |

#### 2.3.2 Parameter Format

```xml
<user_pname idx="1" perm="RW">201</user_pname>
<user_name idx="1" perm="RW">201</user_name>
<user_realname idx="1" perm="RW">John Smith</user_realname>
<user_pass idx="1" perm="RW">secretpass123</user_pass>
<user_host idx="1" perm="RW">172.16.32.153</user_host>
<user_srtp idx="1" perm="RW">off</user_srtp>
```

### 2.4 Additional Phone Settings

#### 2.4.1 Caller ID Configuration

```xml
<contact_source_sip_priority idx="INDEX" perm="PERMISSIONFLAG">PAI RPID FROM</contact_source_sip_priority>
```

**Values:** Space-separated priority list
- `PAI` - P-Asserted-Identity header
- `RPID` - Remote-Party-ID header
- `FROM` - From header

#### 2.4.2 Common Phone Settings

```xml
<answer_after_policy perm="RW">idle</answer_after_policy>
<timezone perm="RW">EST-5</timezone>
<ntp_server perm="RW">pool.ntp.org</ntp_server>
```

### 2.5 Multi-Line Configuration

For phones with multiple lines, repeat account parameters with different `idx` values:

```xml
<user_pname idx="1" perm="RW">201</user_pname>
<user_name idx="1" perm="RW">201</user_name>
<!-- Line 1 configuration -->

<user_pname idx="2" perm="RW">202</user_pname>
<user_name idx="2" perm="RW">202</user_name>
<!-- Line 2 configuration -->
```

---

## 3. Yealink Phone Configuration

### 3.1 File Format

- **Format:** Plain text key-value pairs
- **File Extension:** `.txt` or `.cfg`
- **Line Ending:** CRLF (`\r\n`) preferred, LF (`\n`) acceptable
- **Structure:** Flat parameter list with dotted notation

### 3.2 Version Header

Every Yealink configuration file must begin with a version header:

```
#!version:1.0.0.1
```

### 3.3 SIP Account Parameters

Yealink phones support multiple accounts using indexed parameters (e.g., `account.1.*`, `account.2.*`).

#### 3.3.1 Core Account Parameters

| Parameter | Description | Example Value | Required |
|-----------|-------------|---------------|----------|
| `account.X.enable` | Enable account | `1` (enabled) or `0` (disabled) | Yes |
| `account.X.label` | Display label on phone | `Office (201)` | Yes |
| `account.X.display_name` | Outbound Caller ID | `John Smith` | Yes |
| `account.X.auth_name` | Authentication username | `201` | Yes |
| `account.X.user_name` | SIP username | `201` | Yes |
| `account.X.password` | SIP password | `secretpass123` | Yes |
| `account.X.sip_server_host` | SIP server IP/hostname | `172.16.32.153` | Yes |
| `account.X.sip_server_port` | SIP server port | `5060` | Yes |
| `account.X.transport` | Transport protocol | `0` (UDP), `1` (TCP), `2` (TLS) | Yes |

#### 3.3.2 Codec Configuration

```
account.X.codec.Y.enable = 1
account.X.codec.Y.payload_type = PCMU
account.X.codec.Y.priority = 1
account.X.codec.Y.rtpmap = 0
```

**Common Codecs:**
- `PCMU` (G.711 μ-law) - rtpmap: 0
- `PCMA` (G.711 A-law) - rtpmap: 8
- `G722` - rtpmap: 9
- `G729` - rtpmap: 18

#### 3.3.3 Caller ID Source

```
account.X.cid_source = 4
```

**Values:**
- `0` - FROM header
- `1` - PAI (P-Asserted-Identity)
- `2` - PAI-FROM
- `3` - PRID-PAI-FROM
- `4` - PAI-RPID-FROM (recommended)
- `5` - RPID-FROM

### 3.4 Voicemail Configuration

```
voice_mail.number.X = *97
```

### 3.5 Phone Feature Settings

#### 3.5.1 Intercom Support

```
features.intercom.allow = 1
features.intercom.mute = 0
features.intercom.tone = 1
features.intercom.barge = 1
```

#### 3.5.2 Transfer Settings

```
features.dtmf.transfer = ##
features.dtmf.replace_tran = 1
```

#### 3.5.3 Display Settings

```
phone_setting.lcd_logo.mode = 0
```

**Values:**
- `0` - Text mode
- `2` - Image mode (requires logo URL)

#### 3.5.4 Auto-Provisioning Control

```
auto_provision.dhcp_option.enable = 0
```

Set to `0` to disable DHCP-based provisioning after initial setup.

### 3.6 Time and NTP Configuration

```
local_time.time_zone = -5
local_time.time_zone_name = US-Eastern
local_time.summer_time = 2
static.auto_provision.ntp_server1 = pool.ntp.org
```

### 3.7 Model-Specific Configuration

#### 3.7.1 Standard Phones (T19D, T21D)

```
phone_setting.lcd_logo.mode = 0
```

#### 3.7.2 Advanced Phones (T28P, T46G, T48G)

```
lcd_logo.url = http://server/logo.dob
phone_setting.lcd_logo.mode = 2
```

Logo format: Proprietary `.dob` format (device-specific resolution)

#### 3.7.3 DECT Base Stations (W52P, W60P)

DECT base stations support up to 5 handsets, each with its own SIP account.

**Account Configuration:**
```
account.1.enable = 1
account.1.label = Handset 1 (201)
account.1.display_name = John Smith
account.1.auth_name = 201
account.1.user_name = 201
account.1.password = pass1
account.1.sip_server_host = 172.16.32.153
account.1.sip_server_port = 5060
account.1.transport = 0
voice_mail.number.1 = *97

account.2.enable = 1
account.2.label = Handset 2 (202)
account.2.display_name = Jane Doe
account.2.auth_name = 202
account.2.user_name = 202
account.2.password = pass2
account.2.sip_server_host = 172.16.32.153
account.2.sip_server_port = 5060
account.2.transport = 0
voice_mail.number.2 = *97
```

**Handset Naming:**
```
handset.1.name = John Smith
handset.2.name = Jane Doe
handset.3.name = Bob Johnson
handset.4.name = Alice Brown
handset.5.name = Charlie Davis
```

**Note:** Up to 5 accounts (1-5) can be configured for W52P and W60P models.

---

## 4. Fanvil Phone Configuration

### 4.1 File Format

- **Format:** Proprietary text format with module sections
- **File Extension:** `.txt`
- **Line Ending:** LF (`\n`) or CRLF (`\r\n`)
- **Structure:** Header, module sections, footer

### 4.2 File Structure

```
<<VOIP CONFIG FILE>>Version:2.0002

<SIP CONFIG MODULE>
[SIP parameters]

<TELE CONFIG MODULE>
[Telephony parameters]

<AUTOUPDATE CONFIG MODULE>
[Auto-update parameters]

<<END OF FILE>>
```

### 4.3 Header and Footer

**Required Header:**
```
<<VOIP CONFIG FILE>>Version:2.0002
```

**Required Footer:**
```
<<END OF FILE>>
```

### 4.4 SIP Configuration Module

```
<SIP CONFIG MODULE>
--SIP Line List--  :
SIP1 Enable Reg    :1
SIP1 Phone Number  :201
SIP1 Display Name  :John Smith
SIP1 Sip Name      :201
SIP1 Register Addr :172.16.32.153
SIP1 Register Port :5060
SIP1 Register User :201
SIP1 Register Pswd :secretpass123
SIP1 MWI Num       :*97
SIP1 Proxy User    :
SIP1 Proxy Pswd    :
SIP1 Proxy Addr    :
```

#### 4.4.1 SIP Parameters

| Parameter | Description | Example Value | Required |
|-----------|-------------|---------------|----------|
| `SIPX Enable Reg` | Enable registration | `1` (enabled) or `0` (disabled) | Yes |
| `SIPX Phone Number` | Extension number | `201` | Yes |
| `SIPX Display Name` | Caller ID name | `John Smith` | Yes |
| `SIPX Sip Name` | SIP username | `201` | Yes |
| `SIPX Register Addr` | SIP server IP/hostname | `172.16.32.153` | Yes |
| `SIPX Register Port` | SIP server port | `5060` | Yes |
| `SIPX Register User` | Authentication username | `201` | Yes |
| `SIPX Register Pswd` | SIP password | `secretpass123` | Yes |
| `SIPX MWI Num` | Message Waiting Indicator number | `*97` | No |
| `SIPX Proxy User` | Proxy username (optional) | `` | No |
| `SIPX Proxy Pswd` | Proxy password (optional) | `` | No |
| `SIPX Proxy Addr` | Proxy server (optional) | `` | No |

**Note:** Replace `X` with line number (1-6 for most models)

### 4.5 Telephony Configuration Module

```
<TELE CONFIG MODULE>
SIP1 Caller Id Type:4
P1 Enable Intercom :1
P1 Intercom Mute   :0
P1 Intercom Tone   :1
P1 Intercom Barge  :1
```

#### 4.5.1 Telephony Parameters

| Parameter | Description | Values |
|-----------|-------------|--------|
| `SIPX Caller Id Type` | Caller ID source | `0`=FROM, `1`=PAI, `2`=RPID, `3`=PAI-FROM, `4`=PAI-RPID-FROM |
| `PX Enable Intercom` | Enable intercom | `0`=Disabled, `1`=Enabled |
| `PX Intercom Mute` | Mute on intercom answer | `0`=Unmuted, `1`=Muted |
| `PX Intercom Tone` | Play tone on intercom | `0`=Silent, `1`=Play tone |
| `PX Intercom Barge` | Allow barge-in | `0`=Disabled, `1`=Enabled |

**Note:** `X` matches the SIP line number (P1 for SIP1, P2 for SIP2, etc.)

### 4.6 Auto-Update Configuration Module

```
<AUTOUPDATE CONFIG MODULE>
PNP Enable         :1
PNP IP             :224.0.1.75
PNP Port           :5060
PNP Transport      :0
PNP Interval       :1
```

#### 4.6.1 Auto-Update Parameters

| Parameter | Description | Values |
|-----------|-------------|--------|
| `PNP Enable` | Enable PnP discovery | `0`=Disabled, `1`=Enabled |
| `PNP IP` | Multicast IP address | `224.0.1.75` (standard) |
| `PNP Port` | PnP port | `5060` |
| `PNP Transport` | Transport protocol | `0`=UDP, `1`=TCP |
| `PNP Interval` | Discovery interval | `1` (minutes) |

### 4.7 Multi-Line Configuration

To configure multiple lines, repeat the SIP configuration block with incremented line numbers:

```
<SIP CONFIG MODULE>
--SIP Line List--  :
SIP1 Enable Reg    :1
SIP1 Phone Number  :201
[...SIP1 parameters...]

SIP2 Enable Reg    :1
SIP2 Phone Number  :202
[...SIP2 parameters...]

<TELE CONFIG MODULE>
SIP1 Caller Id Type:4
P1 Enable Intercom :1
[...P1 parameters...]

SIP2 Caller Id Type:4
P2 Enable Intercom :1
[...P2 parameters...]
```

### 4.8 Parameter Formatting Rules

- **Alignment:** Parameters use colon (`:`) alignment with spaces for visual formatting
- **Spacing:** Variable spacing before colons is for readability only
- **Case Sensitivity:** Parameter names are case-sensitive
- **Empty Values:** Leave value empty but include colon (e.g., `Proxy User    :`)

---

## 5. Common SIP Parameters

### 5.1 Required SIP Credentials

All vendors require these core parameters (with vendor-specific naming):

| Concept | Purpose | Example |
|---------|---------|---------|
| Extension/Number | User's phone number | `201` |
| Display Name | Caller ID name | `John Smith` |
| Username | SIP registration username | `201` |
| Password | SIP authentication password | `secretpass123` |
| SIP Server | Server IP or hostname | `172.16.32.153` |
| SIP Port | Server port | `5060` |

### 5.2 Transport Protocols

| Protocol | SNOM | Yealink | Fanvil | Use Case |
|----------|------|---------|--------|----------|
| UDP | Default | `0` | `0` | Standard SIP |
| TCP | Supported | `1` | `1` | Firewall-friendly |
| TLS | `srtp: on` | `2` | Not standard | Encrypted SIP |

### 5.3 Codec Configuration

Common audio codecs supported across vendors:

| Codec | Description | Bandwidth | Quality |
|-------|-------------|-----------|---------|
| PCMU (G.711μ) | Uncompressed | 64 kbps | Excellent |
| PCMA (G.711A) | Uncompressed | 64 kbps | Excellent |
| G722 | Wideband | 64 kbps | High |
| G729 | Compressed | 8 kbps | Good |

### 5.4 Caller ID Priority

All vendors support multiple Caller ID sources with priority ordering:

**Headers (in priority order):**
1. **PAI** (P-Asserted-Identity) - RFC 3325
2. **RPID** (Remote-Party-ID) - RFC 3261
3. **FROM** (From header) - RFC 3261

**Recommended Configuration:** PAI-RPID-FROM (checks PAI first, falls back to RPID, then FROM)

### 5.5 Voicemail Configuration

All vendors support voicemail access codes:
- Configure as dial string (e.g., `*97`, `*98`)
- Phone displays MWI (Message Waiting Indicator) lamp
- One-button access to voicemail system

---

## 6. Configuration File Examples

### 6.1 SNOM Complete Example

**Filename:** `0004131234ab.xml`

```xml
<?xml version="1.0" encoding="utf-8"?>
<settings>
    <phone-settings>
        <!-- Line 1 Configuration -->
        <user_pname idx="1" perm="RW">201</user_pname>
        <user_name idx="1" perm="RW">201</user_name>
        <user_realname idx="1" perm="RW">John Smith</user_realname>
        <user_pass idx="1" perm="RW">Kj8mP3nQ9sL2wX</user_pass>
        <user_host idx="1" perm="RW">172.16.32.153</user_host>
        <user_srtp idx="1" perm="RW">off</user_srtp>
        <user_mailbox idx="1" perm="RW">*97</user_mailbox>
        <user_dp_str idx="1" perm="RW">!([^#]%2b)#!sip:\1@\d!d</user_dp_str>
        <contact_source_sip_priority idx="INDEX" perm="PERMISSIONFLAG">PAI RPID FROM</contact_source_sip_priority>

        <!-- Phone Settings -->
        <answer_after_policy perm="RW">idle</answer_after_policy>
        <timezone perm="RW">EST-5</timezone>
        <ntp_server perm="RW">pool.ntp.org</ntp_server>
    </phone-settings>
</settings>
```

### 6.2 Yealink Single-Line Example

**Filename:** `44dbd2123456.txt`

```
#!version:1.0.0.1

# Account Configuration
account.1.enable = 1
account.1.label = Office (201)
account.1.display_name = John Smith
account.1.auth_name = 201
account.1.user_name = 201
account.1.password = Kj8mP3nQ9sL2wX
account.1.sip_server_host = 172.16.32.153
account.1.sip_server_port = 5060
account.1.transport = 0

# Codec Configuration
account.1.codec.1.enable = 1
account.1.codec.1.payload_type = PCMU
account.1.codec.1.priority = 1
account.1.codec.1.rtpmap = 0

account.1.codec.2.enable = 1
account.1.codec.2.payload_type = PCMA
account.1.codec.2.priority = 2
account.1.codec.2.rtpmap = 8

# Caller ID
account.1.cid_source = 4

# Voicemail
voice_mail.number.1 = *97

# Display Settings
phone_setting.lcd_logo.mode = 0

# Auto-Provisioning
auto_provision.dhcp_option.enable = 0

# Intercom Features
features.intercom.allow = 1
features.intercom.mute = 0
features.intercom.tone = 1
features.intercom.barge = 1

# Transfer
features.dtmf.transfer = ##
features.dtmf.replace_tran = 1

# Headset
features.headset_prior = 1

# Time Configuration
local_time.time_zone = -5
local_time.time_zone_name = US-Eastern
static.auto_provision.ntp_server1 = pool.ntp.org
```

### 6.3 Yealink DECT Multi-Line Example (W52P)

**Filename:** `44dbd2abcdef.txt`

```
#!version:1.0.0.1

# Account 1 - Handset 1
account.1.enable = 1
account.1.label = Handset 1 (201)
account.1.display_name = John Smith
account.1.auth_name = 201
account.1.user_name = 201
account.1.password = Kj8mP3nQ9sL2wX
account.1.sip_server_host = 172.16.32.153
account.1.sip_server_port = 5060
account.1.transport = 0
account.1.codec.1.enable = 1
account.1.codec.1.payload_type = PCMU
account.1.codec.1.priority = 1
account.1.codec.1.rtpmap = 0
account.1.cid_source = 4
voice_mail.number.1 = *97

# Account 2 - Handset 2
account.2.enable = 1
account.2.label = Handset 2 (202)
account.2.display_name = Jane Doe
account.2.auth_name = 202
account.2.user_name = 202
account.2.password = mR7bT4pN3kW9zY
account.2.sip_server_host = 172.16.32.153
account.2.sip_server_port = 5060
account.2.transport = 0
account.2.codec.1.enable = 1
account.2.codec.1.payload_type = PCMU
account.2.codec.1.priority = 1
account.2.codec.1.rtpmap = 0
account.2.cid_source = 4
voice_mail.number.2 = *97

# Account 3 - Handset 3
account.3.enable = 1
account.3.label = Handset 3 (203)
account.3.display_name = Bob Johnson
account.3.auth_name = 203
account.3.user_name = 203
account.3.password = xL9pQ2wN8mK5tS
account.3.sip_server_host = 172.16.32.153
account.3.sip_server_port = 5060
account.3.transport = 0
account.3.codec.1.enable = 1
account.3.codec.1.payload_type = PCMU
account.3.codec.1.priority = 1
account.3.codec.1.rtpmap = 0
account.3.cid_source = 4
voice_mail.number.3 = *97

# Account 4 - Handset 4
account.4.enable = 1
account.4.label = Handset 4 (204)
account.4.display_name = Alice Brown
account.4.auth_name = 204
account.4.user_name = 204
account.4.password = vZ6nM4kT8pL3qR
account.4.sip_server_host = 172.16.32.153
account.4.sip_server_port = 5060
account.4.transport = 0
account.4.codec.1.enable = 1
account.4.codec.1.payload_type = PCMU
account.4.codec.1.priority = 1
account.4.codec.1.rtpmap = 0
account.4.cid_source = 4
voice_mail.number.4 = *97

# Account 5 - Handset 5
account.5.enable = 1
account.5.label = Handset 5 (205)
account.5.display_name = Charlie Davis
account.5.auth_name = 205
account.5.user_name = 205
account.5.password = bN8tR3kM7wP5xQ
account.5.sip_server_host = 172.16.32.153
account.5.sip_server_port = 5060
account.5.transport = 0
account.5.codec.1.enable = 1
account.5.codec.1.payload_type = PCMU
account.5.codec.1.priority = 1
account.5.codec.1.rtpmap = 0
account.5.cid_source = 4
voice_mail.number.5 = *97

# Handset Display Names
handset.1.name = John Smith
handset.2.name = Jane Doe
handset.3.name = Bob Johnson
handset.4.name = Alice Brown
handset.5.name = Charlie Davis

# Auto-Provisioning
auto_provision.dhcp_option.enable = 0

# Intercom Features
features.intercom.allow = 1
features.intercom.mute = 0
features.intercom.tone = 1
features.intercom.barge = 1

# Transfer
features.dtmf.transfer = ##
features.dtmf.replace_tran = 1

# Headset
features.headset_prior = 1
```

### 6.4 Fanvil Single-Line Example

**Filename:** `0c383e789012.txt`

```
<<VOIP CONFIG FILE>>Version:2.0002

<SIP CONFIG MODULE>
--SIP Line List--  :
SIP1 Enable Reg    :1
SIP1 Phone Number  :201
SIP1 Display Name  :John Smith
SIP1 Sip Name      :201
SIP1 Register Addr :172.16.32.153
SIP1 Register Port :5060
SIP1 Register User :201
SIP1 Register Pswd :Kj8mP3nQ9sL2wX
SIP1 MWI Num       :*97
SIP1 Proxy User    :
SIP1 Proxy Pswd    :
SIP1 Proxy Addr    :

<TELE CONFIG MODULE>
SIP1 Caller Id Type:4
P1 Enable Intercom :1
P1 Intercom Mute   :0
P1 Intercom Tone   :1
P1 Intercom Barge  :1

<AUTOUPDATE CONFIG MODULE>
PNP Enable         :1
PNP IP             :224.0.1.75
PNP Port           :5060
PNP Transport      :0
PNP Interval       :1

<<END OF FILE>>
```

### 6.5 Fanvil Multi-Line Example

**Filename:** `0c383e123456.txt`

```
<<VOIP CONFIG FILE>>Version:2.0002

<SIP CONFIG MODULE>
--SIP Line List--  :
SIP1 Enable Reg    :1
SIP1 Phone Number  :201
SIP1 Display Name  :John Smith
SIP1 Sip Name      :201
SIP1 Register Addr :172.16.32.153
SIP1 Register Port :5060
SIP1 Register User :201
SIP1 Register Pswd :Kj8mP3nQ9sL2wX
SIP1 MWI Num       :*97
SIP1 Proxy User    :
SIP1 Proxy Pswd    :
SIP1 Proxy Addr    :

SIP2 Enable Reg    :1
SIP2 Phone Number  :202
SIP2 Display Name  :Jane Doe
SIP2 Sip Name      :202
SIP2 Register Addr :172.16.32.153
SIP2 Register Port :5060
SIP2 Register User :202
SIP2 Register Pswd :mR7bT4pN3kW9zY
SIP2 MWI Num       :*97
SIP2 Proxy User    :
SIP2 Proxy Pswd    :
SIP2 Proxy Addr    :

<TELE CONFIG MODULE>
SIP1 Caller Id Type:4
P1 Enable Intercom :1
P1 Intercom Mute   :0
P1 Intercom Tone   :1
P1 Intercom Barge  :1

SIP2 Caller Id Type:4
P2 Enable Intercom :1
P2 Intercom Mute   :0
P2 Intercom Tone   :1
P2 Intercom Barge  :1

<AUTOUPDATE CONFIG MODULE>
PNP Enable         :1
PNP IP             :224.0.1.75
PNP Port           :5060
PNP Transport      :0
PNP Interval       :1

<<END OF FILE>>
```

---

## 7. Configuration Validation

### 7.1 SNOM Validation Checklist

- [ ] XML declaration present with UTF-8 encoding
- [ ] Root `<settings>` element present
- [ ] `<phone-settings>` container present
- [ ] All indexed parameters have `idx` attribute
- [ ] All parameters have `perm` attribute
- [ ] XML is well-formed (no unclosed tags)
- [ ] Special characters in values are XML-escaped

### 7.2 Yealink Validation Checklist

- [ ] Version header `#!version:1.0.0.1` present as first line
- [ ] All account parameters use correct index numbers
- [ ] Transport value is 0, 1, or 2
 match payload typesalues
- [ ] Boolean values are 0 or 1
- [ ] Line endings are CRLF or LF

### 7.3 Fanvil Validation Checklist

- [ ] Header `<<VOIP CONFIG FILE>>Version:2.0002` present
- [ ] Footer `<<END OF FILE>>` present
- [ ] All module sections present: SIP, TELE, AUTOUPDATE
- [ ] Module headers use format `<MODULE NAME>`
- [ ] `--SIP Line List--  :` separator present
- [ ] Parameter spacing matches format (spaces before colon)
- [ ] All parameters end with colon
- [ ] Line numbers match between SIP and TELE modules (SIP1 → P1)

---

## 8. Parameter Reference Summary

### 8.1 Cross-Vendor Parameter Mapping

| Concept | SNOM | Yealink | Fanvil |
|---------|------|---------|--------|
| Extension | `user_pname` | `account.X.user_name` | `SIPX Phone Number` |
| Display Name | `user_realname` | `account.X.display_name` | `SIPX Display Name` |
| Auth Username | `user_name` | `account.X.auth_name` | `SIPX Register User` |
| SIP Username | `user_name` | `account.X.user_name` | `SIPX Sip Name` |
| Password | `user_pass` | `account.X.password` | `SIPX Register Pswd` |
| SIP Server | `user_host` | `account.X.sip_server_host` | `SIPX Register Addr` |
| SIP Port | N/A (default) | `account.X.sip_server_port` | `SIPX Register Port` |
| Voicemail | `user_mailbox` | `voice_mail.number.X` | `SIPX MWI Num` |
| Caller ID Source | `contact_source_sip_priority` | `account.X.cid_source` | `SIPX Caller Id Type` |

### 8.2 Transport Protocol Values

| Protocol | SNOM | Yealink | Fanvil |
|----------|------|---------|--------|
| UDP | Default | `0` | `0` |
| TCP | XML config | `1` | `1` |
| TLS | SRTP setting | `2` | N/A |

### 8.3 Caller ID Source Values

| Priority | SNOM | Yealink | Fanvil |
|----------|------|---------|--------|
| PAI-RPID-FROM | `PAI RPID FROM` | `4` | `4` |
| PAI-FROM | `PAI FROM` | `2` | `3` |
| PAI only | `PAI` | `1` | `1` |
| RPID only | `RPID` | N/A | `2` |
| FROM only | `FROM` | `0` | `0` |

---

## Appendix A: Advanced Configuration Examples

### A.1 SNOM with Multiple Lines

```xml
<?xml version="1.0" encoding="utf-8"?>
<settings>
    <phone-settings>
        <!-- Line 1 -->
        <user_pname idx="1" perm="RW">201</user_pname>
        <user_name idx="1" perm="RW">201</user_name>
        <user_realname idx="1" perm="RW">John Smith</user_realname>
        <user_pass idx="1" perm="RW">pass1</user_pass>
        <user_host idx="1" perm="RW">172.16.32.153</user_host>
        <user_srtp idx="1" perm="RW">off</user_srtp>
        <user_mailbox idx="1" perm="RW">*97</user_mailbox>

        <!-- Line 2 -->
        <user_pname idx="2" perm="RW">202</user_pname>
        <user_name idx="2" perm="RW">202</user_name>
        <user_realname idx="2" perm="RW">Jane Doe</user_realname>
        <user_pass idx="2" perm="RW">pass2</user_pass>
        <user_host idx="2" perm="RW">172.16.32.153</user_host>
        <user_srtp idx="2" perm="RW">off</user_srtp>
        <user_mailbox idx="2" perm="RW">*97</user_mailbox>

        <!-- Common Settings -->
        <contact_source_sip_priority idx="INDEX" perm="PERMISSIONFLAG">PAI RPID FROM</contact_source_sip_priority>
        <answer_after_policy perm="RW">idle</answer_after_policy>
        <timezone perm="RW">EST-5</timezone>
        <ntp_server perm="RW">pool.ntp.org</ntp_server>
    </phone-settings>
</settings>
```

### A.2 Yealink with Multiple Codecs

```
#!version:1.0.0.1

account.1.enable = 1
account.1.label = Office (201)
account.1.display_name = John Smith
account.1.auth_name = 201
account.1.user_name = 201
account.1.password = secretpass
account.1.sip_server_host = 172.16.32.153
account.1.sip_server_port = 5060
account.1.transport = 0

# Codec 1: PCMU (highest priority)
account.1.codec.1.enable = 1
account.1.codec.1.payload_type = PCMU
account.1.codec.1.priority = 1
account.1.codec.1.rtpmap = 0

# Codec 2: PCMA
account.1.codec.2.enable = 1
account.1.codec.2.payload_type = PCMA
account.1.codec.2.priority = 2
account.1.codec.2.rtpmap = 8

# Codec 3: G722 Wideband
account.1.codec.3.enable = 1
account.1.codec.3.payload_type = G722
account.1.codec.3.priority = 3
account.1.codec.3.rtpmap = 9

# Codec 4: G729 (lowest priority)
account.1.codec.4.enable = 1
account.1.codec.4.payload_type = G729
account.1.codec.4.priority = 4
account.1.codec.4.rtpmap = 18

account.1.cid_source = 4
voice_mail.number.1 = *97
auto_provision.dhcp_option.enable = 0
```

### A.3 Yealink with TLS Encryption

```
#!version:1.0.0.1

account.1.enable = 1
account.1.label = Secure Office (201)
account.1.display_name = John Smith
account.1.auth_name = 201
account.1.user_name = 201
account.1.password = secretpass
account.1.sip_server_host = 172.16.32.153
account.1.sip_server_port = 5061
account.1.transport = 2

account.1.codec.1.enable = 1
account.1.codec.1.payload_type = PCMU
account.1.codec.1.priority = 1
account.1.codec.1.rtpmap = 0

account.1.cid_source = 4
voice_mail.number.1 = *97

# TLS Configuration
security.trust_certificates = 1
```

---

## Document Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-01-04 | Technical Documentation | Initial general specification for IP phone configuration files |

---

**End of Document**
