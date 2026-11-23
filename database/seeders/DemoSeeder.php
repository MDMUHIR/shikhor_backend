<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogImage;
use App\Models\BlogTag;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\Comment;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CoursePost;
use App\Models\CourseReview;
use App\Models\CourseSection;
use App\Models\DeliveryCharge;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonResource;
use App\Models\Like;
use App\Models\LiveSession;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\OrderProduct;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Refund;
use App\Models\User;
use App\Models\VariantAttribute;
use App\Models\VariantImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->truncateDemoTables();

        $admin = $this->upsertUser('admin', 'admin@gmail.com', 'admin', 'admin');
        $customer = $this->upsertUser('customer', 'customer@gmail.com', 'customer', 'customer');
        $nusrat = $this->upsertUser('Nusrat Jahan', 'nusrat@gmail.com', 'customer', 'customer');
        $rafiq = $this->upsertUser('Rafiq Hasan', 'rafiq@gmail.com', 'customer', 'customer');
        $tanvir = $this->upsertUser('Tanvir Ahmed', 'tanvir@gmail.com', 'customer', 'customer');

        $instructors = [
            'arif' => $this->upsertUser('Arif Chowdhury', 'arif@gmail.com', 'instructor', 'instructor', 'Senior full-stack engineer & Laravel educator', 'Arif has shipped production applications for startups across Asia and teaches web development with a project-first approach.', 'images/instructors/arif.jpg'),
            'farhana' => $this->upsertUser('Farhana Rahman', 'farhana@gmail.com', 'instructor', 'instructor', 'Product designer & Figma mentor', 'Farhana leads design systems work for fintech products and has mentored 400+ students into design roles.', 'images/instructors/farhana.jpg'),
            'mahmud' => $this->upsertUser('Mahmudul Hasan', 'mahmud@gmail.com', 'instructor', 'instructor', 'Growth marketer & freelancing coach', 'Mahmudul grew audiences for local brands before building a six-figure freelance practice from Dhaka.', 'images/instructors/mahmud.jpg'),
        ];

        $courseCategories = $this->seedCourseCategories();
        $courses = $this->seedCourses($courseCategories, $instructors);

        $productCategories = $this->seedProductCategories();
        $products = $this->seedProducts($productCategories);

        $this->seedBlogs($admin);
        $this->seedBanners();
        $this->seedCoupons();
        $this->seedDeliveryCharges();

        $courseOrder = $this->seedOrders($customer, $nusrat, $rafiq, $tanvir, $products, $courses);
        $enrollments = $this->seedEnrollments($customer, $nusrat, $rafiq, $tanvir, $courses, $courseOrder);
        $this->seedLearningProgress($customer, $nusrat, $rafiq, $courses, $enrollments);
        $this->seedReviews($customer, $nusrat, $rafiq, $tanvir, $courses);
        $this->seedCertificates($customer, $rafiq, $tanvir, $courses, $enrollments);
        $this->seedLessonResources($courses);
        $this->seedExams($courses, $admin);
        $this->seedCommunity($courses, $instructors, $customer, $nusrat, $rafiq);
        $this->seedNotifications($customer, $nusrat, $courses, $enrollments);
        $this->refreshCourseStats();

        $this->command?->info('Demo data seeded: '
            .Course::count().' courses, '
            .Product::count().' products, '
            .Blog::count().' blogs, '
            .Order::count().' orders, '
            .Enrollment::count().' enrollments.');
    }

    private function truncateDemoTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'notifications',
            'comments', 'likes', 'course_posts', 'live_sessions', 'community_mutes',
            'exam_attempts', 'exam_questions', 'exams',
            'lesson_resources',
            'refunds', 'payment_events', 'payments',
            'coupon_usages', 'coupon_courses', 'coupon_course_categories',
            'order_status_histories', 'order_products', 'order_courses', 'orders',
            'lesson_progress', 'certificates', 'course_reviews', 'quiz_attempts',
            'quiz_questions', 'quizzes', 'enrollments', 'lessons', 'course_sections',
            'cart_courses', 'carts', 'wish_lists', 'courses', 'course_categories',
            'variant_images', 'variant_attributes', 'product_variants',
            'product_images', 'products', 'categories',
            'blog_images', 'blog_tag', 'blogs', 'blog_tags', 'blog_categories',
            'banners', 'coupons', 'delivery_charges',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    private function upsertUser(string $name, string $email, string $type, string $password, ?string $headline = null, ?string $bio = null, ?string $avatar = null): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'type' => $type,
                'is_active' => true,
                'headline' => $headline,
                'bio' => $bio,
                'avatar' => $avatar,
                'email_verified_at' => now(),
            ]
        );
    }

    /**
     * @return array<string, CourseCategory>
     */
    private function seedCourseCategories(): array
    {
        $rows = [
            ['Web Development', 'web-development', 'Front-end and back-end engineering for the modern web.', 'images/category-1.jpg'],
            ['Design & UI/UX', 'design-ui-ux', 'Product design, Figma and user experience fundamentals.', 'images/category-2.jpg'],
            ['Digital Marketing', 'digital-marketing', 'Grow audiences and revenue with data-driven marketing.', 'images/category-3.png'],
            ['Data & AI', 'data-ai', 'Python, analytics and applied machine learning.', 'images/category-4.jpg'],
            ['Business & Freelancing', 'business-freelancing', 'Build a profitable independent career online.', 'images/category-2.jpg'],
        ];

        $categories = [];
        foreach ($rows as $index => [$name, $slug, $description, $image]) {
            $categories[$slug] = CourseCategory::create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'image' => $image,
                'status' => true,
                'sort_order' => $index + 1,
            ]);
        }

        return $categories;
    }

    /**
     * @param  array<string, CourseCategory>  $categories
     * @param  array<string, User>  $instructors
     * @return array<string, Course>
     */
    private function seedCourses(array $categories, array $instructors = []): array
    {
        $instructorByCategory = [
            'web-development' => 'arif',
            'design-ui-ux' => 'farhana',
            'digital-marketing' => 'mahmud',
            'data-ai' => 'farhana',
            'business-freelancing' => 'mahmud',
        ];

        $definitions = [
            [
                'category' => 'web-development',
                'title' => 'Complete Web Development Bootcamp',
                'subtitle' => 'Build responsive, modern websites from scratch with HTML, CSS, JavaScript and Laravel.',
                'description' => '<p>This bootcamp takes you from your very first line of HTML to deploying a full-stack application. You will build real projects, write clean code and learn the workflow professional developers use every day.</p><p>By the end you will have a portfolio-ready course portal powered by a Laravel API.</p>',
                'thumbnail' => 'images/courses/course-1.jpeg',
                'level' => 'beginner',
                'price' => 12000,
                'offer_price' => 6500,
                'access_days' => 0,
                'featured' => true,
                'published_days_ago' => 42,
                'requirements' => ['A computer with internet access', 'No prior coding experience needed', 'Willingness to practise daily'],
                'outcomes' => ['Build responsive layouts with modern CSS', 'Write interactive JavaScript applications', 'Create and consume REST APIs with Laravel', 'Deploy a full-stack project to production'],
                'curriculum' => [
                    ['title' => 'Getting Started', 'description' => 'Set up your tools and understand how the web works.', 'lessons' => [
                        ['title' => 'Welcome to the bootcamp', 'type' => 'video', 'duration' => 360, 'preview' => true, 'video' => 'https://www.youtube.com/watch?v=W6NZfCO5SIk'],
                        ['title' => 'Setting up VS Code and Chrome', 'type' => 'text', 'duration' => 480, 'content' => '<p>In this lesson you will install VS Code, the Live Server extension and Chrome DevTools.</p><ul><li>Install VS Code from the official website</li><li>Enable the Live Server extension</li><li>Create a project folder and open it in your editor</li></ul>'],
                        ['title' => 'How the web works', 'type' => 'video', 'duration' => 720, 'video' => 'https://www.youtube.com/watch?v=7_LPdttKXPc'],
                    ]],
                    ['title' => 'HTML, CSS and JavaScript', 'description' => 'The core languages behind every website.', 'lessons' => [
                        ['title' => 'Semantic HTML in practice', 'type' => 'video', 'duration' => 1080, 'video' => 'https://www.youtube.com/watch?v=kUMe1FH4CHE'],
                        ['title' => 'Responsive layouts with Flexbox and Grid', 'type' => 'video', 'duration' => 1320, 'video' => 'https://www.youtube.com/watch?v=rg7Fvvl3taU'],
                        ['title' => 'JavaScript essentials', 'type' => 'text', 'duration' => 900, 'content' => '<p>JavaScript adds behaviour to your pages. In this reading we cover variables, functions, arrays and events.</p><h3>Practice</h3><p>Open the browser console and try creating a small todo array. Add an item, remove it and log the result.</p>'],
                        ['title' => 'DOM mini-project: interactive gallery', 'type' => 'video', 'duration' => 1500, 'video' => 'https://www.youtube.com/watch?v=0ik6X4DJKCc'],
                    ]],
                    ['title' => 'Laravel and Deployment', 'description' => 'Turn your front end into a real full-stack product.', 'lessons' => [
                        ['title' => 'Laravel fundamentals', 'type' => 'video', 'duration' => 1200, 'video' => 'https://www.youtube.com/watch?v=ImtZ5yENzgE'],
                        ['title' => 'Building your first REST API', 'type' => 'video', 'duration' => 1680, 'video' => 'https://www.youtube.com/watch?v=ImtZ5yENzgE'],
                        ['title' => 'Deploying your project', 'type' => 'text', 'duration' => 720, 'content' => '<p>Deployment is the final step of every project. We will prepare environment variables, build assets and push the application to a managed host.</p><ul><li>Create a production environment file</li><li>Run migrations</li><li>Build the front-end bundle</li></ul>'],
                        ['title' => 'Final quiz', 'type' => 'quiz', 'duration' => 0, 'quiz' => [
                            'title' => 'Web Development Final Quiz',
                            'pass' => 60,
                            'time_limit' => 15,
                            'max_attempts' => 3,
                            'questions' => [
                                ['question' => 'What does HTML stand for?', 'options' => ['a' => 'HyperText Markup Language', 'b' => 'High-Level Text Language', 'c' => 'Hyperlink and Text Markup Language', 'd' => 'Home Tool Markup Language'], 'correct' => ['a']],
                                ['question' => 'Which CSS property creates a flexible row layout?', 'options' => ['a' => 'display: flex', 'b' => 'position: absolute', 'c' => 'float: left', 'd' => 'text-align: center'], 'correct' => ['a']],
                                ['question' => 'Which HTTP method is typically used to create a resource in a REST API?', 'options' => ['a' => 'POST', 'b' => 'GET', 'c' => 'DELETE', 'd' => 'HEAD'], 'correct' => ['a']],
                                ['question' => 'Which of these are valid JavaScript data types?', 'type' => 'multiple_choice', 'options' => ['a' => 'String', 'b' => 'Number', 'c' => 'Paragraph', 'd' => 'Boolean'], 'correct' => ['a', 'b', 'd']],
                                ['question' => 'Which Laravel command creates a new controller?', 'options' => ['a' => 'php artisan make:controller', 'b' => 'php artisan controller:new', 'c' => 'composer make:controller', 'd' => 'npm run controller'], 'correct' => ['a']],
                            ],
                        ]],
                    ]],
                ],
            ],
            [
                'category' => 'design-ui-ux',
                'title' => 'UI/UX Design Masterclass with Figma',
                'subtitle' => 'Design beautiful, usable products and build a portfolio that gets interviews.',
                'description' => '<p>Learn the complete product design process from user research to polished prototypes. Every module includes a hands-on Figma exercise you can add to your portfolio.</p>',
                'thumbnail' => 'images/courses/course-2.jpg',
                'level' => 'intermediate',
                'price' => 8000,
                'offer_price' => 4500,
                'access_days' => null,
                'featured' => true,
                'published_days_ago' => 30,
                'requirements' => ['A free Figma account', 'Basic familiarity with using a computer'],
                'outcomes' => ['Run lightweight user research', 'Build reusable Figma components', 'Design accessible interfaces', 'Present a complete case study'],
                'curriculum' => [
                    ['title' => 'Design Foundations', 'description' => 'Think like a product designer before opening Figma.', 'lessons' => [
                        ['title' => 'Welcome to UI/UX design', 'type' => 'video', 'duration' => 300, 'preview' => true, 'video' => 'https://www.youtube.com/watch?v=1wZo1V4d0jU'],
                        ['title' => 'Design thinking crash course', 'type' => 'video', 'duration' => 840, 'video' => 'https://www.youtube.com/watch?v=_r0VX-aU_T8'],
                        ['title' => 'Setting up your Figma workspace', 'type' => 'text', 'duration' => 540, 'content' => '<p>Organise your Figma file before you design. Create pages for research, wireframes and final screens. Name frames consistently so your team can navigate quickly.</p>'],
                    ]],
                    ['title' => 'Design Systems', 'description' => 'Create consistency with tokens and components.', 'lessons' => [
                        ['title' => 'Color, type and spacing', 'type' => 'video', 'duration' => 1200, 'video' => 'https://www.youtube.com/watch?v=1wZo1V4d0jU'],
                        ['title' => 'Building reusable components', 'type' => 'video', 'duration' => 1440, 'video' => 'https://www.youtube.com/watch?v=k8L2J2l8e1E'],
                        ['title' => 'Prototyping interactions', 'type' => 'video', 'duration' => 1140, 'video' => 'https://www.youtube.com/watch?v=lTIeZ2TgL6w'],
                    ]],
                    ['title' => 'Portfolio Project', 'description' => 'Apply everything to a realistic redesign.', 'lessons' => [
                        ['title' => 'Redesigning a mobile app', 'type' => 'video', 'duration' => 1800, 'video' => 'https://www.youtube.com/watch?v=c9Wg6Cb_YlU'],
                        ['title' => 'Usability testing basics', 'type' => 'text', 'duration' => 720, 'content' => '<p>Usability testing does not need to be expensive. Five participants can uncover the majority of serious issues. Ask them to complete a task while thinking aloud, then note where they hesitate.</p>'],
                        ['title' => 'Final quiz', 'type' => 'quiz', 'duration' => 0, 'quiz' => [
                            'title' => 'UI/UX Final Quiz',
                            'pass' => 60,
                            'time_limit' => 12,
                            'max_attempts' => 3,
                            'questions' => [
                                ['question' => 'What does UX stand for?', 'options' => ['a' => 'User Experience', 'b' => 'Universal Example', 'c' => 'User Export', 'd' => 'Unique Extension'], 'correct' => ['a']],
                                ['question' => 'Which Figma feature lets you reuse a design element across screens?', 'options' => ['a' => 'Components', 'b' => 'Frames', 'c' => 'Slices', 'd' => 'Grids'], 'correct' => ['a']],
                                ['question' => 'Which are principles of good visual hierarchy?', 'type' => 'multiple_choice', 'options' => ['a' => 'Contrast', 'b' => 'Scale', 'c' => 'Random spacing', 'd' => 'Alignment'], 'correct' => ['a', 'b', 'd']],
                                ['question' => 'Accessibility only matters for users with permanent disabilities.', 'type' => 'true_false', 'options' => ['a' => 'True', 'b' => 'False'], 'correct' => ['b']],
                                ['question' => 'What is a wireframe?', 'options' => ['a' => 'A low-fidelity layout blueprint', 'b' => 'A finished visual design', 'c' => 'A marketing plan', 'd' => 'A type of database'], 'correct' => ['a']],
                            ],
                        ]],
                    ]],
                ],
            ],
            [
                'category' => 'digital-marketing',
                'title' => 'Digital Marketing and Social Media Growth',
                'subtitle' => 'Plan campaigns, grow engaged audiences and measure what actually works.',
                'description' => '<p>From positioning to paid ads and analytics, this course gives you a repeatable marketing system for small businesses and personal brands.</p>',
                'thumbnail' => 'images/courses/course-3.jpg',
                'level' => 'all',
                'price' => 6000,
                'offer_price' => 3500,
                'access_days' => null,
                'featured' => true,
                'published_days_ago' => 25,
                'requirements' => ['A business or personal brand to practise with', 'Basic spreadsheet skills'],
                'outcomes' => ['Define a clear audience and offer', 'Run social media ad campaigns', 'Improve organic reach with SEO', 'Read analytics and optimise spend'],
                'curriculum' => [
                    ['title' => 'Marketing Strategy', 'description' => 'Start with the right audience and message.', 'lessons' => [
                        ['title' => 'Welcome and marketing roadmap', 'type' => 'video', 'duration' => 360, 'preview' => true, 'video' => 'https://www.youtube.com/watch?v=nU-IIXBWlS4'],
                        ['title' => 'Understanding your audience', 'type' => 'video', 'duration' => 900, 'video' => 'https://www.youtube.com/watch?v=nU-IIXBWlS4'],
                        ['title' => 'Content strategy that converts', 'type' => 'text', 'duration' => 600, 'content' => '<p>Great content answers a question your audience already has. Map each piece of content to one stage of the buyer journey: awareness, consideration or decision.</p>'],
                    ]],
                    ['title' => 'Channels', 'description' => 'Choose the channels that fit your audience.', 'lessons' => [
                        ['title' => 'Facebook and Instagram ads', 'type' => 'video', 'duration' => 1440, 'video' => 'https://www.youtube.com/watch?v=7bYl1z1c1kE'],
                        ['title' => 'SEO fundamentals', 'type' => 'video', 'duration' => 1200, 'video' => 'https://www.youtube.com/watch?v=DvwS7cV9GmQ'],
                        ['title' => 'Email marketing automation', 'type' => 'video', 'duration' => 1020, 'video' => 'https://www.youtube.com/watch?v=7bYl1z1c1kE'],
                    ]],
                    ['title' => 'Analytics and Growth', 'description' => 'Measure, learn and scale.', 'lessons' => [
                        ['title' => 'Reading analytics like a pro', 'type' => 'video', 'duration' => 1080, 'video' => 'https://www.youtube.com/watch?v=DvwS7cV9GmQ'],
                        ['title' => 'Scaling your campaigns', 'type' => 'text', 'duration' => 720, 'content' => '<p>Scale only after a campaign is profitable at a small budget. Increase spend gradually and watch cost per acquisition. Kill any campaign that cannot break even after a fair test.</p>'],
                        ['title' => 'Final quiz', 'type' => 'quiz', 'duration' => 0, 'quiz' => [
                            'title' => 'Digital Marketing Final Quiz',
                            'pass' => 60,
                            'time_limit' => 10,
                            'max_attempts' => 3,
                            'questions' => [
                                ['question' => 'What does CTR stand for?', 'options' => ['a' => 'Click-Through Rate', 'b' => 'Customer Tracking Report', 'c' => 'Cost To Reach', 'd' => 'Content Target Ratio'], 'correct' => ['a']],
                                ['question' => 'Which metric measures the percentage of visitors who leave after viewing one page?', 'options' => ['a' => 'Bounce rate', 'b' => 'Reach', 'c' => 'Frequency', 'd' => 'Impression share'], 'correct' => ['a']],
                                ['question' => 'Which are examples of owned marketing channels?', 'type' => 'multiple_choice', 'options' => ['a' => 'Blog', 'b' => 'Email list', 'c' => 'Paid billboard', 'd' => 'Website'], 'correct' => ['a', 'b', 'd']],
                                ['question' => 'What is a buyer persona?', 'options' => ['a' => 'A fictional profile of your ideal customer', 'b' => 'A discount coupon', 'c' => 'A social media platform', 'd' => 'A type of advertisement'], 'correct' => ['a']],
                                ['question' => 'SEO is a one-time task.', 'type' => 'true_false', 'options' => ['a' => 'True', 'b' => 'False'], 'correct' => ['b']],
                            ],
                        ]],
                    ]],
                ],
            ],
            [
                'category' => 'data-ai',
                'title' => 'Python for Data Science and Machine Learning',
                'subtitle' => 'Analyse real datasets and train your first machine learning models with Python.',
                'description' => '<p>Learn Python, pandas, visualisation and scikit-learn through practical notebooks. Each module ends with a dataset challenge so you build intuition, not just syntax.</p>',
                'thumbnail' => 'images/courses/course-4.jpg',
                'level' => 'intermediate',
                'price' => 10000,
                'offer_price' => 5500,
                'access_days' => null,
                'featured' => true,
                'published_days_ago' => 18,
                'requirements' => ['Basic computer literacy', 'Comfort with school-level mathematics'],
                'outcomes' => ['Clean and explore data with pandas', 'Create clear visualisations', 'Train and evaluate ML models', 'Avoid common data pitfalls'],
                'curriculum' => [
                    ['title' => 'Python Basics', 'description' => 'Refresh the Python you need for data work.', 'lessons' => [
                        ['title' => 'Welcome to data science', 'type' => 'video', 'duration' => 300, 'preview' => true, 'video' => 'https://www.youtube.com/watch?v=ua-CiDNNj30'],
                        ['title' => 'Python syntax refresher', 'type' => 'video', 'duration' => 960, 'video' => 'https://www.youtube.com/watch?v=rfscVS0vtbw'],
                        ['title' => 'Working with notebooks', 'type' => 'text', 'duration' => 480, 'content' => '<p>Jupyter notebooks combine code, output and notes in one document. Run cells with Shift+Enter and restart the kernel whenever your state becomes confusing.</p>'],
                    ]],
                    ['title' => 'Data Analysis', 'description' => 'Turn raw files into trustworthy insights.', 'lessons' => [
                        ['title' => 'NumPy and pandas essentials', 'type' => 'video', 'duration' => 1560, 'video' => 'https://www.youtube.com/watch?v=vmEHCJofslg'],
                        ['title' => 'Cleaning messy datasets', 'type' => 'video', 'duration' => 1320, 'video' => 'https://www.youtube.com/watch?v=vmEHCJofslg'],
                        ['title' => 'Data visualisation with matplotlib', 'type' => 'video', 'duration' => 1200, 'video' => 'https://www.youtube.com/watch?v=3Xc3CA655Y4'],
                    ]],
                    ['title' => 'Machine Learning', 'description' => 'Train, evaluate and explain your first models.', 'lessons' => [
                        ['title' => 'Your first ML model', 'type' => 'video', 'duration' => 1680, 'video' => 'https://www.youtube.com/watch?v=7eh4d6sabA0'],
                        ['title' => 'Model evaluation and ethics', 'type' => 'text', 'duration' => 840, 'content' => '<p>Accuracy alone can be misleading on imbalanced data. Always inspect precision, recall and the confusion matrix. Consider who is affected when a model is wrong.</p>'],
                        ['title' => 'Final quiz', 'type' => 'quiz', 'duration' => 0, 'quiz' => [
                            'title' => 'Data Science Final Quiz',
                            'pass' => 60,
                            'time_limit' => 15,
                            'max_attempts' => 3,
                            'questions' => [
                                ['question' => 'Which library is most associated with dataframes in Python?', 'options' => ['a' => 'pandas', 'b' => 'requests', 'c' => 'flask', 'd' => 'pygame'], 'correct' => ['a']],
                                ['question' => 'What does supervised learning require?', 'options' => ['a' => 'Labelled training data', 'b' => 'No data at all', 'c' => 'Only unlabelled data', 'd' => 'A graphics card'], 'correct' => ['a']],
                                ['question' => 'Which are common steps in data preparation?', 'type' => 'multiple_choice', 'options' => ['a' => 'Handling missing values', 'b' => 'Feature scaling', 'c' => 'Ignoring outliers always', 'd' => 'Encoding categories'], 'correct' => ['a', 'b', 'd']],
                                ['question' => 'Which function splits a dataframe into train and test sets in scikit-learn?', 'options' => ['a' => 'train_test_split', 'b' => 'split_frame', 'c' => 'model.fit', 'd' => 'read_csv'], 'correct' => ['a']],
                                ['question' => 'A model with perfect training accuracy always performs well on new data.', 'type' => 'true_false', 'options' => ['a' => 'True', 'b' => 'False'], 'correct' => ['b']],
                            ],
                        ]],
                    ]],
                ],
            ],
            [
                'category' => 'business-freelancing',
                'title' => 'Freelancing Masterclass: Earn in Dollars',
                'subtitle' => 'Find clients, price your work and build a stable freelance business.',
                'description' => '<p>A practical system for landing international clients without cold-emailing thousands of strangers. Learn positioning, proposals, pricing and delivery.</p>',
                'thumbnail' => 'images/courses/course-5.jpg',
                'level' => 'beginner',
                'price' => 5000,
                'offer_price' => 2500,
                'access_days' => 0,
                'featured' => false,
                'published_days_ago' => 14,
                'requirements' => ['A skill you can offer to clients', 'A laptop and reliable internet'],
                'outcomes' => ['Choose a profitable niche', 'Write proposals that get replies', 'Price projects with confidence', 'Manage clients professionally'],
                'curriculum' => [
                    ['title' => 'Getting Ready', 'description' => 'Build the foundation before you pitch.', 'lessons' => [
                        ['title' => 'Welcome to freelancing', 'type' => 'video', 'duration' => 240, 'preview' => true, 'video' => 'https://www.youtube.com/watch?v=8kD4pQ6g9yA'],
                        ['title' => 'Choosing a profitable niche', 'type' => 'video', 'duration' => 780, 'video' => 'https://www.youtube.com/watch?v=8kD4pQ6g9yA'],
                        ['title' => 'Setting up your profiles', 'type' => 'text', 'duration' => 600, 'content' => '<p>Your profile should answer one question: who do you help and what result do you deliver? Lead with the outcome, then support it with proof.</p>'],
                    ]],
                    ['title' => 'Winning Clients', 'description' => 'Turn conversations into paid projects.', 'lessons' => [
                        ['title' => 'Writing winning proposals', 'type' => 'video', 'duration' => 1080, 'video' => 'https://www.youtube.com/watch?v=6h8mKx1L0Vw'],
                        ['title' => 'Pricing and negotiating', 'type' => 'video', 'duration' => 960, 'video' => 'https://www.youtube.com/watch?v=6h8mKx1L0Vw'],
                        ['title' => 'Client communication', 'type' => 'video', 'duration' => 840, 'video' => 'https://www.youtube.com/watch?v=8kD4pQ6g9yA'],
                    ]],
                    ['title' => 'Scaling Up', 'description' => 'Deliver consistently and grow your income.', 'lessons' => [
                        ['title' => 'Building a portfolio that sells', 'type' => 'text', 'duration' => 720, 'content' => '<p>Show outcomes, not just screenshots. For each case study include the problem, the process and the measurable result. Three strong case studies beat twenty shallow samples.</p>'],
                        ['title' => 'Managing multiple projects', 'type' => 'video', 'duration' => 900, 'video' => 'https://www.youtube.com/watch?v=6h8mKx1L0Vw'],
                        ['title' => 'Final quiz', 'type' => 'quiz', 'duration' => 0, 'quiz' => [
                            'title' => 'Freelancing Final Quiz',
                            'pass' => 60,
                            'time_limit' => 10,
                            'max_attempts' => 3,
                            'questions' => [
                                ['question' => 'What is the best first step before writing a proposal?', 'options' => ['a' => 'Understand the client problem', 'b' => 'Send your invoice', 'c' => 'Lower your price', 'd' => 'Copy a template'], 'correct' => ['a']],
                                ['question' => 'What does scope creep mean?', 'options' => ['a' => 'Uncontrolled growth of project requirements', 'b' => 'A pricing strategy', 'c' => 'A type of contract', 'd' => 'A marketing funnel'], 'correct' => ['a']],
                                ['question' => 'Which elements strengthen a freelance profile?', 'type' => 'multiple_choice', 'options' => ['a' => 'Case studies', 'b' => 'Clear niche', 'c' => 'Generic copy', 'd' => 'Client testimonials'], 'correct' => ['a', 'b', 'd']],
                                ['question' => 'Which pricing model charges a fixed amount for a defined deliverable?', 'options' => ['a' => 'Fixed-price', 'b' => 'Hourly', 'c' => 'Retainer', 'd' => 'Equity'], 'correct' => ['a']],
                                ['question' => 'Raising your rates requires delivering more hours.', 'type' => 'true_false', 'options' => ['a' => 'True', 'b' => 'False'], 'correct' => ['b']],
                            ],
                        ]],
                    ]],
                ],
            ],
            [
                'category' => 'web-development',
                'title' => 'Advanced Laravel API Development',
                'subtitle' => 'Architect secure, well-tested APIs with Sanctum, resources, queues and queues.',
                'description' => '<p>Move beyond the basics and learn how production Laravel teams structure large APIs. Includes authentication, authorisation, testing and deployment patterns.</p>',
                'thumbnail' => 'images/courses/course-6.jpg',
                'level' => 'advanced',
                'price' => 9000,
                'offer_price' => 5000,
                'access_days' => 180,
                'featured' => false,
                'published_days_ago' => 9,
                'requirements' => ['Comfort with PHP and Laravel basics', 'Familiarity with REST APIs'],
                'outcomes' => ['Design maintainable service layers', 'Secure APIs with Sanctum and policies', 'Write meaningful feature tests', 'Use queues and scheduled jobs in production'],
                'curriculum' => [
                    ['title' => 'Architecture', 'description' => 'Structure applications that stay maintainable.', 'lessons' => [
                        ['title' => 'Welcome to advanced Laravel', 'type' => 'video', 'duration' => 360, 'preview' => true, 'video' => 'https://www.youtube.com/watch?v=ImtZ5yENzgE'],
                        ['title' => 'Structuring large applications', 'type' => 'video', 'duration' => 1320, 'video' => 'https://www.youtube.com/watch?v=ImtZ5yENzgE'],
                        ['title' => 'Service containers and dependency injection', 'type' => 'text', 'duration' => 720, 'content' => '<p>Dependency injection keeps classes focused and testable. Bind interfaces in a service provider and let Laravel resolve implementations automatically.</p>'],
                    ]],
                    ['title' => 'APIs and Security', 'description' => 'Protect data without slowing down delivery.', 'lessons' => [
                        ['title' => 'Sanctum authentication deep dive', 'type' => 'video', 'duration' => 1560, 'video' => 'https://www.youtube.com/watch?v=Qw6u9w6dQ4s'],
                        ['title' => 'Validation, resources and pagination', 'type' => 'video', 'duration' => 1440, 'video' => 'https://www.youtube.com/watch?v=Qw6u9w6dQ4s'],
                        ['title' => 'Rate limiting and security', 'type' => 'video', 'duration' => 1080, 'video' => 'https://www.youtube.com/watch?v=ImtZ5yENzgE'],
                    ]],
                    ['title' => 'Testing and Deployment', 'description' => 'Ship changes with confidence.', 'lessons' => [
                        ['title' => 'Feature testing with Pest', 'type' => 'video', 'duration' => 1260, 'video' => 'https://www.youtube.com/watch?v=Qw6u9w6dQ4s'],
                        ['title' => 'Queues and scheduled jobs', 'type' => 'video', 'duration' => 1140, 'video' => 'https://www.youtube.com/watch?v=ImtZ5yENzgE'],
                        ['title' => 'Final quiz', 'type' => 'quiz', 'duration' => 0, 'quiz' => [
                            'title' => 'Advanced Laravel Final Quiz',
                            'pass' => 70,
                            'time_limit' => 15,
                            'max_attempts' => 2,
                            'questions' => [
                                ['question' => 'Which Laravel package provides API token authentication by default?', 'options' => ['a' => 'Sanctum', 'b' => 'Passport UI', 'c' => 'Breeze', 'd' => 'Horizon'], 'correct' => ['a']],
                                ['question' => 'Which class transforms API responses into consistent JSON?', 'options' => ['a' => 'API Resource', 'b' => 'Middleware', 'c' => 'Migration', 'd' => 'Seeder'], 'correct' => ['a']],
                                ['question' => 'Which techniques help protect a public API?', 'type' => 'multiple_choice', 'options' => ['a' => 'Rate limiting', 'b' => 'Validation', 'c' => 'Logging secrets', 'd' => 'Authentication'], 'correct' => ['a', 'b', 'd']],
                                ['question' => 'Which Laravel feature pushes slow work to the background?', 'options' => ['a' => 'Queues', 'b' => 'Blade', 'c' => 'Migrations', 'd' => 'Artisan tinker'], 'correct' => ['a']],
                                ['question' => 'Feature tests should hit the real production database.', 'type' => 'true_false', 'options' => ['a' => 'True', 'b' => 'False'], 'correct' => ['b']],
                            ],
                        ]],
                    ]],
                ],
            ],
        ];

        $courses = [];
        foreach ($definitions as $definition) {
            $category = $categories[$definition['category']];
            $instructor = $instructors[$instructorByCategory[$definition['category']] ?? ''] ?? null;
            $totalSeconds = 0;
            foreach ($definition['curriculum'] as $sectionData) {
                foreach ($sectionData['lessons'] as $lessonData) {
                    $totalSeconds += (int) ($lessonData['duration'] ?? 0);
                }
            }
            $plainDescription = trim(strip_tags($definition['description']));

            $course = Course::create([
                'course_category_id' => $category->id,
                'instructor_id' => $instructor?->id,
                'title' => $definition['title'],
                'slug' => Str::slug($definition['title']),
                'subtitle' => $definition['subtitle'],
                'short_description' => Str::limit($plainDescription, 180),
                'description' => $definition['description'],
                'thumbnail' => $definition['thumbnail'],
                'banner' => $definition['thumbnail'],
                'level' => $definition['level'],
                'language' => 'en',
                'price' => $definition['price'],
                'offer_price' => $definition['offer_price'],
                'access_days' => $definition['access_days'],
                'status' => 'published',
                'is_featured' => $definition['featured'],
                'estimated_completion_minutes' => (int) ceil($totalSeconds / 60),
                'requirements' => $definition['requirements'],
                'outcomes' => $definition['outcomes'],
                'target_audience' => ['Beginners who want practical, job-ready skills', 'Professionals switching careers'],
                'tags' => [$category->name, Str::headline($definition['level']), 'Online course'],
                'certificate_enabled' => true,
                'completion_percentage_required' => 100,
                'require_quizzes_passed' => true,
                'seo_title' => $definition['title'].' | Shikhor Academy',
                'seo_description' => Str::limit($plainDescription, 155),
                'published_at' => now()->subDays($definition['published_days_ago']),
            ]);

            foreach ($definition['curriculum'] as $sectionIndex => $sectionData) {
                $section = CourseSection::create([
                    'course_id' => $course->id,
                    'title' => $sectionData['title'],
                    'description' => $sectionData['description'] ?? null,
                    'sort_order' => $sectionIndex + 1,
                ]);

                foreach ($sectionData['lessons'] as $lessonIndex => $lessonData) {
                    $type = $lessonData['type'] ?? 'video';
                    $lesson = Lesson::create([
                        'course_id' => $course->id,
                        'course_section_id' => $section->id,
                        'title' => $lessonData['title'],
                        'slug' => Str::slug($course->slug.'-'.$lessonData['title']),
                        'description' => $lessonData['description'] ?? null,
                        'type' => $type,
                        'video_source' => $type === 'video' ? 'external' : null,
                        'video_url' => $type === 'video' ? ($lessonData['video'] ?? null) : null,
                        'duration_seconds' => $lessonData['duration'] ?? 0,
                        'content' => $type === 'text' ? ($lessonData['content'] ?? null) : null,
                        'is_preview' => (bool) ($lessonData['preview'] ?? false),
                        'is_published' => true,
                        'sort_order' => $lessonIndex + 1,
                    ]);

                    if ($type === 'quiz' && isset($lessonData['quiz'])) {
                        $this->createQuiz($course, $lesson, $lessonData['quiz']);
                    }
                }
            }

            $course->refreshLessonStats();
            $courses[$course->slug] = $course->fresh();
        }

        return $courses;
    }

    private function createQuiz(Course $course, Lesson $lesson, array $data): Quiz
    {
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? 'Answer all questions to complete this module.',
            'pass_percentage' => $data['pass'] ?? 60,
            'time_limit_minutes' => $data['time_limit'] ?? null,
            'max_attempts' => $data['max_attempts'] ?? null,
            'is_published' => true,
            'sort_order' => 1,
        ]);

        foreach ($data['questions'] as $index => $question) {
            $options = [];
            foreach ($question['options'] as $id => $text) {
                $options[] = ['id' => (string) $id, 'text' => $text];
            }

            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'type' => $question['type'] ?? 'single_choice',
                'question' => $question['question'],
                'options' => $options,
                'correct_answers' => array_values($question['correct']),
                'marks' => $question['marks'] ?? 1,
                'sort_order' => $index + 1,
            ]);
        }

        return $quiz;
    }

    /**
     * @return array<string, Category>
     */
    private function seedProductCategories(): array
    {
        $rows = [
            ['Electronics', 'electronics', 'Speakers, headphones and smart devices for study and work.', 'images/category-1.jpg'],
            ['Learning Kits', 'learning-kits', 'Hands-on kits that pair with our courses.', 'images/category-2.jpg'],
            ['Accessories', 'accessories', 'Everyday accessories for your desk and bag.', 'images/category-3.png'],
            ['Books and Stationery', 'books-stationery', 'Books, notebooks and tools for focused learning.', 'images/category-4.jpg'],
        ];

        $categories = [];
        foreach ($rows as [$name, $slug, $description, $image]) {
            $categories[$slug] = Category::create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'image' => $image,
                'status' => true,
            ]);
        }

        return $categories;
    }

    /**
     * @param  array<string, Category>  $categories
     * @return array<string, Product>
     */
    private function seedProducts(array $categories): array
    {
        $definitions = [
            [
                'category' => 'electronics',
                'name' => 'HOCO HC2 Wireless Bluetooth Speaker',
                'short' => 'Portable Bluetooth speaker with rich bass.',
                'description' => 'Experience powerful sound and a premium finish with the HOCO HC2 Wireless Bluetooth Speaker. The portable design delivers rich bass and clear highs, making it ideal for study sessions, outdoor trips and small gatherings.',
                'price' => 2099,
                'offer_price' => 1441,
                'stock' => 23,
                'images' => ['images/product-1.jpg', 'images/product-2.png'],
                'variants' => [
                    ['sku' => 'HOCO-HC2-GREEN', 'price' => 2099, 'offer_price' => 1441, 'stock' => 10, 'attributes' => ['Color' => 'Green']],
                    ['sku' => 'HOCO-HC2-BLACK', 'price' => 2099, 'offer_price' => 1441, 'stock' => 8, 'attributes' => ['Color' => 'Black']],
                    ['sku' => 'HOCO-HC2-BLUE', 'price' => 2199, 'offer_price' => 1499, 'stock' => 5, 'attributes' => ['Color' => 'Blue']],
                ],
            ],
            [
                'category' => 'electronics',
                'name' => 'Noise Cancelling Study Headphones',
                'short' => 'Focus deeper with active noise cancellation.',
                'description' => 'Over-ear headphones with active noise cancellation, a 30-hour battery and a lightweight frame. Designed for long study and work sessions.',
                'price' => 4500,
                'offer_price' => 3299,
                'stock' => 18,
                'images' => ['images/product-3.jpeg', 'images/product-4.png'],
                'variants' => [
                    ['sku' => 'NC-HEADPHONES-BLACK', 'price' => 4500, 'offer_price' => 3299, 'stock' => 12, 'attributes' => ['Color' => 'Black']],
                    ['sku' => 'NC-HEADPHONES-SILVER', 'price' => 4700, 'offer_price' => 3499, 'stock' => 6, 'attributes' => ['Color' => 'Silver']],
                ],
            ],
            [
                'category' => 'accessories',
                'name' => 'Mechanical Keyboard TKL',
                'short' => 'Compact tenkeyless keyboard with tactile switches.',
                'description' => 'A compact mechanical keyboard with tactile switches, white backlight and a detachable USB-C cable. Great for coding and long writing sessions.',
                'price' => 5500,
                'offer_price' => 4200,
                'stock' => 24,
                'images' => ['images/product-5.jpg', 'images/product-6.jpg'],
                'variants' => [
                    ['sku' => 'MECH-KEYBOARD-BLACK', 'price' => 5500, 'offer_price' => 4200, 'stock' => 15, 'attributes' => ['Color' => 'Black']],
                    ['sku' => 'MECH-KEYBOARD-WHITE', 'price' => 5700, 'offer_price' => 4400, 'stock' => 9, 'attributes' => ['Color' => 'White']],
                ],
            ],
            [
                'category' => 'electronics',
                'name' => 'Full HD 1080p Webcam',
                'short' => 'Sharp video for online classes and meetings.',
                'description' => 'A full HD webcam with a built-in microphone and automatic light correction. Clip it to your monitor and join any class in seconds.',
                'price' => 3200,
                'offer_price' => 2499,
                'stock' => 14,
                'images' => ['images/product-7.jpg'],
                'variants' => [],
            ],
            [
                'category' => 'learning-kits',
                'name' => 'UI/UX Wireframe Sketch Kit',
                'short' => 'Everything you need to sketch interfaces on paper.',
                'description' => 'A printed wireframe sketchbook with reusable stencils, a fine-liner pen and sticky notes. Perfect companion for the UI/UX masterclass.',
                'price' => 850,
                'offer_price' => 699,
                'stock' => 40,
                'images' => ['images/product-8.jpeg'],
                'variants' => [],
            ],
            [
                'category' => 'learning-kits',
                'name' => 'Arduino Starter Kit',
                'short' => 'Learn electronics with 30 guided projects.',
                'description' => 'Includes an Arduino-compatible board, breadboard, sensors, LEDs, jumper wires and a project guide. A hands-on introduction to electronics and coding.',
                'price' => 2800,
                'offer_price' => 2200,
                'stock' => 16,
                'images' => ['images/product-9.jpg'],
                'variants' => [],
            ],
            [
                'category' => 'books-stationery',
                'name' => 'Atomic Habits Paperback',
                'short' => 'A proven framework for building better habits.',
                'description' => 'The international bestseller by James Clear. Practical strategies for forming good habits and breaking bad ones, one small step at a time.',
                'price' => 650,
                'offer_price' => 520,
                'stock' => 60,
                'images' => ['images/product-10.jpg'],
                'variants' => [],
            ],
            [
                'category' => 'accessories',
                'name' => 'Laptop Sleeve 15 inch',
                'short' => 'Water-resistant padded sleeve for daily commutes.',
                'description' => 'A slim, water-resistant laptop sleeve with soft lining and an extra pocket for chargers and notebooks.',
                'price' => 1200,
                'offer_price' => 899,
                'stock' => 32,
                'images' => ['images/category-1.jpg'],
                'variants' => [
                    ['sku' => 'SLEEVE-14', 'price' => 1200, 'offer_price' => 899, 'stock' => 20, 'attributes' => ['Size' => '14 inch']],
                    ['sku' => 'SLEEVE-15', 'price' => 1300, 'offer_price' => 999, 'stock' => 12, 'attributes' => ['Size' => '15.6 inch']],
                ],
            ],
            [
                'category' => 'electronics',
                'name' => 'Smart LED Desk Lamp',
                'short' => 'Adjustable light with USB charging port.',
                'description' => 'Dimmable LED desk lamp with three colour temperatures and a built-in USB charging port. Reduce eye strain during late study sessions.',
                'price' => 1800,
                'offer_price' => 1399,
                'stock' => 21,
                'images' => ['images/category-2.jpg'],
                'variants' => [],
            ],
            [
                'category' => 'books-stationery',
                'name' => 'Dot Grid Notebook A5',
                'short' => 'Lay-flat notebook for notes and wireframes.',
                'description' => 'A5 dot grid notebook with 160 pages of thick, bleed-resistant paper and a lay-flat binding.',
                'price' => 450,
                'offer_price' => 349,
                'stock' => 75,
                'images' => ['images/category-3.png'],
                'variants' => [],
            ],
        ];

        $products = [];
        foreach ($definitions as $definition) {
            $product = Product::create([
                'name' => $definition['name'],
                'slug' => Str::slug($definition['name']),
                'description' => $definition['description'],
                'short_description' => $definition['short'],
                'price' => $definition['price'],
                'offer_price' => $definition['offer_price'],
                'stock' => $definition['stock'],
                'status' => 'active',
                'category_id' => $categories[$definition['category']]->id,
            ]);

            foreach ($definition['images'] as $index => $url) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'url' => $url,
                    'is_featured' => $index === 0,
                ]);
            }

            foreach ($definition['variants'] as $variantIndex => $variantData) {
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $variantData['sku'],
                    'price' => $variantData['price'],
                    'offer_price' => $variantData['offer_price'],
                    'stock' => $variantData['stock'],
                ]);

                foreach ($variantData['attributes'] as $attribute => $value) {
                    VariantAttribute::create([
                        'variant_id' => $variant->id,
                        'attribute' => $attribute,
                        'value' => $value,
                    ]);
                }

                VariantImage::create([
                    'variant_id' => $variant->id,
                    'url' => $definition['images'][$variantIndex % count($definition['images'])],
                    'is_featured' => true,
                ]);
            }

            $products[$product->slug] = $product->fresh();
        }

        return $products;
    }

    private function seedBlogs(User $admin): void
    {
        $categories = [
            'learning-tips' => ['Learning Tips', '#2563eb', 'Practical advice for studying smarter online.'],
            'career-growth' => ['Career Growth', '#0f766e', 'Grow your skills and your career.'],
            'tutorials' => ['Tutorials', '#7c3aed', 'Step-by-step technical walkthroughs.'],
            'product-news' => ['Product News', '#ea580c', 'Updates from Shikhor Academy.'],
        ];

        $categoryModels = [];
        foreach ($categories as $slug => [$name, $color, $description]) {
            $categoryModels[$slug] = BlogCategory::create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'color' => $color,
                'is_active' => true,
                'sort_order' => count($categoryModels) + 1,
            ]);
        }

        $tagModels = [];
        foreach (['Laravel', 'Vue', 'Design', 'Career', 'Productivity', 'AI'] as $tagName) {
            $tagModels[$tagName] = BlogTag::create([
                'name' => $tagName,
                'slug' => Str::slug($tagName),
            ]);
        }

        $blogs = [
            [
                'title' => '5 Habits of Highly Effective Online Learners',
                'category' => 'learning-tips',
                'image' => 'images/blog/blog-1.jpg',
                'tags' => ['Productivity'],
                'featured' => true,
                'views' => 214,
                'days' => 12,
                'content' => '<p>Online learning rewards consistency far more than intensity. The students who finish courses are rarely the ones who study for eight hours once a week. They are the ones who show up for forty focused minutes almost every day.</p><h3>1. Schedule learning like a meeting</h3><p>Put study blocks in your calendar and protect them. A recurring slot at the same time each day removes the daily decision of when to start.</p><h3>2. Remove friction before you sit down</h3><p>Open the lesson, close unrelated tabs and keep a notebook nearby. Every small obstacle gives your brain an excuse to delay.</p><h3>3. Take notes in your own words</h3><p>Copying slides feels productive but fades quickly. Rephrasing forces you to process the idea and makes review far faster.</p><h3>4. Apply one idea per lesson</h3><p>Finish each lesson by writing one concrete action you can try. Small applications turn knowledge into skill.</p><h3>5. Review on a schedule</h3><p>Spend ten minutes at the end of each week revisiting your notes and quiz results. Spaced review is the cheapest performance upgrade available.</p>',
            ],
            [
                'title' => 'How to Build a Portfolio That Gets You Hired',
                'category' => 'career-growth',
                'image' => 'images/blog/blog-2.jpg',
                'tags' => ['Career', 'Design'],
                'featured' => true,
                'views' => 176,
                'days' => 8,
                'content' => '<p>Hiring managers spend less than a minute on most portfolios. Your job is to make the value obvious immediately, then give them a reason to keep reading.</p><h3>Lead with outcomes</h3><p>Instead of listing tools, describe the problem you solved and the result you produced. Numbers help, but clear before-and-after stories work too.</p><h3>Show your thinking</h3><p>Anyone can post a polished final screen. Include a short paragraph about the constraints, the trade-offs and what you would improve next.</p><h3>Cut the filler</h3><p>Three strong projects are better than ten average ones. Remove anything you would not want to discuss in an interview.</p>',
            ],
            [
                'title' => 'Laravel Sanctum Explained for Nuxt Developers',
                'category' => 'tutorials',
                'image' => 'images/blog/blog-3.jpg',
                'tags' => ['Laravel', 'Vue'],
                'featured' => false,
                'views' => 302,
                'days' => 5,
                'content' => '<p>Sanctum provides simple token authentication for single-page applications. Instead of storing a token in localStorage, the safest pattern is to keep it in an httpOnly cookie managed by your server.</p><h3>Why not localStorage?</h3><p>Any script on your page can read localStorage. A malicious dependency could steal the token. An httpOnly cookie is invisible to JavaScript and can be scoped and expired by the server.</p><h3>The BFF pattern</h3><p>Your Nuxt server exposes its own login route. It forwards credentials to Laravel, receives the token and sets the cookie. Authenticated API calls then flow through the same server proxy, which attaches the token on the way out.</p><h3>CORS becomes simple</h3><p>When the browser only talks to your own origin, you avoid complex cross-origin cookie rules and reduce the attack surface.</p>',
            ],
            [
                'title' => 'Designing Accessible Forms: A Practical Checklist',
                'category' => 'learning-tips',
                'image' => 'images/blog/blog-4.jpg',
                'tags' => ['Design'],
                'featured' => false,
                'views' => 98,
                'days' => 3,
                'content' => '<p>Forms are where users get stuck. Small accessibility improvements remove friction for everyone, not just people using assistive technology.</p><h3>Labels and inputs</h3><p>Every input needs a visible, associated label. Placeholder text disappears as soon as typing starts and should never replace a label.</p><h3>Error messages</h3><p>State the problem and the fix in plain language. Announce errors to screen readers and keep them close to the field they describe.</p><h3>Keyboard support</h3><p>Tab through the form and confirm every control is reachable and has a visible focus ring. Never remove outlines without replacing them.</p><h3>Contrast and size</h3><p>Body text should meet a 4.5:1 contrast ratio and touch targets should be at least 44 by 44 pixels.</p>',
            ],
            [
                'title' => 'What is New at Shikhor Academy This Season',
                'category' => 'product-news',
                'image' => 'images/blog/blog-5.jpg',
                'tags' => ['Career'],
                'featured' => false,
                'views' => 64,
                'days' => 2,
                'content' => '<p>We have been busy making the platform faster and more useful. Here is a quick roundup of recent improvements.</p><h3>New learning paths</h3><p>Courses now include structured sections and quizzes, so you can track progress and test your understanding as you go.</p><h3>Verified certificates</h3><p>Every certificate has a unique number that anyone can verify on our public verification page. Employers can confirm your achievement in seconds.</p><h3>A refreshed store</h3><p>Our store now supports product variants, wishlists and delivery charges, all in BDT.</p>',
            ],
            [
                'title' => 'Machine Learning Myths Debunked for Beginners',
                'category' => 'tutorials',
                'image' => 'images/blog/blog-6.jpg',
                'tags' => ['AI'],
                'featured' => false,
                'views' => 141,
                'days' => 1,
                'content' => '<p>Machine learning is powerful, but it is not magic. Clearing up a few common myths will save you weeks of confusion.</p><h3>Myth: more data always means a better model</h3><p>Quality beats quantity. Labelled, representative data with sensible cleaning will outperform a huge messy dataset.</p><h3>Myth: the model learns like a human</h3><p>Models find statistical patterns in the data you provide. They have no understanding of meaning and will happily learn your biases.</p><h3>Myth: accuracy is the only metric</h3><p>For imbalanced problems, precision and recall tell a very different story. Always choose a metric that reflects the real cost of mistakes.</p>',
            ],
        ];

        foreach ($blogs as $index => $blog) {
            $model = Blog::create([
                'user_id' => $admin->id,
                'blog_category_id' => $categoryModels[$blog['category']]->id,
                'title' => $blog['title'],
                'slug' => Str::slug($blog['title']),
                'content' => $blog['content'],
                'is_published' => true,
                'is_featured' => $blog['featured'],
                'views' => $blog['views'],
                'published_at' => now()->subDays($blog['days']),
            ]);

            BlogImage::create([
                'blog_id' => $model->id,
                'image_path' => $blog['image'],
                'sort_order' => 0,
            ]);

            $tagIds = collect($blog['tags'])->map(fn (string $tag) => $tagModels[$tag]->id)->all();
            if ($tagIds !== []) {
                $model->tags()->attach($tagIds);
            }
        }
    }

    private function seedBanners(): void
    {
        $banners = [
            ['Learn skills that pay', 'Expert-led courses in development, design, marketing and data.', 'images/banner/banner-1.png', '/courses', 1],
            ['Gear up for smarter learning', 'Curated speakers, keyboards and study kits delivered across Bangladesh.', 'images/banner/banner-2.png', '/store', 2],
            ['Earn a verifiable certificate', 'Finish a course and share a certificate employers can verify online.', 'images/banner/banner-3.png', '/certificates/verify', 3],
        ];

        foreach ($banners as [$title, $description, $image, $link, $sortOrder]) {
            Banner::create([
                'title' => $title,
                'description' => $description,
                'image' => $image,
                'link' => $link,
                'is_active' => true,
                'sort_order' => $sortOrder,
            ]);
        }
    }

    private function seedCoupons(): void
    {
        Coupon::create([
            'code' => 'WELCOME10', 'type' => 'percentage', 'discount' => 10,
            'is_active' => true, 'applies_to' => 'all', 'max_discount' => 2000,
            'min_purchase' => 1000, 'per_user_limit' => 1, 'usage_limit' => 500,
            'starts_at' => now()->subMonths(2), 'expires_at' => now()->addMonths(6),
        ]);
        Coupon::create([
            'code' => 'FLAT500', 'type' => 'fixed', 'discount' => 500,
            'is_active' => true, 'applies_to' => 'courses', 'min_purchase' => 3000,
            'per_user_limit' => 1, 'usage_limit' => 200,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addMonths(3),
        ]);
        Coupon::create([
            'code' => 'LEARN15', 'type' => 'percentage', 'discount' => 15,
            'is_active' => true, 'applies_to' => 'courses', 'max_discount' => 3000,
            'min_purchase' => 2000, 'per_user_limit' => 2, 'usage_limit' => 300,
            'starts_at' => now()->subWeek(), 'expires_at' => now()->addMonths(2),
        ]);
    }

    private function seedDeliveryCharges(): void
    {
        DeliveryCharge::create(['name' => 'Inside Dhaka', 'zone' => 'inside_dhaka', 'charge' => 60, 'minimum_order' => 0, 'status' => true]);
        DeliveryCharge::create(['name' => 'Outside Dhaka', 'zone' => 'outside_dhaka', 'charge' => 120, 'minimum_order' => 0, 'status' => true]);
        DeliveryCharge::create(['name' => 'Free delivery over 2000', 'zone' => 'free_over', 'charge' => 0, 'minimum_order' => 2000, 'status' => true]);
    }

    /**
     * @param  array<string, Product>  $products
     * @param  array<string, Course>  $courses
     */
    private function seedOrders(User $customer, User $nusrat, User $rafiq, User $tanvir, array $products, array $courses): Order
    {
        $speaker = $products['hoco-hc2-wireless-bluetooth-speaker'];
        $speakerGreen = $speaker->variants()->where('sku', 'HOCO-HC2-GREEN')->first();
        $book = $products['atomic-habits-paperback'];
        $headphones = $products['noise-cancelling-study-headphones'];
        $headphonesBlack = $headphones->variants()->where('sku', 'NC-HEADPHONES-BLACK')->first();
        $keyboard = $products['mechanical-keyboard-tkl'];
        $webcam = $products['full-hd-1080p-webcam'];
        $sleeve = $products['laptop-sleeve-15-inch'];
        $sleeve15 = $sleeve->variants()->where('sku', 'SLEEVE-15')->first();
        $lamp = $products['smart-led-desk-lamp'];

        $order1 = $this->createOrder($customer, 'ORD-20261110-0001', 'delivered', 'paid', 'cash_on_delivery', [
            ['product' => $speaker, 'variant' => $speakerGreen, 'quantity' => 1, 'unit_price' => 1441],
            ['product' => $book, 'variant' => null, 'quantity' => 2, 'unit_price' => 520],
        ], [], ['delivery_charge' => 60, 'notes' => 'Please call before delivery.'], [
            ['pending', 'Order Placed', null, 14],
            ['confirmed', 'Order Confirmed', 'Stock reserved.', 13],
            ['processing', 'Processing', null, 12],
            ['shipped', 'Shipped', 'Handed to courier.', 10],
            ['delivered', 'Delivered', 'Received by customer.', 8],
        ]);

        $this->createOrder($customer, 'ORD-20261116-0002', 'pending', 'pending', 'cash_on_delivery', [
            ['product' => $keyboard, 'variant' => null, 'quantity' => 1, 'unit_price' => 4200],
        ], [], ['delivery_charge' => 60], [
            ['pending', 'Order Placed', null, 2],
        ]);

        $courseOrder = $this->createOrder($customer, 'ORD-20261108-0003', 'enrolled', 'paid', 'online', [], [
            ['course' => $courses['complete-web-development-bootcamp'], 'unit_price' => 6500],
            ['course' => $courses['uiux-design-masterclass-with-figma'], 'unit_price' => 4500],
        ], [
            'delivery_charge' => 0,
            'coupon_code' => 'WELCOME10',
            'discount' => 1100,
            'discount_type' => 'fixed',
            'notes' => 'Course bundle purchase.',
        ], [
            ['pending', 'Order Placed', null, 11],
            ['confirmed', 'Order Confirmed', 'Payment confirmed.', 11],
            ['enrolled', 'Enrolled', 'Course access granted.', 10],
        ]);

        $this->createOrder($nusrat, 'ORD-20261114-0004', 'shipped', 'paid', 'cash_on_delivery', [
            ['product' => $headphones, 'variant' => $headphonesBlack, 'quantity' => 1, 'unit_price' => 3299],
            ['product' => $sleeve, 'variant' => $sleeve15, 'quantity' => 1, 'unit_price' => 999],
        ], [], ['delivery_charge' => 120], [
            ['pending', 'Order Placed', null, 5],
            ['confirmed', 'Order Confirmed', null, 4],
            ['processing', 'Processing', null, 4],
            ['shipped', 'Shipped', 'Courier picked up the parcel.', 3],
        ]);

        $this->createOrder($rafiq, 'ORD-20261112-0005', 'delivered', 'paid', 'cash_on_delivery', [
            ['product' => $lamp, 'variant' => null, 'quantity' => 1, 'unit_price' => 1399],
        ], [], ['delivery_charge' => 60], [
            ['pending', 'Order Placed', null, 7],
            ['confirmed', 'Order Confirmed', null, 6],
            ['delivered', 'Delivered', null, 4],
        ]);

        $this->createOrder($tanvir, 'ORD-20261115-0006', 'cancelled', 'refunded', 'online', [
            ['product' => $webcam, 'variant' => null, 'quantity' => 1, 'unit_price' => 2499],
        ], [], ['delivery_charge' => 60, 'notes' => 'Customer changed their mind.'], [
            ['pending', 'Order Placed', null, 4],
            ['cancelled', 'Cancelled', 'Refund processed to original payment method.', 3],
        ]);

        return $courseOrder;
    }

    private function createOrder(User $user, string $number, string $status, string $paymentStatus, string $paymentMethod, array $productLines, array $courseLines, array $meta = [], array $history = []): Order
    {
        $subtotal = 0;
        $totalQuantity = 0;

        foreach ($productLines as $line) {
            $subtotal += $line['unit_price'] * $line['quantity'];
            $totalQuantity += $line['quantity'];
        }

        foreach ($courseLines as $line) {
            $subtotal += $line['unit_price'];
        }

        $deliveryCharge = (float) ($meta['delivery_charge'] ?? 0);
        $discount = (float) ($meta['discount'] ?? 0);
        $discountType = $meta['discount_type'] ?? null;

        $discountedSubtotal = $discount > 0
            ? ($discountType === 'percentage' ? $subtotal - ($subtotal * $discount / 100) : $subtotal - $discount)
            : $subtotal;

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => $number,
            'status' => $status,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '017'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
            'phone_alt' => null,
            'line1' => 'House 12, Road 5',
            'line2' => 'Dhanmondi',
            'district' => 'Dhaka',
            'city' => 'Dhaka',
            'country' => 'Bangladesh',
            'currency' => 'BDT',
            'subtotal' => $subtotal,
            'delivery_charge' => $deliveryCharge,
            'discount' => $discount,
            'discount_type' => $discountType,
            'coupon_code' => $meta['coupon_code'] ?? null,
            'shipping_method' => 'Standard',
            'estimated_delivery_days' => 3,
            'total_items' => count($productLines) + count($courseLines),
            'total_quantity' => $totalQuantity,
            'total' => max($discountedSubtotal, 0) + $deliveryCharge,
            'notes' => $meta['notes'] ?? null,
            'paid_at' => in_array($paymentStatus, ['paid', 'refunded', 'partially_refunded'], true) ? now()->subDays(10) : null,
            'refunded_amount' => $paymentStatus === 'refunded' ? max($discountedSubtotal, 0) + $deliveryCharge : 0,
        ]);

        if (in_array($paymentStatus, ['paid', 'refunded', 'partially_refunded'], true)) {
            $provider = $paymentMethod === 'online' ? 'stripe' : 'manual';

            $payment = Payment::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_reference' => $provider === 'stripe' ? 'cs_demo_'.$order->id : 'manual-'.$order->order_number,
                'transaction_id' => $provider === 'stripe' ? 'pi_demo_'.$order->id : null,
                'amount' => $order->total,
                'refunded_amount' => $paymentStatus === 'refunded' ? $order->total : 0,
                'currency' => 'BDT',
                'status' => $paymentStatus === 'refunded' ? Payment::REFUNDED : Payment::SUCCESSFUL,
                'paid_at' => now()->subDays(10),
            ]);

            if ($paymentStatus === 'refunded') {
                Refund::create([
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'amount' => $order->total,
                    'reason' => 'Customer changed their mind',
                    'status' => 'completed',
                    'provider_refund_id' => 're_demo_'.$order->id,
                    'created_by' => null,
                ]);
            }
        }

        $couponCode = $meta['coupon_code'] ?? null;

        if ($couponCode && $discount > 0 && ($coupon = Coupon::query()->where('code', $couponCode)->first())) {
            CouponUsage::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'order_id' => $order->id,
                'discount_amount' => round($subtotal - $discountedSubtotal, 2),
            ]);

            $coupon->increment('times_used');
        }

        foreach ($productLines as $line) {
            /** @var Product $product */
            $product = $line['product'];
            /** @var ProductVariant|null $variant */
            $variant = $line['variant'];
            $featuredImage = $product->images()->where('is_featured', true)->first()
                ?? $product->images()->first();
            $attributes = $variant
                ? $variant->attributes()->get()->map(fn (VariantAttribute $attribute) => [
                    'attribute' => $attribute->attribute,
                    'value' => $attribute->value,
                ])->all()
                : [];
            $originalPrice = (float) ($variant?->price ?? $product->price);
            $unitPrice = (float) $line['unit_price'];

            OrderProduct::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $variant?->sku,
                'image' => $featuredImage?->url,
                'attributes' => $attributes,
                'original_price' => $originalPrice,
                'unit_price' => $unitPrice,
                'discount' => max($originalPrice - $unitPrice, 0),
                'quantity' => $line['quantity'],
                'price' => $unitPrice,
                'line_total' => $unitPrice * $line['quantity'],
                'stock_snapshot' => $variant
                    ? ['variant_id' => $variant->id, 'stock' => $variant->stock]
                    : ['product_id' => $product->id, 'stock' => $product->stock],
            ]);
        }

        foreach ($courseLines as $line) {
            /** @var Course $course */
            $course = $line['course'];
            $originalPrice = (float) $course->price;
            $unitPrice = (float) $line['unit_price'];

            OrderCourse::create([
                'order_id' => $order->id,
                'course_id' => $course->id,
                'name' => $course->title,
                'slug' => $course->slug,
                'image' => $course->thumbnail,
                'original_price' => $originalPrice,
                'unit_price' => $unitPrice,
                'discount' => max($originalPrice - $unitPrice, 0),
                'line_total' => $unitPrice,
                'access_days' => $course->access_days,
            ]);
        }

        foreach ($history as [$historyStatus, $label, $note, $daysAgo]) {
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => $historyStatus,
                'label' => $label,
                'note' => $note,
                'created_by' => $historyStatus === 'pending' ? $user->name : 'admin',
                'created_at' => now()->subDays($daysAgo),
            ]);
        }

        $createdAt = now()->subDays(15 - ($user->id % 5));
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $order;
    }

    /**
     * @param  array<string, Course>  $courses
     * @return array<string, Enrollment>
     */
    private function seedEnrollments(User $customer, User $nusrat, User $rafiq, User $tanvir, array $courses, Order $courseOrder): array
    {
        $enrollments = [];

        $enrollments['customer-web'] = $this->enroll($customer, $courses['complete-web-development-bootcamp'], 'active', $courseOrder, 10);
        $enrollments['customer-uiux'] = $this->enroll($customer, $courses['uiux-design-masterclass-with-figma'], 'active', $courseOrder, 10);
        $enrollments['customer-marketing'] = $this->enroll($customer, $courses['digital-marketing-and-social-media-growth'], 'active', null, 24);
        $enrollments['customer-freelancing'] = $this->enroll($customer, $courses['freelancing-masterclass-earn-in-dollars'], 'active', null, 6);

        $enrollments['nusrat-web'] = $this->enroll($nusrat, $courses['complete-web-development-bootcamp'], 'active', null, 32);
        $enrollments['nusrat-data'] = $this->enroll($nusrat, $courses['python-for-data-science-and-machine-learning'], 'active', null, 18);

        $enrollments['rafiq-web'] = $this->enroll($rafiq, $courses['complete-web-development-bootcamp'], 'active', null, 40);
        $enrollments['rafiq-marketing'] = $this->enroll($rafiq, $courses['digital-marketing-and-social-media-growth'], 'active', null, 22);
        $enrollments['rafiq-freelancing'] = $this->enroll($rafiq, $courses['freelancing-masterclass-earn-in-dollars'], 'active', null, 21);

        $enrollments['tanvir-uiux'] = $this->enroll($tanvir, $courses['uiux-design-masterclass-with-figma'], 'active', null, 16);
        $enrollments['tanvir-laravel'] = $this->enroll($tanvir, $courses['advanced-laravel-api-development'], 'active', null, 8);

        $this->enroll($rafiq, $courses['advanced-laravel-api-development'], 'cancelled', null, 30);
        $this->enroll($rafiq, $courses['uiux-design-masterclass-with-figma'], 'expired', null, 400);

        return $enrollments;
    }

    private function enroll(User $user, Course $course, string $status, ?Order $order, int $daysAgo): Enrollment
    {
        $enrolledAt = now()->subDays($daysAgo);
        $expiresAt = null;

        if ($course->access_days !== 0) {
            $expiresAt = (clone $enrolledAt)->addDays($course->access_days ?? 365);
        }

        if ($status === 'expired') {
            $expiresAt = now()->subDays(5);
        }

        return Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $order?->id,
            'source' => $order ? 'order' : 'manual',
            'status' => $status,
            'enrolled_at' => $enrolledAt,
            'starts_at' => $enrolledAt,
            'expires_at' => $expiresAt,
            'progress_percentage' => 0,
            'certificate_eligible' => false,
        ]);
    }

    /**
     * @param  array<string, Course>  $courses
     * @param  array<string, Enrollment>  $enrollments
     */
    private function seedLearningProgress(User $customer, User $nusrat, User $rafiq, array $courses, array $enrollments): void
    {
        $this->completeLessons($customer, $courses['uiux-design-masterclass-with-figma'], null, $enrollments['customer-uiux']);
        $this->completeLessons($customer, $courses['complete-web-development-bootcamp'], 4, $enrollments['customer-web']);
        $this->completeLessons($nusrat, $courses['python-for-data-science-and-machine-learning'], 3, $enrollments['nusrat-data']);
        $this->completeLessons($rafiq, $courses['freelancing-masterclass-earn-in-dollars'], null, $enrollments['rafiq-freelancing']);

        $this->seedQuizAttempt($customer, $courses['complete-web-development-bootcamp'], $enrollments['customer-web'], 4, 8);
        $this->seedQuizAttempt($rafiq, $courses['freelancing-masterclass-earn-in-dollars'], $enrollments['rafiq-freelancing'], 5, 15);
    }

    private function completeLessons(User $user, Course $course, ?int $limit, ?Enrollment $enrollment = null): void
    {
        $lessons = $course->publishedLessons()->get();
        if ($limit !== null) {
            $lessons = $lessons->take($limit);
        }

        $lastLesson = null;

        foreach ($lessons as $index => $lesson) {
            $completedAt = now()->subDays(max(1, 20 - $index));

            LessonProgress::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'lesson_id' => $lesson->id,
                'completed_at' => $completedAt,
                'last_position_seconds' => $lesson->duration_seconds,
                'started_at' => $completedAt->copy()->subMinutes(20),
                'last_activity_at' => $completedAt,
                'max_position_seconds' => $lesson->duration_seconds,
                'watched_seconds' => $lesson->duration_seconds,
            ]);

            $lastLesson = $lesson;
        }

        if (! $enrollment) {
            return;
        }

        $total = $course->publishedLessons()->count();
        $completed = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereNotNull('completed_at')
            ->count();
        $progress = $total > 0 ? round($completed / $total * 100, 2) : 0;
        $finished = $total > 0 && $completed >= $total;

        $enrollment->forceFill([
            'progress_percentage' => $progress,
            'last_lesson_id' => $lastLesson?->id,
            'last_accessed_at' => now()->subHours(6),
            'completed_at' => $finished ? now()->subDays(2) : null,
            'certificate_eligible' => $finished && $progress >= (float) $course->completion_percentage_required,
            'status' => $finished ? 'completed' : $enrollment->status,
        ])->save();
    }

    private function seedQuizAttempt(User $user, Course $course, Enrollment $enrollment, int $score, int $daysAgo): void
    {
        $quiz = $course->quizzes()->first();
        if (! $quiz) {
            return;
        }

        $totalMarks = (int) $quiz->questions()->sum('marks');

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'enrollment_id' => $enrollment->id,
            'score' => $score,
            'total_marks' => $totalMarks,
            'percentage' => $totalMarks > 0 ? round($score / $totalMarks * 100, 2) : 0,
            'passed' => $totalMarks > 0 && ($score / $totalMarks * 100) >= $quiz->pass_percentage,
            'answers' => [],
            'started_at' => now()->subDays($daysAgo),
            'completed_at' => now()->subDays($daysAgo)->addMinutes(9),
        ]);
    }

    /**
     * @param  array<string, Course>  $courses
     */
    private function seedReviews(User $customer, User $nusrat, User $rafiq, User $tanvir, array $courses): void
    {
        $rows = [
            [$courses['complete-web-development-bootcamp'], $customer, 5, 'Exactly what I needed', 'The projects kept me accountable. I deployed my first full-stack app in week six.', true],
            [$courses['complete-web-development-bootcamp'], $nusrat, 4, 'Very practical', 'Clear explanations and great pacing. I would love even more exercises on JavaScript arrays.', true],
            [$courses['complete-web-development-bootcamp'], $rafiq, 5, 'Worth every taka', 'The Laravel section alone paid for the course. Highly recommended for beginners.', true],
            [$courses['uiux-design-masterclass-with-figma'], $customer, 5, 'Portfolio changed completely', 'I rebuilt my portfolio during the course and landed two interviews within a month.', true],
            [$courses['uiux-design-masterclass-with-figma'], $tanvir, 4, 'Strong design foundations', 'The design system module was excellent. I use the component workflow every day now.', true],
            [$courses['digital-marketing-and-social-media-growth'], $nusrat, 4, 'Helped my small business', 'I finally understand where my ad budget goes. The analytics lesson was an eye-opener.', true],
            [$courses['digital-marketing-and-social-media-growth'], $rafiq, 5, 'Actionable from day one', 'I applied the content strategy to my page and doubled my reach in three weeks.', true],
            [$courses['python-for-data-science-and-machine-learning'], $customer, 4, 'Great introduction', 'Well structured and beginner friendly. The notebooks are a useful reference.', true],
            [$courses['python-for-data-science-and-machine-learning'], $tanvir, 5, 'Best data course in Bangla', 'The instructor explains the why, not just the code. The final project was challenging in a good way.', true],
            [$courses['freelancing-masterclass-earn-in-dollars'], $rafiq, 5, 'Landed my first client', 'The proposal template and pricing advice worked. I signed my first international client last week.', true],
            [$courses['advanced-laravel-api-development'], $nusrat, 5, 'Advanced but clear', 'The Sanctum and testing modules are excellent. This is a proper production-focused course.', true],
            [$courses['advanced-laravel-api-development'], $tanvir, 4, 'Solid architecture lessons', 'I refactored a legacy API after the first two modules. Looking forward to the testing section.', false],
        ];

        foreach ($rows as [$course, $user, $rating, $title, $comment, $approved]) {
            $review = CourseReview::create([
                'course_id' => $course->id,
                'user_id' => $user->id,
                'rating' => $rating,
                'title' => $title,
                'comment' => $comment,
                'is_approved' => $approved,
                'status' => $approved ? 'approved' : 'pending',
                'helpful_count' => $approved ? random_int(2, 24) : 0,
            ]);

            $review->forceFill(['created_at' => now()->subDays(random_int(1, 25))])->saveQuietly();
        }
    }

    /**
     * @param  array<string, Course>  $courses
     * @param  array<string, Enrollment>  $enrollments
     */
    private function seedCertificates(User $customer, User $rafiq, User $tanvir, array $courses, array $enrollments): void
    {
        $this->issueCertificate($customer, $courses['uiux-design-masterclass-with-figma'], $enrollments['customer-uiux'], 'issued', 9);
        $this->issueCertificate($rafiq, $courses['freelancing-masterclass-earn-in-dollars'], $enrollments['rafiq-freelancing'], 'issued', 6);
        $this->issueCertificate($tanvir, $courses['advanced-laravel-api-development'], $enrollments['tanvir-laravel'], 'revoked', 4);
    }

    private function issueCertificate(User $user, Course $course, Enrollment $enrollment, string $status, int $daysAgo): Certificate
    {
        $issuedAt = now()->subDays($daysAgo);

        return Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'enrollment_id' => $enrollment->id,
            'certificate_number' => sprintf('CERT-%s-%05d-%05d', strtoupper(Str::random(6)), $course->id, $user->id),
            'verification_token' => Str::random(48),
            'status' => $status,
            'issued_at' => $issuedAt,
            'completed_at' => $issuedAt->copy()->subDay(),
            'revoked_at' => $status === 'revoked' ? now()->subDays(max(1, $daysAgo - 2)) : null,
            'metadata' => [
                'student_name' => $user->name,
                'course_title' => $course->title,
            ],
        ]);
    }

    /**
     * Course-level attachments and per-lesson resources so download panels have content.
     *
     * @param  array<string, Course>  $courses
     */
    private function seedLessonResources(array $courses): void
    {
        $links = [
            'complete-web-development-bootcamp' => [
                ['Course starter files', 'https://github.com/example/web-bootcamp-starter'],
                ['HTML & CSS cheat sheet', 'https://developer.mozilla.org/en-US/docs/Web/CSS'],
            ],
            'uiux-design-masterclass-with-figma' => [
                ['Figma file: design system kit', 'https://www.figma.com/community/file/design-system-example'],
            ],
            'digital-marketing-and-social-media-growth' => [
                ['Campaign planning template', 'https://docs.google.com/spreadsheets/d/example-campaign-template'],
            ],
            'python-for-data-science-and-machine-learning' => [
                ['Practice notebooks', 'https://github.com/example/python-data-notebooks'],
            ],
            'advanced-laravel-api-development' => [
                ['API testing collection', 'https://github.com/example/laravel-api-collection'],
            ],
        ];

        foreach ($links as $slug => $resources) {
            $course = $courses[$slug] ?? null;

            if (! $course) {
                continue;
            }

            foreach ($resources as $index => [$title, $url]) {
                LessonResource::create([
                    'course_id' => $course->id,
                    'lesson_id' => null,
                    'title' => $title,
                    'external_url' => $url,
                    'is_downloadable' => true,
                    'sort_order' => $index + 1,
                ]);
            }

            $firstLesson = $course->publishedLessons()->first();

            if ($firstLesson) {
                LessonResource::create([
                    'course_id' => $course->id,
                    'lesson_id' => $firstLesson->id,
                    'title' => 'Lesson notes — '.$firstLesson->title,
                    'external_url' => 'https://developer.mozilla.org/en-US/docs/Learn',
                    'is_downloadable' => true,
                    'sort_order' => 1,
                ]);
            }
        }
    }

    /**
     * Standalone and course exams with multiple question types.
     *
     * @param  array<string, Course>  $courses
     */
    private function seedExams(array $courses, User $admin): void
    {
        $webExam = Exam::create([
            'course_id' => $courses['complete-web-development-bootcamp']->id,
            'title' => 'Web Development Final Exam',
            'description' => 'Covers HTML, CSS, JavaScript and Laravel fundamentals.',
            'instructions' => 'Answer all questions. One mark per question, 60% to pass.',
            'duration_minutes' => 30,
            'pass_percentage' => 60,
            'max_attempts' => 2,
            'negative_marking' => false,
            'shuffle_questions' => true,
            'show_answers' => 'after_submit',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(20),
            'is_published' => true,
        ]);

        $this->createExamQuestions($webExam, [
            ['single_choice', 'Which HTML element holds page metadata?', ['a' => '<body>', 'b' => '<head>', 'c' => '<main>', 'd' => '<footer>'], ['b'], 1, 'Metadata lives inside the <head> element.'],
            ['multiple_choice', 'Which of these are valid JavaScript data types?', ['a' => 'String', 'b' => 'Number', 'c' => 'Paragraph', 'd' => 'Boolean'], ['a', 'b', 'd'], 2, 'String, Number and Boolean are primitive types.'],
            ['true_false', 'Laravel migrations are used to version the database schema.', ['a' => 'True', 'b' => 'False'], ['a'], 1, 'Migrations let the team version schema changes.'],
            ['single_choice', 'Which HTTP method best describes updating an existing resource?', ['a' => 'GET', 'b' => 'POST', 'c' => 'PUT', 'd' => 'HEAD'], ['c'], 1, 'PUT/PATCH are used for updates.'],
        ]);

        $designExam = Exam::create([
            'course_id' => $courses['uiux-design-masterclass-with-figma']->id,
            'title' => 'Design Portfolio Assessment',
            'description' => 'A short assessment on design systems and usability.',
            'instructions' => 'This exam opens next week. Two attempts allowed.',
            'duration_minutes' => 25,
            'pass_percentage' => 50,
            'max_attempts' => 2,
            'negative_marking' => true,
            'negative_marking_type' => 'fixed',
            'negative_marking_value' => 0.25,
            'allow_negative_total' => false,
            'shuffle_questions' => false,
            'show_answers' => 'never',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(17),
            'is_published' => true,
        ]);

        $this->createExamQuestions($designExam, [
            ['single_choice', 'What does UX stand for?', ['a' => 'User Experience', 'b' => 'Universal Export', 'c' => 'User Example', 'd' => 'Unique Extension'], ['a'], 1, null],
            ['multiple_choice', 'Which are principles of visual hierarchy?', ['a' => 'Contrast', 'b' => 'Scale', 'c' => 'Random spacing', 'd' => 'Alignment'], ['a', 'b', 'd'], 2, 'Contrast, scale and alignment guide the eye.'],
            ['true_false', 'Five usability test participants usually uncover most serious issues.', ['a' => 'True', 'b' => 'False'], ['a'], 1, 'Small qualitative tests surface most major problems.'],
        ]);

        Exam::create([
            'course_id' => null,
            'title' => 'Shikhor Scholarship Admission Test',
            'description' => 'Open to every signed-in student. Score 50% or more to qualify.',
            'instructions' => '20 minutes. 3 questions. No negative marking.',
            'duration_minutes' => 20,
            'pass_percentage' => 50,
            'max_attempts' => 1,
            'is_published' => true,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(20),
        ]);

        $standalone = Exam::query()->whereNull('course_id')->latest('id')->first();

        if ($standalone) {
            $this->createExamQuestions($standalone, [
                ['single_choice', 'Which of these is an operating system?', ['a' => 'Chrome', 'b' => 'Linux', 'c' => 'Figma', 'd' => 'MySQL'], ['b'], 1, 'Linux is an operating system.'],
                ['true_false', 'HTTP is a stateless protocol by design.', ['a' => 'True', 'b' => 'False'], ['a'], 1, 'Each request is independent unless sessions are added.'],
                ['multiple_choice', 'Which skills are useful for online learning?', ['a' => 'Time management', 'b' => 'Note taking', 'c' => 'Ignoring deadlines', 'd' => 'Asking questions'], ['a', 'b', 'd'], 2, null],
            ]);
        }
    }

    /**
     * @param  array<int, array{0:string,1:string,2:array<string,string>,3:array<int,string>,4:int,5:?string}>  $questions
     */
    private function createExamQuestions(Exam $exam, array $questions): void
    {
        foreach ($questions as $index => [$type, $text, $options, $correct, $marks, $explanation]) {
            ExamQuestion::create([
                'exam_id' => $exam->id,
                'type' => $type,
                'question' => $text,
                'options' => collect($options)
                    ->map(fn (string $label, string $id) => ['id' => $id, 'text' => $label])
                    ->values()
                    ->all(),
                'correct_answers' => $correct,
                'marks' => $marks,
                'explanation' => $explanation,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Announcements, comments, likes, live classes and chat for the community pages.
     *
     * @param  array<string, Course>  $courses
     * @param  array<string, User>  $instructors
     */
    private function seedCommunity(array $courses, array $instructors, User $customer, User $nusrat, User $rafiq): void
    {
        $web = $courses['complete-web-development-bootcamp'];
        $design = $courses['uiux-design-masterclass-with-figma'];
        $arif = $instructors['arif'] ?? null;
        $farhana = $instructors['farhana'] ?? null;

        if ($arif && $web) {
            $post = CoursePost::create([
                'course_id' => $web->id,
                'user_id' => $arif->id,
                'title' => 'Week 3 live workshop: build a REST API together',
                'body' => 'This Friday we will build a small REST API from scratch and test it. Bring your questions from the Laravel module. The recording will be posted here afterwards.',
                'link_url' => 'https://zoom.us/j/9876543210',
                'link_label' => 'Join the workshop',
                'link_provider' => 'zoom',
                'is_pinned' => true,
                'pinned_at' => now()->subDay(),
            ]);

            $topComment = Comment::create([
                'commentable_type' => 'post',
                'commentable_id' => $post->id,
                'user_id' => $customer->id,
                'body' => 'Will the recording be available if I cannot attend live?',
                'likes_count' => 2,
            ]);

            Comment::create([
                'commentable_type' => 'post',
                'commentable_id' => $post->id,
                'user_id' => $arif->id,
                'parent_id' => $topComment->id,
                'body' => 'Yes, I will upload the recording to this post within a few hours.',
            ]);

            Comment::create([
                'commentable_type' => 'post',
                'commentable_id' => $post->id,
                'user_id' => $nusrat->id,
                'body' => 'Excited! The last workshop helped me finish my first API.',
            ]);

            Like::create(['likeable_type' => 'post', 'likeable_id' => $post->id, 'user_id' => $customer->id]);
            Like::create(['likeable_type' => 'post', 'likeable_id' => $post->id, 'user_id' => $nusrat->id]);
            Like::create(['likeable_type' => 'comment', 'likeable_id' => $topComment->id, 'user_id' => $nusrat->id]);
            Like::create(['likeable_type' => 'comment', 'likeable_id' => $topComment->id, 'user_id' => $rafiq->id]);

            $session = LiveSession::create([
                'course_id' => $web->id,
                'created_by' => $arif->id,
                'title' => 'Build a REST API with Laravel',
                'description' => 'A hands-on workshop. We will create routes, controllers, validation and tests.',
                'provider' => 'zoom',
                'join_url' => 'https://zoom.us/j/9876543210',
                'scheduled_at' => now()->addDays(2)->setTime(20, 0),
                'duration_minutes' => 90,
                'status' => 'scheduled',
                'chat_enabled' => true,
            ]);

            Comment::create([
                'commentable_type' => 'post',
                'commentable_id' => $post->id,
                'user_id' => $rafiq->id,
                'body' => 'Can we use SQLite for the practice project?',
            ]);

            unset($session);
        }

        if ($farhana && $design) {
            $post = CoursePost::create([
                'course_id' => $design->id,
                'user_id' => $farhana->id,
                'title' => 'Share your portfolio for feedback',
                'body' => 'Post a link to your portfolio in the comments. I will review the first ten submissions this weekend. Focus on showing your process, not just final screens.',
                'link_url' => null,
                'is_pinned' => false,
            ]);

            $comment = Comment::create([
                'commentable_type' => 'post',
                'commentable_id' => $post->id,
                'user_id' => $customer->id,
                'body' => 'Here is mine: https://example.com/portfolio — I would love feedback on the case study flow.',
            ]);

            Like::create(['likeable_type' => 'comment', 'likeable_id' => $comment->id, 'user_id' => $farhana->id]);
            $comment->forceFill(['likes_count' => 1])->saveQuietly();

            $live = LiveSession::create([
                'course_id' => $design->id,
                'created_by' => $farhana->id,
                'title' => 'Live portfolio critique',
                'description' => 'We are live now. Bring one screen you are proud of and one you are unsure about.',
                'provider' => 'youtube',
                'join_url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
                'youtube_video_id' => 'aqz-KE-bpKQ',
                'scheduled_at' => now()->subMinutes(15),
                'duration_minutes' => 60,
                'status' => 'live',
                'started_at' => now()->subMinutes(15),
                'chat_enabled' => true,
            ]);

            Comment::create([
                'commentable_type' => 'live',
                'commentable_id' => $live->id,
                'user_id' => $customer->id,
                'body' => 'The spacing on the pricing page feels tight on mobile.',
            ]);

            Comment::create([
                'commentable_type' => 'live',
                'commentable_id' => $live->id,
                'user_id' => $farhana->id,
                'body' => 'Good catch. Try 24px vertical rhythm and check the 375px breakpoint.',
            ]);
        }
    }

    /**
     * In-app notifications for the demo student (database channel payload shape).
     *
     * @param  array<string, Course>  $courses
     * @param  array<string, Enrollment>  $enrollments
     */
    private function seedNotifications(User $customer, User $nusrat, array $courses, array $enrollments): void
    {
        $rows = [
            [$customer, 'payment_received', 'Payment received', 'Your payment for order ORD-20261108-0003 was successful.', ['order_id' => 3], null],
            [$customer, 'course_enrolled', 'You are enrolled', 'You now have access to "'.$courses['complete-web-development-bootcamp']->title.'".', ['course_id' => $courses['complete-web-development-bootcamp']->id, 'course_slug' => $courses['complete-web-development-bootcamp']->slug], null],
            [$customer, 'certificate_issued', 'Certificate ready', 'Your certificate for UI/UX Design Masterclass with Figma is ready to download.', ['course_id' => $courses['uiux-design-masterclass-with-figma']->id, 'course_slug' => $courses['uiux-design-masterclass-with-figma']->slug], now()->subDays(2)],
            [$customer, 'community_post', 'New announcement', 'Arif Chowdhury posted a workshop announcement in Complete Web Development Bootcamp.', ['course_slug' => $courses['complete-web-development-bootcamp']->slug], null],
            [$customer, 'live_class', 'Live class starting', 'Live portfolio critique is happening now in UI/UX Design Masterclass with Figma.', ['course_slug' => $courses['uiux-design-masterclass-with-figma']->slug], null],
            [$nusrat, 'course_enrolled', 'You are enrolled', 'You now have access to "'.$courses['python-for-data-science-and-machine-learning']->title.'".', ['course_id' => $courses['python-for-data-science-and-machine-learning']->id, 'course_slug' => $courses['python-for-data-science-and-machine-learning']->slug], now()->subDay()],
        ];

        foreach ($rows as $index => [$user, $kind, $title, $message, $data, $readAt]) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\LmsNotification',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'kind' => $kind,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                ]),
                'read_at' => $readAt,
                'created_at' => now()->subHours(6 + $index * 5),
                'updated_at' => now()->subHours(6 + $index * 5),
            ]);
        }
    }

    private function refreshCourseStats(): void
    {
        foreach (Course::all() as $course) {
            $course->refreshLessonStats();
            $course->refreshRating();
            $course->refreshStudentCount();
        }
    }
}
