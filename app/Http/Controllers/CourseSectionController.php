<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseSection;
use Illuminate\Http\Request;

class CourseSectionController extends Controller
{
    public function index($courseId)
    {
        if ($d = $this->denyCourse($courseId)) { return $d; }
        $course = Course::find($courseId);

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $sections = $course->sections()
            ->with(['lessons' => fn ($query) => $query->with('quiz')])
            ->get();

        return $this->success('Course sections retrieved successfully', $sections);
    }

    public function store(Request $request)
    {
        if ($d = $this->denyCourse($request->course_id)) { return $d; }
        $request->validate([
            'course_id' => 'required|exists:courses,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ]);

        $section = CourseSection::create([
            'course_id' => $request->course_id,
            'title' => $request->title,
            'description' => $request->description,
            'sort_order' => (int) $request->input('sort_order', 0),
        ]);

        return $this->success('Course section created successfully', $section);
    }

    public function update(Request $request)
    {
        if ($d = $this->denySection($request->id)) { return $d; }
        $request->validate([
            'id' => 'required|exists:course_sections,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ]);

        $section = CourseSection::findOrFail($request->id);

        $section->fill([
            'title' => $request->title,
            'description' => $request->description,
            'sort_order' => $request->has('sort_order') ? (int) $request->sort_order : $section->sort_order,
        ])->save();

        return $this->success('Course section updated successfully', $section->fresh());
    }

    public function destroy($id)
    {
        if ($d = $this->denySection($id)) { return $d; }
        $section = CourseSection::find($id);

        if (! $section) {
            return $this->error('Course section not found', 404);
        }

        $section->delete();

        return $this->success('Course section deleted successfully', null);
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => 'required|exists:course_sections,id',
            'sections.*.sort_order' => 'required|integer',
        ]);

        foreach ($request->sections as $item) {
            if ($d = $this->denySection($item['id'])) { return $d; }

            CourseSection::where('id', $item['id'])->update(['sort_order' => (int) $item['sort_order']]);
        }

        return $this->success('Course sections reordered successfully', null);
    }
}
