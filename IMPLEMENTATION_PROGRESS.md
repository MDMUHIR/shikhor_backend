# LMS backend – implementation progress

Status: core backend complete. `php artisan test` → 17 passed. Migrations applied to the dev MySQL DB.

## Done
- **Schema** (`2026_11_21_000001_upgrade_lms_schema`, `notifications` table): payments, payment_events (unique per provider+event), refunds, coupon_usages, coupon_courses/categories, lesson_resources, review status, progress/enrollment fields, indexes.
- **Pricing/orders**: discounts and prices are computed server-side only (client `discount`, item `price`, `delivery_charge` for course orders ignored). Coupons validated by `CouponService` (active, dates, usage/per-user limits, min purchase, max discount, course/category scope), redeemed atomically, released on refund/cancel. `Idempotency-Key` header de-duplicates orders. Re-buying an active course is rejected. Random order numbers (no max(id) race).
- **Payments** (`PaymentService`, `PaymentController`): Stripe Checkout session creation, HMAC-verified webhook with replay tolerance, event de-duplication, amount/currency/order-total verification, row locks, idempotent fulfilment (order paid → enrollment + notifications). Manual/offline confirmation by admin. Full/partial refunds (Stripe refund API when provider is stripe); full refund revokes access and releases coupon. Free orders (total 0) auto-fulfil.
- **Enrollment**: only granted after verified payment (`COURSE_ENROLL_ON_ORDER_PLACEMENT` now defaults to false). Statuses: active/completed/expired/suspended/cancelled.
- **Progress**: server-side resume position clamp, max position/watch time, completion threshold, enrollment progress %, last lesson, completion rules (required lessons, % required, required quizzes passed), auto certificate issuance (unique per user+course, DB constraint).
- **Quizzes**: scoring was already server-side; answer key now withheld until pass/last attempt; passing syncs course progress.
- **Reviews**: status pending/approved/rejected, only approved count toward rating; edits re-enter moderation when `COURSE_REVIEWS_AUTO_APPROVE=false`.
- **Videos**: upload (private disk, signed stream URL, S3 redirect via temporary URL), YouTube (id parsed/validated, embed via youtube-nocookie), external https only. Lesson resources (private files + https links, access-checked download).
- **Roles**: `staff` middleware (admin + instructor). Instructors manage only their own courses/sections/lessons/quizzes/resources; cannot self-publish (→ `under_review`). Admin-only: payments, refunds, enrollments, reviews, certificates, users, coupons, categories. Deactivated users cannot log in.
- **Other**: notifications API, student dashboard, admin/instructor analytics, certificate numbers non-guessable, public verify throttled.

## Not done / remaining
- Product (physical) delivery charge is still taken from the client for mixed/product orders (outside LMS scope).
- No transcoding/HLS pipeline; large uploads go through the app server.
- Quiz time limit is not enforced server-side (no attempt-start endpoint); shuffle flags are stored but unused.
- Stripe is the only online gateway; other gateways need an adapter in `PaymentService`.
- Dev DB only: not tested against production data volumes. `API.md`/Postman not regenerated.
- Queue: notifications are queued (`QUEUE_CONNECTION=sync` locally); run a worker in production.

## Environment variables
`FRONTEND_URL`, `PAYMENT_PROVIDER` (manual|stripe), `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYMENT_WEBHOOK_TOLERANCE`, `COURSE_ENROLL_ON_ORDER_PLACEMENT` (false), `COURSE_REVIEWS_AUTO_APPROVE`, `COURSE_VIDEO_DISK`, `COURSE_RESOURCE_DISK`, `COURSE_VIDEO_STREAM_TTL`, `QUEUE_CONNECTION` (database/redis in prod).

## Frontend API changes
- Orders: send `pricing.coupon_code` only; never rely on client discounts. Response `payment_status` is `pending` until paid. Optional `Idempotency-Key` header.
- New: `POST orders/{id}/pay {provider}` → `checkout_url`; `GET my/payments`; `GET my/dashboard`; `GET/POST notifications…`; `GET lesson-resources/{id}/download`.
- Webhook (gateway-facing): `POST webhooks/payments/stripe`.
- Admin: `GET admin/payments`, `GET admin/payments/{id}`, `POST admin/orders/{id}/confirm-payment`, `POST admin/payments/{id}/refund {amount?, reason?}`, `GET admin/analytics`, `lesson-resources` add/delete, `GET admin/lessons/{id}/resources`. Coupon add/update accept the new fields (`starts_at`, `expires_at`, `usage_limit`, `per_user_limit`, `min_purchase`, `max_discount`, `applies_to`, `course_ids`, `course_category_ids`).
- Lessons: `video_source` may be `youtube` (video payload has `video_id`, `embed_url`); `url` fields must be https. New `completion_threshold`, `is_required`.
- Courses: new fields (instructor, discount window, tags, certificate settings, SEO); statuses `draft|under_review|published|unpublished|archived`.
- Reviews: responses include `status`; `is_approved` kept for compatibility.
- Enrollment status `revoked` is now `cancelled`; `completed` is an active state.
- Validation errors from new endpoints use Laravel's 422 format.

## Exams (dedicated, auto-marked)
- Tables `exams`, `exam_questions`, `exam_attempts` (migration `2026_11_23_000001`). `ExamService` does all marking server-side.
- Negative marking is set per exam by the admin: off / fraction of the question marks (0–1) / fixed marks, optional per-question override, optional "allow total below zero". Rules are snapshotted into each attempt, and questions lock once students have attempted.
- Server-kept timer (deadline stored at start, 20 s grace on the final submit), autosave endpoint, one live attempt per student (unique `active_key`), max attempts, open/close window, course-enrollment gating, `show_answers` policy.
- Student API: `GET exams`, `GET exams/{id}`, `POST exams/{id}/start`, `GET exam-attempts/{id}`, `POST exam-attempts/{id}/answers`, `POST exam-attempts/{id}/submit`, `GET exams/{id}/attempts`.
- Admin/instructor API: `admin/exams` (index/show/add/update/delete), `admin/exam-questions/*`, `GET admin/exams/{id}/attempts` (results + stats).
- Scheduler: `exams:finalize-expired` runs every minute (needs `php artisan schedule:work` / cron `schedule:run`).

## Course community & live classes
- Tables: live_sessions, course_posts, comments (announcement comments + live chat), likes, community_mutes (migration 2026_11_26).
- Staff (admin / the course's instructor) post announcements (pin, link, notify, lock comments), schedule/edit/cancel/start/end live classes (Zoom, Meet, YouTube Live, Facebook, Teams...), pin or delete chat messages, lock chat, mute students. Enrolled students read, like, comment/reply and chat. Join links are private to enrolled students + staff.
- Live chat is polled (GET live-sessions/{id}/chat?after=<id>, ~2.5 s while live); no websocket server needed. For very large classes, front it with Laravel Reverb later.
