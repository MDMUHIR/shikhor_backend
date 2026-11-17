# Course Platform API

This document covers the coaching-center layer added on top of the existing store API:
courses, sections, lessons, enrollments, progress, reviews, quizzes and certificates.
Everything else (products, variants, cart, orders, coupons, blogs, banners) keeps working
exactly as before.

All endpoints live under `/api`.

---

## 1. How it works

```
course_categories
  └── courses  (status, price, offer_price, access_days)
        ├── course_sections
        │     └── lessons   (video | text | quiz)
        ├── quizzes
        │     └── quiz_questions
        │           └── quiz_attempts
        ├── enrollments   (per student, expiry date)
        │     └── lesson_progress
        ├── course_reviews
        └── certificates
```

### Time-limited access

* `courses.access_days` controls how long a student keeps access after purchase.
  * `null` → use `COURSE_DEFAULT_ACCESS_DAYS` (default `365`).
  * `0` → lifetime access (`expires_at = null`).
  * `N` → access ends `N` days after purchase.
* An enrollment stores `starts_at` and `expires_at`. Expired enrollments are marked
  `expired` automatically the next time access is checked.
* Admins can extend, revoke and re-activate any enrollment.

### When is a student enrolled?

* `COURSE_ENROLL_ON_ORDER_PLACEMENT=true` (default) → access is granted immediately when
  the order is placed. Convenient while no payment gateway is wired up.
* `COURSE_ENROLL_ON_ORDER_PLACEMENT=false` → access is granted only when an admin sets the
  order `payment_status` to `paid`.
* Cancelling / refunding / returning an order revokes order-based access automatically.
  Manual grants (admin `enrollments/grant`) are never revoked by orders.

### Videos

Each lesson can be:

| `type` | Meaning |
| --- | --- |
| `video` | `video_source = external` (streaming URL) or `upload` (file on the API server) |
| `text` | Rich text lesson (`content`) |
| `quiz` | Practical quiz, linked to a `Quiz` row |

* External: `video_url` is returned to enrolled students only.
* Uploaded: the file is stored on the `COURSE_VIDEO_DISK` disk (default `local`, i.e.
  `storage/app/private/courses/{courseId}/lessons/{lessonId}/...`) and is **never** exposed
  directly. Students receive a temporary signed URL (default 60 minutes) pointing to
  `GET /api/lessons/{lesson}/stream`.

---

## 2. Configuration

`.env`:

```dotenv
COURSE_DEFAULT_ACCESS_DAYS=365
COURSE_ENROLL_ON_ORDER_PLACEMENT=true
COURSE_VIDEO_DISK=local
COURSE_VIDEO_MAX_SIZE_KB=524288
COURSE_VIDEO_STREAM_TTL=60
COURSE_CERTIFICATE_PROVIDER="Coaching Center"
COURSE_CERTIFICATE_SIGNATURE="Director"
```

Setup:

```bash
composer install
php artisan migrate
php artisan config:clear
```

Uploaded videos are private by design – no `storage:link` is required. If you switch
`COURSE_VIDEO_DISK` to `s3`, make sure the bucket is private.

Response envelope for every endpoint:

```json
{ "status": true, "message": "…", "data": {} }
```

Authenticated requests use the same Sanctum bearer token as the rest of the API:

```
Authorization: Bearer <token>
Accept: application/json
```

---

## 3. Public endpoints

| Method | URI | Description |
| --- | --- | --- |
| GET | `/api/course-categories` | Active categories with published course counts |
| GET | `/api/courses` | Published course catalog (paginated) |
| GET | `/api/courses/{slug}` | Course detail with curriculum (locked lessons hide content) |
| GET | `/api/courses/{slug}/reviews` | Approved reviews + rating summary (paginated) |
| GET | `/api/certificates/verify/{number}` | Public certificate verification |
| GET | `/api/lessons/{lesson}/stream` | Signed video stream (only via generated URL) |

### `GET /api/courses`

Query params:

