<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReviewsTable extends Migration
{
    public function up()
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->text('text');
            $table->text('advantages')->nullable();
            $table->text('disadvantages')->nullable();
            $table->dateTime('published_at');
            $table->string('image')->nullable();
            $table->text('first_response_text')->nullable();
            $table->text('text_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('reviews');
    }
}
