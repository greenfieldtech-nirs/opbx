---
sidebar_position: 4
title: Audio Recordings
description: Managing announcements and audio files
---

# Audio Recordings

Audio Recordings store audio files used for IVR prompts, announcements, and hold music. Upload your own recordings or reference external URLs for flexible audio management.

## Recording Types

OPBX supports two types of audio recordings:

| Type | Storage | Access Method |
|------|---------|---------------|
| Upload | MinIO (S3-compatible) internal storage | Encrypted token + HMAC-signed URLs |
| Remote | External URL reference | Direct URL access |

### Upload Recordings

Files uploaded to OPBX storage benefit from:

- Secure internal storage
- Time-limited access URLs
- Automatic validation and sanitization
- Version control

### Remote Recordings

External URLs provide flexibility for:

- Content Delivery Networks (CDNs)
- Third-party audio services
- Dynamic content generation

:::tip
Use remote recordings when you need to update audio content frequently without accessing OPBX.
:::

## Supported Formats

| Format | Extension | Recommended Use |
|--------|-----------|-----------------|
| MP3 | .mp3 | General purpose, smaller file size |
| WAV | .wav | Higher quality, IVR prompts |

Maximum file size: 5MB (configurable by your administrator).

## Uploading Audio Files

1. Navigate to **Recordings** or **Announcements** in the sidebar
2. Click **Upload**
3. Select an audio file from your computer
4. Enter a descriptive name
5. Click **Save**

:::note
The upload process includes a validation pipeline to ensure file safety and compatibility.
:::

## Security Validation

All uploaded files pass through a security pipeline:

| Check | Purpose |
|-------|---------|
| MIME Type Check | Verifies the file content matches the declared format |
| Extension Check | Validates the file extension is allowed |
| Binary Signature | Confirms the file is a valid audio file, not a disguised script |
| Script Injection Detection | Scans for embedded malicious code |
| Filename Sanitization | Removes dangerous characters from filenames |

:::warning
Files that fail validation are rejected. If your legitimate file is rejected, try converting it to a standard format or contact your administrator.
:::

## Using Recordings

Once uploaded, use recordings throughout OPBX:

| Feature | How to Select |
|---------|---------------|
| IVR Menus | Choose from the audio dropdown when creating menu options |
| Hold Music | Select as the music source in queue settings |
| Announcements | Assign to time-based or event-based announcements |

## Downloading Recordings

To download a recording:

1. Navigate to the Recordings list
2. Find the recording you need
3. Click the **Download** button
4. The system generates a time-limited access URL
5. Your browser downloads the file

:::info
Download URLs expire after a short time for security. If the link expires, request a new download.
:::

## Managing Remote Recordings

When using remote recordings:

1. Enter a descriptive name
2. Provide the full URL to the audio file
3. Ensure the URL is publicly accessible
4. Test the recording to verify it plays correctly

:::warning
Remote recordings depend on external availability. If the external service is down, the audio will not play.
:::

## Best Practices

### File Naming

Use descriptive names that indicate the content and purpose:

- "Main_Menu_Welcome_v2"
- "Support_Hold_Music"
- "After_Hours_Message"

### Audio Quality

| Use Case | Recommended Format | Bitrate |
|----------|-------------------|---------|
| IVR Prompts | WAV | 128 kbps |
| Hold Music | MP3 | 192 kbps |
| Announcements | MP3 | 128 kbps |

### Storage Management

- Delete unused recordings to free storage space
- Use version numbers in filenames when updating (e.g., "Welcome_v2.mp3")
- Document which recordings are used where for easier maintenance

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Upload fails | Check file size (max 5MB) and format (MP3/WAV) |
| Recording does not play | Verify the file is not corrupted; try re-uploading |
| Remote recording fails | Check the URL is accessible and returns audio content |
| Poor audio quality | Use higher bitrate files or WAV format |