| Param | Values |
| --- | --- |
| `search` | matches title, subtitle, description |
| `category` | category slug or id |
| `level` | `all`, `beginner`, `intermediate`, `advanced` |
| `featured` | `1` |
| `sort` | `latest`, `price_asc`, `price_desc`, `rating`, `popular` |
| `per_page` | 1–100 (default 12) |

Each course includes `price`, `offer_price`, `effective_price`, `access_days`,
`effective_access_days`, `access_type` (`time_limited` / `lifetime`), `rating.average`,
`rating.count`, `total_lessons`, `total_students`, `category`.

### `GET /api/courses/{slug}`

Adds `sections[].lessons[]`, `quizzes[]` and `reviews_summary`.

Lesson payload (public):

```json
{
  "id": 10,
  "title": "Listening overview",
  "type": "video",
  "duration_seconds": 300,
  "is_preview": false,
  "is_locked": true,
  "is_completed": false,
  "last_position_seconds": 0
}
```

`content`, `video` and `quiz` are only present when the lesson is a free preview or the
caller has access.

### `GET /api/course-categories`

Returns categories ordered by `sort_order`, with `courses_count` (published only).

### `GET /api/courses/{slug}/reviews`

`per_page` (default 10), optional `rating` (1–5). Response:

```json
{
  "summary": { "average": 4.5, "count": 2, "five_star": 1, "four_star": 1, "three_star": 0, "two_star": 0, "one_star": 0 },
  "reviews": { "data": [ { "id": 1, "rating": 5, "title": "Great", "comment": "…", "user": { "id": 3, "name": "Ayesha" } } ] }
}
```

### `GET /api/certificates/verify/{number}`

```json
{
  "valid": true,
  "status": "issued",
  "certificate_number": "CERT-AB12CD-00004-00003",
  "student_name": "Ayesha Rahman",
  "course_title": "IELTS Academic Booster",
  "issued_at": "2026-11-16T10:00:00+00:00",
  "revoked_at": null
}
```

---

## 4. Student endpoints (auth required)

### Course cart

| Method | URI | Body |
| --- | --- | --- |
| GET | `/api/cart/courses` | – |
| POST | `/api/cart/courses/add` | `course_id` or `course_ids[]` |
| POST | `/api/cart/courses/toggle-selection` | `id` |
| POST | `/api/cart/courses/select-all` | – |
| POST | `/api/cart/courses/deselect-all` | – |
| DELETE | `/api/cart/courses/delete/{id}` | – |

Already-enrolled courses (active access) are skipped and reported in `skipped[]`.

### Orders

Course items use the existing order endpoints: `POST /api/orders/add` (selected product
cart **and** selected course cart) and `POST /api/orders/direct` (supports
`items[]` for products and `course_ids[]` for courses).

The order payload now contains `course_items` and pricing split:

```json
{
  "pricing": {
    "subtotal": 4700,
    "products_subtotal": 1200,
    "courses_subtotal": 3500,
    "delivery_charge": 60,
    "discount": 0,
    "total": 4760
  },
  "items": [ … physical products … ],
  "course_items": [
    { "course_id": 7, "name": "IELTS Academic Booster", "unit_price": 3500, "line_total": 3500, "access_days": 30 }
  ]
}
```

Coupons keep working: `discount` is applied to the combined subtotal.

### My learning

| Method | URI | Description |
| --- | --- | --- |
| GET | `/api/my/courses` | Enrolled courses + progress + expiry |
| GET | `/api/my/courses/{slug}` | Enrollment detail + progress + certificate |
| GET | `/api/my/courses/{slug}/learn` | Full curriculum with unlocked content and progress |
| GET | `/api/lessons/{id}` | Single lesson (requires access or preview) |
| POST | `/api/lessons/{id}/complete` | Mark lesson completed |
| POST | `/api/lessons/{id}/incomplete` | Mark lesson incomplete |
| POST | `/api/lessons/{id}/progress` | Save watch position: `{ "position_seconds": 145 }` |

`/my/courses/{slug}/learn` returns:

