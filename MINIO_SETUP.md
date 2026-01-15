# MinIO Setup Instructions

## Issue
The `recordings` bucket must exist in MinIO for file uploads to work.

## One-Time Setup

After starting the containers, create the required bucket:

```bash
# Create the recordings bucket
docker compose exec minio mc alias set local http://localhost:9000 minioadmin minioadmin
docker compose exec minio mc mb local/recordings
```

## Verification

Test that storage works:

```bash
docker compose exec app php artisan tinker --execute="Storage::disk('recordings')->put('test.txt', 'test'); echo Storage::disk('recordings')->exists('test.txt') ? 'OK' : 'FAILED';"
```

## Future Improvement

TODO: Create an artisan command `php artisan storage:init` that automatically creates required MinIO buckets.

