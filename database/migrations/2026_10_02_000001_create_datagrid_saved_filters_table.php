<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datagrid_saved_filters', function (Blueprint $table) {
            $table->id();
            $table->string('actor_id');
            $table->string('grid');
            $table->string('name');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['actor_id', 'grid', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datagrid_saved_filters');
    }
};