```json
{
  "access": { "has_access": true, "enrollment": { "status": "active", "is_active": true, "expires_at": "…", "remaining_days": 27 } },
  "progress": { "total_lessons": 12, "completed_lessons": 4, "percentage": 33.33, "is_complete": false, "completed_lesson_ids": [1,2,3,4] },
  "sections": [ { "id": 1, "title": "…", "lessons": [ { "video": { "source": "upload", "stream_url": "https://…" } } ] } ]
}
```

Watch position ≥ `duration_seconds - 5` auto-completes a video lesson.

### Reviews

| Method | URI | Body |
| --- | --- | --- |
| GET | `/api/courses/{slug}/reviews/mine` | – |
| POST | `/api/courses/{slug}/reviews` | `rating` (1–5), `title`, `comment` |
| DELETE | `/api/courses/{slug}/reviews` | – |

Only students with active access can review; one review per student per course
(posting again updates it). Course `rating_avg` / `rating_count` are refreshed automatically.

### Quizzes

| Method | URI | Body |
| --- | --- | --- |
| GET | `/api/quizzes/{id}` | – (questions without correct answers) |
| POST | `/api/quizzes/{id}/submit` | `{ "answers": { "<question_id>": ["b"] } }` |
| GET | `/api/quizzes/{id}/attempts` | – |
| GET | `/api/quiz-attempts/{id}` | – |

Submit response includes the attempt, `passed`, per-question `review` (correct answers),
and the refreshed course progress. Passing a quiz linked to a `quiz` lesson marks that
lesson complete. `max_attempts` is enforced server-side.

Question types: `single_choice`, `multiple_choice`, `true_false`.
Options are `[{ "id": "a", "text": "…" }]`; `correct_answers` is an array of option ids.

### Certificates

| Method | URI | Description |
| --- | --- | --- |
| POST | `/api/courses/{slug}/certificate` | Issue certificate (requires 100% of published lessons completed) |
| GET | `/api/my/certificates` | My certificates |
| GET | `/api/certificates/{number}` | Certificate detail (owner only) |
| GET | `/api/certificates/{number}/download` | PDF download (owner only) |

Certificates are unique per student+course, verifiable publicly and can be revoked by an
admin. The PDF is generated with `dompdf` from
`resources/views/certificates/template.blade.php`.

---

## 5. Admin endpoints (auth + admin user type)

All admin routes are prefixed with `/api/admin`.

### Course categories

| Method | URI | Body |
| --- | --- | --- |
| GET | `/admin/course-categories` | – |
| POST | `/admin/course-categories/add` | `name`, `slug?`, `description?`, `image?` (file or path), `status?`, `sort_order?` |
| POST | `/admin/course-categories/update` | `id` + same fields |
| DELETE | `/admin/course-categories/delete/{id}` | – |

### Courses

| Method | URI | Body |
| --- | --- | --- |
| GET | `/admin/courses` | filters: `search`, `status`, `category_id`, `per_page` |
| GET | `/admin/courses/{id}` | full curriculum + quizzes + review summary |
| POST | `/admin/courses/add` | see below |
| POST | `/admin/courses/update` | `id` + same fields |
| DELETE | `/admin/courses/delete/{id}` | soft delete |

Course fields: `course_category_id`, `title`, `slug`, `subtitle`, `description`,
`thumbnail` (uploaded file or existing path → `images/courses`), `promo_video_url`,
`level`, `language`, `price`, `offer_price`, `access_days`, `status`
(`draft|published|archived`), `is_featured`, `requirements[]`, `outcomes[]`.

### Sections

| Method | URI | Body |
| --- | --- | --- |
| GET | `/admin/courses/{courseId}/sections` | – |
| POST | `/admin/course-sections/add` | `course_id`, `title`, `description?`, `sort_order?` |
| POST | `/admin/course-sections/update` | `id`, `title`, … |
| POST | `/admin/course-sections/reorder` | `sections: [{ id, sort_order }]` |
| DELETE | `/admin/course-sections/delete/{id}` | – |

### Lessons

