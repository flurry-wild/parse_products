<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'text',
        'advantages',
        'disadvantages',
        'published_at',
        'image',
        'first_response_text',
        'text_id'
    ];

    public $timestamps = false;
}
