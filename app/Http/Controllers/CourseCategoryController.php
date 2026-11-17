<?php

namespace App\Http\Controllers;

use App\Models\CourseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CourseCategoryController extends Controller
{
    public function index()
    {
        $categories = CourseCategory::query()
            ->where('status', true)
            ->withCount(['courses' => fn ($query) => $query->published()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success('Course categories retrieved successfully', $categories);
    }

    public function adminIndex()
    {
        $categories = CourseCategory::query()
            ->withCount('courses')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success('Course categories retrieved successfully', $categories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:course_categories,slug',
            'description' => 'nullable|string',
            'image' => 'nullable',
            'status' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $category = CourseCategory::create([
            'name' => $request->name,
            'slug' => $request->filled('slug') ? Str::slug($request->slug) : CourseCategory::uniqueSlug($request->name),
            'description' => $request->description,
            'image' => $this->resolveImage($request),
            'status' => $request->boolean('status', true),
            'sort_order' => (int) $request->input('sort_order', 0),
        ]);

        return $this->success('Course category created successfully', $category);
    }

    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:course_categories,id',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:course_categories,slug,'.$request->id,
            'description' => 'nullable|string',
            'image' => 'nullable',
            'status' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $category = CourseCategory::findOrFail($request->id);

        $category->fill([
            'name' => $request->name,
            'slug' => $request->filled('slug') ? Str::slug($request->slug) : $category->slug,
            'description' => $request->description,
            'image' => $this->resolveImage($request, $category->image),
            'status' => $request->has('status') ? $request->boolean('status') : $category->status,
            'sort_order' => $request->has('sort_order') ? (int) $request->sort_order : $category->sort_order,
        ])->save();

        return $this->success('Course category updated successfully', $category->fresh());
    }

    public function destroy($id)
    {
        $category = CourseCategory::find($id);

        if (! $category) {
            return $this->error('Course category not found', 404);
        }

        $category->delete();

        return $this->success('Course category deleted successfully', null);
    }

    private function resolveImage(Request $request, ?string $current = null): ?string
    {
        if ($request->hasFile('image')) {
            return $this->storePublicImage($request->file('image'), 'course-categories');
        }

        if ($request->filled('image')) {
            return $request->image;
        }

        return $current;
    }

    private function storePublicImage($file, string $folder): string
    {
        $directory = public_path('images/'.$folder);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $name = time().'-'.uniqid().'.'.$file->extension();
        $file->move($directory, $name);

        return 'images/'.$folder.'/'.$name;
    }
}
