<?php
// LOCATION: app/Http/Controllers/Admin/AdminSectorController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sector;
use App\Models\SectorCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminSectorController extends Controller
{
    // ── SECTORS ────────────────────────────────────────────────────────────

    // GET /admin/sectors
    public function index(Request $request)
    {
        $sectors = Sector::with('categories')
            ->ordered()
            ->get()
            ->map(fn($s) => $this->formatSector($s));

        if ($request->expectsJson()) {
            return response()->json(['sectors' => $sectors]);
        }
        return view('admin.sectors.index', compact('sectors'));
    }

    // POST /admin/sectors
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon'        => ['nullable', 'string', 'max:50'],
            'sort_order'  => ['nullable', 'integer'],
            'status'      => ['nullable', 'in:active,inactive'],
        ]);

        $sector = Sector::create([
            'name'        => $validated['name'],
            'slug'        => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'icon'        => $validated['icon'] ?? null,
            'sort_order'  => $validated['sort_order'] ?? 0,
            'status'      => $validated['status'] ?? 'active',
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Sector created.', 'sector' => $this->formatSector($sector)], 201);
        }
        return back()->with('success', 'Sector created.');
    }

    // PUT /admin/sectors/{sector}
    public function update(Request $request, Sector $sector)
    {
        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon'        => ['nullable', 'string', 'max:50'],
            'sort_order'  => ['nullable', 'integer'],
            'status'      => ['nullable', 'in:active,inactive'],
        ]);

        $sector->update([
            'name'        => $validated['name'] ?? $sector->name,
            'slug'        => isset($validated['name']) ? Str::slug($validated['name']) : $sector->slug,
            'description' => $validated['description'] ?? $sector->description,
            'icon'        => $validated['icon'] ?? $sector->icon,
            'sort_order'  => $validated['sort_order'] ?? $sector->sort_order,
            'status'      => $validated['status'] ?? $sector->status,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Sector updated.', 'sector' => $this->formatSector($sector->fresh('categories'))]);
        }
        return back()->with('success', 'Sector updated.');
    }

    // DELETE /admin/sectors/{sector}
    public function destroy(Request $request, Sector $sector)
    {
        // Don't delete if any of its categories still have plans attached
        $hasPlans = $sector->categories()
            ->whereHas('investmentPlans')
            ->exists();

        if ($hasPlans) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Cannot delete a sector with investment plans attached to its categories.'], 422);
            }
            return back()->withErrors(['error' => 'Cannot delete a sector with plans attached.']);
        }

        $sector->delete(); // categories cascade-delete via FK

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Sector deleted.']);
        }
        return back()->with('success', 'Sector deleted.');
    }

    // POST /admin/sectors/{sector}/activate
    public function activate(Request $request, Sector $sector)
    {
        $sector->update(['status' => 'active']);
        return $request->expectsJson()
            ? response()->json(['message' => 'Sector activated.', 'status' => 'active'])
            : back()->with('success', 'Sector activated.');
    }

    // POST /admin/sectors/{sector}/deactivate
    public function deactivate(Request $request, Sector $sector)
    {
        $sector->update(['status' => 'inactive']);
        return $request->expectsJson()
            ? response()->json(['message' => 'Sector deactivated.', 'status' => 'inactive'])
            : back()->with('success', 'Sector deactivated.');
    }

    // ── CATEGORIES ─────────────────────────────────────────────────────────

    // POST /admin/sectors/{sector}/categories
    public function storeCategory(Request $request, Sector $sector)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order'  => ['nullable', 'integer'],
            'status'      => ['nullable', 'in:active,inactive'],
        ]);

        $category = $sector->categories()->create([
            'name'        => $validated['name'],
            'slug'        => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'sort_order'  => $validated['sort_order'] ?? 0,
            'status'      => $validated['status'] ?? 'active',
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Category created.', 'category' => $category], 201);
        }
        return back()->with('success', 'Category created.');
    }

    // PUT /admin/sector-categories/{category}
    public function updateCategory(Request $request, SectorCategory $category)
    {
        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order'  => ['nullable', 'integer'],
            'status'      => ['nullable', 'in:active,inactive'],
        ]);

        $category->update([
            'name'        => $validated['name'] ?? $category->name,
            'slug'        => isset($validated['name']) ? Str::slug($validated['name']) : $category->slug,
            'description' => $validated['description'] ?? $category->description,
            'sort_order'  => $validated['sort_order'] ?? $category->sort_order,
            'status'      => $validated['status'] ?? $category->status,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Category updated.', 'category' => $category->fresh()]);
        }
        return back()->with('success', 'Category updated.');
    }

    // DELETE /admin/sector-categories/{category}
    public function destroyCategory(Request $request, SectorCategory $category)
    {
        if ($category->investmentPlans()->exists()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Cannot delete a category with investment plans attached.'], 422);
            }
            return back()->withErrors(['error' => 'Cannot delete a category with plans attached.']);
        }

        $category->delete();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Category deleted.']);
        }
        return back()->with('success', 'Category deleted.');
    }

    // POST /admin/sector-categories/{category}/activate
    public function activateCategory(Request $request, SectorCategory $category)
    {
        $category->update(['status' => 'active']);
        return $request->expectsJson()
            ? response()->json(['message' => 'Category activated.', 'status' => 'active'])
            : back()->with('success', 'Category activated.');
    }

    // POST /admin/sector-categories/{category}/deactivate
    public function deactivateCategory(Request $request, SectorCategory $category)
    {
        $category->update(['status' => 'inactive']);
        return $request->expectsJson()
            ? response()->json(['message' => 'Category deactivated.', 'status' => 'inactive'])
            : back()->with('success', 'Category deactivated.');
    }

    // ── HELPERS ────────────────────────────────────────────────────────────

    protected function formatSector(Sector $sector): array
    {
        return [
            'id'          => $sector->id,
            'name'        => $sector->name,
            'slug'        => $sector->slug,
            'description' => $sector->description,
            'icon'        => $sector->icon,
            'sort_order'  => $sector->sort_order,
            'status'      => $sector->status,
            'categories'  => $sector->categories->map(fn($c) => [
                'id'          => $c->id,
                'name'        => $c->name,
                'slug'        => $c->slug,
                'description' => $c->description,
                'sort_order'  => $c->sort_order,
                'status'      => $c->status,
            ]),
        ];
    }
}