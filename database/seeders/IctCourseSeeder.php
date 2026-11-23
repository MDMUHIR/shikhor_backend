<?php

namespace Database\Seeders;

use App\Models\CoursePost;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LiveSession;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Additive, idempotent demo course: "ICT for Class 11-12 (HSC)".
 * Safe to run on a live database - it never truncates anything.
 *
 *   php artisan db:seed --class=IctCourseSeeder
 */
class IctCourseSeeder extends Seeder
{
    public function run(): void
    {
        $category = CourseCategory::firstOrCreate(
            ['slug' => 'hsc-academic'],
            ['name' => 'HSC Academic', 'description' => 'Class 11-12 (HSC) subject courses', 'status' => true, 'sort_order' => 6, 'image' => 'images/category-1.jpg']
        );

        $instructor = User::firstOrCreate(
            ['email' => 'sabbir@gmail.com'],
            [
                'name' => 'Sabbir Hossain',
                'password' => 'instructor',
                'type' => 'instructor',
                'headline' => 'HSC ICT teacher & board-exam mentor',
                'bio' => 'Sabbir has taught HSC ICT for nine years and helps students master number systems, HTML and C programming for board exams.',
                'email_verified_at' => now(),
            ]
        );

        $course = Course::updateOrCreate(
            ['slug' => 'ict-class-11-12-hsc'],
            [
                'course_category_id' => $category->id,
                'instructor_id' => $instructor?->id,
                'title' => 'ICT for Class 11-12 (HSC)',
                'subtitle' => 'Complete HSC ICT: all 6 chapters, board-style MCQs and live doubt-solving',
                'short_description' => 'Step-by-step HSC ICT course covering the full syllabus with notes, practice MCQs and weekly live classes.',
                'description' => "A complete preparation course for the HSC Information & Communication Technology (ICT) paper.\n\nEvery chapter is explained in simple Bangla-English with examples, number-system practice, HTML coding labs, C programming and database basics. Each chapter ends with a board-style MCQ quiz so you can check yourself before the exam.",
                'level' => 'intermediate',
                'language' => 'bn',
                'price' => 2500,
                'offer_price' => 1500,
                'access_days' => 365,
                'status' => 'published',
                'is_featured' => true,
                'published_at' => now()->subDays(3),
                'requirements' => ['HSC 1st or 2nd year student', 'A computer or smartphone with internet', 'Basic English reading'],
                'outcomes' => ['Master all six HSC ICT chapters', 'Convert between binary, octal, decimal and hexadecimal confidently', 'Write HTML pages and basic C programs', 'Solve board MCQs and creative questions with speed'],
                'target_audience' => ['HSC 2026 & 2027 candidates', 'Admission-test aspirants'],
                'tags' => ['ICT', 'HSC', 'Class 11-12', 'Board exam'],
                'certificate_enabled' => true,
                'completion_percentage_required' => 100,
                'require_quizzes_passed' => false,
                'seo_title' => 'HSC ICT Course for Class 11-12',
                'seo_description' => 'Full HSC ICT syllabus with video lessons, notes, MCQ quizzes and live classes.',
            ]
        );

        // Rebuild the curriculum so re-running never duplicates it.
        $course->sections()->each(fn (CourseSection $s) => $s->delete());
        Lesson::where('course_id', $course->id)->delete();

        $video = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'; // neutral public sample video

        $chapters = [
            ['1. ICT: World and Bangladesh Perspective', 'What ICT is, global trends and Digital Bangladesh', [
                ['Introduction to ICT', 'video', 900, true],
                ['Global village, e-commerce and e-governance', 'video', 1100, false],
                ['Digital Bangladesh and emerging technologies', 'text', 0, false],
            ]],
            ['2. Communication Systems and Computer Networking', 'Data transmission, media, topologies and wireless networks', [
                ['Data communication and transmission modes', 'video', 1200, false],
                ['Network topologies and devices', 'video', 1000, false],
                ['Wi-Fi, Bluetooth, cloud and IoT', 'text', 0, false],
            ]],
            ['3. Number Systems and Digital Devices', 'Binary, octal, decimal, hexadecimal, codes and logic gates', [
                ['Number system conversions', 'video', 1500, true],
                ['ASCII, BCD, Unicode and binary arithmetic', 'video', 1300, false],
                ['Boolean algebra and logic gates', 'video', 1400, false],
                ['Chapter 3 board MCQs', 'quiz', 0, false],
            ]],
            ['4. Introduction to Web Design and HTML', 'HTML structure, tags, tables, forms and links', [
                ['HTML basics and page structure', 'video', 1300, false],
                ['Lists, tables, links and images', 'video', 1250, false],
                ['Lab: build your first web page', 'text', 0, false],
            ]],
            ['5. Programming Language', 'Algorithms, flowcharts and C programming', [
                ['Algorithm and flowchart', 'video', 1100, false],
                ['C language: variables, operators, input/output', 'video', 1500, false],
                ['Loops, arrays and functions in C', 'video', 1700, false],
            ]],
            ['6. Database Management System', 'Tables, keys, queries and data security', [
                ['Introduction to databases and DBMS', 'video', 1000, false],
                ['Primary keys, relations and SQL basics', 'video', 1200, false],
                ['Chapter 6 revision notes', 'text', 0, false],
            ]],
        ];

        foreach ($chapters as $s => [$title, $description, $lessons]) {
            $section = CourseSection::create(['course_id' => $course->id, 'title' => $title, 'description' => $description, 'sort_order' => $s + 1]);

            foreach ($lessons as $l => [$lessonTitle, $type, $seconds, $preview]) {
                $lesson = Lesson::create([
                    'course_id' => $course->id,
                    'course_section_id' => $section->id,
                    'title' => $lessonTitle,
                    'slug' => Str::slug($lessonTitle).'-'.($s + 1).'-'.($l + 1),
                    'description' => $type === 'video' ? 'Watch the lesson, then try the practice questions in the chapter quiz.' : null,
                    'type' => $type,
                    'video_source' => $type === 'video' ? 'youtube' : null,
                    'video_url' => $type === 'video' ? $video : null,
                    'video_external_id' => $type === 'video' ? 'aqz-KE-bpKQ' : null,
                    'duration_seconds' => $seconds,
                    'content' => $type === 'text' ? "<h3>{$lessonTitle}</h3><p>Key points for this topic are summarised below. Read them once, then revise with the chapter quiz.</p><ul><li>Understand the definition and purpose.</li><li>Memorise the important terms and examples.</li><li>Practise 10 board-style questions.</li></ul>" : null,
                    'is_preview' => $preview,
                    'is_published' => true,
                    'is_required' => true,
                    'sort_order' => $l + 1,
                ]);

                if ($type === 'quiz') {
                    $this->quiz($course, $lesson);
                }
            }
        }

        $course->refreshLessonStats();

        $this->community($course, $instructor);

        $this->command?->info("Seeded “{$course->title}” (/courses/{$course->slug}) with 6 chapters, {$course->fresh()->total_lessons} lessons and a chapter quiz.");
    }