| Method | URI | Body |
| --- | --- | --- |
| GET | `/admin/courses/{courseId}/lessons` | – |
| GET | `/admin/lessons/{id}` | includes video source/path |
| POST | `/admin/lessons/add` | see below (multipart for uploads) |
| POST | `/admin/lessons/update` | `id` + same fields |
| POST | `/admin/lessons/reorder` | `lessons: [{ id, sort_order, course_section_id? }]` |
| DELETE | `/admin/lessons/delete/{id}` | – |

Lesson fields: `course_id` (add only), `course_section_id`, `title`, `slug`,
`description`, `type` (`video|text|quiz`), `video_source` (`external|upload`),
`video_url`, `video` (file), `duration_seconds`, `content`, `is_preview`, `is_published`,
`sort_order`, `remove_video` (update only).

Upload limits: `COURSE_VIDEO_MAX_SIZE_KB` (default 512 MB), mime types mp4 / webm / mov / mkv.

### Enrollments

| Method | URI | Body |
| --- | --- | --- |
| GET | `/admin/enrollments` | filters: `user_id`, `course_id`, `status`, `per_page` |
| POST | `/admin/enrollments/grant` | `user_id`, `course_id`, `access_days?`, `expires_at?` |
| POST | `/admin/enrollments/extend` | `id`, `days` |
| POST | `/admin/enrollments/revoke/{id}` | – |
| POST | `/admin/enrollments/reactivate/{id}` | – |

Manual grants have `source = manual` and are ignored by order cancellation.

### Reviews

| Method | URI | Body |
| --- | --- | --- |
| GET | `/admin/reviews` | filters: `course_id`, `user_id`, `is_approved`, `rating` |
| POST | `/admin/reviews/{id}/approve` | – |
| POST | `/admin/reviews/{id}/reject` | – |
| DELETE | `/admin/reviews/delete/{id}` | – |

### Quizzes & questions

| Method | URI | Body |
| --- | --- | --- |
| GET | `/admin/quizzes` | filters: `course_id`, `lesson_id` |
| GET | `/admin/quizzes/{id}` | includes questions + correct answers |
| POST | `/admin/quizzes/add` | `course_id`, `lesson_id?`, `title`, `description?`, `pass_percentage?`, `time_limit_minutes?`, `max_attempts?`, `is_published?`, `sort_order?` |
| POST | `/admin/quizzes/update` | `id` + same fields |
| DELETE | `/admin/quizzes/delete/{id}` | – |
| POST | `/admin/quiz-questions/add` | `quiz_id`, `type?`, `question`, `options[]`, `correct_answers[]`, `marks?`, `sort_order?` |
| POST | `/admin/quiz-questions/update` | `id` + same fields |
| DELETE | `/admin/quiz-questions/delete/{id}` | – |

### Certificates

| Method | URI | Body |
| --- | --- | --- |
| GET | `/admin/certificates` | filters: `course_id`, `user_id`, `status` |
| POST | `/admin/certificates/revoke/{id}` | – |
| POST | `/admin/certificates/restore/{id}` | – |

---

## 6. Typical frontend flow

1. Admin creates category → course → sections → lessons (external URL or uploaded file) →
   optional quizzes (link a quiz to a `quiz` lesson).
2. Student browses `GET /api/courses`, adds a course with `POST /api/cart/courses/add`.
3. Student checks out through `POST /api/orders/add`.
4. Enrollment is created automatically (or after an admin marks the payment as paid).
5. Student opens `GET /api/my/courses/{slug}/learn`, plays videos (external URL or signed
   stream URL), and reports progress with `POST /api/lessons/{id}/progress` or
   `POST /api/lessons/{id}/complete`.
6. Student takes quizzes and leaves a review.
7. Once every published lesson is completed, the student requests a certificate and
   downloads the PDF.

---

## 7. Tests

`phpunit.xml` now uses an in-memory SQLite database. Run:

```bash
php artisan test
```

`tests/Feature/CoursePlatformTest.php` covers the full journey:
purchase → enrollment → lesson access → progress → review → quiz → certificate → PDF,
plus expiry handling, order cancellation revocation, deferred payment enrollment and
signed private video streaming.
