# Weekly synchronization repair (7.4.10)

The includes/education-queue.php file is now based on the installed 7.4.9 calendar/approval implementation. It preserves manual edits, deleted items, English revision binding and approval rules while adding a second official GitHub endpoint, compressed transfer, bounded downloads and useful network diagnostics. TLS verification stays enabled.

The screenshot's generic download error means the download failed or returned a non-200 response. The exact production network cause is not yet known. The public inbox was fetched successfully from the development environment; its 3.84 MB size is below the existing 25 MB cap.

For an existing 7.4.9 installation, back up includes/education-queue.php outside public_html, then replace only that file. Do not install the older admin/education-admin.php in this folder over a newer production admin. Do not replace configuration, uploads, education-queue.json or education-overrides.json.

Optional offline recovery: place a valid inbox snapshot at includes/education-inbox.json. The authenticated admin's existing receive action imports missing batch IDs from that snapshot before trying the network. Imported IDs are immutable. Existing edits and approvals stay authoritative. Every new article is forced to draft, with no proposed publish_at. If the snapshot adds nothing, normal network synchronization continues.

The user-facing installation archive prepared on 2026-11-06 includes snapshots of week-2026-10-30 and week-2026-11-06 only (14 unchanged drafts), excluding the withdrawn legacy batch. It is not a production deployment.

Validation: PHP syntax and execution; both snapshots pass the site's original content/image validator; offline sync imports two batches; repeat import adds zero batches and leaves queue bytes unchanged; manual edits and approval overrides are unchanged; malformed input leaves the queue unchanged. Production host connectivity and live admin import have not been verified. No article has been approved or published by this repair.
