<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\CourseCartController;
use App\Http\Controllers\CourseCategoryController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\CourseReviewController;
use App\Http\Controllers\CourseSectionController;
use App\Http\Controllers\DeliveryChargeController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamAdminController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\InstructorController;
use App\Http\Controllers\LessonResourceController;
use App\Http\Controllers\LiveSessionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\WishListController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');

// Google OAuth routes need session support
Route::group(['middleware' => ['web']], function () {
    Route::get('auth/google', [AuthController::class, 'redirectToGoogle']);
    Route::get('auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
});

Route::get('products', [ProductController::class, 'getProducts']);
Route::get('products/search', [ProductController::class, 'searchProducts']);
Route::get('products/{identifier}', [ProductController::class, 'getSingleProduct']);
Route::get('categories', [CategoryController::class, 'getCategories']);

Route::get('banners', [BannerController::class, 'getBanners']);

Route::get('delivery-charges', [DeliveryChargeController::class, 'getDeliveryCharges']);
Route::get('delivery-charges/quote', [DeliveryChargeController::class, 'quote']);

Route::get('blogs', [BlogController::class, 'getBlogs']);
Route::get('blogs/{id}', [BlogController::class, 'getSingleBlog']);
Route::get('blog-categories', [BlogController::class, 'getBlogCategories']);
Route::get('blog-tags', [BlogController::class, 'getBlogTags']);

/*
|--------------------------------------------------------------------------
| Course catalog (public)
|--------------------------------------------------------------------------
*/

Route::get('courses', [CourseController::class, 'index']);
Route::get('courses/{slug}/reviews', [CourseReviewController::class, 'index']);
Route::get('courses/{slug}/attachments', [LessonResourceController::class, 'forCourse']);
Route::get('courses/{slug}', [CourseController::class, 'show']);
Route::get('course-categories', [CourseCategoryController::class, 'index']);
Route::get('instructors', [InstructorController::class, 'index']);
Route::get('instructors/{id}', [InstructorController::class, 'show']);

// Signed URL used by the video player for privately hosted lesson videos.
Route::get('lessons/{lesson}/stream', [LessonController::class, 'stream'])
    ->middleware('signed')
    ->name('lessons.stream');

// Public certificate verification.
Route::get('certificates/verify/{number}', [CertificateController::class, 'verify'])->middleware('throttle:60,1');

// Payment gateway webhooks (signature verified, idempotent).
Route::post('webhooks/payments/{provider}', [PaymentController::class, 'webhook'])->middleware('throttle:120,1');

Route::group(['prefix' => 'admin'], function () {
    // Course management: admins (everything) and instructors (own courses only).
    Route::group(['middleware' => ['auth:sanctum', 'staff']], function () {
        /*
        | Course management
        */

        Route::get('course-categories', [CourseCategoryController::class, 'adminIndex']);

        Route::get('courses', [CourseController::class, 'adminIndex']);
        Route::get('courses/{id}', [CourseController::class, 'adminShow']);
        Route::post('courses/add', [CourseController::class, 'store']);
        Route::post('courses/update', [CourseController::class, 'update']);
        Route::delete('courses/delete/{id}', [CourseController::class, 'destroy']);

        Route::get('courses/{courseId}/sections', [CourseSectionController::class, 'index']);
        Route::post('course-sections/add', [CourseSectionController::class, 'store']);
        Route::post('course-sections/update', [CourseSectionController::class, 'update']);
        Route::post('course-sections/reorder', [CourseSectionController::class, 'reorder']);
        Route::delete('course-sections/delete/{id}', [CourseSectionController::class, 'destroy']);

        Route::get('courses/{courseId}/lessons', [LessonController::class, 'adminIndex']);
        Route::get('lessons/{id}', [LessonController::class, 'adminShow']);
        Route::post('lessons/add', [LessonController::class, 'store']);
        Route::post('lessons/update', [LessonController::class, 'update']);
        Route::post('lessons/reorder', [LessonController::class, 'reorder']);
        Route::delete('lessons/delete/{id}', [LessonController::class, 'destroy']);


            Route::get('quizzes', [QuizController::class, 'adminIndex']);
            Route::get('quizzes/{id}', [QuizController::class, 'adminShow']);
            Route::post('quizzes/add', [QuizController::class, 'store']);
            Route::post('quizzes/update', [QuizController::class, 'update']);
            Route::delete('quizzes/delete/{id}', [QuizController::class, 'destroy']);
            Route::post('quiz-questions/add', [QuizController::class, 'storeQuestion']);
            Route::post('quiz-questions/update', [QuizController::class, 'updateQuestion']);
            Route::delete('quiz-questions/delete/{id}', [QuizController::class, 'destroyQuestion']);


        Route::get('analytics', [DashboardController::class, 'analytics']);

        // Exams
        Route::get('exams', [ExamAdminController::class, 'index']);
        Route::get('exams/{id}', [ExamAdminController::class, 'show']);
        Route::get('exams/{id}/attempts', [ExamAdminController::class, 'attempts']);
        Route::post('exams/add', [ExamAdminController::class, 'store']);
        Route::post('exams/update', [ExamAdminController::class, 'update']);
        Route::delete('exams/delete/{id}', [ExamAdminController::class, 'destroy']);
        Route::post('exam-questions/add', [ExamAdminController::class, 'storeQuestion']);
        Route::post('exam-questions/update', [ExamAdminController::class, 'updateQuestion']);
        Route::delete('exam-questions/delete/{id}', [ExamAdminController::class, 'destroyQuestion']);

        Route::get('lessons/{lessonId}/resources', [LessonResourceController::class, 'index']);
        Route::get('courses/{courseId}/attachments', [LessonResourceController::class, 'courseIndex']);
        Route::post('lesson-resources/add', [LessonResourceController::class, 'store']);
        Route::delete('lesson-resources/delete/{id}', [LessonResourceController::class, 'destroy']);
    });

    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::group(['middleware' => 'admin'], function () {
            Route::get('users', [AuthController::class, 'getUsers']);
            Route::post('users/add', [AuthController::class, 'addUser']);
            Route::post('users/update', [AuthController::class, 'updateUser']);
            Route::post('users/{id}/avatar', [AuthController::class, 'uploadAvatar']);
            Route::delete('users/delete/{id}', [AuthController::class, 'deleteUser']);

            Route::post('categories/add', [CategoryController::class, 'addCategory']);
            Route::delete('categories/delete/{id}', [CategoryController::class, 'deleteCategory']);

            Route::post('products/add', [ProductController::class, 'addProduct']);
            Route::post('products/update', [ProductController::class, 'updateProduct']);
            Route::delete('products/delete/{id}', [ProductController::class, 'deleteProduct']);
            Route::get('products/{slug}', [ProductController::class, 'getAdminProduct']);
            Route::delete('products/{productId}/images/{imageId}', [ProductController::class, 'deleteProductImage']);
            Route::post('products/{id}/variants', [ProductController::class, 'addVariant']);
            Route::post('variants/delete/{id}', [ProductController::class, 'deleteVariant']);
            Route::get('variants/{id}', [ProductController::class, 'getAdminVariant']);
            Route::match(['PUT', 'POST'], 'variants/{id}', [ProductController::class, 'updateVariant']);
            Route::post('variants/{id}/images', [ProductController::class, 'addVariantImage']);
            Route::delete('variants/{variantId}/images/{imageId}', [ProductController::class, 'deleteVariantImage']);

            Route::get('orders', [OrderController::class, 'getAdminOrders']);
            Route::get('orders/{id}', [OrderController::class, 'getAdminOrder']);
            Route::post('orders/update', [OrderController::class, 'updateAdminOrder']);

            Route::get('coupon', [CouponController::class, 'getCoupons']);
            Route::post('coupon/add', [CouponController::class, 'addCoupon']);
            Route::post('coupon/update', [CouponController::class, 'updateCoupon']);
            Route::delete('coupon/delete/{id}', [CouponController::class, 'deleteCoupon']);

            Route::get('blogs', [BlogController::class, 'getBlogs']);
            Route::post('blogs/add', [BlogController::class, 'addBlog']);
            Route::post('blogs/update', [BlogController::class, 'updateBlog']);
            Route::delete('blogs/delete/{id}', [BlogController::class, 'deleteBlog']);

            Route::post('blog-categories/add', [BlogController::class, 'addBlogCategory']);
            Route::post('blog-categories/update', [BlogController::class, 'updateBlogCategory']);
            Route::delete('blog-categories/delete/{id}', [BlogController::class, 'deleteBlogCategory']);

            Route::post('blog-tags/add', [BlogController::class, 'addBlogTag']);
            Route::post('blog-tags/update', [BlogController::class, 'updateBlogTag']);
            Route::delete('blog-tags/delete/{id}', [BlogController::class, 'deleteBlogTag']);

            Route::get('banners', [BannerController::class, 'getBanners']);
            Route::post('banners/add', [BannerController::class, 'addBanner']);
            Route::post('banners/update', [BannerController::class, 'updateBanner']);
            Route::delete('banners/delete/{id}', [BannerController::class, 'deleteBanner']);

            Route::get('delivery-charges', [DeliveryChargeController::class, 'getDeliveryCharges']);
            Route::post('delivery-charges/add', [DeliveryChargeController::class, 'addDeliveryCharge']);
            Route::post('delivery-charges/update', [DeliveryChargeController::class, 'updateDeliveryCharge']);
            Route::delete('delivery-charges/delete/{id}', [DeliveryChargeController::class, 'deleteDeliveryCharge']);

            Route::get('enrollments', [EnrollmentController::class, 'adminIndex']);
            Route::post('enrollments/grant', [EnrollmentController::class, 'grant']);
            Route::post('enrollments/extend', [EnrollmentController::class, 'extend']);
            Route::post('enrollments/revoke/{id}', [EnrollmentController::class, 'revoke']);
            Route::post('enrollments/reactivate/{id}', [EnrollmentController::class, 'reactivate']);

            Route::get('reviews', [CourseReviewController::class, 'adminIndex']);
            Route::post('reviews/{id}/approve', [CourseReviewController::class, 'approve']);
            Route::post('reviews/{id}/reject', [CourseReviewController::class, 'reject']);
            Route::delete('reviews/delete/{id}', [CourseReviewController::class, 'adminDestroy']);

            Route::post('course-categories/add', [CourseCategoryController::class, 'store']);
            Route::post('course-categories/update', [CourseCategoryController::class, 'update']);
            Route::delete('course-categories/delete/{id}', [CourseCategoryController::class, 'destroy']);

            Route::get('payments', [PaymentController::class, 'adminIndex']);
            Route::get('payments/{id}', [PaymentController::class, 'adminShow']);
            Route::post('orders/{orderId}/enroll', [PaymentController::class, 'enroll']);
            Route::post('orders/{orderId}/confirm-payment', [PaymentController::class, 'confirm']);
            Route::post('payments/{id}/refund', [PaymentController::class, 'refund']);

            Route::get('certificates', [CertificateController::class, 'adminIndex']);
            Route::post('certificates/revoke/{id}', [CertificateController::class, 'revoke']);
            Route::post('certificates/restore/{id}', [CertificateController::class, 'restore']);
        });
    });
});