    private function quiz(Course $course, Lesson $lesson): void
    {
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'title' => 'Number Systems & Digital Devices — MCQ',
            'description' => 'Board-style practice. 4 questions, 60% to pass.',
            'pass_percentage' => 60,
            'max_attempts' => null,
            'is_published' => true,
            'is_required' => false,
        ]);

        $questions = [
            ['What is the decimal value of the binary number 1011?', ['a' => '9', 'b' => '10', 'c' => '11', 'd' => '13'], ['c'], 'Binary 1011 = 8 + 0 + 2 + 1 = 11.'],
            ['The base of the hexadecimal number system is —', ['a' => '8', 'b' => '10', 'c' => '12', 'd' => '16'], ['d'], 'Hexadecimal uses 16 symbols: 0–9 and A–F.'],
            ['Which gate gives output 1 only when ALL inputs are 1?', ['a' => 'OR', 'b' => 'AND', 'c' => 'NOT', 'd' => 'XOR'], ['b'], 'AND is true only if every input is true.'],
            ['ASCII is a — bit code (standard).', ['a' => '4', 'b' => '6', 'c' => '7', 'd' => '16'], ['c'], 'Standard ASCII uses 7 bits (128 characters).'],
        ];

        foreach ($questions as $i => [$text, $options, $correct, $explanation]) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'type' => 'single_choice',
                'question' => $text,
                'options' => collect($options)->map(fn ($t, $id) => ['id' => $id, 'text' => $t])->values()->all(),
                'correct_answers' => $correct,
                'marks' => 1,
                'sort_order' => $i + 1,
                'explanation' => $explanation,
            ]);
        }
    }

    /** One announcement and one upcoming live class so the new community page has content. */
    private function community(Course $course, ?User $instructor): void
    {
        if (! $instructor) {
            return;
        }

        if (! CoursePost::where('course_id', $course->id)->exists()) {
            CoursePost::create([
                'course_id' => $course->id,
                'user_id' => $instructor->id,
                'title' => 'Welcome to ICT for Class 11-12!',
                'body' => "Assalamu alaikum everyone. Start with Chapter 1, and keep Chapter 3 (Number Systems) for the weekend — it carries the most board marks.\nLive doubt-solving class every Friday 8 PM. Join the group below for notes and updates.",
                'link_url' => 'https://chat.whatsapp.com/example-group-link',
                'link_label' => 'Join the WhatsApp group',
                'link_provider' => 'whatsapp',
                'is_pinned' => true,
                'pinned_at' => now(),
            ]);
        }

        if (! LiveSession::where('course_id', $course->id)->exists()) {
            LiveSession::create([
                'course_id' => $course->id,
                'created_by' => $instructor->id,
                'title' => 'Chapter 3 live practice: number system conversions',
                'description' => 'Bring a pen and paper. We will solve 20 board questions together.',
                'provider' => 'zoom',
                'join_url' => 'https://zoom.us/j/1234567890',
                'scheduled_at' => now()->next('Friday')->setTime(20, 0),
                'duration_minutes' => 90,
                'status' => 'scheduled',
            ]);
        }
    }
}
