<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->integer('stock');
            $table->decimal('price', 10, 2);
            $table->boolean('featured')->default(false);
            $table->json('settings')->nullable();
            $table->enum('condition', ['new', 'refurbished'])->default('new');
            $table->uuid('reference');
            $table->foreignId('owner_id');
            $table->dateTime('released_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
