<?php
// TASK 4 — news category.
namespace App\Models\News;

use Illuminate\Database\Eloquent\Model;

class NewsCategory extends Model
{
    protected $table = 'news_categories';
    protected $fillable = ['slug', 'name', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
}
