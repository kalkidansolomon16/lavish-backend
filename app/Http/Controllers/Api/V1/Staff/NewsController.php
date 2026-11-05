<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Resources\NewsResource;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** News for the app Home screen (CEO / General Manager). */
class NewsController extends StaffController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return NewsResource::collection(News::query()->with('author')->latest()->paginate($this->perPage($request, 50)));
    }

    /** multipart/form-data if an image is sent. */
    public function store(Request $request): NewsResource
    {
        $data = $request->validate($this->rules(true));
        $news = News::create([
            ...collect($data)->except('image')->all(),
            'author_id' => $request->user()->id,
            'published_at' => ! empty($data['published']) ? now() : null,
            'image_path' => $request->file('image')?->store('news', 'public'),
        ]);

        return new NewsResource($news->load('author'));
    }

    public function update(Request $request, News $news): NewsResource
    {
        $data = $request->validate($this->rules(false));
        $news->fill(collect($data)->except('image')->all());
        if ($request->hasFile('image')) {
            $news->image_path = $request->file('image')->store('news', 'public');
        }
        if ($news->isDirty('published')) {
            $news->published_at = $news->published ? now() : null;
        }
        $news->save();

        return new NewsResource($news->load('author'));
    }

    public function destroy(News $news): JsonResponse
    {
        $news->delete();

        return response()->json(['message' => 'News removed.']);
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:160'],
            'body' => [$req, 'string', 'max:5000'],
            'tag' => [$req, Rule::in(News::TAGS)],
            'published' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