Route::group(['middleware' => 'auth:sanctum'], function () {

    Route::get('cart', [CartController::class, 'getCart']);
    Route::post('cart/add', [CartController::class, 'addToCart'])->middleware('throttle:cart');
    Route::post('cart/update', [CartController::class, 'updateCart'])->middleware('throttle:cart');
    Route::delete('cart/delete/{id}', [CartController::class, 'deleteCart']);
    Route::post('cart/toggle-selection', [CartController::class, 'toggleSelection']);
    Route::post('cart/select-all', [CartController::class, 'selectAll']);
    Route::post('cart/deselect-all', [CartController::class, 'deselectAll']);

    Route::get('wishlist', [WishListController::class, 'getWishList']);
    Route::post('wishlist/add', [WishListController::class, 'addToWishList'])->middleware('throttle:sensitive');
    Route::delete('wishlist/delete/{id}', [WishListController::class, 'deleteWishList']);

    Route::post('verify-coupon', [CouponController::class, 'verifyCoupon'])->middleware('throttle:sensitive');

    Route::get('orders', [OrderController::class, 'getOrder']);
    Route::post('orders/add', [OrderController::class, 'addOrder'])->middleware('throttle:sensitive');
    Route::post('orders/direct', [OrderController::class, 'directOrder'])->middleware('throttle:sensitive');

    /*
    | Course cart
    */

    Route::get('cart/courses', [CourseCartController::class, 'index']);
    Route::post('cart/courses/add', [CourseCartController::class, 'add'])->middleware('throttle:cart');
    Route::post('cart/courses/toggle-selection', [CourseCartController::class, 'toggleSelection']);
    Route::post('cart/courses/select-all', [CourseCartController::class, 'selectAll']);
    Route::post('cart/courses/deselect-all', [CourseCartController::class, 'deselectAll']);
    Route::delete('cart/courses/delete/{id}', [CourseCartController::class, 'destroy']);

    /*
    | Student learning
    */

    Route::get('my/courses', [EnrollmentController::class, 'myCourses']);
    Route::get('my/courses/{slug}', [EnrollmentController::class, 'show']);
    Route::get('my/courses/{slug}/learn', [LessonController::class, 'learning']);
    Route::get('my/certificates', [CertificateController::class, 'mine']);
    Route::get('my/dashboard', [DashboardController::class, 'student']);

    // Exams (timed, auto-marked, optional negative marking)
    Route::get('exams', [ExamController::class, 'index']);
    Route::get('exams/{id}', [ExamController::class, 'show']);
    Route::get('exams/{id}/attempts', [ExamController::class, 'myAttempts']);
    Route::post('exams/{id}/start', [ExamController::class, 'start'])->middleware('throttle:sensitive');
    Route::get('exam-attempts/{id}', [ExamController::class, 'attempt']);
    Route::post('exam-attempts/{id}/answers', [ExamController::class, 'saveAnswers'])->middleware('throttle:cart');
    Route::post('exam-attempts/{id}/submit', [ExamController::class, 'submit'])->middleware('throttle:sensitive');
    Route::get('my/payments', [PaymentController::class, 'mine']);
    Route::post('orders/{id}/pay', [PaymentController::class, 'pay'])->middleware('throttle:sensitive');
    Route::get('lesson-resources/{id}/download', [LessonResourceController::class, 'download']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read']);

    // Course community: announcements, comments, likes and live classes with live chat.
    // Access (enrolled student / the course's instructor / admin) is enforced inside the controllers.
    Route::get('courses/{slug}/posts', [CommunityController::class, 'posts']);
    Route::post('courses/{slug}/posts', [CommunityController::class, 'storePost'])->middleware('throttle:sensitive');
    Route::post('posts/{id}/update', [CommunityController::class, 'updatePost']);
    Route::delete('posts/{id}', [CommunityController::class, 'destroyPost']);
    Route::post('posts/{id}/pin', [CommunityController::class, 'pinPost']);
    Route::post('posts/{id}/lock-comments', [CommunityController::class, 'lockPost']);
    Route::post('posts/{id}/like', [CommunityController::class, 'likePost'])->middleware('throttle:cart');
    Route::get('posts/{id}/comments', [CommunityController::class, 'comments']);
    Route::post('posts/{id}/comments', [CommunityController::class, 'storeComment'])->middleware('throttle:30,1');
    Route::delete('comments/{id}', [CommunityController::class, 'destroyComment']);
    Route::post('comments/{id}/pin', [CommunityController::class, 'pinComment']);
    Route::post('comments/{id}/like', [CommunityController::class, 'likeComment'])->middleware('throttle:cart');
    Route::post('courses/{slug}/mute', [CommunityController::class, 'mute']);
    Route::post('courses/{slug}/unmute', [CommunityController::class, 'unmute']);

    Route::get('courses/{slug}/live-sessions', [LiveSessionController::class, 'index']);
    Route::post('courses/{slug}/live-sessions', [LiveSessionController::class, 'store'])->middleware('throttle:sensitive');
    Route::post('live-sessions/{id}/update', [LiveSessionController::class, 'update']);
    Route::delete('live-sessions/{id}', [LiveSessionController::class, 'destroy']);
    Route::post('live-sessions/{id}/start', [LiveSessionController::class, 'start']);
    Route::post('live-sessions/{id}/end', [LiveSessionController::class, 'end']);
    Route::post('live-sessions/{id}/cancel', [LiveSessionController::class, 'cancel']);
    Route::post('live-sessions/{id}/lock-chat', [LiveSessionController::class, 'lockChat']);
    Route::get('live-sessions/{id}/chat', [LiveSessionController::class, 'chat'])->middleware('throttle:90,1');
    Route::post('live-sessions/{id}/chat', [LiveSessionController::class, 'postChat'])->middleware('throttle:60,1');
    Route::get('lessons/{id}', [LessonController::class, 'show']);
    Route::post('lessons/{id}/complete', [LessonController::class, 'complete']);
    Route::post('lessons/{id}/incomplete', [LessonController::class, 'incomplete']);
    Route::post('lessons/{id}/progress', [LessonController::class, 'trackProgress']);

    Route::get('courses/{slug}/reviews/mine', [CourseReviewController::class, 'mine']);
    Route::post('courses/{slug}/reviews', [CourseReviewController::class, 'store'])->middleware('throttle:sensitive');
    Route::delete('courses/{slug}/reviews', [CourseReviewController::class, 'destroy']);

    Route::get('quizzes/{id}', [QuizController::class, 'show']);
    Route::post('quizzes/{id}/submit', [QuizController::class, 'submit'])->middleware('throttle:sensitive');
    Route::get('quizzes/{id}/attempts', [QuizController::class, 'attempts']);
    Route::get('quiz-attempts/{id}', [QuizController::class, 'result']);

    Route::post('courses/{slug}/certificate', [CertificateController::class, 'issue'])->middleware('throttle:sensitive');
    Route::get('certificates/{number}/download', [CertificateController::class, 'download']);
    Route::get('certificates/{number}', [CertificateController::class, 'show']);

    Route::get('logout', [AuthController::class, 'logout']);
    Route::get('user', [AuthController::class, 'user']);
});
