<?php

use Illuminate\Database\Migrations\Migration;
use Gecche\Breeze\Database\Schema\Blueprint;

class CreateImportazioniTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('importazioni', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nome')->nullable();
            $table->text('descrizione')->nullable();
            $table->string('filename')->nullable();
            $table->string('ext', 6);
            $table->text('data')->nullable();
            $table->nullableTimestamps();
            $table->nullableOwnerships();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('importazioni');
    }

}
