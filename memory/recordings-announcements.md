# Recordings / Announcements

## Overview
Audio file management for IVR prompts and announcements. Supports upload to MinIO (S3-compatible) and remote URL references. Secure access via encrypted tokens and HMAC-signed URLs.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/RecordingsController.php` | CRUD + download + secure serving (577 lines) |
| `app/Models/Recording.php` | Recording model (236 lines) |
| `app/Services/Recording/RecordingUploadService.php` | Upload pipeline with security validation (381 lines) |
| `app/Services/Recording/RecordingAccessService.php` | Token-based access control (182 lines) |
| `app/Services/Recording/RecordingRemoteService.php` | Remote URL validation |
| `app/Jobs/ProcessRecordingUpload.php` | Post-upload processing |
| `app/Jobs/ValidateRemoteUrl.php` | Async remote URL validation |
| `frontend/src/pages/Announcements.tsx` | Recordings management page |

## Two Recording Types
| Type | Storage | Access |
|------|---------|--------|
| upload | MinIO bucket (`recordings/{org_id}/{random}_{name}.{ext}`) | Encrypted token or HMAC-signed URL |
| remote | External URL reference | Direct URL |

## Upload Security Pipeline (RecordingUploadService)
1. Size validation (configurable, default 5MB)
2. MIME whitelist (audio/mpeg, audio/wav)
3. Extension whitelist (.mp3, .wav)
4. Binary file signature verification (first 12 bytes)
5. Script injection detection
6. Filename sanitization (blocks traversal, null bytes, reserved names)

## Access Control
- **Internal access** (RecordingsController:214): Generates encrypted token (AES-256-CBC) with `{recording_id, org_id, user_id, expires_at}`. Default 30min expiry.
- **External access** (RecordingsController:401, `serveMinioFile`): HMAC-signed URLs for Cloudonix. Signature = `HMAC-SHA256("{path}|{expires}", APP_KEY)`. 5min cache.
- **Secure deletion** (configurable): Overwrites first 1KB with random data before unlinking.

## API Routes
| Method | URI | Purpose |
|--------|-----|---------|
| Standard CRUD | `/v1/recordings[/{recording}]` | apiResource |
| GET | `/v1/recordings/{recording}/download` | Generate access token |
| GET | `/v1/recordings/secure-download` | Serve file via token |
| GET | `/recordings/serve/{path}` | Public HMAC-signed access (for Cloudonix) |

## Related Modules
- [IVR Menus](ivr-menus.md) - Audio source for IVR prompts
- [Infrastructure](infrastructure-docker.md) - MinIO storage service
