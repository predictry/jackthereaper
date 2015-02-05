<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateLogMigrations2Table extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('log_migrations2', function(Blueprint $table) {
            $table->increments('id');
            $table->string('log_name', 100);
            $table->string('full_path', 255);
            $table->integer('batch');
            $table->enum('status', ['pending', 'on_queue', 'processed', 'on_backup'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('log_migrations2');
    }

}
