<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StoreArticle extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'content' => 'array', 'tags' => 'array', 'published_at' => 'immutable_datetime'];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function summary(): array
    {
        return $this->only(['id', 'title', 'slug', 'author_name', 'excerpt', 'cover', 'tags', 'published_at']);
    }
}
