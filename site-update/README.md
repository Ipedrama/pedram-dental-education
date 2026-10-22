# Support the requested eight-day batch

This small update applies to the already installed v7.4.7 site. It accepts batches containing seven or eight articles and displays the actual count in the approval form. Existing drafts, edits, approvals and scheduling rules are preserved. No article is approved by this update.

Back up the two existing files, then replace these two files in public_html with the matching files in this folder:

- includes/education-queue.php
- admin/education-admin.php

Do not upload this README or any test files. Do not replace configuration, uploads, or admin education JSON data files.

Then open the authenticated /admin/ page and choose «دریافت بسته‌های جدید». The week-2026-10-23 batch contains eight drafts for 23–30 October 2026 (۱ تا ۸ آبان ۱۴۰۵). Review them before approving. The approval start time must be in the future and must not overlap other scheduled articles.

Deployment of these two files has not been performed by the assistant. The original seven-article importer will reject this eight-article batch until updated.
